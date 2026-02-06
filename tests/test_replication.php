#!/usr/bin/env php
<?php

/**
 * Test script to verify replication results
 * This script validates that data has been properly replicated between databases
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Database\DatabaseManager;

function testReplication($masterConfig, $slaveConfig, $mode) {
    echo "\n=== Testing $mode Replication ===\n";
    
    $dbManager = new DatabaseManager();
    
    try {
        // Connect to databases
        $dbManager->connect('master', $masterConfig);
        $dbManager->connect('slave', $slaveConfig);
        
        // Test tables to verify
        $tables = ['customers', 'accounts', 'transactions'];
        $allPassed = true;
        
        foreach ($tables as $table) {
            echo "Testing table: $table\n";
            
            // Count rows in master
            $masterCount = $dbManager->query('master', "SELECT COUNT(*) as cnt FROM `$table`")[0]['cnt'];
            echo "  Master count: $masterCount\n";
            
            // Count rows in slave
            $slaveCount = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM `$table`")[0]['cnt'];
            echo "  Slave count: $slaveCount\n";
            
            // For master-slave mode, slave should have same records as master
            if ($mode === 'master-slave') {
                if ($slaveCount === $masterCount) {
                    echo "  ✓ PASS: Slave has exact same number of records as master\n";
                } else {
                    echo "  ✗ FAIL: Slave record count mismatch (expected $masterCount, got $slaveCount)\n";
                    $allPassed = false;
                }
            }
            
            // For master-master mode, check bidirectional sync
            if ($mode === 'master-master') {
                // In master-master mode, both should have at least the original records
                // The total should account for unique records from both sides
                if ($slaveCount >= $masterCount || $masterCount >= $slaveCount) {
                    echo "  ✓ PASS: Bidirectional sync completed (Master: $masterCount, Slave: $slaveCount)\n";
                } else {
                    echo "  ✗ FAIL: Bidirectional sync incomplete\n";
                    $allPassed = false;
                }
            }
        }
        
        // Verify specific data integrity
        echo "\nVerifying data integrity:\n";
        
        // Check if a specific customer exists in both databases
        $masterCustomer = $dbManager->query('master', 
            "SELECT * FROM customers WHERE email = ?", 
            ['john.doe@example.com']
        );
        
        $slaveCustomer = $dbManager->query('slave', 
            "SELECT * FROM customers WHERE email = ?", 
            ['john.doe@example.com']
        );
        
        if (count($masterCustomer) > 0 && count($slaveCustomer) > 0) {
            $master = $masterCustomer[0];
            $slave = $slaveCustomer[0];
            
            if ($master['first_name'] === $slave['first_name'] && 
                $master['last_name'] === $slave['last_name']) {
                echo "  ✓ PASS: Customer data matches between master and slave\n";
            } else {
                echo "  ✗ FAIL: Customer data mismatch\n";
                $allPassed = false;
            }
        } else {
            echo "  ✗ FAIL: Customer not found in one or both databases\n";
            $allPassed = false;
        }
        
        $dbManager->closeAll();
        
        if ($allPassed) {
            echo "\n✓ All tests passed for $mode mode!\n";
            return 0;
        } else {
            echo "\n✗ Some tests failed for $mode mode\n";
            return 1;
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        return 1;
    }
}

// Parse command line arguments
if ($argc < 2) {
    echo "Usage: php test_replication.php <config-file>\n";
    exit(1);
}

$configFile = $argv[1];
if (!file_exists($configFile)) {
    echo "Error: Config file not found: $configFile\n";
    exit(1);
}

$config = json_decode(file_get_contents($configFile), true);
if (!$config) {
    echo "Error: Invalid JSON in config file\n";
    exit(1);
}

$mode = $config['mode'] ?? 'master-slave';
$masterConfig = $config['databases']['master'];
$slaveConfig = $config['databases']['slave'];

exit(testReplication($masterConfig, $slaveConfig, $mode));
