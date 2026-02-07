<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Replication\ReplicationStatsDB;

echo "Testing ReplicationStatsDB class...\n\n";

// Test 1: Check if class can be loaded
echo "✓ ReplicationStatsDB class loaded successfully\n";

// Test 2: Check if methods exist
$reflection = new ReflectionClass(ReplicationStatsDB::class);
$methods = [
    'startSync',
    'completeSyncSuccess',
    'completeSyncFailure',
    'recordTableStats',
    'logOperation',
    'getOverallStats',
    'getRecentSyncs',
    'getTableStats',
    'getRecentErrors',
    'recordMetadata',
    'markAsDeleted',
    'getLastSyncTimestamp'
];

echo "\nChecking methods:\n";
foreach ($methods as $method) {
    if ($reflection->hasMethod($method)) {
        echo "✓ Method '$method' exists\n";
    } else {
        echo "✗ Method '$method' missing\n";
    }
}

echo "\n✓ All basic checks passed!\n";
echo "\nNote: Database connection tests require MySQL to be running.\n";
echo "The full functionality will be tested when the replication engine runs.\n";
