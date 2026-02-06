<?php

namespace NunezReplication\Database;

use PDO;
use PDOException;

class DatabaseManager
{
    private $connections = [];

    public function connect($name, $config)
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['database']
            );
            
            $pdo = new PDO(
                $dsn,
                $config['user'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            
            $this->connections[$name] = $pdo;
            error_log("Connected to $name database: {$config['host']}:{$config['port']}/{$config['database']}");
            
            return $pdo;
        } catch (PDOException $e) {
            error_log("Error connecting to $name database: " . $e->getMessage());
            throw $e;
        }
    }

    public function getConnection($name)
    {
        if (!isset($this->connections[$name])) {
            throw new \Exception("No connection found for $name");
        }
        return $this->connections[$name];
    }

    public function query($name, $sql, $params = [])
    {
        $connection = $this->getConnection($name);
        
        try {
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Query error on $name: " . $e->getMessage());
            throw $e;
        }
    }

    public function execute($name, $sql, $params = [])
    {
        $connection = $this->getConnection($name);
        
        try {
            $stmt = $connection->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Execute error on $name: " . $e->getMessage());
            throw $e;
        }
    }

    public function closeAll()
    {
        foreach (array_keys($this->connections) as $name) {
            $this->connections[$name] = null;
            error_log("Closed $name database connection");
        }
        $this->connections = [];
    }
}
