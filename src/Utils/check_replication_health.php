<?php

/**
 * MySQL Replication Health Check Script
 * 
 * Monitors the health of MySQL replication and logs any issues.
 * This script should be run periodically via cron.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use NunezReplication\Config\ConfigLoader;
use NunezReplication\Database\DatabaseManager;

echo "[" . date('Y-m-d H:i:s') . "] Starting replication health check...\n";

try {
    // Load configuration
    $configLoader = new ConfigLoader();
    $config = $configLoader->load();
    
    // Initialize database connections
    $dbManager = new DatabaseManager();
    $dbManager->connect('master', $config['databases']['master']);
    
    if (isset($config['databases']['slave'])) {
        $dbManager->connect('slave', $config['databases']['slave']);
    }
    
    // Check master status
    echo "Checking master database...\n";
    $masterConn = $dbManager->getConnection('master');
    $masterStatus = $masterConn->query("SHOW MASTER STATUS")->fetch(PDO::FETCH_ASSOC);
    
    if ($masterStatus) {
        echo "✓ Master is active\n";
        echo "  - Binary log: {$masterStatus['File']}\n";
        echo "  - Position: {$masterStatus['Position']}\n";
        if (isset($masterStatus['Executed_Gtid_Set'])) {
            echo "  - GTID: {$masterStatus['Executed_Gtid_Set']}\n";
        }
    } else {
        echo "⚠️  Master status not available\n";
    }
    
    // Check slave status if configured
    if (isset($config['databases']['slave'])) {
        echo "\nChecking slave database...\n";
        $slaveConn = $dbManager->getConnection('slave');
        $slaveStatus = $slaveConn->query("SHOW SLAVE STATUS")->fetch(PDO::FETCH_ASSOC);
        
        if ($slaveStatus) {
            echo "✓ Slave is configured\n";
            echo "  - Slave IO Running: {$slaveStatus['Slave_IO_Running']}\n";
            echo "  - Slave SQL Running: {$slaveStatus['Slave_SQL_Running']}\n";
            $lag = $slaveStatus['Seconds_Behind_Master'] ?? 'Unknown';
            echo "  - Seconds Behind Master: {$lag}\n";
            
            if ($slaveStatus['Slave_IO_Running'] !== 'Yes' || $slaveStatus['Slave_SQL_Running'] !== 'Yes') {
                echo "❌ CRITICAL: Slave replication is not running!\n";
                if (!empty($slaveStatus['Last_Error'])) {
                    echo "   Error: {$slaveStatus['Last_Error']}\n";
                }
                exit(1);
            }
            
            if (isset($slaveStatus['Seconds_Behind_Master']) && $slaveStatus['Seconds_Behind_Master'] !== null && $slaveStatus['Seconds_Behind_Master'] > 300) {
                echo "⚠️  WARNING: Slave is lagging by {$slaveStatus['Seconds_Behind_Master']} seconds\n";
            }
        } else {
            echo "⚠️  Slave status not available\n";
        }
    }
    
    // Check replication tracking database
    if (isset($config['databases']['stats'])) {
        echo "\nChecking replication tracking database...\n";
        $dbManager->connect('stats', $config['databases']['stats']);
        $statsConn = $dbManager->getConnection('stats');
        
        // Get recent sync history
        $recentSyncs = $statsConn->query(
            "SELECT * FROM sync_history 
             WHERE start_time > DATE_SUB(NOW(), INTERVAL 1 HOUR) 
             ORDER BY start_time DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($recentSyncs) > 0) {
            echo "✓ Recent sync operations (last hour):\n";
            foreach ($recentSyncs as $sync) {
                $status = $sync['status'] === 'completed' ? '✓' : '❌';
                echo "  {$status} {$sync['start_time']} - {$sync['status']} ({$sync['duration_seconds']}s)\n";
            }
        } else {
            echo "⚠️  No sync operations in the last hour\n";
        }
        
        // Check for recent errors
        $recentErrors = $statsConn->query(
            "SELECT * FROM replication_errors 
             WHERE occurred_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
             AND resolution_status = 'pending'
             ORDER BY occurred_at DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($recentErrors) > 0) {
            echo "\n⚠️  Unresolved errors (last 24 hours):\n";
            foreach ($recentErrors as $error) {
                echo "  - [{$error['error_type']}] {$error['table_name']}: {$error['error_message']}\n";
            }
        } else {
            echo "\n✓ No unresolved errors in the last 24 hours\n";
        }
    }
    
    // Close connections
    $dbManager->closeAll();
    
    echo "\n[" . date('Y-m-d H:i:s') . "] Health check completed successfully\n";
    exit(0);
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
    exit(1);
}
