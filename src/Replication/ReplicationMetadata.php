<?php

namespace NunezReplication\Replication;

use NunezReplication\Database\DatabaseManager;

/**
 * Manages replication metadata including timestamps and deletion tracking
 */
class ReplicationMetadata
{
    private $dbManager;
    private $metadataTableName = '_replication_metadata';

    public function __construct(DatabaseManager $dbManager)
    {
        $this->dbManager = $dbManager;
    }

    /**
     * Initialize metadata table in a database if it doesn't exist
     */
    public function initializeMetadataTable($dbName)
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->metadataTableName}` (
            id INT PRIMARY KEY AUTO_INCREMENT,
            table_name VARCHAR(255) NOT NULL,
            primary_key_value VARCHAR(255) NOT NULL,
            last_sync_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_deleted BOOLEAN DEFAULT FALSE,
            deleted_at TIMESTAMP NULL,
            UNIQUE KEY uk_table_pk (table_name, primary_key_value),
            INDEX idx_deleted (is_deleted, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->dbManager->execute($dbName, $sql);
    }

    /**
     * Record that a row was synced
     */
    public function recordSync($dbName, $tableName, $primaryKeyValue)
    {
        $sql = "INSERT INTO `{$this->metadataTableName}` 
                (table_name, primary_key_value, last_sync_timestamp, is_deleted)
                VALUES (?, ?, NOW(), FALSE)
                ON DUPLICATE KEY UPDATE 
                last_sync_timestamp = NOW(),
                is_deleted = FALSE,
                deleted_at = NULL";
        
        $this->dbManager->execute($dbName, $sql, [$tableName, (string)$primaryKeyValue]);
    }

    /**
     * Mark a row as deleted in metadata
     */
    public function markAsDeleted($dbName, $tableName, $primaryKeyValue)
    {
        $sql = "INSERT INTO `{$this->metadataTableName}` 
                (table_name, primary_key_value, is_deleted, deleted_at)
                VALUES (?, ?, TRUE, NOW())
                ON DUPLICATE KEY UPDATE 
                is_deleted = TRUE,
                deleted_at = NOW()";
        
        $this->dbManager->execute($dbName, $sql, [$tableName, (string)$primaryKeyValue]);
    }

    /**
     * Get all rows marked as deleted since a certain timestamp
     */
    public function getDeletedRows($dbName, $tableName, $sinceTimestamp = null)
    {
        if ($sinceTimestamp) {
            $sql = "SELECT primary_key_value, deleted_at 
                    FROM `{$this->metadataTableName}` 
                    WHERE table_name = ? 
                    AND is_deleted = TRUE 
                    AND deleted_at > ?
                    ORDER BY deleted_at ASC";
            return $this->dbManager->query($dbName, $sql, [$tableName, $sinceTimestamp]);
        } else {
            $sql = "SELECT primary_key_value, deleted_at 
                    FROM `{$this->metadataTableName}` 
                    WHERE table_name = ? 
                    AND is_deleted = TRUE
                    ORDER BY deleted_at ASC";
            return $this->dbManager->query($dbName, $sql, [$tableName]);
        }
    }

    /**
     * Get last sync timestamp for a table
     */
    public function getLastSyncTimestamp($dbName, $tableName)
    {
        $sql = "SELECT MAX(last_sync_timestamp) as last_sync 
                FROM `{$this->metadataTableName}` 
                WHERE table_name = ?";
        
        $result = $this->dbManager->query($dbName, $sql, [$tableName]);
        
        if (!empty($result) && !empty($result[0]['last_sync'])) {
            return $result[0]['last_sync'];
        }
        
        return null;
    }

    /**
     * Check if metadata table exists
     */
    public function metadataTableExists($dbName)
    {
        try {
            $sql = "SELECT 1 FROM `{$this->metadataTableName}` LIMIT 1";
            $this->dbManager->query($dbName, $sql);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clean up old deleted records metadata (older than specified days)
     */
    public function cleanupOldDeletedRecords($dbName, $daysToKeep = 30)
    {
        $sql = "DELETE FROM `{$this->metadataTableName}` 
                WHERE is_deleted = TRUE 
                AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        $this->dbManager->execute($dbName, $sql, [$daysToKeep]);
    }
}
