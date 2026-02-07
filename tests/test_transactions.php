#!/usr/bin/env php
<?php

/**
 * Test script to verify transaction support and rollback behavior
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Config\ConfigLoader;
use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;

echo "\n=== Testing Transaction Support ===\n\n";

try {
    // Load configuration
    $configLoader = new ConfigLoader();
    $configPath = isset($argv[1]) ? $argv[1] : null;
    $config = $configLoader->load($configPath);

    // Initialize database connections
    $dbManager = new DatabaseManager();
    $dbManager->connect('master', $config['databases']['master']);
    $dbManager->connect('slave', $config['databases']['slave']);

    echo "Test 1: Basic Transaction Operations\n";
    echo "-----------------------------------\n";

    // Test beginTransaction
    $dbManager->beginTransaction('slave');
    echo "✓ Transaction started on slave\n";

    // Check inTransaction
    if ($dbManager->inTransaction('slave')) {
        echo "✓ Transaction is active on slave\n";
    } else {
        echo "✗ FAIL: Transaction not active\n";
        exit(1);
    }

    // Test commit
    $dbManager->commit('slave');
    echo "✓ Transaction committed on slave\n";

    // Check transaction is no longer active
    if (!$dbManager->inTransaction('slave')) {
        echo "✓ Transaction properly closed\n";
    } else {
        echo "✗ FAIL: Transaction still active after commit\n";
        exit(1);
    }

    echo "\nTest 2: Transaction Rollback\n";
    echo "----------------------------\n";

    // Get initial count from a test table
    $tables = $config['replication']['tables'];
    $testTable = $tables[0]['name'];
    $primaryKey = $tables[0]['primaryKey'];

    $initialCount = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM `$testTable`")[0]['cnt'];
    echo "Initial record count in $testTable: $initialCount\n";

    // Start transaction and insert a record
    $dbManager->beginTransaction('slave');
    
    // Insert a test record using configured primary key
    $testId = 999999;
    
    // Check if table has name and email columns
    $tableColumns = $dbManager->query('slave', "SHOW COLUMNS FROM `$testTable`");
    $columnNames = array_map(function($col) { return $col['Field']; }, $tableColumns);
    
    if (in_array('name', $columnNames) && in_array('email', $columnNames)) {
        $sql = "INSERT INTO `$testTable` (`$primaryKey`, name, email) VALUES (?, ?, ?)";
        $dbManager->execute('slave', $sql, [$testId, 'Test User', 'test@example.com']);
        echo "✓ Test record inserted in transaction\n";
        
        // Verify record exists in transaction
        $result = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM `$testTable` WHERE `$primaryKey` = ?", [$testId]);
        if ($result[0]['cnt'] == 1) {
            echo "✓ Record visible within transaction\n";
        }
        
        // Rollback transaction
        $dbManager->rollback('slave');
        echo "✓ Transaction rolled back\n";
        
        // Verify record was rolled back
        $result = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM `$testTable` WHERE `$primaryKey` = ?", [$testId]);
        if ($result[0]['cnt'] == 0) {
            echo "✓ Record properly rolled back\n";
        } else {
            echo "✗ FAIL: Record not rolled back\n";
            exit(1);
        }
        
        // Verify count is back to initial
        $finalCount = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM `$testTable`")[0]['cnt'];
        if ($finalCount == $initialCount) {
            echo "✓ Record count restored to initial value\n";
        } else {
            echo "✗ FAIL: Record count mismatch (expected $initialCount, got $finalCount)\n";
            exit(1);
        }
    } else {
        echo "⚠ Skipping insert test - table schema doesn't match expected columns\n";
        $dbManager->rollback('slave');
    }

    echo "\nTest 3: Replication Engine Transaction Integration\n";
    echo "---------------------------------------------------\n";

    // Get initial counts
    $masterCount = $dbManager->query('master', "SELECT COUNT(*) as cnt FROM `$testTable`")[0]['cnt'];
    $slaveCountBefore = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM `$testTable`")[0]['cnt'];
    
    echo "Master count: $masterCount\n";
    echo "Slave count before sync: $slaveCountBefore\n";

    // Run a sync (this should use transactions internally)
    $engine = new ReplicationEngine($dbManager, $config);
    $result = $engine->sync();

    if ($result['success']) {
        echo "✓ Sync completed successfully with transactions\n";
        echo "  Duration: {$result['duration']}s\n";
        
        // Verify slave count after sync
        $slaveCountAfter = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM `$testTable`")[0]['cnt'];
        echo "Slave count after sync: $slaveCountAfter\n";
        
        if ($config['mode'] === 'master-slave' && $slaveCountAfter == $masterCount) {
            echo "✓ Slave synchronized correctly with master using transactions\n";
        } else if ($config['mode'] === 'master-master') {
            echo "✓ Master-master sync completed with transactions\n";
        }
    } else {
        echo "✗ Sync failed: {$result['error']}\n";
        exit(1);
    }

    echo "\n=== All Transaction Tests Passed ===\n\n";

    // Close connections
    $dbManager->closeAll();

    exit(0);

} catch (\Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
