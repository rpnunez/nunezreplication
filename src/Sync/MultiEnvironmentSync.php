<?php

namespace NunezReplication\Sync;

use NunezReplication\Api\ApiClient;
use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;

/**
 * Orchestrates synchronization across multiple independent environments
 */
class MultiEnvironmentSync
{
    private $localEngine;
    private $config;
    private $remoteClients = [];

    public function __construct(ReplicationEngine $localEngine, $config)
    {
        $this->localEngine = $localEngine;
        $this->config = $config;

        // Initialize API clients for remote environments
        if (isset($config['remoteEnvironments'])) {
            foreach ($config['remoteEnvironments'] as $envName => $envConfig) {
                $this->remoteClients[$envName] = new ApiClient(
                    $envConfig['url'],
                    $envConfig['apiKey'] ?? null,
                    $envConfig['timeout'] ?? 30
                );
            }
        }
    }

    /**
     * Synchronize with all remote environments
     */
    public function syncWithAllRemotes()
    {
        $results = [];

        foreach ($this->remoteClients as $envName => $client) {
            error_log("Syncing with remote environment: $envName");
            
            try {
                $result = $this->syncWithRemote($envName, $client);
                $results[$envName] = [
                    'success' => true,
                    'result' => $result
                ];
                error_log("Successfully synced with $envName");
            } catch (\Exception $e) {
                $results[$envName] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                error_log("Failed to sync with $envName: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Synchronize with a specific remote environment
     */
    private function syncWithRemote($envName, ApiClient $client)
    {
        $stats = [
            'pushed' => [],
            'pulled' => []
        ];

        // Get sync mode from config
        $envConfig = $this->config['remoteEnvironments'][$envName];
        $syncMode = $envConfig['syncMode'] ?? 'bidirectional'; // 'push', 'pull', or 'bidirectional'

        foreach ($this->config['replication']['tables'] as $tableConfig) {
            $tableName = $tableConfig['name'];
            
            // Push data to remote (if mode allows)
            if ($syncMode === 'push' || $syncMode === 'bidirectional') {
                try {
                    $stats['pushed'][$tableName] = $this->pushTableToRemote($client, $tableConfig);
                } catch (\Exception $e) {
                    error_log("Failed to push $tableName to $envName: " . $e->getMessage());
                    $stats['pushed'][$tableName] = ['error' => $e->getMessage()];
                }
            }

            // Pull data from remote (if mode allows)
            if ($syncMode === 'pull' || $syncMode === 'bidirectional') {
                try {
                    $stats['pulled'][$tableName] = $this->pullTableFromRemote($client, $tableConfig);
                } catch (\Exception $e) {
                    error_log("Failed to pull $tableName from $envName: " . $e->getMessage());
                    $stats['pulled'][$tableName] = ['error' => $e->getMessage()];
                }
            }
        }

        return $stats;
    }

    /**
     * Push local table data to remote environment
     */
    private function pushTableToRemote(ApiClient $client, $tableConfig)
    {
        $tableName = $tableConfig['name'];
        $timestampColumn = $tableConfig['timestampColumn'] ?? 'updated_at';

        // Get metadata from remote to determine what needs to be synced
        try {
            $remoteMetadata = $client->getMetadata($tableName);
            $lastSync = $remoteMetadata['metadata']['last_sync'] ?? null;
        } catch (\Exception $e) {
            // If metadata fetch fails, sync all data
            $lastSync = null;
        }

        // Pull data from local that's newer than remote's last sync
        $data = $this->localEngine->pullDataFromLocal($tableName, $lastSync);

        if (empty($data)) {
            return ['rows' => 0, 'message' => 'No new data to push'];
        }

        // Push data to remote
        $result = $client->pushData($tableName, $data);
        
        return [
            'rows' => count($data),
            'inserted' => $result['inserted'] ?? 0,
            'updated' => $result['updated'] ?? 0
        ];
    }

    /**
     * Pull data from remote environment to local
     */
    private function pullTableFromRemote(ApiClient $client, $tableConfig)
    {
        $tableName = $tableConfig['name'];

        // Get local metadata to determine what needs to be synced
        try {
            $localMetadata = $this->localEngine->getTableMetadata($tableName);
            $lastSync = $localMetadata['last_sync'] ?? null;
        } catch (\Exception $e) {
            // If metadata fetch fails, pull all data
            $lastSync = null;
        }

        // Pull data from remote
        $response = $client->pullData($tableName, $lastSync);
        $data = $response['data'] ?? [];

        if (empty($data)) {
            return ['rows' => 0, 'message' => 'No new data to pull'];
        }

        // Push to local database
        $result = $this->localEngine->pushDataToLocal($tableName, $data);
        
        return [
            'rows' => count($data),
            'inserted' => $result['inserted'] ?? 0,
            'updated' => $result['updated'] ?? 0
        ];
    }

    /**
     * Get status of all remote environments
     */
    public function getRemoteStatuses()
    {
        $statuses = [];

        foreach ($this->remoteClients as $envName => $client) {
            try {
                $statuses[$envName] = $client->getStatus();
            } catch (\Exception $e) {
                $statuses[$envName] = [
                    'error' => $e->getMessage(),
                    'reachable' => false
                ];
            }
        }

        return $statuses;
    }

    /**
     * Trigger sync on all remote environments
     */
    public function triggerRemoteSyncs()
    {
        $results = [];

        foreach ($this->remoteClients as $envName => $client) {
            try {
                $results[$envName] = $client->triggerSync();
            } catch (\Exception $e) {
                $results[$envName] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}
