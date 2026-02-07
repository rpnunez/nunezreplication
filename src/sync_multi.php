#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Config\ConfigLoader;
use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;
use NunezReplication\Sync\MultiEnvironmentSync;

// This script synchronizes data across multiple independent environments via API

echo "[" . date('Y-m-d H:i:s') . "] Starting multi-environment sync...\n";

try {
    // Load configuration
    $configLoader = new ConfigLoader();
    $configPath = isset($argv[1]) ? $argv[1] : null;
    $config = $configLoader->load($configPath);

    // Check if remote environments are configured
    if (!isset($config['remoteEnvironments']) || empty($config['remoteEnvironments'])) {
        echo "[" . date('Y-m-d H:i:s') . "] No remote environments configured. Exiting.\n";
        exit(0);
    }

    // Initialize database connections
    $dbManager = new DatabaseManager();
    $dbManager->connect('master', $config['databases']['master']);

    if (isset($config['databases']['slave'])) {
        $dbManager->connect('slave', $config['databases']['slave']);
    }

    // Initialize replication engine
    $engine = new ReplicationEngine($dbManager, $config);

    // Initialize multi-environment sync
    $multiSync = new MultiEnvironmentSync($engine, $config);

    // First, sync local databases (master-slave or master-master)
    echo "[" . date('Y-m-d H:i:s') . "] Syncing local databases...\n";
    $localResult = $engine->sync();
    
    if ($localResult['success']) {
        echo "[" . date('Y-m-d H:i:s') . "] Local sync completed in {$localResult['duration']}s\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Local sync failed: {$localResult['error']}\n";
        echo "[" . date('Y-m-d H:i:s') . "] Continuing with remote sync...\n";
    }

    // Then, sync with remote environments
    echo "[" . date('Y-m-d H:i:s') . "] Syncing with remote environments...\n";
    $remoteResults = $multiSync->syncWithAllRemotes();

    // Display results
    foreach ($remoteResults as $envName => $result) {
        if ($result['success']) {
            echo "[" . date('Y-m-d H:i:s') . "] Successfully synced with $envName\n";
            
            // Show detailed stats
            if (isset($result['result']['pushed'])) {
                foreach ($result['result']['pushed'] as $table => $stats) {
                    if (isset($stats['error'])) {
                        echo "  Push $table: ERROR - {$stats['error']}\n";
                    } else {
                        echo "  Push $table: {$stats['rows']} rows (I:{$stats['inserted']}, U:{$stats['updated']})\n";
                    }
                }
            }
            
            if (isset($result['result']['pulled'])) {
                foreach ($result['result']['pulled'] as $table => $stats) {
                    if (isset($stats['error'])) {
                        echo "  Pull $table: ERROR - {$stats['error']}\n";
                    } else {
                        echo "  Pull $table: {$stats['rows']} rows (I:{$stats['inserted']}, U:{$stats['updated']})\n";
                    }
                }
            }
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] Failed to sync with $envName: {$result['error']}\n";
        }
    }

    // Close connections
    $dbManager->closeAll();

    echo "[" . date('Y-m-d H:i:s') . "] Multi-environment sync completed\n";

} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
    exit(1);
}
