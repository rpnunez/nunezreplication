<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Config\ConfigLoader;
use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;

// This script should be run via cron based on the syncInterval configuration
// Example cron entry: */5 * * * * php /path/to/sync.php

echo "[" . date('Y-m-d H:i:s') . "] Starting replication sync...\n";

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

    // Initialize replication engine and sync
    $engine = new ReplicationEngine($dbManager, $config);
    $result = $engine->sync();

    if ($result['success']) {
        echo "[" . date('Y-m-d H:i:s') . "] Sync completed successfully in {$result['duration']}s\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Sync failed: {$result['error']}\n";
    }

    // Close connections
    $dbManager->closeAll();

} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
    exit(1);
}
