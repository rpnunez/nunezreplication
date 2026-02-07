<?php

namespace NunezReplication\Replication;

use PDO;
use PDOException;

/**
 * Manages a dedicated replication statistics database
 * This database stores all replication metadata, statistics, successes, and failures
 * separate from the application databases
 */
class ReplicationStatsDB
{
    private $connection;
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
        $this->connect();
        $this->initializeSchema();
    }

    private function connect()
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                $this->config['host'],
                $this->config['port']
            );
            
            $pdo = new PDO(
                $dsn,
                $this->config['user'],
                $this->config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            
            // Create database if it doesn't exist
            $dbName = $this->config['database'] ?? 'replication_stats';
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbName`");
            
            $this->connection = $pdo;
            error_log("Connected to replication stats database: {$this->config['host']}:{$this->config['port']}/$dbName");
        } catch (PDOException $e) {
            error_log("Error connecting to replication stats database: " . $e->getMessage());
            throw $e;
        }
    }

    private function initializeSchema()
    {
        // Sync history table - records each sync operation
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS sync_history (
                id INT PRIMARY KEY AUTO_INCREMENT,
                sync_started_at TIMESTAMP NOT NULL,
                sync_completed_at TIMESTAMP NULL,
                duration_seconds DECIMAL(10, 2) NULL,
                status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
                mode VARCHAR(50) NOT NULL,
                error_message TEXT NULL,
                total_inserts INT DEFAULT 0,
                total_updates INT DEFAULT 0,
                total_deletes INT DEFAULT 0,
                tables_processed INT DEFAULT 0,
                INDEX idx_status (status),
                INDEX idx_started (sync_started_at),
                INDEX idx_mode (mode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Per-table statistics for each sync
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS table_sync_stats (
                id INT PRIMARY KEY AUTO_INCREMENT,
                sync_id INT NOT NULL,
                table_name VARCHAR(255) NOT NULL,
                rows_processed INT DEFAULT 0,
                inserts INT DEFAULT 0,
                updates INT DEFAULT 0,
                deletes INT DEFAULT 0,
                sync_timestamp TIMESTAMP NOT NULL,
                FOREIGN KEY (sync_id) REFERENCES sync_history(id) ON DELETE CASCADE,
                INDEX idx_sync_id (sync_id),
                INDEX idx_table_name (table_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Replication metadata - replaces _replication_metadata in app DBs
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS replication_metadata (
                id INT PRIMARY KEY AUTO_INCREMENT,
                environment VARCHAR(50) NOT NULL,
                table_name VARCHAR(255) NOT NULL,
                primary_key_value VARCHAR(255) NOT NULL,
                last_sync_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                is_deleted BOOLEAN DEFAULT FALSE,
                deleted_at TIMESTAMP NULL,
                UNIQUE KEY uk_env_table_pk (environment, table_name, primary_key_value),
                INDEX idx_deleted (is_deleted, deleted_at),
                INDEX idx_env_table (environment, table_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Detailed operation log for troubleshooting
        $this->connection->exec("
            CREATE TABLE IF NOT EXISTS operation_log (
                id INT PRIMARY KEY AUTO_INCREMENT,
                sync_id INT NULL,
                log_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                level ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'info',
                message TEXT NOT NULL,
                context JSON NULL,
                FOREIGN KEY (sync_id) REFERENCES sync_history(id) ON DELETE CASCADE,
                INDEX idx_sync_id (sync_id),
                INDEX idx_timestamp (log_timestamp),
                INDEX idx_level (level)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        error_log("Replication stats database schema initialized");
    }

    /**
     * Start a new sync operation and return its ID
     */
    public function startSync($mode)
    {
        $stmt = $this->connection->prepare("
            INSERT INTO sync_history (sync_started_at, mode, status)
            VALUES (NOW(), ?, 'running')
        ");
        $stmt->execute([$mode]);
        return $this->connection->lastInsertId();
    }

    /**
     * Mark a sync as completed successfully
     */
    public function completeSyncSuccess($syncId, $stats)
    {
        $duration = isset($stats['duration']) ? $stats['duration'] : null;
        $stmt = $this->connection->prepare("
            UPDATE sync_history 
            SET sync_completed_at = NOW(),
                duration_seconds = ?,
                status = 'success',
                total_inserts = ?,
                total_updates = ?,
                total_deletes = ?,
                tables_processed = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $duration,
            $stats['inserts'] ?? 0,
            $stats['updates'] ?? 0,
            $stats['deletes'] ?? 0,
            count($stats['tablesProcessed'] ?? []),
            $syncId
        ]);
    }

    /**
     * Mark a sync as failed
     */
    public function completeSyncFailure($syncId, $errorMessage, $stats = [])
    {
        $stmt = $this->connection->prepare("
            UPDATE sync_history 
            SET sync_completed_at = NOW(),
                duration_seconds = ?,
                status = 'failed',
                error_message = ?,
                total_inserts = ?,
                total_updates = ?,
                total_deletes = ?,
                tables_processed = ?
            WHERE id = ?
        ");
        
        $duration = isset($stats['duration']) ? $stats['duration'] : null;
        $stmt->execute([
            $duration,
            $errorMessage,
            $stats['inserts'] ?? 0,
            $stats['updates'] ?? 0,
            $stats['deletes'] ?? 0,
            count($stats['tablesProcessed'] ?? []),
            $syncId
        ]);
    }

    /**
     * Record per-table statistics for a sync
     */
    public function recordTableStats($syncId, $tableName, $stats)
    {
        $stmt = $this->connection->prepare("
            INSERT INTO table_sync_stats 
            (sync_id, table_name, rows_processed, inserts, updates, deletes, sync_timestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $syncId,
            $tableName,
            $stats['rows'] ?? 0,
            $stats['inserts'] ?? 0,
            $stats['updates'] ?? 0,
            $stats['deletes'] ?? 0,
            $stats['timestamp'] ?? date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Log an operation message
     */
    public function logOperation($syncId, $level, $message, $context = null)
    {
        $stmt = $this->connection->prepare("
            INSERT INTO operation_log (sync_id, level, message, context)
            VALUES (?, ?, ?, ?)
        ");
        $contextJson = $context ? json_encode($context) : null;
        $stmt->execute([$syncId, $level, $message, $contextJson]);
    }

    /**
     * Get overall statistics
     */
    public function getOverallStats()
    {
        $stmt = $this->connection->query("
            SELECT 
                COUNT(*) as total_syncs,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_syncs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_syncs,
                MAX(sync_started_at) as last_sync,
                SUM(total_inserts) as total_inserts,
                SUM(total_updates) as total_updates,
                SUM(total_deletes) as total_deletes,
                AVG(duration_seconds) as avg_duration
            FROM sync_history
        ");
        return $stmt->fetch();
    }

    /**
     * Get recent sync history
     */
    public function getRecentSyncs($limit = 10)
    {
        $stmt = $this->connection->prepare("
            SELECT 
                id,
                sync_started_at,
                sync_completed_at,
                duration_seconds,
                status,
                mode,
                error_message,
                total_inserts,
                total_updates,
                total_deletes,
                tables_processed
            FROM sync_history
            ORDER BY sync_started_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get statistics for a specific table
     */
    public function getTableStats($tableName, $limit = 10)
    {
        $stmt = $this->connection->prepare("
            SELECT 
                tss.*,
                sh.sync_started_at,
                sh.status
            FROM table_sync_stats tss
            JOIN sync_history sh ON tss.sync_id = sh.id
            WHERE tss.table_name = ?
            ORDER BY tss.sync_timestamp DESC
            LIMIT ?
        ");
        $stmt->execute([$tableName, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get recent error logs
     */
    public function getRecentErrors($limit = 20)
    {
        $stmt = $this->connection->prepare("
            SELECT 
                ol.*,
                sh.sync_started_at,
                sh.mode
            FROM operation_log ol
            LEFT JOIN sync_history sh ON ol.sync_id = sh.id
            WHERE ol.level = 'error'
            ORDER BY ol.log_timestamp DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Record metadata for a synced row
     */
    public function recordMetadata($environment, $tableName, $primaryKeyValue)
    {
        $stmt = $this->connection->prepare("
            INSERT INTO replication_metadata 
            (environment, table_name, primary_key_value, last_sync_timestamp, is_deleted)
            VALUES (?, ?, ?, NOW(), FALSE)
            ON DUPLICATE KEY UPDATE 
            last_sync_timestamp = NOW(),
            is_deleted = FALSE,
            deleted_at = NULL
        ");
        $stmt->execute([$environment, $tableName, (string)$primaryKeyValue]);
    }

    /**
     * Mark a row as deleted in metadata
     */
    public function markAsDeleted($environment, $tableName, $primaryKeyValue)
    {
        $stmt = $this->connection->prepare("
            INSERT INTO replication_metadata 
            (environment, table_name, primary_key_value, is_deleted, deleted_at)
            VALUES (?, ?, ?, TRUE, NOW())
            ON DUPLICATE KEY UPDATE 
            is_deleted = TRUE,
            deleted_at = NOW()
        ");
        $stmt->execute([$environment, $tableName, (string)$primaryKeyValue]);
    }

    /**
     * Get last sync timestamp for a table in an environment
     */
    public function getLastSyncTimestamp($environment, $tableName)
    {
        $stmt = $this->connection->prepare("
            SELECT MAX(last_sync_timestamp) as last_sync 
            FROM replication_metadata 
            WHERE environment = ? AND table_name = ?
        ");
        $stmt->execute([$environment, $tableName]);
        $result = $stmt->fetch();
        
        if (!empty($result) && !empty($result['last_sync'])) {
            return $result['last_sync'];
        }
        
        return null;
    }

    public function getConnection()
    {
        return $this->connection;
    }
}
