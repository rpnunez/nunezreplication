#!/usr/bin/env php
<?php

/**
 * MySQL Master-Slave Replication Setup Script
 * 
 * This script generates all necessary configuration files for setting up
 * MySQL Master-Slave replication for WordPress on AlmaLinux VPS.
 * 
 * Usage:
 *   php setup_mysql_replication.php [options]
 * 
 * Options:
 *   --master-db=NAME        Master database name (default: dstdb)
 *   --slave-db=NAME         Slave database name (default: dstdbslave)
 *   --replication-db=NAME   Replication tracking DB name (default: dstreplication)
 *   --output-dir=PATH       Output directory for generated files (default: ./mysql_replication_config)
 *   --help                  Show this help message
 */

require_once __DIR__ . '/vendor/autoload.php';

use NunezReplication\Utils\MySQLReplicationSetup;

// Parse command line arguments
$options = getopt('', [
    'master-db::',
    'slave-db::',
    'replication-db::',
    'output-dir::',
    'help'
]);

// Display help if requested
if (isset($options['help'])) {
    echo file_get_contents(__FILE__);
    exit(0);
}

// Build configuration from command line options
$config = [];

if (isset($options['master-db'])) {
    $config['masterDB'] = $options['master-db'];
}

if (isset($options['slave-db'])) {
    $config['slaveDB'] = $options['slave-db'];
}

if (isset($options['replication-db'])) {
    $config['replicationDB'] = $options['replication-db'];
}

if (isset($options['output-dir'])) {
    $config['outputDir'] = $options['output-dir'];
}

// Create setup utility and generate configurations
try {
    $setup = new MySQLReplicationSetup($config);
    $generatedFiles = $setup->generateAllConfigs();
    
    echo "\nGenerated files:\n";
    foreach ($generatedFiles as $file) {
        echo "  - $file\n";
    }
    
    exit(0);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
