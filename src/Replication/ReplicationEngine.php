<?php

namespace NunezReplication\Replication;

use NunezReplication\Database\DatabaseManager;

class ReplicationEngine
{
    private $dbManager;
    private $config;
    private $stats;
    private $metadata;

    public function __construct(DatabaseManager $dbManager, $config)
    {
        $this->dbManager = $dbManager;
        $this->config = $config;
        $this->metadata = new ReplicationMetadata($dbManager);
        $this->stats = [
            'lastSync' => null,
            'totalSyncs' => 0,
            'successfulSyncs' => 0,
            'failedSyncs' => 0,
            'lastError' => null,
            'tablesProcessed' => [],
            'updates' => 0,
            'inserts' => 0,
            'deletes' => 0
        ];
    }

    public function sync()
    {
        $startTime = microtime(true);
        $this->stats['lastSync'] = date('Y-m-d H:i:s');
        $this->stats['totalSyncs']++;
        $this->stats['updates'] = 0;
        $this->stats['inserts'] = 0;
        $this->stats['deletes'] = 0;

        try {
            // Initialize metadata tables for tracking
            $this->metadata->initializeMetadataTable('master');
            $this->metadata->initializeMetadataTable('slave');

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
            $timestampColumn = $tableConfig['timestampColumn'] ?? 'updated_at';

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

            // Check if timestamp column exists
            $hasTimestamp = in_array($timestampColumn, $columns);

            // Get all rows from master
            $masterRows = $this->dbManager->query('master', "SELECT `" . implode('`, `', $columns) . "` FROM `$tableName`");

            // Track primary keys we've seen in master (for deletion detection)
            $masterPrimaryKeys = [];
            
            // Track per-table stats
            $tableInserts = 0;
            $tableUpdates = 0;
            $tableDeletes = 0;
            
            $synced = 0;
            foreach ($masterRows as $row) {
                $masterPrimaryKeys[] = $row[$primaryKey];
                
                // Check if row exists in slave
                $exists = $this->dbManager->query(
                    'slave',
                    "SELECT `$primaryKey`" . ($hasTimestamp ? ", `$timestampColumn`" : "") . " FROM `$tableName` WHERE `$primaryKey` = ?",
                    [$row[$primaryKey]]
                );

                if (empty($exists)) {
                    // Insert new row
                    $this->insertRow('slave', $tableName, $row);
                    $this->stats['inserts']++;
                    $tableInserts++;
                } else {
                    // Check if update is needed based on timestamp
                    $shouldUpdate = true;
                    if ($hasTimestamp) {
                        $slaveTimestamp = $exists[0][$timestampColumn] ?? null;
                        $masterTimestamp = $row[$timestampColumn] ?? null;
                        
                        // Only update if master is newer
                        if ($slaveTimestamp && $masterTimestamp && $masterTimestamp <= $slaveTimestamp) {
                            $shouldUpdate = false;
                        }
                    }
                    
                    if ($shouldUpdate) {
                        $this->updateRow('slave', $tableName, $row, $primaryKey);
                        $this->stats['updates']++;
                        $tableUpdates++;
                    }
                }
                
                // Record sync in metadata
                $this->metadata->recordSync('slave', $tableName, $row[$primaryKey]);
                
                $synced++;
            }

            // Handle deletions: find rows in slave that don't exist in master
            // Always check for deletions, even if master is empty (to clear slave)
            $deletedBefore = $this->stats['deletes'];
            $this->handleDeletions('slave', $tableName, $primaryKey, $masterPrimaryKeys);
            $tableDeletes = $this->stats['deletes'] - $deletedBefore;

            $this->stats['tablesProcessed'][$tableName] = [
                'rows' => $synced,
                'inserts' => $tableInserts,
                'updates' => $tableUpdates,
                'deletes' => $tableDeletes,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            error_log("Synced $synced rows for table: $tableName (Table stats - Inserts: $tableInserts, Updates: $tableUpdates, Deletes: $tableDeletes)");
        }
    }

    private function syncMasterMaster()
    {
        foreach ($this->config['replication']['tables'] as $tableConfig) {
            $tableName = $tableConfig['name'];
            $ignoreColumns = $tableConfig['ignoreColumns'] ?? [];
            $primaryKey = $tableConfig['primaryKey'];
            $timestampColumn = $tableConfig['timestampColumn'] ?? 'updated_at';

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

            // Check if timestamp column exists
            $hasTimestamp = in_array($timestampColumn, $columns);

            // Track per-table stats
            $tableInserts = 0;
            $tableUpdates = 0;
            $tableDeletes = 0;

            // Sync master -> slave
            $masterRows = $this->dbManager->query('master', "SELECT `" . implode('`, `', $columns) . "` FROM `$tableName`");
            $synced = 0;
            $masterPrimaryKeys = [];
            
            foreach ($masterRows as $row) {
                $masterPrimaryKeys[] = $row[$primaryKey];
                
                $exists = $this->dbManager->query(
                    'slave',
                    "SELECT `$primaryKey`" . ($hasTimestamp ? ", `$timestampColumn`" : "") . " FROM `$tableName` WHERE `$primaryKey` = ?",
                    [$row[$primaryKey]]
                );

                if (empty($exists)) {
                    $this->insertRow('slave', $tableName, $row);
                    $this->stats['inserts']++;
                    $tableInserts++;
                } else {
                    // Check if update is needed based on timestamp
                    $shouldUpdate = true;
                    if ($hasTimestamp) {
                        $slaveTimestamp = $exists[0][$timestampColumn] ?? null;
                        $masterTimestamp = $row[$timestampColumn] ?? null;
                        
                        // Only update if master is newer
                        if ($slaveTimestamp && $masterTimestamp && $masterTimestamp <= $slaveTimestamp) {
                            $shouldUpdate = false;
                        }
                    }
                    
                    if ($shouldUpdate) {
                        $this->updateRow('slave', $tableName, $row, $primaryKey);
                        $this->stats['updates']++;
                        $tableUpdates++;
                    }
                }
                
                $this->metadata->recordSync('slave', $tableName, $row[$primaryKey]);
                
                $synced++;
            }

            // Sync slave -> master
            $slaveRows = $this->dbManager->query('slave', "SELECT `" . implode('`, `', $columns) . "` FROM `$tableName`");
            $slavePrimaryKeys = [];
            
            foreach ($slaveRows as $row) {
                $slavePrimaryKeys[] = $row[$primaryKey];
                
                $exists = $this->dbManager->query(
                    'master',
                    "SELECT `$primaryKey`" . ($hasTimestamp ? ", `$timestampColumn`" : "") . " FROM `$tableName` WHERE `$primaryKey` = ?",
                    [$row[$primaryKey]]
                );

                if (empty($exists)) {
                    $this->insertRow('master', $tableName, $row);
                    $this->stats['inserts']++;
                    $tableInserts++;
                    $synced++;
                    
                    // Add to masterPrimaryKeys since we just inserted it
                    $masterPrimaryKeys[] = $row[$primaryKey];
                    
                    $this->metadata->recordSync('master', $tableName, $row[$primaryKey]);
                } else {
                    // In master-master mode with timestamp tracking, use last-write-wins
                    if ($hasTimestamp) {
                        $masterTimestamp = $exists[0][$timestampColumn] ?? null;
                        $slaveTimestamp = $row[$timestampColumn] ?? null;
                        
                        // Update master if slave is newer OR if master has no timestamp but slave does
                        if ($slaveTimestamp && (!$masterTimestamp || $slaveTimestamp > $masterTimestamp)) {
                            $this->updateRow('master', $tableName, $row, $primaryKey);
                            $this->stats['updates']++;
                            $tableUpdates++;
                            $this->metadata->recordSync('master', $tableName, $row[$primaryKey]);
                        }
                    }
                    // Note: Without timestamp columns, master data takes precedence to avoid conflicts.
                    // Only new records from slave are synced to master. Existing master records
                    // are not overwritten by slave data. This prevents bi-directional conflicts
                    // where simultaneous updates on both sides could cause inconsistency.
                }
            }

            // Handle deletions for bidirectional sync
            // Always check for deletions, even if source is empty (to clear target)
            $deletedBefore = $this->stats['deletes'];
            
            // Sync deletions from master to slave
            $this->handleDeletions('slave', $tableName, $primaryKey, $masterPrimaryKeys);
            
            // Sync deletions from slave to master
            $this->handleDeletions('master', $tableName, $primaryKey, $slavePrimaryKeys);
            
            $tableDeletes = $this->stats['deletes'] - $deletedBefore;

            $this->stats['tablesProcessed'][$tableName] = [
                'rows' => $synced,
                'inserts' => $tableInserts,
                'updates' => $tableUpdates,
                'deletes' => $tableDeletes,
                'timestamp' => date('Y-m-d H:i:s')
            ];

            error_log("Bidirectional sync completed: $synced operations for table: $tableName (Table stats - Inserts: $tableInserts, Updates: $tableUpdates, Deletes: $tableDeletes)");
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

    /**
     * Handle deletion of rows that exist in target but not in source
     */
    private function handleDeletions($targetDbName, $tableName, $primaryKey, $sourcePrimaryKeys)
    {
        $this->validateIdentifier($tableName);
        $this->validateIdentifier($primaryKey);
        
        // Convert to associative array for O(1) lookups
        $sourcePkMap = array_flip($sourcePrimaryKeys);
        
        // Get all primary keys from target
        $targetRows = $this->dbManager->query($targetDbName, "SELECT `$primaryKey` FROM `$tableName`");
        
        foreach ($targetRows as $targetRow) {
            $targetPk = $targetRow[$primaryKey];
            
            // If this row doesn't exist in source, it was deleted
            if (!isset($sourcePkMap[$targetPk])) {
                // Delete from target
                $sql = "DELETE FROM `$tableName` WHERE `$primaryKey` = ?";
                $this->dbManager->execute($targetDbName, $sql, [$targetPk]);
                
                // Mark as deleted in metadata
                $this->metadata->markAsDeleted($targetDbName, $tableName, $targetPk);
                
                $this->stats['deletes']++;
                error_log("Deleted row with $primaryKey=$targetPk from $tableName in $targetDbName");
            }
        }
    }

    public function getStats()
    {
        return $this->stats;
    }
}
