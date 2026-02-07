<?php

/**
 * Test script for MySQL Replication Setup Utility
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Utils\MySQLReplicationSetup;

echo "========================================\n";
echo "Testing MySQL Replication Setup Utility\n";
echo "========================================\n\n";

$testDir = sys_get_temp_dir() . '/mysql_replication_test_' . time();

try {
    // Test 1: Default configuration
    echo "Test 1: Default configuration...\n";
    $setup = new MySQLReplicationSetup([
        'outputDir' => $testDir . '/default'
    ]);
    $files = $setup->generateAllConfigs();
    
    if (count($files) === 8) {
        echo "✓ Test 1 PASSED - Generated 8 files\n\n";
    } else {
        echo "❌ Test 1 FAILED - Expected 8 files, got " . count($files) . "\n\n";
        exit(1);
    }
    
    // Test 2: Custom database names
    echo "Test 2: Custom database names...\n";
    $setup2 = new MySQLReplicationSetup([
        'masterDB' => 'custom_master',
        'slaveDB' => 'custom_slave',
        'replicationDB' => 'custom_repl',
        'outputDir' => $testDir . '/custom'
    ]);
    $files2 = $setup2->generateAllConfigs();
    
    // Verify custom names are in the master config
    $masterConfig = file_get_contents($testDir . '/custom/master.cnf');
    if (strpos($masterConfig, 'custom_master') !== false) {
        echo "✓ Test 2 PASSED - Custom database names applied\n\n";
    } else {
        echo "❌ Test 2 FAILED - Custom names not found in config\n\n";
        exit(1);
    }
    
    // Test 3: Verify all required files are generated
    echo "Test 3: Verify required files...\n";
    $requiredFiles = [
        'master.cnf',
        'slave.cnf',
        '01_replication_user_setup.sql',
        '02_replication_schema_setup.sql',
        '03_wordpress_db_setup.sql',
        'SETUP_INSTRUCTIONS.md',
        'setup_cron.sh',
        'config.wordpress-replication.json'
    ];
    
    $allFilesExist = true;
    foreach ($requiredFiles as $file) {
        if (!file_exists($testDir . '/default/' . $file)) {
            echo "❌ Missing file: $file\n";
            $allFilesExist = false;
        }
    }
    
    if ($allFilesExist) {
        echo "✓ Test 3 PASSED - All required files exist\n\n";
    } else {
        echo "❌ Test 3 FAILED - Some files are missing\n\n";
        exit(1);
    }
    
    // Test 4: Verify SQL script structure
    echo "Test 4: Verify SQL script structure...\n";
    $schemaSQL = file_get_contents($testDir . '/default/02_replication_schema_setup.sql');
    
    $requiredTables = [
        'replication_metadata',
        'sync_history',
        'table_sync_stats',
        'replication_errors',
        'replication_config'
    ];
    
    $allTablesFound = true;
    foreach ($requiredTables as $table) {
        if (strpos($schemaSQL, "CREATE TABLE IF NOT EXISTS $table") === false) {
            echo "❌ Missing table: $table\n";
            $allTablesFound = false;
        }
    }
    
    if ($allTablesFound) {
        echo "✓ Test 4 PASSED - All required tables in schema\n\n";
    } else {
        echo "❌ Test 4 FAILED - Some tables are missing\n\n";
        exit(1);
    }
    
    // Test 5: Verify cron setup script is executable
    echo "Test 5: Verify cron script permissions...\n";
    $cronScript = $testDir . '/default/setup_cron.sh';
    if (is_executable($cronScript)) {
        echo "✓ Test 5 PASSED - Cron script is executable\n\n";
    } else {
        echo "❌ Test 5 FAILED - Cron script is not executable\n\n";
        exit(1);
    }
    
    // Test 6: Verify JSON config is valid
    echo "Test 6: Verify JSON configuration...\n";
    $jsonConfig = file_get_contents($testDir . '/default/config.wordpress-replication.json');
    $config = json_decode($jsonConfig, true);
    
    if ($config !== null && isset($config['databases']['master'])) {
        echo "✓ Test 6 PASSED - JSON config is valid\n\n";
    } else {
        echo "❌ Test 6 FAILED - JSON config is invalid\n\n";
        exit(1);
    }
    
    // Cleanup
    echo "Cleaning up test files...\n";
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($testDir);
    
    echo "\n========================================\n";
    echo "All tests PASSED! ✓\n";
    echo "========================================\n";
    exit(0);
    
} catch (Exception $e) {
    echo "\n❌ Test FAILED with exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
