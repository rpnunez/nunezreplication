# MySQL Database Replication Application

A standalone PHP application for replicating data between MySQL databases with support for both Master-Slave and Master-Master replication modes.

## Features

- **Flexible Replication Modes**: Support for Master-Slave and Master-Master replication
- **Timestamp-Based Tracking**: Track row modifications and last sync times for intelligent conflict resolution
- **Update Detection**: Automatically detect and replicate row-level updates using timestamp comparison
- **Delete Tracking**: Handle deletions with metadata tracking to ensure consistency
- **Configurable Sync**: Define which tables to replicate and which columns to ignore
- **REST API**: Programmatic access to replication status and manual sync triggers
- **Real-time Dashboard**: Web UI with live status updates and statistics
- **Automated Syncing**: Periodic synchronization via cron jobs
- **Error Tracking**: Comprehensive logging and error reporting

## Requirements

- PHP 7.4 or higher
- MySQL 8.0 or higher (MySQL 5.7+ may work but 8.0 is tested and recommended)
- PHP Extensions: PDO, PDO_MySQL, JSON
- Composer (for dependency management)

## Installation

1. Clone the repository:
```bash
git clone https://github.com/rpnunez/nunezreplication.git
cd nunezreplication
```

2. Install dependencies:
```bash
composer install
```

3. Create your configuration file:
```bash
cp config.example.json config.json
```

4. Edit `config.json` with your database credentials and replication settings.

## Configuration

The application uses a JSON configuration file. Here's what each setting means:

```json
{
  "mode": "master-slave",           // Replication mode: "master-slave" or "master-master"
  "syncInterval": "*/5 * * * *",    // Cron expression for sync schedule
  "port": 8080,                      // Web server port (for reference)
  "databases": {
    "master": {
      "host": "localhost",
      "port": 3306,
      "user": "root",
      "password": "password",
      "database": "master_db"
    },
    "slave": {
      "host": "localhost",
      "port": 3307,
      "user": "root",
      "password": "password",
      "database": "slave_db"
    }
  },
  "replication": {
    "tables": [
      {
        "name": "users",                              // Table name
        "ignoreColumns": ["password_hash"],          // Columns to exclude
        "primaryKey": "id",                          // Primary key for updates
        "timestampColumn": "updated_at"              // Column used for update tracking
      }
    ]
  }
}
```

### Configuration Options

**timestampColumn** (default: `updated_at`): Specifies which column to use for tracking row modifications. This column should be a TIMESTAMP or DATETIME that updates automatically when rows are modified (e.g., `updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`).

**Tracking Features** (always enabled):
- Automatic metadata table creation (`_replication_metadata`)
- Timestamp-based conflict resolution
- Deletion tracking and propagation
- Last sync tracking per row

### Replication Modes

**Master-Slave**: Data flows from master to slave (one-way sync)
- All changes from master are replicated to slave
- Updates are tracked using timestamps to avoid unnecessary writes
- Deletions in master are propagated to slave
- Slave database is kept in sync with master

**Master-Master**: Bidirectional sync between databases
- Changes from master are synced to slave
- Changes from slave are synced back to master
- **With timestamp columns configured**: Last-write-wins conflict resolution
  - Updates are compared by timestamp
  - Most recent change wins regardless of origin
- **Without timestamp columns**: Master takes precedence
  - Only new records from slave are synced to master
  - Existing master records are not overwritten by slave

### How Updates and Deletes Work

**Update Tracking**:
- Each table should have an `updated_at` or similar timestamp column
- During sync, timestamps are compared to determine if an update is needed
- Only rows with newer timestamps are replicated, reducing unnecessary writes
- Metadata table tracks last sync time for each row

**Delete Tracking**:
- A `_replication_metadata` table is created automatically
- Rows deleted from source are detected by comparing primary keys
- Deletions are propagated to target database
- Delete metadata is maintained for audit purposes

**Metadata Table**:
```sql
CREATE TABLE _replication_metadata (
    id INT PRIMARY KEY AUTO_INCREMENT,
    table_name VARCHAR(255) NOT NULL,
    primary_key_value VARCHAR(255) NOT NULL,
    last_sync_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted BOOLEAN DEFAULT FALSE,
    deleted_at TIMESTAMP NULL
);
```

## Usage

### Running the Web Application

Using PHP built-in server:
```bash
php -S localhost:8080 -t public public/index.php
```

Then open your browser to: `http://localhost:8080`

### Setting Up Automated Sync

Add to your crontab (edit with `crontab -e`):
```bash
# Sync every 5 minutes
*/5 * * * * php /path/to/nunezreplication/src/sync.php >> /var/log/replication.log 2>&1
```

### Manual Sync

Run sync manually from command line:
```bash
php src/sync.php
```

## API Endpoints

### GET /api/status
Returns current replication status and statistics.

**Response:**
```json
{
  "mode": "master-slave",
  "status": "running",
  "stats": {
    "lastSync": "2026-02-06 22:00:00",
    "totalSyncs": 100,
    "successfulSyncs": 98,
    "failedSyncs": 2,
    "lastError": null,
    "tablesProcessed": {
      "users": {
        "rows": 150,
        "timestamp": "2026-02-06 22:00:00"
      }
    }
  }
}
```

### GET /api/config
Returns current configuration (without passwords).

### POST /api/sync
Triggers a manual synchronization.

**Response:**
```json
{
  "success": true,
  "duration": 2.34,
  "stats": { ... }
}
```

## Web Dashboard

The web dashboard provides:
- Real-time replication status
- Sync statistics and success rate
- Configuration overview
- Table replication status
- Manual sync trigger button
- Auto-refresh every 5 seconds

## Project Structure

```
nunezreplication/
├── src/
│   ├── Config/
│   │   └── ConfigLoader.php           # Configuration management
│   ├── Database/
│   │   └── DatabaseManager.php        # Database connection handler
│   ├── Replication/
│   │   ├── ReplicationEngine.php      # Core replication logic
│   │   └── ReplicationMetadata.php    # Metadata tracking system
│   ├── Api/
│   │   ├── Router.php                 # API routing
│   │   └── ApiController.php          # API endpoints
│   └── sync.php                       # CLI sync script
├── public/
│   ├── index.php                      # Application entry point
│   ├── index.html                     # Dashboard UI
│   ├── css/
│   │   └── style.css                  # Dashboard styles
│   └── js/
│       └── app.js                     # Dashboard JavaScript
├── tests/
│   ├── test_replication.php                    # Basic replication tests
│   ├── test_update_delete_replication.php      # Update/delete tests
│   └── banking_schema.sql                      # Test schema
├── config.json                        # Your configuration
├── config.example.json                # Example configuration
└── composer.json                      # PHP dependencies

```

## Security Considerations

- Keep `config.json` secure and never commit it to version control
- Use strong database passwords
- Restrict database user permissions to only necessary operations
- Use HTTPS in production environments
- Consider using environment variables for sensitive credentials

## Troubleshooting

**Connection errors:**
- Verify database credentials in config.json
- Ensure databases are accessible from the application server
- Check firewall rules and network connectivity

**Sync failures:**
- Check that tables exist in both databases
- Verify primary keys are configured correctly
- Review application logs for specific error messages

**Performance issues:**
- Consider adjusting sync interval for large datasets
- Add database indexes on primary keys
- Monitor database server resources

## Testing

The project includes automated tests that run via GitHub Actions. The test suite validates both Master-Slave and Master-Master replication modes using a banking application schema.

### Running Tests Locally

To run the replication tests locally, you'll need:
- MySQL 8.0 or higher (with multiple instances on different ports)
- PHP 7.4 or higher
- Composer

1. Set up two MySQL instances (e.g., on ports 3306 and 3307)
2. Initialize the databases with the banking schema:
   ```bash
   mysql -h 127.0.0.1 -P 3306 -u root -p database1 < tests/banking_schema.sql
   mysql -h 127.0.0.1 -P 3307 -u root -p database2 < tests/banking_schema.sql
   ```
3. Create a test configuration file (e.g., `config.test.json`)
4. Run the sync:
   ```bash
   php src/sync.php config.test.json
   ```
5. Verify basic replication:
   ```bash
   php tests/test_replication.php config.test.json
   ```
6. Test update and delete functionality:
   ```bash
   php tests/test_update_delete_replication.php config.test.json
   ```

### Test Suites

**test_replication.php**: Basic replication tests
- Validates row counts match between master and slave
- Checks data integrity for specific records
- Tests both master-slave and master-master modes

**test_update_delete_replication.php**: Advanced tracking tests
- **Update Replication Test**: Verifies that row updates are detected and replicated
- **Delete Replication Test**: Confirms deletions are properly tracked and propagated
- **Timestamp Conflict Resolution**: Tests last-write-wins logic for master-master mode
- **Metadata Tracking Test**: Validates that metadata tables are created and maintained

### GitHub Actions Workflow

The CI/CD pipeline automatically tests:
- **Master-Slave Replication**: Verifies one-way data synchronization
- **Master-Master Replication**: Validates bidirectional sync with conflict resolution
- **Update Tracking**: Tests row-level update detection and replication
- **Delete Tracking**: Ensures deletions are properly handled
- **API Endpoints**: Tests status, config, and manual sync triggers
- **Data Integrity**: Ensures data consistency across databases

The workflow uses a banking application schema with customers, accounts, and transactions tables to simulate real-world replication scenarios.

## License

ISC

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.