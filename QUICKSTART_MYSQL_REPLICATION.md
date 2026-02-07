# MySQL Replication Setup - Quick Reference

## Quick Start
```bash
# Generate configuration files
php setup_mysql_replication.php

# Generated files will be in: ./mysql_replication_config/
```

## What Gets Generated

### MySQL Configuration Files
- **master.cnf** - Master server configuration with:
  - Binary logging enabled (log-bin)
  - GTID mode enabled for reliable replication
  - Performance optimizations for WordPress
  - Error and slow query logging

- **slave.cnf** - Slave server configuration with:
  - Read-only mode (prevents accidental writes)
  - Relay log configuration
  - Crash-safe replication settings
  - GTID mode enabled

### SQL Scripts (run in order)
1. **01_replication_user_setup.sql**
   - Creates replication user with secure password
   - Grants REPLICATION SLAVE and REPLICATION CLIENT privileges

2. **02_replication_schema_setup.sql**
   - Creates `dstreplication` tracking database
   - Creates 5 tables:
     - `replication_metadata` - Tracks sync status per table
     - `sync_history` - Records all sync operations
     - `table_sync_stats` - Per-table statistics
     - `replication_errors` - Error tracking
     - `replication_config` - Configuration storage
   - Grants permissions to replication user

3. **03_wordpress_db_setup.sql**
   - Creates `dstdb` (master) database
   - Creates `dstdbslave` (slave) database

### Automation
- **setup_cron.sh** - Sets up 3 cron jobs:
  - Replication sync (every 5 minutes)
  - Health check (hourly)
  - Log rotation (daily at 2am)

### Configuration
- **config.wordpress-replication.json** - Application config with:
  - Database connection details for master, slave, and stats
  - WordPress table configuration
  - API keys for secure access

### Documentation
- **SETUP_INSTRUCTIONS.md** - Complete setup guide (6.7KB)

## Databases Created

| Database | Purpose | Access |
|----------|---------|--------|
| `dstdb` | Master (writes) | Full access |
| `dstdbslave` | Slave (reads) | Read-only |
| `dstreplication` | Tracking | Read/Write for app |

## Cron Jobs Created

```bash
# Sync every 5 minutes
*/5 * * * * cd /path/to/app && php src/sync.php >> /var/log/mysql_replication_sync.log 2>&1

# Health check hourly
0 * * * * cd /path/to/app && php src/Utils/check_replication_health.php >> /var/log/mysql_replication_health.log 2>&1

# Log rotation daily at 2am
0 2 * * * /usr/sbin/logrotate /etc/logrotate.d/mysql-replication
```

## Customization Options

```bash
# Custom database names
php setup_mysql_replication.php \
  --master-db=my_db \
  --slave-db=my_db_slave \
  --replication-db=my_replication

# Custom output directory
php setup_mysql_replication.php \
  --output-dir=/path/to/output

# View help
php setup_mysql_replication.php --help
```

## Installation Steps

1. **Generate configs**: `php setup_mysql_replication.php`
2. **Review files**: Check `mysql_replication_config/` directory
3. **Install MySQL configs**: Copy to `/etc/my.cnf.d/`
4. **Restart MySQL**: `sudo systemctl restart mysqld`
5. **Run SQL scripts**: Execute in order (01, 02, 03)
6. **Copy slave data**: `mysqldump dstdb | mysql dstdbslave`
7. **Setup cron**: Run `./mysql_replication_config/setup_cron.sh`
8. **Configure app**: Copy config.wordpress-replication.json to config.json
9. **Test sync**: `php src/sync.php`

## Monitoring

### Check Replication Status
```bash
# Run health check
php src/Utils/check_replication_health.php

# View MySQL status
mysql -u root -p -e "SHOW MASTER STATUS"
mysql -u root -p -e "SHOW SLAVE STATUS\G"

# View tracking data
mysql -u root -p dstreplication -e "SELECT * FROM sync_history ORDER BY start_time DESC LIMIT 10"
```

### View Logs
```bash
# Sync logs
tail -f /var/log/mysql_replication_sync.log

# Health check logs
tail -f /var/log/mysql_replication_health.log

# MySQL error logs
tail -f /var/log/mysql/error.log
tail -f /var/log/mysql/error-slave.log
```

## Troubleshooting

### Replication Not Running
```sql
STOP SLAVE;
START SLAVE;
SHOW SLAVE STATUS\G
```

### Slave Lagging
- Check `Seconds_Behind_Master` in SHOW SLAVE STATUS
- Increase `slave_parallel_workers` if needed

### Permission Errors
```bash
# Verify replication user permissions
mysql -u root -p -e "SHOW GRANTS FOR 'repl_user'@'localhost'"
```

## Security Notes

- Generated passwords are stored in SQL scripts - change them!
- Slave is configured as read-only by default
- MySQL config files should have 640 permissions
- Store replication password securely

## Performance Tuning

Key settings in generated configs:
- `innodb_buffer_pool_size` = 1G (adjust based on RAM)
- `innodb_log_file_size` = 256M
- `max_connections` = 200
- `binlog_format` = ROW (safest for replication)

## Files Summary

Total: 8 files (~20KB)
- 2 MySQL configs (master.cnf, slave.cnf)
- 3 SQL scripts (setup user, schema, databases)
- 1 cron setup script (setup_cron.sh)
- 1 app config (config.wordpress-replication.json)
- 1 documentation (SETUP_INSTRUCTIONS.md)

## Next Steps

After setup:
1. Monitor logs for first 24 hours
2. Test read/write split in WordPress
3. Verify data consistency between master and slave
4. Set up automated backups
5. Configure WordPress to use slave for reads

---
For detailed instructions, see: mysql_replication_config/SETUP_INSTRUCTIONS.md
