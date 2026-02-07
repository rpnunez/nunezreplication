<?php

namespace NunezReplication\Data;

use NunezReplication\Database\DatabaseManager;
use NunezReplication\Data\DataGenerator;

class DataManagementService
{
    private $dbManager;
    private $config;
    
    public function __construct(DatabaseManager $dbManager, $config)
    {
        $this->dbManager = $dbManager;
        $this->config = $config;
    }
    
    /**
     * Get list of available databases from config
     */
    public function getAvailableDatabases()
    {
        $databases = [];
        
        if (isset($this->config['databases'])) {
            foreach ($this->config['databases'] as $dbName => $dbConfig) {
                // Skip stats database
                if ($dbName === 'stats') {
                    continue;
                }
                
                $databases[] = [
                    'name' => $dbName,
                    'database' => $dbConfig['database'] ?? $dbName,
                    'host' => $dbConfig['host'] ?? 'localhost'
                ];
            }
        }
        
        return $databases;
    }
    
    /**
     * Get tables for a specific database
     */
    public function getTablesForDatabase($dbName)
    {
        try {
            $pdo = $this->dbManager->getConnection($dbName);
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            // Filter out replication metadata tables
            $tables = array_filter($tables, function($table) {
                return strpos($table, '_replication_metadata') === false;
            });
            
            return array_values($tables);
        } catch (\Exception $e) {
            throw new \Exception("Failed to get tables for database $dbName: " . $e->getMessage());
        }
    }
    
    /**
     * Get foreign key relationships for tables
     */
    private function getForeignKeyRelationships($pdo, $tables)
    {
        $relationships = [];
        
        foreach ($tables as $table) {
            $stmt = $pdo->query("
                SELECT 
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME
                FROM 
                    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE 
                    TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = '$table'
                    AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            $fks = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            if (!empty($fks)) {
                $relationships[$table] = [];
                foreach ($fks as $fk) {
                    $relationships[$table][] = [
                        'column' => $fk['COLUMN_NAME'],
                        'referenced_table' => $fk['REFERENCED_TABLE_NAME'],
                        'referenced_column' => $fk['REFERENCED_COLUMN_NAME']
                    ];
                }
            }
        }
        
        return $relationships;
    }
    
    /**
     * Determine insertion order based on foreign keys
     */
    private function getInsertionOrder($tables, $relationships)
    {
        $ordered = [];
        $remaining = $tables;
        $maxIterations = count($tables) * 2;
        $iteration = 0;
        
        while (!empty($remaining) && $iteration < $maxIterations) {
            $iteration++;
            
            foreach ($remaining as $key => $table) {
                // Check if all dependencies are already in ordered list
                $canInsert = true;
                
                if (isset($relationships[$table])) {
                    foreach ($relationships[$table] as $fk) {
                        $refTable = $fk['referenced_table'];
                        if (!in_array($refTable, $ordered) && in_array($refTable, $tables)) {
                            $canInsert = false;
                            break;
                        }
                    }
                }
                
                if ($canInsert) {
                    $ordered[] = $table;
                    unset($remaining[$key]);
                }
            }
            
            $remaining = array_values($remaining);
        }
        
        // Add any remaining tables (in case of circular dependencies)
        foreach ($remaining as $table) {
            $ordered[] = $table;
        }
        
        return $ordered;
    }
    
    /**
     * Generate data for a database
     */
    public function generateData($dbName, $tableRowCounts)
    {
        try {
            $pdo = $this->dbManager->getConnection($dbName);
            $generator = new DataGenerator($pdo);
            
            // Get all tables
            $allTables = $this->getTablesForDatabase($dbName);
            
            // Get only tables we need to generate data for
            $tables = array_keys($tableRowCounts);
            $tables = array_intersect($tables, $allTables);
            
            if (empty($tables)) {
                return [
                    'success' => false,
                    'error' => 'No valid tables specified'
                ];
            }
            
            // Get foreign key relationships
            $relationships = $this->getForeignKeyRelationships($pdo, $tables);
            
            // Determine insertion order
            $orderedTables = $this->getInsertionOrder($tables, $relationships);
            
            // Start transaction
            $pdo->beginTransaction();
            
            $results = [];
            $foreignKeyData = [];
            
            try {
                foreach ($orderedTables as $table) {
                    $rowCount = $tableRowCounts[$table] ?? 0;
                    
                    if ($rowCount <= 0) {
                        continue;
                    }
                    
                    // Prepare foreign key data for this table
                    $fkData = [];
                    if (isset($relationships[$table])) {
                        foreach ($relationships[$table] as $fk) {
                            $refTable = $fk['referenced_table'];
                            $fkColumn = $fk['column'];
                            
                            if (isset($foreignKeyData[$refTable])) {
                                $fkData[$fkColumn] = $foreignKeyData[$refTable];
                            }
                        }
                    }
                    
                    // Generate data
                    $insertedIds = $generator->generateDataForTable($table, $rowCount, $fkData);
                    
                    // Store IDs for foreign key references
                    $foreignKeyData[$table] = $insertedIds;
                    
                    $results[$table] = [
                        'inserted' => count($insertedIds),
                        'ids' => $insertedIds
                    ];
                }
                
                $pdo->commit();
                
                return [
                    'success' => true,
                    'database' => $dbName,
                    'results' => $results
                ];
                
            } catch (\Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Introduce updates to existing data
     */
    public function introduceUpdates($dbName, $tableUpdateCounts)
    {
        try {
            $pdo = $this->dbManager->getConnection($dbName);
            $generator = new DataGenerator($pdo);
            
            // Get all tables
            $allTables = $this->getTablesForDatabase($dbName);
            
            // Get only tables we need to update
            $tables = array_keys($tableUpdateCounts);
            $tables = array_intersect($tables, $allTables);
            
            if (empty($tables)) {
                return [
                    'success' => false,
                    'error' => 'No valid tables specified'
                ];
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            $results = [];
            
            try {
                foreach ($tables as $table) {
                    $updateCount = $tableUpdateCounts[$table] ?? 0;
                    
                    if ($updateCount <= 0) {
                        continue;
                    }
                    
                    $result = $generator->updateDataInTable($table, $updateCount);
                    $results[$table] = $result;
                }
                
                $pdo->commit();
                
                return [
                    'success' => true,
                    'database' => $dbName,
                    'results' => $results
                ];
                
            } catch (\Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
