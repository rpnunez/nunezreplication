<?php

namespace NunezReplication\Replication;

use NunezReplication\Database\DatabaseManager;

class ReplicationEngine
{
    private $dbManager;
    private $config;
    private $stats;

    public function __construct(DatabaseManager $dbManager, $config)
    {
        $this->dbManager = $dbManager;
        $this->config = $config;
        $this->stats = [
            'lastSync' => null,
            'totalSyncs' => 0,
            'successfulSyncs' => 0,
            'failedSyncs' => 0,
            'lastError' => null,
            'tablesProcessed' => []
        ];
    }

    public function sync()
    {
        $startTime = microtime(true);
        $this->stats['lastSync'] = date('Y-m-d H:i:s');
        $this->stats['totalSyncs']++;

        try {
            if ($this->config['mode'] === 'master-slave') {
                $this->syncMasterToSlave();
            } elseif ($this->config['mode'] === 'master-master') {
                $this->syncMasterMaster();
            }

            $this->stats['successfulSyncs']++;
            $this->stats['lastError'] = null;
            
            $duration = round(microtime(true) - $startTime, 2);
            error_log("Replication sync completed successfully in {$duration}s");
            
            return [
                'success' => true,
                'duration' => $duration,
                'stats' => $this->stats
            ];
        } catch (\Exception $e) {
            $this->stats['failedSyncs']++;
            $this->stats['lastError'] = $e->getMessage();
            error_log("Replication sync failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $this->stats
            ];
        }
    }

    private function syncMasterToSlave()
    {
        foreach ($this->config['replication']['tables'] as $tableConfig) {
            $tableName = $tableConfig['name'];
            $ignoreColumns = $tableConfig['ignoreColumns'] ?? [];
            $primaryKey = $tableConfig['primaryKey'];

            // Validate identifiers
            $this->validateIdentifier($tableName);
            $this->validateIdentifier($primaryKey);

            error_log("Syncing table: $tableName");

            // Get all columns from master table
            $columns = $this->getTableColumns('master', $tableName);
            $columns = array_diff($columns, $ignoreColumns);
            
            // Validate column names
            foreach ($columns as $col) {
                $this->validateIdentifier($col);
            }

            // Get all rows from master
            $masterRows = $this->dbManager->query('master', "SELECT `" . implode('`, `', $columns) . "` FROM `$tableName`");

            $synced = 0;
            foreach ($masterRows as $row) {
                // Check if row exists in slave
                $exists = $this->dbManager->query(
                    'slave',
                    "SELECT `$primaryKey` FROM `$tableName` WHERE `$primaryKey` = ?",
                    [$row[$primaryKey]]
                );

                if (empty($exists)) {
                    // Insert new row
                    $this->insertRow('slave', $tableName, $row);
                } else {
                    // Update existing row
                    $this->updateRow('slave', $tableName, $row, $primaryKey);
                }
                $synced++;
            }

            $this->stats['tablesProcessed'][$tableName] = [
                'rows' => $synced,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            error_log("Synced $synced rows for table: $tableName");
        }
    }

    private function syncMasterMaster()
    {
        foreach ($this->config['replication']['tables'] as $tableConfig) {
            $tableName = $tableConfig['name'];
            $ignoreColumns = $tableConfig['ignoreColumns'] ?? [];
            $primaryKey = $tableConfig['primaryKey'];

            // Validate identifiers
            $this->validateIdentifier($tableName);
            $this->validateIdentifier($primaryKey);

            error_log("Bidirectional sync for table: $tableName");

            // Get all columns
            $columns = $this->getTableColumns('master', $tableName);
            $columns = array_diff($columns, $ignoreColumns);
            
            // Validate column names
            foreach ($columns as $col) {
                $this->validateIdentifier($col);
            }

            // Sync master -> slave
            $masterRows = $this->dbManager->query('master', "SELECT `" . implode('`, `', $columns) . "` FROM `$tableName`");
            $synced = 0;
            
            foreach ($masterRows as $row) {
                $exists = $this->dbManager->query(
                    'slave',
                    "SELECT `$primaryKey` FROM `$tableName` WHERE `$primaryKey` = ?",
                    [$row[$primaryKey]]
                );

                if (empty($exists)) {
                    $this->insertRow('slave', $tableName, $row);
                } else {
                    $this->updateRow('slave', $tableName, $row, $primaryKey);
                }
                $synced++;
            }

            // Sync slave -> master
            $slaveRows = $this->dbManager->query('slave', "SELECT `" . implode('`, `', $columns) . "` FROM `$tableName`");
            
            foreach ($slaveRows as $row) {
                $exists = $this->dbManager->query(
                    'master',
                    "SELECT `$primaryKey` FROM `$tableName` WHERE `$primaryKey` = ?",
                    [$row[$primaryKey]]
                );

                if (empty($exists)) {
                    $this->insertRow('master', $tableName, $row);
                    $synced++;
                }
                // Note: In master-master mode, master data takes precedence to avoid conflicts.
                // Only new records from slave are synced to master. Existing master records
                // are not overwritten by slave data. This prevents bi-directional conflicts
                // where simultaneous updates on both sides could cause inconsistency.
            }

            $this->stats['tablesProcessed'][$tableName] = [
                'rows' => $synced,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            error_log("Bidirectional sync completed: $synced operations for table: $tableName");
        }
    }

    private function getTableColumns($dbName, $tableName)
    {
        // Validate table name to prevent SQL injection
        $this->validateIdentifier($tableName);
        $columns = $this->dbManager->query($dbName, "SHOW COLUMNS FROM `$tableName`");
        return array_map(function($col) {
            return $col['Field'];
        }, $columns);
    }

    private function validateIdentifier($identifier)
    {
        // Validate that identifier is safe (alphanumeric and underscores only)
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new \Exception("Invalid identifier: $identifier. Only alphanumeric characters and underscores are allowed.");
        }
    }

    private function insertRow($dbName, $tableName, $row)
    {
        // Validate identifiers
        $this->validateIdentifier($tableName);
        
        $columns = array_keys($row);
        foreach ($columns as $col) {
            $this->validateIdentifier($col);
        }
        
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = sprintf(
            "INSERT INTO `%s` (`%s`) VALUES (%s)",
            $tableName,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );
        
        $this->dbManager->execute($dbName, $sql, array_values($row));
    }

    private function updateRow($dbName, $tableName, $row, $primaryKey)
    {
        // Validate identifiers
        $this->validateIdentifier($tableName);
        $this->validateIdentifier($primaryKey);
        
        $columns = array_keys($row);
        foreach ($columns as $col) {
            $this->validateIdentifier($col);
        }
        
        $setClauses = array_map(function($col) {
            return "`$col` = ?";
        }, $columns);
        
        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE `%s` = ?",
            $tableName,
            implode(', ', $setClauses),
            $primaryKey
        );
        
        $params = array_values($row);
        $params[] = $row[$primaryKey];
        
        $this->dbManager->execute($dbName, $sql, $params);
    }

    public function getStats()
    {
        return $this->stats;
    }
}
