<?php

namespace Headcount\Database;

use PDO;
use Exception;

/**
 * Database Migration System
 * Handles database migrations and versioning
 */
class Migration
{
    private $db;
    private $migrationsPath;

    public function __construct(PDO $db, $migrationsPath = null)
    {
        $this->db = $db;
        $this->migrationsPath = $migrationsPath ?? __DIR__ . '/migrations';
    }

    /**
     * Get all migration files
     */
    private function getMigrationFiles()
    {
        $files = [];
        if (is_dir($this->migrationsPath)) {
            $files = glob($this->migrationsPath . '/*.sql');
            natsort($files);
        }
        return $files;
    }

    /**
     * Get executed migrations from database
     */
    private function getExecutedMigrations()
    {
        // Create migrations table if it doesn't exist
        $this->createMigrationsTable();

        $stmt = $this->db->query("SELECT migration_name FROM migrations ORDER BY migration_name");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Create migrations tracking table
     */
    private function createMigrationsTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            batch INT DEFAULT 1,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    /**
     * Get pending migrations
     */
    public function getPendingMigrations()
    {
        $allFiles = $this->getMigrationFiles();
        $executed = $this->getExecutedMigrations();

        $pending = [];
        foreach ($allFiles as $file) {
            $migration = basename($file);
            if (!in_array($migration, $executed)) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    /**
     * Run all pending migrations
     */
    public function run()
    {
        $pending = $this->getPendingMigrations();
        
        if (empty($pending)) {
            return [
                'success' => true,
                'message' => 'No pending migrations',
                'executed' => []
            ];
        }

        $executed = [];
        $errors = [];

        foreach ($pending as $migration) {
            try {
                $this->executeMigration($migration);
                $this->recordMigration($migration);
                $executed[] = $migration;
            } catch (Exception $e) {
                $errors[] = [
                    'migration' => $migration,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'success' => empty($errors),
            'executed' => $executed,
            'errors' => $errors
        ];
    }

    /**
     * Execute a single migration
     */
    private function executeMigration($migration)
    {
        $file = $this->migrationsPath . '/' . $migration;
        
        if (!file_exists($file)) {
            throw new Exception("Migration file not found: {$migration}");
        }

        $sql = file_get_contents($file);
        
        if (empty($sql)) {
            throw new Exception("Migration file is empty: {$migration}");
        }

        // Begin transaction
        $this->db->beginTransaction();

        try {
            // Split SQL by semicolons; strip leading -- line comments so ALTER after a
            // comment block is not skipped (preg_match on whole chunk would drop it).
            $statements = array_filter(
                array_map([$this, 'stripSqlLineComments'], array_map('trim', explode(';', $sql))),
                static fn ($stmt) => $stmt !== ''
            );

            foreach ($statements as $statement) {
                $this->db->exec($statement);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw new Exception("Migration failed: {$e->getMessage()}");
        }
    }

    /**
     * Remove full-line SQL comments (-- ...) while keeping executable statements.
     */
    private function stripSqlLineComments(string $sql): string
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (trim($line) === '' || preg_match('/^\s*--/', $line)) {
                continue;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    /**
     * Record migration as executed
     */
    private function recordMigration($migration)
    {
        $stmt = $this->db->prepare("INSERT INTO migrations (migration_name, batch) VALUES (?, ?)");
        $stmt->execute([$migration, 1]);
    }

    /**
     * Get migration status
     */
    public function getStatus()
    {
        $allFiles = $this->getMigrationFiles();
        $executed = $this->getExecutedMigrations();

        $status = [];
        foreach ($allFiles as $file) {
            $migration = basename($file);
            $status[] = [
                'migration' => $migration,
                'executed' => in_array($migration, $executed),
                'executed_at' => in_array($migration, $executed) 
                    ? $this->getMigrationDate($migration) 
                    : null
            ];
        }

        return $status;
    }

    /**
     * Get execution date for a migration
     */
    private function getMigrationDate($migration)
    {
        $stmt = $this->db->prepare("SELECT executed_at FROM migrations WHERE migration_name = ?");
        $stmt->execute([$migration]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['executed_at'] : null;
    }
}
