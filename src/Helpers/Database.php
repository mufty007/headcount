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
     * Execute a query and return results
     */
    public function query($sql, $params = [])
    {
        try {
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
        try {
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
     * Check if table has a column
     */
    public function hasColumn($table, $column)
    {
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE :column";
        $result = $this->query($sql, ['column' => $column]);
        return !empty($result);
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
