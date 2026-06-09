<?php

namespace Headcount\Helpers;

use PDO;
use PDOException;

/**
 * Database Helper Class
 * Provides PDO-based database abstraction layer
 */
class Database
{
    private $connection;
    private static $instance = null;

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct($config)
    {
        try {
            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=utf8mb4",
                $config['host'],
                $config['name'] ?? $config['database'] ?? ''
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // true: required for SQL that reuses named placeholders (e.g. :today twice)
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_TIMEOUT => 5,
            ];

            $this->connection = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $options
            );
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new \Exception("Database connection failed. Please check your configuration.", 0, $e);
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance($config = null)
    {
        if (self::$instance === null && $config !== null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Get PDO connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * SHOW/DESCRIBE/EXPLAIN cannot use native prepared statements on MariaDB.
     */
    private function shouldUseDirectQuery(string $sql, array $params): bool
    {
        if ($params !== []) {
            return false;
        }

        return (bool) preg_match('/^\s*(SHOW|DESCRIBE|DESC|EXPLAIN)\s/i', $sql);
    }

    /**
     * Normalise bound parameters for the given SQL.
     *
     * - `?` placeholders: PDO requires a zero-indexed list.
     * - `:named` placeholders: drop any params the SQL does not reference. Under
     *   emulated prepares, passing an extra named param throws HY093
     *   ("Invalid parameter number"), so callers that reuse one shared param array
     *   across queries that each use only a subset would otherwise fail silently.
     */
    private function normalizeParams(array $params, string $sql): array
    {
        if ($params === []) {
            return $params;
        }

        if (str_contains($sql, '?')) {
            return array_is_list($params) ? $params : array_values($params);
        }

        // Named-placeholder query: keep only params actually referenced in the SQL.
        // The leading char after ':' must be a letter/underscore, so time literals
        // like '00:00:00' are not mistaken for placeholders.
        if (preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $m)) {
            $used = array_flip($m[1]);
            $filtered = [];
            foreach ($params as $k => $v) {
                $key = ltrim((string) $k, ':');
                if (isset($used[$key])) {
                    $filtered[$key] = $v;
                }
            }
            return $filtered;
        }

        return $params;
    }

    /**
     * Execute a query and return results
     */
    public function query($sql, $params = [])
    {
        $params = $this->normalizeParams($params, $sql);

        try {
            if ($this->shouldUseDirectQuery($sql, $params)) {
                $stmt = $this->connection->query($sql);

                return $stmt ? $stmt->fetchAll() : [];
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $errorMsg = "Query failed: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params);
            error_log($errorMsg);
            throw new \Exception("Database query failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a query and return single row
     */
    public function queryOne($sql, $params = [])
    {
        $params = $this->normalizeParams($params, $sql);

        try {
            if ($this->shouldUseDirectQuery($sql, $params)) {
                $stmt = $this->connection->query($sql);

                return $stmt ? $stmt->fetch() : false;
            }

            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetch();
        } catch (PDOException $e) {
            $errorMsg = "Query failed: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params);
            error_log($errorMsg);
            throw new \Exception("Database query failed: " . $e->getMessage());
        }
    }

    /**
     * Insert a record
     */
    public function insert($table, $data)
    {
        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($data);
            return $this->connection->lastInsertId();
        } catch (\PDOException $e) {
            $errorMsg = "Insert failed: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($data);
            error_log($errorMsg);
            // Preserve the PDOException so error codes and constraint names can be checked
            throw $e;
        }
    }

    /**
     * Insert a row; if a unique constraint would be violated, skip silently (MySQL INSERT IGNORE).
     *
     * @return bool True if a new row was inserted, false if ignored as duplicate
     */
    public function insertIgnore($table, array $data): bool
    {
        $columns = '`' . implode('`, `', array_keys($data)) . '`';
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT IGNORE INTO `{$table}` ({$columns}) VALUES ({$placeholders})";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($data);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            $errorMsg = "Insert ignore failed: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($data);
            error_log($errorMsg);
            throw $e;
        }
    }

    /**
     * Update a record
     */
    public function update($table, $id, $data, $idColumn = 'id')
    {
        $setClause = [];
        foreach (array_keys($data) as $key) {
            $setClause[] = "`{$key}` = :{$key}";
        }
        $setClause = implode(', ', $setClause);

        $sql = "UPDATE `{$table}` SET {$setClause} WHERE `{$idColumn}` = :id";
        $data['id'] = $id;

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($data);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Update failed: " . $e->getMessage() . " | SQL: " . $sql);
            throw new \Exception("Failed to update record.");
        }
    }

    /**
     * Delete a record (soft delete by default)
     */
    public function delete($table, $id, $idColumn = 'id', $softDelete = true)
    {
        if ($softDelete && $this->hasColumn($table, 'status')) {
            return $this->update($table, $id, ['status' => 'deleted'], $idColumn);
        }

        $sql = "DELETE FROM `{$table}` WHERE `{$idColumn}` = :id";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute(['id' => $id]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Delete failed: " . $e->getMessage() . " | SQL: " . $sql);
            throw new \Exception("Failed to delete record.");
        }
    }

    /**
     * Whether $name is a safe unquoted SQL identifier (table/column).
     */
    private function isValidSqlIdentifier($name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', (string) $name);
    }

    /**
     * Check if a table exists in the current database.
     * Uses SHOW TABLES (no prepared placeholders) for MariaDB compatibility.
     */
    public function tableExists($table): bool
    {
        static $cache = [];

        if (!$this->isValidSqlIdentifier($table)) {
            return false;
        }

        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $sql = 'SHOW TABLES LIKE ' . $this->connection->quote((string) $table);
            $stmt = $this->connection->query($sql);
            $cache[$table] = $stmt !== false && $stmt->fetch() !== false;
        } catch (\Throwable $e) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }

    /**
     * Check if table has a column.
     * Uses SHOW COLUMNS (no prepared placeholders) — MariaDB rejects LIKE ? in SHOW statements.
     */
    public function hasColumn($table, $column)
    {
        static $cache = [];

        if (!$this->isValidSqlIdentifier($table) || !$this->isValidSqlIdentifier($column)) {
            return false;
        }

        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $quotedTable = '`' . str_replace('`', '``', (string) $table) . '`';
            $sql = "SHOW COLUMNS FROM {$quotedTable} LIKE " . $this->connection->quote((string) $column);
            $stmt = $this->connection->query($sql);
            $cache[$key] = $stmt !== false && $stmt->fetch() !== false;
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }

    /**
     * Begin transaction
     */
    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit()
    {
        return $this->connection->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback()
    {
        return $this->connection->rollBack();
    }

    /**
     * Execute raw SQL (use with caution)
     */
    public function execute($sql, $params = [])
    {
        try {
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Execute failed: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . print_r($params, true));
            throw new \Exception("Database execution failed: " . $e->getMessage());
        }
    }
}
