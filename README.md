# MySQL Database Replication Application

A standalone PHP application for replicating data between MySQL databases with support for both Master-Slave and Master-Master replication modes.

## Features

- **Flexible Replication Modes**: Support for Master-Slave and Master-Master replication
- **Configurable Sync**: Define which tables to replicate and which columns to ignore
- **REST API**: Programmatic access to replication status and manual sync triggers
- **Real-time Dashboard**: Web UI with live status updates and statistics
- **Automated Syncing**: Periodic synchronization via cron jobs
- **Error Tracking**: Comprehensive logging and error reporting

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
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
        "primaryKey": "id"                           // Primary key for updates
      }
    ]
  }
}
```

### Replication Modes

**Master-Slave**: Data flows from master to slave (one-way sync)
- All changes from master are replicated to slave
- Slave database is kept in sync with master

**Master-Master**: Bidirectional sync between databases
- Changes from master are synced to slave
- New records from slave are synced back to master
- Existing master records are not overwritten by slave

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
│   │   └── ConfigLoader.php       # Configuration management
│   ├── Database/
│   │   └── DatabaseManager.php    # Database connection handler
│   ├── Replication/
│   │   └── ReplicationEngine.php  # Core replication logic
│   ├── Api/
│   │   ├── Router.php             # API routing
│   │   └── ApiController.php      # API endpoints
│   └── sync.php                   # CLI sync script
├── public/
│   ├── index.php                  # Application entry point
│   ├── index.html                 # Dashboard UI
│   ├── css/
│   │   └── style.css              # Dashboard styles
│   └── js/
│       └── app.js                 # Dashboard JavaScript
├── config.json                    # Your configuration
├── config.example.json            # Example configuration
└── composer.json                  # PHP dependencies

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

## License

ISC

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.