# Multi-Environment Synchronization Guide

## Overview

This guide explains how to set up and use the multi-environment synchronization features of the NunezReplication engine. This allows you to synchronize data across multiple independent environments, each with their own IP address/DNS.

## Use Cases

- **Geographic Distribution**: Sync data across multiple data centers
- **Environment Tiers**: Keep staging and production in sync
- **Hybrid Cloud**: Synchronize between on-premise and cloud databases
- **Disaster Recovery**: Maintain replicas across different locations
- **Multi-Tenant**: Sync data between isolated tenant databases

## Architecture

The multi-environment sync works as follows:

```
┌─────────────────┐      ┌─────────────────┐      ┌─────────────────┐
│  Environment A  │      │  Environment B  │      │  Environment C  │
│                 │      │                 │      │                 │
│  ┌───────────┐  │      │  ┌───────────┐  │      │  ┌───────────┐  │
│  │  Master   │  │      │  │  Master   │  │      │  │  Master   │  │
│  │    DB     │  │      │  │    DB     │  │      │  │    DB     │  │
│  └─────┬─────┘  │      │  └─────┬─────┘  │      │  └─────┬─────┘  │
│        │        │      │        │        │      │        │        │
│  ┌─────▼─────┐  │      │  ┌─────▼─────┐  │      │  ┌─────▼─────┐  │
│  │   Slave   │  │      │  │   Slave   │  │      │  │   Slave   │  │
│  │    DB     │  │      │  │    DB     │  │      │  │    DB     │  │
│  └─────┬─────┘  │      │  └─────┬─────┘  │      │  └─────┬─────┘  │
│        │        │      │        │        │      │        │        │
│  ┌─────▼─────┐  │      │  ┌─────▼─────┐  │      │  ┌─────▼─────┐  │
│  │   API     │◄─┼──────┼─►│   API     │◄─┼──────┼─►│   API     │  │
│  │ Endpoint  │  │      │  │ Endpoint  │  │      │  │ Endpoint  │  │
│  └───────────┘  │      │  └───────────┘  │      │  └───────────┘  │
└─────────────────┘      └─────────────────┘      └─────────────────┘
         │                        │                        │
         └────────────────────────┴────────────────────────┘
                    API-based Synchronization
```

## Configuration

### 1. Configure API Keys

Add API keys to your `config.json` file:

```json
{
  "api": {
    "keys": [
      "your-secret-api-key-1",
      "your-secret-api-key-2"
    ]
  }
}
```

**Important**: Keep API keys secure and rotate them regularly.

### 2. Configure Remote Environments

Add remote environment configurations:

```json
{
  "remoteEnvironments": {
    "production": {
      "url": "https://prod-server.example.com",
      "apiKey": "prod-api-key",
      "syncMode": "bidirectional",
      "timeout": 30
    },
    "staging": {
      "url": "https://staging-server.example.com",
      "apiKey": "staging-api-key",
      "syncMode": "pull",
      "timeout": 30
    },
    "dr-site": {
      "url": "https://dr-server.example.com",
      "apiKey": "dr-api-key",
      "syncMode": "push",
      "timeout": 30
    }
  }
}
```

### 3. Sync Modes

Choose the appropriate sync mode for each environment:

- **`bidirectional`**: Both push and pull data (default)
  - Use when: Both environments need to share changes
  - Example: Production ↔ Disaster Recovery

- **`push`**: Only push data to remote
  - Use when: Remote is read-only or should only receive updates
  - Example: Production → Analytics DB

- **`pull`**: Only pull data from remote
  - Use when: Local should only receive updates from remote
  - Example: Staging ← Production

## Usage

### Manual Sync

Sync with all configured remote environments:

```bash
php src/sync_multi.php
```

Sync with specific configuration:

```bash
php src/sync_multi.php config.production.json
```

### Automated Sync

Add to crontab for periodic synchronization:

```bash
# Sync every 15 minutes
*/15 * * * * php /path/to/nunezreplication/src/sync_multi.php >> /var/log/multi-replication.log 2>&1
```

### Sync Process

The multi-environment sync performs these steps:

1. **Local Sync**: First syncs master-slave or master-master locally
2. **Remote Sync**: Then syncs with each configured remote environment
3. **Per-Table Sync**: For each table in configuration:
   - **Push Mode**: Sends local changes to remote
   - **Pull Mode**: Receives remote changes to local
   - **Bidirectional**: Both push and pull

## API Endpoints

### For Remote Environments to Call

#### POST /api/push
Receive data from a remote environment.

**Headers:**
```
X-API-Key: your-api-key
Content-Type: application/json
```

**Body:**
```json
{
  "table": "users",
  "data": [
    {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "updated_at": "2026-02-07 10:00:00"
    }
  ]
}
```

#### GET /api/pull
Provide data to a remote environment.

**Headers:**
```
X-API-Key: your-api-key
```

**Parameters:**
- `table`: Table name (required)
- `since`: Timestamp for incremental sync (optional)

**Example:**
```
GET /api/pull?table=users&since=2026-02-07%2010:00:00
```

#### GET /api/metadata
Get replication metadata for a table.

**Headers:**
```
X-API-Key: your-api-key
```

**Parameters:**
- `table`: Table name (required)

## Transaction Support

All replication operations are wrapped in database transactions:

### Benefits

1. **Atomicity**: Either all changes succeed or none are applied
2. **Consistency**: Database remains in consistent state
3. **Rollback on Error**: Automatic rollback if any operation fails
4. **Per-Table Isolation**: Each table sync is a separate transaction

### How It Works

```php
// For Master-Slave sync
foreach ($tables as $table) {
    $dbManager->beginTransaction('slave');
    try {
        // Perform inserts, updates, deletes
        $dbManager->commit('slave');
    } catch (Exception $e) {
        $dbManager->rollback('slave');
        throw $e;
    }
}

// For Master-Master sync
foreach ($tables as $table) {
    $dbManager->beginTransaction('master');
    $dbManager->beginTransaction('slave');
    try {
        // Perform bidirectional sync
        $dbManager->commit('master');
        $dbManager->commit('slave');
    } catch (Exception $e) {
        $dbManager->rollback('master');
        $dbManager->rollback('slave');
        throw $e;
    }
}
```

## Security Best Practices

### 1. Use HTTPS

Always use HTTPS for API communication in production:

```json
{
  "remoteEnvironments": {
    "production": {
      "url": "https://prod-server.example.com",  // HTTPS!
      "apiKey": "prod-api-key"
    }
  }
}
```

### 2. Secure API Keys

- Generate strong, random API keys (min 32 characters)
- Store keys securely (environment variables, secret managers)
- Rotate keys regularly
- Use different keys for each environment
- Never commit keys to version control

### 3. Network Security

- Use VPN or private networks for database connections
- Implement IP whitelisting
- Use firewalls to restrict access
- Monitor API access logs

### 4. Database Security

- Use least-privilege database accounts
- Enable SSL/TLS for MySQL connections
- Regularly audit database permissions
- Monitor transaction logs

## Monitoring and Troubleshooting

### Check Remote Environment Status

```bash
# View status of all remote environments
php -r "
require 'vendor/autoload.php';
use NunezReplication\Config\ConfigLoader;
use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;
use NunezReplication\Sync\MultiEnvironmentSync;

\$config = (new ConfigLoader())->load();
\$dbManager = new DatabaseManager();
\$dbManager->connect('master', \$config['databases']['master']);
\$engine = new ReplicationEngine(\$dbManager, \$config);
\$multiSync = new MultiEnvironmentSync(\$engine, \$config);

print_r(\$multiSync->getRemoteStatuses());
"
```

### Common Issues

#### Connection Timeout

**Problem**: Remote environment not responding

**Solutions:**
- Check network connectivity
- Verify URL is correct
- Increase timeout in configuration
- Check firewall rules

#### Authentication Failed

**Problem**: 401 Unauthorized response

**Solutions:**
- Verify API key is correct
- Check API key is configured on remote
- Ensure API key is in X-API-Key header

#### Sync Conflicts

**Problem**: Data conflicts between environments

**Solutions:**
- Review timestamp columns configuration
- Check last-write-wins behavior
- Consider sync modes (push/pull/bidirectional)
- Review conflict resolution strategy

## Example Setup: 3-Environment Architecture

### Scenario

- **Production**: Main database (US East)
- **Staging**: Testing environment (US West)  
- **DR**: Disaster recovery (EU)

### Configuration

**Production (config.production.json):**
```json
{
  "mode": "master-slave",
  "api": {
    "keys": ["prod-key-123"]
  },
  "remoteEnvironments": {
    "staging": {
      "url": "https://staging.example.com",
      "apiKey": "staging-key-456",
      "syncMode": "push",
      "timeout": 30
    },
    "dr": {
      "url": "https://dr.example.com",
      "apiKey": "dr-key-789",
      "syncMode": "bidirectional",
      "timeout": 60
    }
  }
}
```

**Staging (config.staging.json):**
```json
{
  "mode": "master-slave",
  "api": {
    "keys": ["staging-key-456"]
  },
  "remoteEnvironments": {
    "production": {
      "url": "https://prod.example.com",
      "apiKey": "prod-key-123",
      "syncMode": "pull",
      "timeout": 30
    }
  }
}
```

**DR (config.dr.json):**
```json
{
  "mode": "master-slave",
  "api": {
    "keys": ["dr-key-789"]
  },
  "remoteEnvironments": {
    "production": {
      "url": "https://prod.example.com",
      "apiKey": "prod-key-123",
      "syncMode": "bidirectional",
      "timeout": 60
    }
  }
}
```

### Cron Setup

**Production:**
```bash
# Sync local every 5 min, push to staging every 15 min, sync with DR every 30 min
*/5 * * * * php /app/src/sync.php config.production.json
*/15 * * * * php /app/src/sync_multi.php config.production.json
```

**Staging:**
```bash
# Pull from production every 30 minutes
*/30 * * * * php /app/src/sync_multi.php config.staging.json
```

**DR:**
```bash
# Bidirectional sync with production every 10 minutes
*/10 * * * * php /app/src/sync_multi.php config.dr.json
```

## Testing

### Test Transaction Support

```bash
php tests/test_transactions.php config.test.json
```

### Test API Client

```bash
php tests/test_api_client.php
```

### Integration Test

1. Start local server:
```bash
php -S localhost:8080 -t public public/index.php
```

2. Test API endpoints:
```bash
# Status
curl http://localhost:8080/api/status

# Config
curl http://localhost:8080/api/config

# Push (with auth)
curl -X POST http://localhost:8080/api/push \
  -H "X-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{"table":"users","data":[{"id":1,"name":"Test"}]}'

# Pull (with auth)
curl "http://localhost:8080/api/pull?table=users" \
  -H "X-API-Key: your-api-key"
```

## Performance Considerations

### Large Datasets

For tables with millions of rows:

1. **Increase Timeout**: Set higher timeout values
2. **Incremental Sync**: Use `since` parameter for timestamp-based increments
3. **Off-Peak Sync**: Schedule heavy syncs during low-traffic periods
4. **Batch Processing**: Consider splitting large tables

### Network Optimization

1. **Compression**: Consider adding gzip compression for API responses
2. **Connection Pooling**: Reuse connections when possible
3. **Geographic Routing**: Use CDN or geo-routing for distributed environments
4. **Monitoring**: Track sync duration and data volumes

## Conclusion

The multi-environment synchronization feature provides a robust, secure, and flexible way to keep data in sync across multiple independent database environments. With transaction support ensuring data consistency and API authentication securing communications, you can confidently deploy distributed database architectures.
