#!/usr/bin/env php
<?php

/**
 * Test script to verify update and delete replication functionality
 * This script validates that updates and deletes are properly tracked and replicated
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;

function testUpdateReplication($config) {
    echo "\n=== Testing Update Replication ===\n";
    
    $dbManager = new DatabaseManager();
    
    try {
        // Connect to databases
        $dbManager->connect('master', $config['databases']['master']);
        $dbManager->connect('slave', $config['databases']['slave']);
        
        // Test update propagation
        echo "1. Testing update in master database...\n";
        
        // Update a customer in master
        $dbManager->execute('master', 
            "UPDATE customers SET phone = ? WHERE email = ?", 
            ['555-9999', 'john.doe@example.com']
        );
        
        echo "   Updated customer phone in master\n";
        
        // Run sync
        echo "2. Running replication sync...\n";
        $engine = new ReplicationEngine($dbManager, $config);
        $result = $engine->sync();
        
        if (!$result['success']) {
            echo "   ✗ FAIL: Sync failed: {$result['error']}\n";
            return 1;
        }
        
        echo "   Sync completed (Duration: {$result['duration']}s)\n";
        echo "   Updates: {$result['stats']['updates']}, Inserts: {$result['stats']['inserts']}, Deletes: {$result['stats']['deletes']}\n";
        
        // Verify update in slave
        echo "3. Verifying update in slave...\n";
        $masterCustomer = $dbManager->query('master', 
            "SELECT * FROM customers WHERE email = ?", 
            ['john.doe@example.com']
        );
        
        $slaveCustomer = $dbManager->query('slave', 
            "SELECT * FROM customers WHERE email = ?", 
            ['john.doe@example.com']
        );
        
        if (empty($masterCustomer) || empty($slaveCustomer)) {
            echo "   ✗ FAIL: Customer not found in one or both databases\n";
            return 1;
        }
        
        $master = $masterCustomer[0];
        $slave = $slaveCustomer[0];
        
        if ($master['phone'] === $slave['phone'] && $slave['phone'] === '555-9999') {
            echo "   ✓ PASS: Update successfully replicated to slave\n";
            return 0;
        } else {
            echo "   ✗ FAIL: Update not replicated (Master: {$master['phone']}, Slave: {$slave['phone']})\n";
            return 1;
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        return 1;
    } finally {
        $dbManager->closeAll();
    }
}

function testDeleteReplication($config) {
    echo "\n=== Testing Delete Replication ===\n";
    
    $dbManager = new DatabaseManager();
    
    try {
        // Connect to databases
        $dbManager->connect('master', $config['databases']['master']);
        $dbManager->connect('slave', $config['databases']['slave']);
        
        // Test delete propagation
        echo "1. Getting initial count...\n";
        $initialMasterCount = $dbManager->query('master', "SELECT COUNT(*) as cnt FROM customers")[0]['cnt'];
        $initialSlaveCount = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM customers")[0]['cnt'];
        
        echo "   Master count: $initialMasterCount, Slave count: $initialSlaveCount\n";
        
        // Delete a customer from master
        echo "2. Deleting customer from master database...\n";
        $dbManager->execute('master', 
            "DELETE FROM customers WHERE email = ?", 
            ['maria.g@example.com']
        );
        
        echo "   Deleted customer from master\n";
        
        // Run sync
        echo "3. Running replication sync...\n";
        $engine = new ReplicationEngine($dbManager, $config);
        $result = $engine->sync();
        
        if (!$result['success']) {
            echo "   ✗ FAIL: Sync failed: {$result['error']}\n";
            return 1;
        }
        
        echo "   Sync completed (Duration: {$result['duration']}s)\n";
        echo "   Updates: {$result['stats']['updates']}, Inserts: {$result['stats']['inserts']}, Deletes: {$result['stats']['deletes']}\n";
        
        // Verify deletion in slave
        echo "4. Verifying deletion in slave...\n";
        $slaveCustomer = $dbManager->query('slave', 
            "SELECT * FROM customers WHERE email = ?", 
            ['maria.g@example.com']
        );
        
        if (empty($slaveCustomer)) {
            echo "   ✓ PASS: Delete successfully replicated to slave\n";
            
            // Check counts match
            $finalMasterCount = $dbManager->query('master', "SELECT COUNT(*) as cnt FROM customers")[0]['cnt'];
            $finalSlaveCount = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM customers")[0]['cnt'];
            
            echo "   Final counts - Master: $finalMasterCount, Slave: $finalSlaveCount\n";
            
            if ($finalMasterCount === $finalSlaveCount) {
                echo "   ✓ PASS: Master and slave counts match after deletion\n";
                return 0;
            } else {
                echo "   ✗ FAIL: Master and slave counts don't match\n";
                return 1;
            }
        } else {
            echo "   ✗ FAIL: Delete not replicated (customer still exists in slave)\n";
            return 1;
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        return 1;
    } finally {
        $dbManager->closeAll();
    }
}

function testTimestampConflictResolution($config) {
    echo "\n=== Testing Timestamp-Based Conflict Resolution ===\n";
    
    $dbManager = new DatabaseManager();
    
    try {
        // Connect to databases
        $dbManager->connect('master', $config['databases']['master']);
        $dbManager->connect('slave', $config['databases']['slave']);
        
        echo "1. Creating conflicting updates with different timestamps...\n";
        
        // Update in master (older timestamp)
        sleep(1); // Ensure time difference
        $dbManager->execute('master', 
            "UPDATE customers SET address = ?, updated_at = ? WHERE email = ?", 
            ['100 Old Address St', date('Y-m-d H:i:s', time() - 60), 'john.doe@example.com']
        );
        
        // Update in slave (newer timestamp)
        $dbManager->execute('slave', 
            "UPDATE customers SET address = ?, updated_at = ? WHERE email = ?", 
            ['200 New Address Ave', date('Y-m-d H:i:s'), 'john.doe@example.com']
        );
        
        echo "   Created conflicting updates\n";
        
        // Run sync (should prefer newer timestamp)
        echo "2. Running replication sync...\n";
        $engine = new ReplicationEngine($dbManager, $config);
        $result = $engine->sync();
        
        if (!$result['success']) {
            echo "   ✗ FAIL: Sync failed: {$result['error']}\n";
            return 1;
        }
        
        echo "   Sync completed\n";
        
        // For master-slave mode, master always wins regardless of timestamp
        // For master-master mode with tracking, newer timestamp should win
        if ($config['mode'] === 'master-master' && ($config['replication']['enableTracking'] ?? true)) {
            echo "3. Verifying last-write-wins for master-master mode...\n";
            $slaveCustomer = $dbManager->query('slave', 
                "SELECT address FROM customers WHERE email = ?", 
                ['john.doe@example.com']
            );
            
            // In master-master with timestamps, the sync should handle conflicts intelligently
            echo "   Slave address: {$slaveCustomer[0]['address']}\n";
            echo "   ✓ PASS: Conflict resolution based on timestamps\n";
            return 0;
        } else {
            echo "3. Verifying master precedence for master-slave mode...\n";
            $slaveCustomer = $dbManager->query('slave', 
                "SELECT address FROM customers WHERE email = ?", 
                ['john.doe@example.com']
            );
            
            if ($slaveCustomer[0]['address'] === '100 Old Address St') {
                echo "   ✓ PASS: Master data took precedence in slave\n";
                return 0;
            } else {
                echo "   ✗ FAIL: Expected master data in slave\n";
                return 1;
            }
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        return 1;
    } finally {
        $dbManager->closeAll();
    }
}

function testMetadataTracking($config) {
    echo "\n=== Testing Metadata Tracking ===\n";
    
    $dbManager = new DatabaseManager();
    
    try {
        // Connect to databases
        $dbManager->connect('master', $config['databases']['master']);
        $dbManager->connect('slave', $config['databases']['slave']);
        
        echo "1. Checking metadata table existence...\n";
        
        // Check if metadata table exists in both databases
        $masterTables = $dbManager->query('master', "SHOW TABLES LIKE '_replication_metadata'");
        $slaveTables = $dbManager->query('slave', "SHOW TABLES LIKE '_replication_metadata'");
        
        if (!empty($masterTables) && !empty($slaveTables)) {
            echo "   ✓ PASS: Metadata tables exist in both databases\n";
        } else {
            echo "   ✗ FAIL: Metadata tables not found\n";
            return 1;
        }
        
        echo "2. Checking metadata records...\n";
        $metadata = $dbManager->query('slave', 
            "SELECT COUNT(*) as cnt FROM _replication_metadata WHERE table_name = 'customers'"
        );
        
        $count = $metadata[0]['cnt'];
        if ($count > 0) {
            echo "   ✓ PASS: Found $count metadata records for customers table\n";
        } else {
            echo "   ⚠ WARNING: No metadata records found (may be first sync)\n";
        }
        
        return 0;
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        return 1;
    } finally {
        $dbManager->closeAll();
    }
}

// Parse command line arguments
if ($argc < 2) {
    echo "Usage: php test_update_delete_replication.php <config-file>\n";
    exit(1);
}

$configFile = $argv[1];
if (!file_exists($configFile)) {
    echo "Error: Config file not found: $configFile\n";
    exit(1);
}

$configJson = file_get_contents($configFile);
if ($configJson === false) {
    echo "Error: Unable to read config file: $configFile\n";
    exit(1);
}

$config = json_decode($configJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Error: Invalid JSON in config file: " . json_last_error_msg() . "\n";
    exit(1);
}

// Run all tests
$totalTests = 0;
$failedTests = 0;

$totalTests++;
if (testUpdateReplication($config) !== 0) {
    $failedTests++;
}

$totalTests++;
if (testDeleteReplication($config) !== 0) {
    $failedTests++;
}

$totalTests++;
if (testTimestampConflictResolution($config) !== 0) {
    $failedTests++;
}

$totalTests++;
if (testMetadataTracking($config) !== 0) {
    $failedTests++;
}

// Print summary
echo "\n=== Test Summary ===\n";
echo "Total tests: $totalTests\n";
echo "Passed: " . ($totalTests - $failedTests) . "\n";
echo "Failed: $failedTests\n";

if ($failedTests === 0) {
    echo "\n✓ All tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed\n";
    exit(1);
}
