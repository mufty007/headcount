<?php

namespace Headcount\Core;

use PDO;
use PDOException;

/**
 * Database Helper Class
 * Handles all database connections and queries
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
                "mysql:host=%s;dbname=%s;charset=%s",
                $config['host'],
                $config['name'],
                $config['charset']
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->connection = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $options
            );
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
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
            throw new \Exception("Query failed: " . $e->getMessage());
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
            throw new \Exception("Query failed: " . $e->getMessage());
        }
    }

    /**
     * Insert a record
     */
    public function insert($table, $data)
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($data);
            return $this->connection->lastInsertId();
        } catch (PDOException $e) {
            throw new \Exception("Insert failed: " . $e->getMessage());
        }
    }

    /**
     * Update a record
     */
    public function update($table, $id, $data, $idColumn = 'id')
    {
        $set = [];
        foreach (array_keys($data) as $key) {
            $set[] = "{$key} = :{$key}";
        }
        $setClause = implode(', ', $set);

        $sql = "UPDATE {$table} SET {$setClause} WHERE {$idColumn} = :id";
        $data['id'] = $id;

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($data);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new \Exception("Update failed: " . $e->getMessage());
        }
    }

    /**
     * Delete a record (soft delete by default)
     */
    public function delete($table, $id, $idColumn = 'id', $softDelete = true, $statusColumn = 'status')
    {
        if ($softDelete) {
            return $this->update($table, $id, [$statusColumn => 'deleted'], $idColumn);
        } else {
            $sql = "DELETE FROM {$table} WHERE {$idColumn} = :id";
            try {
                $stmt = $this->connection->prepare($sql);
                $stmt->execute(['id' => $id]);
                return $stmt->rowCount();
            } catch (PDOException $e) {
                throw new \Exception("Delete failed: " . $e->getMessage());
            }
        }
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
}
