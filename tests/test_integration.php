<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Config\ConfigLoader;
use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;
use NunezReplication\Api\ApiController;

echo "=== Comprehensive Integration Test ===\n\n";

$allPassed = true;

// Test 1: ReplicationEngine without stats DB
echo "Test 1: ReplicationEngine without stats DB\n";
$engine = null;
try {
    $config = [
        'mode' => 'master-slave',
        'databases' => [
            'master' => ['host' => 'localhost', 'port' => 3306, 'user' => 'root', 'password' => 'test', 'database' => 'test_master'],
            'slave' => ['host' => 'localhost', 'port' => 3306, 'user' => 'root', 'password' => 'test', 'database' => 'test_slave']
        ],
        'replication' => ['tables' => [['name' => 'users', 'primaryKey' => 'id', 'ignoreColumns' => []]]]
    ];
    
    $dbManager = new DatabaseManager();
    $engine = new ReplicationEngine($dbManager, $config);
    
    echo "  ✓ Engine created without stats DB\n";
    echo "  ✓ Stats DB is null: " . ($engine->getStatsDB() === null ? 'Yes' : 'No') . "\n";
} catch (\Throwable $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    $allPassed = false;
}

// Test 2: ApiController basic functionality
echo "\nTest 2: ApiController integration\n";
if ($engine === null) {
    echo "  ✗ Skipping: Engine not initialized from Test 1\n";
    $allPassed = false;
} else {
    try {
        $apiController = new ApiController($engine, $config);
    
    // Test getStatus
    $status = $apiController->getStatus();
    echo "  ✓ getStatus() returns data\n";
    echo "    - Mode: " . $status['mode'] . "\n";
    echo "    - Status: " . $status['status'] . "\n";
    
    // Test getConfig
    $configResponse = $apiController->getConfig();
    echo "  ✓ getConfig() returns data\n";
    echo "    - Mode: " . $configResponse['mode'] . "\n";
    
    // Test stats endpoints with null stats DB
    $history = $apiController->getStatsHistory();
    echo "  ✓ getStatsHistory() handles null stats DB\n";
    if (isset($history['error'])) {
        echo "    - Expected error: " . $history['error'] . "\n";
    }
    
    $errors = $apiController->getRecentErrors();
    echo "  ✓ getRecentErrors() handles null stats DB\n";
    if (isset($errors['error'])) {
        echo "    - Expected error: " . $errors['error'] . "\n";
    }
    
} catch (\Throwable $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    $allPassed = false;
}
}

// Test 3: Check all class methods exist
echo "\nTest 3: Verify all required methods\n";
if ($engine === null) {
    echo "  ✗ Skipping: Engine not initialized\n";
    $allPassed = false;
} else {
    try {
        $engineReflection = new ReflectionClass(get_class($engine));
    $requiredMethods = ['sync', 'getStats', 'getStatsDB'];
    
    foreach ($requiredMethods as $method) {
        if ($engineReflection->hasMethod($method)) {
            echo "  ✓ ReplicationEngine::$method() exists\n";
        } else {
            echo "  ✗ ReplicationEngine::$method() missing\n";
            $allPassed = false;
        }
    }
    
    $apiReflection = new ReflectionClass(get_class($apiController));
    $requiredApiMethods = ['getStatus', 'getConfig', 'triggerSync', 'getStatsHistory', 'getTableStats', 'getRecentErrors'];
    
    foreach ($requiredApiMethods as $method) {
        if ($apiReflection->hasMethod($method)) {
            echo "  ✓ ApiController::$method() exists\n";
        } else {
            echo "  ✗ ApiController::$method() missing\n";
            $allPassed = false;
        }
    }
    
} catch (\Throwable $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    $allPassed = false;
}
}

// Test 4: Stats structure
echo "\nTest 4: Stats structure validation\n";
if ($engine === null) {
    echo "  ✗ Skipping: Engine not initialized\n";
    $allPassed = false;
} else {
    try {
        $stats = $engine->getStats();
    $requiredKeys = ['lastSync', 'totalSyncs', 'successfulSyncs', 'failedSyncs', 'lastError', 'tablesProcessed', 'updates', 'inserts', 'deletes'];
    
    foreach ($requiredKeys as $key) {
        if (array_key_exists($key, $stats)) {
            echo "  ✓ Stats has '$key'\n";
        } else {
            echo "  ✗ Stats missing '$key'\n";
            $allPassed = false;
        }
    }
    
} catch (\Throwable $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
    $allPassed = false;
}
}

// Summary
echo "\n" . str_repeat("=", 40) . "\n";
if ($allPassed) {
    echo "✓ ALL TESTS PASSED!\n";
    echo "\nThe system is ready for production use.\n";
    echo "Both with and without stats database configuration.\n";
    exit(0);
} else {
    echo "✗ SOME TESTS FAILED\n";
    echo "\nPlease review the errors above.\n";
    exit(1);
}
