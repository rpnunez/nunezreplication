<?php

namespace NunezReplication\Utils;

/**
 * MySQL Replication Setup Utility
 * 
 * Creates production-ready MySQL Master-Slave replication configuration files
 * for AlmaLinux VPS running WordPress with same-server setup
 */
class MySQLReplicationSetup
{
    private $masterDB;
    private $slaveDB;
    private $replicationDB;
    private $outputDir;
    private $mysqlConfigDir;
    private $replicationUser;
    private $replicationPassword;
    private $generatedFiles = [];
    
    public function __construct($options = [])
    {
        $this->masterDB = $options['masterDB'] ?? 'dstdb';
        $this->slaveDB = $options['slaveDB'] ?? 'dstdbslave';
        $this->replicationDB = $options['replicationDB'] ?? 'dstreplication';
        $this->outputDir = $options['outputDir'] ?? getcwd() . '/mysql_replication_config';
        $this->mysqlConfigDir = $options['mysqlConfigDir'] ?? '/etc/my.cnf.d';
        $this->replicationUser = $options['replicationUser'] ?? 'repl_user';
        $this->replicationPassword = $options['replicationPassword'] ?? $this->generateSecurePassword();
    }
    
    /**
     * Generate all necessary configuration files
     */
    public function generateAllConfigs()
    {
        echo "========================================\n";
        echo "MySQL Master-Slave Replication Setup\n";
        echo "========================================\n\n";
        
        // Create output directory
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
            echo "✓ Created output directory: {$this->outputDir}\n";
        }
        
        // Generate MySQL configuration files
        $this->generateMasterConfig();
        $this->generateSlaveConfig();
        
        // Generate SQL scripts
        $this->generateReplicationUserSQL();
        $this->generateReplicationSchemaSQL();
        $this->generateWordPressConfigSQL();
        
        // Generate setup instructions
        $this->generateSetupInstructions();
        
        // Generate cron job setup
        $this->generateCronSetup();
        
        // Generate application config
        $this->generateApplicationConfig();
        
        echo "\n========================================\n";
        echo "Configuration Generation Complete!\n";
        echo "========================================\n\n";
        echo "All files have been generated in: {$this->outputDir}\n\n";
        echo "Next steps:\n";
        echo "1. Review the generated files\n";
        echo "2. Follow instructions in: {$this->outputDir}/SETUP_INSTRUCTIONS.md\n";
        echo "3. Run the SQL scripts in order\n";
        echo "4. Copy MySQL configs to {$this->mysqlConfigDir}/\n";
        echo "5. Setup cron jobs using: {$this->outputDir}/setup_cron.sh\n\n";
        
        return $this->generatedFiles;
    }
    
    /**
     * Generate Master MySQL configuration
     */
    private function generateMasterConfig()
    {
        $serverId = rand(1, 100);
        
        $config = <<<EOT
# MySQL Master Configuration
# Generated on: {$this->getTimestamp()}
# For: {$this->masterDB} database

[mysqld]
# Server ID - must be unique across all MySQL instances
server-id = {$serverId}

# Enable binary logging for replication
log-bin = /var/log/mysql/mysql-bin
binlog_format = ROW
binlog_do_db = {$this->masterDB}

# Relay log configuration
relay-log = /var/log/mysql/relay-bin
relay-log-index = /var/log/mysql/relay-bin.index

# Binary log retention (days)
expire_logs_days = 7
max_binlog_size = 100M

# Sync settings for durability
sync_binlog = 1
innodb_flush_log_at_trx_commit = 1

# Performance optimizations for WordPress
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_method = O_DIRECT
max_connections = 200

# Query cache (deprecated in MySQL 8.0+, but useful for 5.7)
# query_cache_type = 1
# query_cache_size = 64M

# Enable GTID for easier replication management (MySQL 5.6+)
gtid_mode = ON
enforce_gtid_consistency = ON

# Replication user privileges
# Run the replication_user_setup.sql to create the user

# Character set
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Error log
log-error = /var/log/mysql/error.log

# Slow query log
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow-query.log
long_query_time = 2

EOT;

        $filename = $this->outputDir . '/master.cnf';
        file_put_contents($filename, $config);
        $this->generatedFiles[] = $filename;
        echo "✓ Generated Master config: $filename\n";
    }
    
    /**
     * Generate Slave MySQL configuration
     */
    private function generateSlaveConfig()
    {
        $serverId = rand(101, 200);
        
        $config = <<<EOT
# MySQL Slave Configuration
# Generated on: {$this->getTimestamp()}
# For: {$this->slaveDB} database

[mysqld]
# Server ID - must be unique and different from master
server-id = {$serverId}

# Enable binary logging on slave for chained replication (optional)
log-bin = /var/log/mysql/mysql-bin-slave
binlog_format = ROW
binlog_do_db = {$this->slaveDB}

# Relay log configuration
relay-log = /var/log/mysql/relay-bin-slave
relay-log-index = /var/log/mysql/relay-bin-slave.index
relay-log-purge = 1

# Read-only mode (prevents writes to slave, except by replication thread)
read_only = 1
super_read_only = 1

# Replication settings
replicate-do-db = {$this->slaveDB}
slave_skip_errors = none

# Binary log retention
expire_logs_days = 7
max_binlog_size = 100M

# Performance optimizations
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_method = O_DIRECT
max_connections = 200

# Enable GTID
gtid_mode = ON
enforce_gtid_consistency = ON
log_slave_updates = ON

# Character set
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Error log
log-error = /var/log/mysql/error-slave.log

# Slow query log
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow-query-slave.log
long_query_time = 2

# Crash-safe replication
master_info_repository = TABLE
relay_log_info_repository = TABLE

EOT;

        $filename = $this->outputDir . '/slave.cnf';
        file_put_contents($filename, $config);
        $this->generatedFiles[] = $filename;
        echo "✓ Generated Slave config: $filename\n";
    }
    
    /**
     * Generate SQL script to create replication user
     */
    private function generateReplicationUserSQL()
    {
        $sql = <<<EOT
-- MySQL Replication User Setup Script
-- Generated on: {$this->getTimestamp()}

-- Create replication user
CREATE USER IF NOT EXISTS '{$this->replicationUser}'@'localhost' 
    IDENTIFIED BY '{$this->replicationPassword}';

-- Grant replication privileges
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* 
    TO '{$this->replicationUser}'@'localhost';

-- Also grant access from 127.0.0.1 for flexibility
CREATE USER IF NOT EXISTS '{$this->replicationUser}'@'127.0.0.1' 
    IDENTIFIED BY '{$this->replicationPassword}';

GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* 
    TO '{$this->replicationUser}'@'127.0.0.1';

-- Flush privileges
FLUSH PRIVILEGES;

-- Display user information
SELECT User, Host FROM mysql.user WHERE User = '{$this->replicationUser}';

-- Display master status (for reference)
SHOW MASTER STATUS;

EOT;

        $filename = $this->outputDir . '/01_replication_user_setup.sql';
        file_put_contents($filename, $sql);
        $this->generatedFiles[] = $filename;
        echo "✓ Generated replication user SQL: $filename\n";
    }
    
    /**
     * Generate tracking/replication schema SQL
     */
    private function generateReplicationSchemaSQL()
    {
        $sql = <<<EOT
-- Replication Tracking Database Schema
-- Generated on: {$this->getTimestamp()}
-- This database tracks replication metadata and sync history

-- Create replication tracking database
CREATE DATABASE IF NOT EXISTS {$this->replicationDB} 
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE {$this->replicationDB};

-- Replication metadata table
-- Tracks last sync times and positions for each table
CREATE TABLE IF NOT EXISTS replication_metadata (
    id INT PRIMARY KEY AUTO_INCREMENT,
    source_db VARCHAR(64) NOT NULL,
    target_db VARCHAR(64) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    last_sync_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_sync_gtid VARCHAR(255),
    last_sync_binlog_file VARCHAR(255),
    last_sync_binlog_position BIGINT,
    rows_synced INT DEFAULT 0,
    sync_status ENUM('active', 'paused', 'error') DEFAULT 'active',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_replication (source_db, target_db, table_name),
    INDEX idx_table_name (table_name),
    INDEX idx_sync_status (sync_status),
    INDEX idx_last_sync_time (last_sync_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sync history table
-- Records each synchronization operation
CREATE TABLE IF NOT EXISTS sync_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sync_id VARCHAR(36) NOT NULL,
    source_db VARCHAR(64) NOT NULL,
    target_db VARCHAR(64) NOT NULL,
    sync_mode ENUM('master-slave', 'master-master', 'custom') NOT NULL,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NULL,
    duration_seconds DECIMAL(10, 2),
    status ENUM('running', 'completed', 'failed') DEFAULT 'running',
    tables_processed INT DEFAULT 0,
    rows_inserted INT DEFAULT 0,
    rows_updated INT DEFAULT 0,
    rows_deleted INT DEFAULT 0,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sync_id (sync_id),
    INDEX idx_status (status),
    INDEX idx_start_time (start_time),
    INDEX idx_source_target (source_db, target_db)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table sync stats
-- Per-table statistics
CREATE TABLE IF NOT EXISTS table_sync_stats (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sync_id VARCHAR(36) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    rows_before INT DEFAULT 0,
    rows_after INT DEFAULT 0,
    rows_inserted INT DEFAULT 0,
    rows_updated INT DEFAULT 0,
    rows_deleted INT DEFAULT 0,
    sync_duration_seconds DECIMAL(10, 2),
    last_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sync_id (sync_id),
    INDEX idx_table_name (table_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replication errors log
-- Detailed error tracking
CREATE TABLE IF NOT EXISTS replication_errors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sync_id VARCHAR(36),
    source_db VARCHAR(64) NOT NULL,
    target_db VARCHAR(64) NOT NULL,
    table_name VARCHAR(64),
    error_type ENUM('connection', 'query', 'constraint', 'timeout', 'other') NOT NULL,
    error_message TEXT NOT NULL,
    error_details JSON,
    resolution_status ENUM('pending', 'investigating', 'resolved', 'ignored') DEFAULT 'pending',
    resolution_notes TEXT,
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    INDEX idx_sync_id (sync_id),
    INDEX idx_error_type (error_type),
    INDEX idx_resolution_status (resolution_status),
    INDEX idx_occurred_at (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replication configuration
-- Stores configuration for different replication pairs
CREATE TABLE IF NOT EXISTS replication_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    config_name VARCHAR(100) NOT NULL UNIQUE,
    source_db VARCHAR(64) NOT NULL,
    target_db VARCHAR(64) NOT NULL,
    sync_mode ENUM('master-slave', 'master-master', 'custom') NOT NULL,
    sync_interval VARCHAR(50) DEFAULT '*/5 * * * *',
    is_active BOOLEAN DEFAULT TRUE,
    tables_to_sync JSON,
    ignore_columns JSON,
    config_options JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_config_name (config_name),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default configuration for WordPress replication
INSERT INTO replication_config (
    config_name, 
    source_db, 
    target_db, 
    sync_mode, 
    sync_interval,
    tables_to_sync,
    config_options
) VALUES (
    'wordpress_master_slave',
    '{$this->masterDB}',
    '{$this->slaveDB}',
    'master-slave',
    '*/5 * * * *',
    JSON_ARRAY('wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta', 'wp_comments', 
               'wp_commentmeta', 'wp_terms', 'wp_term_taxonomy', 'wp_term_relationships', 
               'wp_options'),
    JSON_OBJECT(
        'description', 'WordPress Master-Slave replication for improved read performance',
        'slave_read_only', true,
        'use_gtid', true
    )
) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- Grant permissions to replication user
GRANT SELECT, INSERT, UPDATE, DELETE ON {$this->replicationDB}.* 
    TO '{$this->replicationUser}'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON {$this->replicationDB}.* 
    TO '{$this->replicationUser}'@'127.0.0.1';

FLUSH PRIVILEGES;

-- Display created tables
SHOW TABLES;

EOT;

        $filename = $this->outputDir . '/02_replication_schema_setup.sql';
        file_put_contents($filename, $sql);
        $this->generatedFiles[] = $filename;
        echo "✓ Generated replication schema SQL: $filename\n";
    }
    
    /**
     * Generate WordPress-specific configuration SQL
     */
    private function generateWordPressConfigSQL()
    {
        $sql = <<<EOT
-- WordPress Database Configuration for Replication
-- Generated on: {$this->getTimestamp()}

-- Ensure master database exists
CREATE DATABASE IF NOT EXISTS {$this->masterDB} 
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Ensure slave database exists with same structure
CREATE DATABASE IF NOT EXISTS {$this->slaveDB} 
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grant WordPress user full access to master database
-- Replace 'wordpress_user' and 'wordpress_password' with your actual WordPress database credentials
-- GRANT ALL PRIVILEGES ON {$this->masterDB}.* TO 'wordpress_user'@'localhost';

-- Grant WordPress user READ-ONLY access to slave database
-- This ensures WordPress can read from slave but all writes go to master
-- GRANT SELECT ON {$this->slaveDB}.* TO 'wordpress_user'@'localhost';

-- FLUSH PRIVILEGES;

-- Note: You will need to update your wp-config.php to use the slave for reads
-- See the generated wp-config-replication.php for implementation details

-- Verify databases exist
SHOW DATABASES LIKE 'dst%';

EOT;

        $filename = $this->outputDir . '/03_wordpress_db_setup.sql';
        file_put_contents($filename, $sql);
        $this->generatedFiles[] = $filename;
        echo "✓ Generated WordPress DB setup SQL: $filename\n";
    }
    
    /**
     * Generate cron job setup script
     */
    private function generateCronSetup()
    {
        $appPath = dirname(dirname(__DIR__));
        
        $script = <<<EOT
#!/bin/bash
# MySQL Replication Cron Job Setup
# Generated on: {$this->getTimestamp()}

echo "=========================================="
echo "Setting up MySQL Replication Cron Jobs"
echo "=========================================="
echo ""

# Cron job for replication sync (runs every 5 minutes)
SYNC_CRON="*/5 * * * * cd {$appPath} && php src/sync.php >> /var/log/mysql_replication_sync.log 2>&1"

# Cron job for replication health check (runs every hour)
HEALTH_CRON="0 * * * * cd {$appPath} && php src/Utils/check_replication_health.php >> /var/log/mysql_replication_health.log 2>&1"

# Cron job for log rotation (runs daily at 2am)
ROTATE_CRON="0 2 * * * /usr/sbin/logrotate /etc/logrotate.d/mysql-replication"

echo "The following cron jobs need to be added:"
echo ""
echo "1. Replication Sync (every 5 minutes):"
echo "   \$SYNC_CRON"
echo ""
echo "2. Health Check (every hour):"
echo "   \$HEALTH_CRON"
echo ""
echo "3. Log Rotation (daily at 2am):"
echo "   \$ROTATE_CRON"
echo ""

# Check if running as root or with sudo
if [ "\$EUID" -ne 0 ]; then
    echo "⚠️  Not running as root. Cannot automatically add cron jobs."
    echo ""
    echo "To add these cron jobs manually, run:"
    echo "  crontab -e"
    echo ""
    echo "Then add the following lines:"
    echo "\$SYNC_CRON"
    echo "\$HEALTH_CRON"
    echo "\$ROTATE_CRON"
    echo ""
else
    echo "Attempting to add cron jobs..."
    
    # Add cron jobs to root crontab
    (crontab -l 2>/dev/null | grep -v "mysql_replication_sync.log"; echo "\$SYNC_CRON") | crontab -
    (crontab -l 2>/dev/null | grep -v "mysql_replication_health.log"; echo "\$HEALTH_CRON") | crontab -
    (crontab -l 2>/dev/null | grep -v "mysql-replication"; echo "\$ROTATE_CRON") | crontab -
    
    echo "✓ Cron jobs added successfully!"
    echo ""
    echo "Current crontab:"
    crontab -l | grep -E "(mysql_replication|mysql-replication)"
fi

echo ""
echo "=========================================="
echo "Cron Setup Complete"
echo "=========================================="

EOT;

        $filename = $this->outputDir . '/setup_cron.sh';
        file_put_contents($filename, $script);
        chmod($filename, 0755);
        $this->generatedFiles[] = $filename;
        echo "✓ Generated cron setup script: $filename\n";
    }
    
    /**
     * Generate application configuration file
     */
    private function generateApplicationConfig()
    {
        $config = [
            'mode' => 'master-slave',
            'syncInterval' => '*/5 * * * *',
            'port' => 8080,
            'demoMode' => false,
            'api' => [
                'keys' => [$this->generateSecurePassword(32)]
            ],
            'databases' => [
                'master' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'user' => 'wordpress_user',
                    'password' => 'CHANGE_ME',
                    'database' => $this->masterDB
                ],
                'slave' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'user' => 'wordpress_user',
                    'password' => 'CHANGE_ME',
                    'database' => $this->slaveDB
                ],
                'stats' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'user' => 'wordpress_user',
                    'password' => 'CHANGE_ME',
                    'database' => $this->replicationDB
                ]
            ],
            'replication' => [
                'tables' => [
                    [
                        'name' => 'wp_posts',
                        'ignoreColumns' => [],
                        'primaryKey' => 'ID',
                        'timestampColumn' => 'post_modified'
                    ],
                    [
                        'name' => 'wp_postmeta',
                        'ignoreColumns' => [],
                        'primaryKey' => 'meta_id',
                        'timestampColumn' => 'meta_id'
                    ],
                    [
                        'name' => 'wp_users',
                        'ignoreColumns' => ['user_pass'],
                        'primaryKey' => 'ID',
                        'timestampColumn' => 'user_registered'
                    ],
                    [
                        'name' => 'wp_usermeta',
                        'ignoreColumns' => [],
                        'primaryKey' => 'umeta_id',
                        'timestampColumn' => 'umeta_id'
                    ],
                    [
                        'name' => 'wp_comments',
                        'ignoreColumns' => [],
                        'primaryKey' => 'comment_ID',
                        'timestampColumn' => 'comment_date'
                    ],
                    [
                        'name' => 'wp_commentmeta',
                        'ignoreColumns' => [],
                        'primaryKey' => 'meta_id',
                        'timestampColumn' => 'meta_id'
                    ],
                    [
                        'name' => 'wp_options',
                        'ignoreColumns' => [],
                        'primaryKey' => 'option_id',
                        'timestampColumn' => 'option_id'
                    ]
                ]
            ]
        ];
        
        $filename = $this->outputDir . '/config.wordpress-replication.json';
        file_put_contents($filename, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->generatedFiles[] = $filename;
        echo "✓ Generated application config: $filename\n";
    }
    
    /**
     * Generate setup instructions
     */
    private function generateSetupInstructions()
    {
        $instructions = <<<EOT
# MySQL Master-Slave Replication Setup Instructions
Generated on: {$this->getTimestamp()}

## Overview
This guide will help you set up MySQL Master-Slave replication for your WordPress site on AlmaLinux VPS.
The setup uses two databases on the same server:
- **Master DB**: {$this->masterDB} (handles all writes)
- **Slave DB**: {$this->slaveDB} (handles reads for better performance)
- **Tracking DB**: {$this->replicationDB} (tracks replication metadata)

## Prerequisites
- AlmaLinux VPS with root access
- MySQL 8.0+ installed
- WordPress site running
- Sufficient disk space for database replication
- Backup of your existing databases

## Step 1: Backup Your Current Database
```bash
mysqldump -u root -p {$this->masterDB} > {$this->masterDB}_backup_\$(date +%Y%m%d).sql
```

## Step 2: Create MySQL Log Directory
```bash
sudo mkdir -p /var/log/mysql
sudo chown mysql:mysql /var/log/mysql
sudo chmod 755 /var/log/mysql
```

## Step 3: Install MySQL Configuration Files
```bash
# Copy the generated MySQL configuration files
sudo cp {$this->outputDir}/master.cnf {$this->mysqlConfigDir}/master.cnf
sudo cp {$this->outputDir}/slave.cnf {$this->mysqlConfigDir}/slave.cnf

# Set proper permissions
sudo chmod 644 {$this->mysqlConfigDir}/master.cnf
sudo chmod 644 {$this->mysqlConfigDir}/slave.cnf
```

## Step 4: Restart MySQL
```bash
sudo systemctl restart mysqld
# Or if using mariadb:
# sudo systemctl restart mariadb

# Verify MySQL is running
sudo systemctl status mysqld
```

## Step 5: Run SQL Setup Scripts (in order)
```bash
# 1. Create replication user
mysql -u root -p < {$this->outputDir}/01_replication_user_setup.sql

# 2. Create replication tracking database
mysql -u root -p < {$this->outputDir}/02_replication_schema_setup.sql

# 3. Setup WordPress databases
mysql -u root -p < {$this->outputDir}/03_wordpress_db_setup.sql
```

## Step 6: Initialize Slave Database
If slave database is empty, copy data from master:
```bash
# Create slave database structure
mysqldump -u root -p --no-data {$this->masterDB} | mysql -u root -p {$this->slaveDB}

# Copy initial data
mysqldump -u root -p {$this->masterDB} | mysql -u root -p {$this->slaveDB}
```

## Step 7: Configure Application
```bash
# Copy the application configuration
cp {$this->outputDir}/config.wordpress-replication.json config.json

# Edit config.json with your actual database credentials
nano config.json
```

Update the following in config.json:
- `databases.master.user` and `databases.master.password`
- `databases.slave.user` and `databases.slave.password`
- `databases.stats.user` and `databases.stats.password`

## Step 8: Setup Cron Jobs
```bash
# Make the script executable
chmod +x {$this->outputDir}/setup_cron.sh

# Run the setup script (as root or with sudo)
sudo {$this->outputDir}/setup_cron.sh
```

Or manually add to crontab:
```bash
crontab -e
```

Add these lines:
```
*/5 * * * * cd {dirname(dirname(__DIR__))} && php src/sync.php >> /var/log/mysql_replication_sync.log 2>&1
0 * * * * cd {dirname(dirname(__DIR__))} && php src/Utils/check_replication_health.php >> /var/log/mysql_replication_health.log 2>&1
```

## Step 9: Test Replication
```bash
# Run a manual sync test
cd {dirname(dirname(__DIR__))}
php src/sync.php

# Check sync logs
tail -f /var/log/mysql_replication_sync.log
```

## Step 10: Update WordPress Configuration (Optional)
To leverage the slave database for read operations in WordPress, you can implement a read-write split in your `wp-config.php`.

### Option A: Using HyperDB Plugin
1. Install and configure HyperDB plugin for WordPress
2. Configure it to use master for writes and slave for reads

### Option B: Manual Configuration
Add to `wp-config.php` before "That's all, stop editing!":
```php
// Define slave database for read operations
define('DB_SLAVE_HOST', 'localhost');
define('DB_SLAVE_USER', 'wordpress_user');
define('DB_SLAVE_PASSWORD', 'your_password');
define('DB_SLAVE_NAME', '{$this->slaveDB}');
```

## Monitoring and Maintenance

### Check Replication Status
```bash
# Connect to MySQL
mysql -u root -p

# Check master status
SHOW MASTER STATUS;

# Check slave status
SHOW SLAVE STATUS\G

# Check replication tracking
USE {$this->replicationDB};
SELECT * FROM sync_history ORDER BY start_time DESC LIMIT 10;
SELECT * FROM replication_metadata;
```

### View Sync Logs
```bash
# View main sync log
tail -f /var/log/mysql_replication_sync.log

# View health check log
tail -f /var/log/mysql_replication_health.log

# View MySQL error logs
tail -f /var/log/mysql/error.log
tail -f /var/log/mysql/error-slave.log
```

### Common Issues and Solutions

#### Issue: Slave lag
**Solution**: Check slave_parallel_workers setting, increase if needed.

#### Issue: Replication stopped
**Solution**: 
```sql
STOP SLAVE;
START SLAVE;
SHOW SLAVE STATUS\G
```

#### Issue: Duplicate key errors
**Solution**: These can occur if data was modified on slave. Reset slave:
```sql
STOP SLAVE;
RESET SLAVE;
-- Re-sync data from master
START SLAVE;
```

## Security Considerations

1. **Replication User Password**: 
   - Current password: {$this->replicationPassword}
   - Store this securely and consider changing it

2. **Firewall Rules**:
   ```bash
   # If you later need remote replication, open MySQL port
   sudo firewall-cmd --permanent --add-port=3306/tcp
   sudo firewall-cmd --reload
   ```

3. **File Permissions**: Ensure MySQL config files are not world-readable:
   ```bash
   sudo chmod 640 {$this->mysqlConfigDir}/master.cnf
   sudo chmod 640 {$this->mysqlConfigDir}/slave.cnf
   ```

## Performance Tuning

For optimal WordPress performance with replication:

1. **Increase Buffer Pool**: Adjust `innodb_buffer_pool_size` based on available RAM
2. **Enable Query Cache**: If using MySQL 5.7 (deprecated in 8.0)
3. **Optimize Table Structures**: Add proper indexes on frequently queried columns
4. **Monitor Slow Queries**: Use slow query log to identify bottlenecks

## Backup Strategy

Even with replication, maintain regular backups:
```bash
# Daily backup script (add to cron)
0 3 * * * mysqldump -u root -p{$this->replicationPassword} {$this->masterDB} | gzip > /backup/{$this->masterDB}_\$(date +\%Y\%m\%d).sql.gz
```

## Support and Troubleshooting

For issues or questions:
1. Check the logs in /var/log/mysql/
2. Review replication status in {$this->replicationDB} database
3. Check application logs
4. Consult MySQL documentation: https://dev.mysql.com/doc/refman/8.0/en/replication.html

---
Configuration generated by MySQL Replication Setup Utility
For more information, see the repository README.

EOT;

        $filename = $this->outputDir . '/SETUP_INSTRUCTIONS.md';
        file_put_contents($filename, $instructions);
        $this->generatedFiles[] = $filename;
        echo "✓ Generated setup instructions: $filename\n";
    }
    
    /**
     * Generate a secure random password
     */
    private function generateSecurePassword($length = 16)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        
        return $password;
    }
    
    /**
     * Get current timestamp
     */
    private function getTimestamp()
    {
        return date('Y-m-d H:i:s');
    }
    
    /**
     * Get generated files list
     */
    public function getGeneratedFiles()
    {
        return $this->generatedFiles;
    }
}
