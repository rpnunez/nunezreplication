<?php

namespace NunezReplication\Api;

use NunezReplication\Replication\ReplicationEngine;

class ApiController
{
    private $engine;
    private $config;

    public function __construct(ReplicationEngine $engine, $config)
    {
        $this->engine = $engine;
        $this->config = $config;
    }

    public function getStatus()
    {
        $stats = $this->engine->getStats();
        
        return [
            'mode' => $this->config['mode'],
            'status' => 'running',
            'stats' => $stats,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    public function getConfig()
    {
        // Authenticate API request - config may contain sensitive data
        if (!$this->authenticateRequest()) {
            http_response_code(401);
            return ['error' => 'Unauthorized'];
        }

        // Return config without sensitive data
        $safeConfig = $this->config;
        
        if (isset($safeConfig['databases'])) {
            foreach ($safeConfig['databases'] as $key => &$db) {
                unset($db['password']);
            }
        }
        
        // Remove API keys from response
        if (isset($safeConfig['api']['keys'])) {
            unset($safeConfig['api']['keys']);
        }
        
        return $safeConfig;
    }

    public function triggerSync()
    {
        return $this->engine->sync();
    }
    
    public function getStatsHistory()
    {
        $statsDB = $this->engine->getStatsDB();
        
        if (!$statsDB) {
            return [
                'error' => 'Stats database not configured',
                'history' => []
            ];
        }
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $limit = max(1, min($limit, 100)); // Between 1 and 100
        
        try {
            $history = $statsDB->getRecentSyncs($limit);
            return [
                'history' => $history,
                'count' => count($history)
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to retrieve history: ' . $e->getMessage(),
                'history' => []
            ];
        }
    }
    
    public function getTableStats()
    {
        $statsDB = $this->engine->getStatsDB();
        
        if (!$statsDB) {
            return [
                'error' => 'Stats database not configured',
                'tables' => []
            ];
        }
        
        $tableName = $_GET['table'] ?? null;
        
        if (!$tableName) {
            return [
                'error' => 'Table name parameter required',
                'tables' => []
            ];
        }
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $limit = max(1, min($limit, 100));
        
        try {
            $stats = $statsDB->getTableStats($tableName, $limit);
            return [
                'table' => $tableName,
                'stats' => $stats,
                'count' => count($stats)
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to retrieve table stats: ' . $e->getMessage(),
                'stats' => []
            ];
        }
    }
    
    public function getRecentErrors()
    {
        $statsDB = $this->engine->getStatsDB();
        
        if (!$statsDB) {
            return [
                'error' => 'Stats database not configured',
                'errors' => []
            ];
        }
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $limit = max(1, min($limit, 100));
        
        try {
            $errors = $statsDB->getRecentErrors($limit);
            return [
                'errors' => $errors,
                'count' => count($errors)
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to retrieve errors: ' . $e->getMessage(),
                'errors' => []
            ];
        }
    }

    public function pushData()
    {
        // Authenticate API request
        if (!$this->authenticateRequest()) {
            http_response_code(401);
            return ['error' => 'Unauthorized'];
        }

        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['table']) || !isset($input['data'])) {
            http_response_code(400);
            return ['error' => 'Missing required parameters: table and data'];
        }

        try {
            $result = $this->engine->pushDataToLocal($input['table'], $input['data']);
            return [
                'success' => true,
                'result' => $result
            ];
        } catch (\Exception $e) {
            http_response_code(500);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function pullData()
    {
        // Authenticate API request
        if (!$this->authenticateRequest()) {
            http_response_code(401);
            return ['error' => 'Unauthorized'];
        }

        $tableName = $_GET['table'] ?? null;
        $since = $_GET['since'] ?? null;

        if (!$tableName) {
            http_response_code(400);
            return ['error' => 'Missing required parameter: table'];
        }

        try {
            $data = $this->engine->pullDataFromLocal($tableName, $since);
            return [
                'success' => true,
                'table' => $tableName,
                'data' => $data,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            http_response_code(500);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getMetadata()
    {
        // Authenticate API request
        if (!$this->authenticateRequest()) {
            http_response_code(401);
            return ['error' => 'Unauthorized'];
        }

        $tableName = $_GET['table'] ?? null;

        if (!$tableName) {
            http_response_code(400);
            return ['error' => 'Missing required parameter: table'];
        }

        try {
            $metadata = $this->engine->getTableMetadata($tableName);
            return [
                'success' => true,
                'table' => $tableName,
                'metadata' => $metadata
            ];
        } catch (\Exception $e) {
            http_response_code(500);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function authenticateRequest()
    {
        // Check for API key in header (case-insensitive)
        $headers = getallheaders();
        if ($headers === false) {
            $headers = [];
        }
        
        // Normalize header keys to lowercase for case-insensitive lookup
        $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);
        $apiKey = $normalizedHeaders['x-api-key'] ?? null;

        // Get configured API keys
        $configuredKeys = $this->config['api']['keys'] ?? [];

        // If no keys configured, allow all requests (backward compatibility)
        if (empty($configuredKeys)) {
            return true;
        }

        // Validate API key using constant-time comparison to prevent timing attacks
        if ($apiKey === null) {
            return false;
        }
        
        foreach ($configuredKeys as $validKey) {
            if (hash_equals($validKey, $apiKey)) {
                return true;
            }
        }
        
        return false;
    }
}
