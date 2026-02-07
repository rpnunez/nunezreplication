# Copilot Instructions for NunezReplication

This document provides guidance for AI coding agents working on this repository.

## Project Overview

**NunezReplication** is a standalone PHP application for replicating data between MySQL databases with support for:
- Master-Slave and Master-Master replication modes
- Multi-environment synchronization via REST API
- Transaction support with automatic rollback
- Timestamp-based conflict resolution
- Comprehensive statistics tracking and web dashboard

## Repository Structure

```
.
├── src/                          # Source code (PSR-4 autoloaded)
│   ├── Api/                      # API endpoints and client
│   │   ├── ApiController.php     # Main API controller (582 lines)
│   │   ├── ApiClient.php         # HTTP client for remote sync
│   │   └── Router.php            # Simple URL router
│   ├── Config/                   # Configuration management
│   │   ├── ConfigLoader.php      # Loads config.json files
│   │   └── ConfigManager.php     # Web UI config editor (467 lines)
│   ├── Data/                     # Test data generation
│   │   ├── DataGenerator.php     # Generates realistic test data
│   │   └── DataManagementService.php
│   ├── Database/                 # Database connections
│   │   └── DatabaseManager.php   # PDO wrapper with connection pooling
│   ├── Replication/              # Core replication logic
│   │   ├── ReplicationEngine.php # Main sync engine (718 lines)
│   │   ├── ReplicationMetadata.php
│   │   └── ReplicationStatsDB.php # Statistics database manager
│   ├── Sync/                     # Multi-environment sync
│   │   └── MultiEnvironmentSync.php
│   ├── sync.php                  # CLI: Local database sync
│   └── sync_multi.php           # CLI: Multi-environment sync
├── public/                       # Web dashboard
│   ├── index.php                 # Application entry point
│   ├── index.html                # Dashboard UI
│   ├── js/app.js                 # Frontend JavaScript
│   └── css/style.css             # Styles
├── tests/                        # Test files
│   ├── banking_schema.sql        # Idempotent test schema
│   └── test_*.php               # Integration tests
├── .github/workflows/            # CI/CD
│   └── test-replication.yml      # Main test workflow (929 lines)
├── config.example.json           # Example configuration
├── composer.json                 # Dependencies (PHP 7.4+)
├── setup.sh                      # Quick setup script
└── README.md                     # Comprehensive documentation
```

## Key Architecture Concepts

### 1. Replication Engine (`src/Replication/ReplicationEngine.php`)

The core component that handles all sync operations:
- **Entry point**: `sync()` method - orchestrates entire sync process
- **Master-Slave**: One-way sync from master → slave
- **Master-Master**: Bidirectional sync with last-write-wins conflict resolution
- **Transaction support**: All operations wrapped in DB transactions, automatic rollback on errors
- **Metadata tracking**: `_replication_metadata` table created automatically in each database

### 2. Multi-Environment Sync (`src/Sync/MultiEnvironmentSync.php`)

Synchronizes data across physically separate environments via REST API:
- **Sync modes**: `push`, `pull`, or `bidirectional`
- **API authentication**: API keys in `X-API-Key` header
- **Fail-fast**: Local sync must succeed before remote sync (see `sync_multi.php:41-54`)
- **Current limitation**: Full sync only (incremental sync not yet implemented due to cursor issues)

### 3. Statistics Database (Optional)

Dedicated MySQL database for storing replication metrics:
- **Configuration**: `databases.stats` in config.json
- **Backward compatible**: Works without stats DB (in-memory only)
- **Tables**: `sync_history`, `table_sync_stats`, `replication_metadata`, `operation_log`
- **API endpoints**: `/api/stats/history`, `/api/stats/table`, `/api/stats/errors`

### 4. Web Dashboard

Real-time monitoring interface:
- **Entry point**: `public/index.php`
- **Auto-refresh**: 5-second polling interval
- **Features**: Config editor, data management, sync history, statistics visualization
- **Demo mode**: Works without database connections (`demoMode: true` in config)

## Development Workflows

### Installation & Setup

```bash
# Install dependencies
composer install

# Create configuration from template
cp config.example.json config.json
# Edit config.json with your database credentials

# Quick setup script
./setup.sh

# Run web dashboard
php -S localhost:8080 -t public public/index.php
# Open http://localhost:8080
```

### Running Tests

**No automated test runner** - tests are PHP scripts executed directly:

```bash
# Run specific test
php tests/test_replication.php [config-file]
php tests/test_integration.php
php tests/test_stats_db.php
php tests/test_transactions.php

# Test schema (idempotent - safe to run multiple times)
mysql -h host -u user -p database < tests/banking_schema.sql
```

### CI/CD Workflow

GitHub Actions workflow (`.github/workflows/test-replication.yml`):
- **3 jobs**: master-slave, master-master, multi-environment tests
- **MySQL services**: Runs MySQL 8.0 containers on ports 3306/3307/3308
- **Wait pattern**: 30 retries with 1-2s sleep for service readiness
- **HTTP validation**: Uses `curl --fail` for endpoint testing
- **Test data**: `tests/banking_schema.sql` (5 customers, 8 accounts, 10 transactions)

### Running Sync Operations

```bash
# Local database sync (master-slave or master-master)
php src/sync.php [optional-config-file]

# Multi-environment sync (syncs local then remote environments)
php src/sync_multi.php [optional-config-file]

# With custom config
php src/sync.php config.production.json
```

### Configuration Files

- **Pattern**: `config*.json` (e.g., `config.json`, `config.production.json`)
- **Security**: Must match `/^config[a-zA-Z0-9]*([._-]?[a-zA-Z0-9]+)*\.json$/`
- **Backups**: Auto-created on save, keeps last 5 only (see `ConfigManager.php:384-400`)
- **Gitignore**: All config files ignored except `config.example.json`

## Coding Conventions

### PHP Code Style

1. **Namespace structure**: PSR-4 autoloading
   ```php
   namespace NunezReplication\Replication;
   use NunezReplication\Database\DatabaseManager;
   ```

2. **Visibility modifiers**: Always explicit (`private`, `protected`, `public`)

3. **Error handling**: Try-catch blocks with specific exception handling
   ```php
   try {
       // operation
   } catch (\Exception $e) {
       // cleanup and logging
   }
   ```

4. **No comments by default**: Code is self-documenting except for complex algorithms

5. **SQL identifier validation**: Always validate table/column names (see `validateIdentifier()`)
   ```php
   if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
       throw new \Exception("Invalid identifier");
   }
   ```

### Database Patterns

1. **Foreign key handling**: DataGenerator determines insertion order based on FK dependencies

2. **Idempotent schemas**: Test schemas use `CREATE TABLE IF NOT EXISTS` and `TRUNCATE` before inserts

3. **Timestamp columns**: Use `updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

4. **Primary keys**: Always `id INT PRIMARY KEY AUTO_INCREMENT`

5. **Character set**: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`

### Frontend (JavaScript)

1. **XSS Prevention**: ALWAYS use DOM manipulation, NEVER innerHTML for dynamic content
   ```javascript
   // ✓ CORRECT
   element.textContent = userInput;
   
   // ✗ WRONG - XSS vulnerability
   element.innerHTML = userInput;
   ```

2. **Modal system**: Custom modals with keyboard accessibility (Escape key closes)
   - Never use `alert()` or `prompt()` - breaks UI consistency

3. **API calls**: Async/await with proper error handling

4. **Auto-refresh**: Use 5-second interval, clear timer on errors

## Security Practices

### API Authentication

- **Timing-safe comparison**: Use `hash_equals()` for API key validation (prevents timing attacks)
  ```php
  if (hash_equals($validKey, $apiKey)) { ... }
  ```

- **Case-insensitive headers**: Use `array_change_key_case()` for header lookup

- **All sensitive endpoints**: Stats API, config editor, data management require authentication

### Input Validation

- **Config filenames**: Must start with 'config' and match security pattern
- **SQL identifiers**: Validated with regex before use in queries
- **No raw SQL injection**: Use parameterized queries via PDO

### XSS Prevention

- **Server-side**: Data comes from database, no user HTML input
- **Client-side**: Use `textContent` for DOM updates, especially error messages

## Common Tasks & Patterns

### Adding a New API Endpoint

1. Add route in `public/index.php`:
   ```php
   if ($router->match('GET', '/api/new-endpoint')) {
       echo json_encode($apiController->newEndpoint());
       exit;
   }
   ```

2. Add method in `ApiController.php`:
   ```php
   public function newEndpoint() {
       $this->authenticateRequest(); // If auth required
       // Implementation
       return ['success' => true, 'data' => $result];
   }
   ```

3. Add frontend call in `public/js/app.js`:
   ```javascript
   async function fetchNewData() {
       const response = await fetch('/api/new-endpoint');
       const data = await response.json();
       // Update UI with textContent
   }
   ```

### Adding a Table to Replication

Add to `config.json`:
```json
{
  "replication": {
    "tables": [
      {
        "name": "new_table",
        "ignoreColumns": ["sensitive_field"],
        "primaryKey": "id",
        "timestampColumn": "updated_at"
      }
    ]
  }
}
```

### Debugging Replication Issues

1. Check metadata table: `SELECT * FROM _replication_metadata WHERE table_name = 'tablename';`
2. Check stats history: `GET /api/stats/history?limit=20`
3. Check error logs: `GET /api/stats/errors?limit=50`
4. Run sync with output: `php src/sync.php 2>&1 | tee sync.log`

### Writing Tests

1. Use existing MySQL services in tests (see `test_replication.php` pattern)
2. Load `banking_schema.sql` for consistent test data
3. Test both success and failure cases
4. Clean up test data in teardown

## Important Gotchas & Pitfalls

### 1. Config File Patterns
- ❌ DON'T name files like `my-config.json` - won't load
- ✅ DO use pattern: `config[name].json` or `config.[name].json`

### 2. Multi-Environment Sync
- ❌ DON'T rely on incremental sync - it's full sync only currently
- ✅ DO ensure local sync succeeds before remote sync
- Note: `metadata.last_sync` uses `NOW()` instead of table cursors (see `MultiEnvironmentSync.php:105-125`)

### 3. Stats Database
- Optional feature - check existence before operations
- ReplicationEngine logs "No stats database configured" when absent
- System works fully without it (backward compatible)

### 4. Frontend Modals
- ❌ DON'T use `alert()` / `confirm()` / `prompt()`
- ✅ DO use the custom modal system (see `public/js/app.js:795-802`)

### 5. Transaction Rollback
- All replication operations in transactions
- ❌ DON'T catch exceptions without rollback
- ✅ DO rely on automatic rollback in ReplicationEngine

### 6. Data Updates
- Only modify safe columns (names, descriptions, dates, balances)
- ❌ DON'T change primary keys, foreign keys, or `created_at` timestamps
- Pattern ensures referential integrity (see `DataGenerator.php:270-340`)

### 7. Database Target in Multi-Master
- `pushDataToLocal()` writes to 'master' database (not 'slave')
- Always includes connection verification before transaction
- See `ReplicationEngine.php:460-520`

## Memory Store Guidelines

Use `store_memory` when you discover:
- Architectural decisions not obvious from single file
- Naming conventions used across multiple modules
- Security patterns that must be maintained
- Complex workflows involving multiple components
- Build/test commands that work (after verification)

Examples:
- "Use hash_equals() for API key comparison to prevent timing attacks"
- "Config files must match /^config[a-zA-Z0-9]*([._-]?[a-zA-Z0-9]+)*\.json$/ pattern"
- "Dashboard uses textContent (never innerHTML) to prevent XSS"

## Additional Resources

- `README.md` - Comprehensive feature documentation
- `MULTI_ENV_GUIDE.md` - Multi-environment setup guide
- `ROADMAP.md` - Future enhancements and missing features
- `ENHANCEMENT_SUMMARY.md` - Recent feature additions
- `config.example.json` - Complete configuration reference

## Quick Command Reference

```bash
# Development
composer install                              # Install dependencies
./setup.sh                                    # Quick setup
php -S localhost:8080 -t public public/index.php  # Run dashboard

# Testing
php tests/test_replication.php               # Test replication
mysql ... < tests/banking_schema.sql         # Load test schema

# Sync operations
php src/sync.php                             # Local sync
php src/sync.php config.production.json      # Sync with specific config
php src/sync_multi.php                       # Multi-environment sync

# Cron setup (automated sync)
*/5 * * * * php /path/to/src/sync.php >> /var/log/replication.log 2>&1
*/15 * * * * php /path/to/src/sync_multi.php >> /var/log/multi-replication.log 2>&1
```

## Notes on This Repository

- **PHP version**: 7.4+ required, 8.0+ recommended
- **MySQL version**: 8.0 tested and recommended (5.7+ may work)
- **No build step**: Pure PHP, no compilation needed
- **No linting tools**: No PHPStan, PHPCS, or Psalm configured
- **Manual testing**: Run test PHP scripts directly, no PHPUnit
- **Self-contained**: No external dependencies beyond composer packages
