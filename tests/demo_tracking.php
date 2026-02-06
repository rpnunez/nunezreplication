#!/usr/bin/env php
<?php

/**
 * Demo script showing update and delete tracking features
 * This script demonstrates the new replication tracking capabilities
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;
use NunezReplication\Replication\ReplicationMetadata;

echo "=== Replication Update/Delete Tracking Demo ===\n\n";

echo "This demo showcases the new features:\n";
echo "1. Timestamp-based update tracking\n";
echo "2. Deletion detection and propagation\n";
echo "3. Metadata table for sync state management\n";
echo "4. Conflict resolution using last-write-wins\n\n";

// Check if config file is provided
if ($argc < 2) {
    echo "Usage: php demo_tracking.php <config-file>\n";
    echo "Example: php demo_tracking.php config.test.master-slave.json\n";
    exit(1);
}

$configFile = $argv[1];
if (!file_exists($configFile)) {
    echo "Error: Config file not found: $configFile\n";
    exit(1);
}

// Load configuration
$configJson = file_get_contents($configFile);
$config = json_decode($configJson, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Error: Invalid JSON in config file: " . json_last_error_msg() . "\n";
    exit(1);
}

echo "Configuration loaded: {$config['mode']} mode\n";
echo "Tracking: Always enabled\n\n";

// Initialize database manager
$dbManager = new DatabaseManager();

try {
    // Connect to databases
    echo "Connecting to databases...\n";
    $dbManager->connect('master', $config['databases']['master']);
    $dbManager->connect('slave', $config['databases']['slave']);
    echo "Connected successfully!\n\n";
    
    // Create replication engine
    $engine = new ReplicationEngine($dbManager, $config);
    
    // Show initial state
    echo "=== Initial State ===\n";
    $masterCount = $dbManager->query('master', "SELECT COUNT(*) as cnt FROM customers")[0]['cnt'];
    $slaveCount = $dbManager->query('slave', "SELECT COUNT(*) as cnt FROM customers")[0]['cnt'];
    echo "Master customers: $masterCount\n";
    echo "Slave customers: $slaveCount\n\n";
    
    // Run initial sync
    echo "Running initial sync...\n";
    $result = $engine->sync();
    
    if ($result['success']) {
        echo "✓ Sync completed in {$result['duration']}s\n";
        echo "  Inserts: {$result['stats']['inserts']}\n";
        echo "  Updates: {$result['stats']['updates']}\n";
        echo "  Deletes: {$result['stats']['deletes']}\n\n";
    } else {
        echo "✗ Sync failed: {$result['error']}\n";
        exit(1);
    }
    
    // Check metadata table
    echo "=== Metadata Tracking ===\n";
    $metadata = new ReplicationMetadata($dbManager);
    
    if ($metadata->metadataTableExists('slave')) {
        echo "✓ Metadata table exists in slave\n";
        
        $metaCount = $dbManager->query('slave', 
            "SELECT COUNT(*) as cnt FROM _replication_metadata WHERE table_name = 'customers'"
        )[0]['cnt'];
        echo "  Metadata records for customers: $metaCount\n";
        
        $lastSync = $metadata->getLastSyncTimestamp('slave', 'customers');
        echo "  Last sync timestamp: " . ($lastSync ?? 'N/A') . "\n\n";
    }
    
    // Show table configuration
    echo "=== Replication Configuration ===\n";
    foreach ($config['replication']['tables'] as $table) {
        echo "Table: {$table['name']}\n";
        echo "  Primary Key: {$table['primaryKey']}\n";
        echo "  Timestamp Column: " . ($table['timestampColumn'] ?? 'updated_at') . "\n";
        $ignoreCount = count($table['ignoreColumns'] ?? []);
        echo "  Ignored Columns: $ignoreCount\n";
    }
    echo "\n";
    
    echo "=== Demo Complete ===\n";
    echo "The replication system is now configured with:\n";
    echo "✓ Automatic update detection via timestamp comparison\n";
    echo "✓ Deletion tracking and propagation\n";
    echo "✓ Metadata tables for sync state management\n";
    echo "✓ Conflict resolution based on timestamps\n\n";
    
    echo "Try these operations to test the features:\n";
    echo "1. Update a customer in master: UPDATE customers SET phone='555-1234' WHERE id=1;\n";
    echo "2. Delete a customer from master: DELETE FROM customers WHERE id=2;\n";
    echo "3. Run sync again to see updates and deletes propagate\n";
    echo "4. Check metadata: SELECT * FROM _replication_metadata;\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    $dbManager->closeAll();
}

echo "\n";
exit(0);
