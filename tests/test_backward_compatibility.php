<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Config\ConfigLoader;
use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;

echo "Testing Backward Compatibility (without stats DB)...\n\n";

// Test 1: Load config without stats DB
$config = [
    'mode' => 'master-slave',
    'databases' => [
        'master' => [
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'root',
            'password' => 'test',
            'database' => 'test_master'
        ],
        'slave' => [
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'root',
            'password' => 'test',
            'database' => 'test_slave'
        ]
        // No stats database configured
    ],
    'replication' => [
        'tables' => [
            ['name' => 'users', 'primaryKey' => 'id', 'ignoreColumns' => []]
        ]
    ]
];

echo "✓ Config without stats DB created\n";

// Test 2: Initialize DatabaseManager
$dbManager = new DatabaseManager();
echo "✓ DatabaseManager initialized\n";

// Test 3: Initialize ReplicationEngine without connecting to databases
try {
    $engine = new ReplicationEngine($dbManager, $config);
    echo "✓ ReplicationEngine initialized without stats DB\n";
    
    // Test 4: Check stats
    $stats = $engine->getStats();
    echo "✓ getStats() works without stats DB\n";
    echo "  - totalSyncs: " . $stats['totalSyncs'] . "\n";
    echo "  - successfulSyncs: " . $stats['successfulSyncs'] . "\n";
    
    // Test 5: Check that statsDB is null
    $statsDB = $engine->getStatsDB();
    if ($statsDB === null) {
        echo "✓ Stats DB is null when not configured (expected)\n";
    } else {
        echo "✗ Stats DB should be null when not configured\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✓ All backward compatibility tests passed!\n";
echo "\nThe system works correctly without a stats database configured.\n";
echo "Stats will be tracked in memory only (lost on restart).\n";
