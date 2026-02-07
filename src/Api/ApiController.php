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
        // Return config without sensitive data
        $safeConfig = $this->config;
        
        if (isset($safeConfig['databases'])) {
            foreach ($safeConfig['databases'] as $key => &$db) {
                unset($db['password']);
            }
        }
        
        return $safeConfig;
    }

    public function triggerSync()
    {
        return $this->engine->sync();
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
        // Check for API key in header
        $headers = getallheaders();
        $apiKey = $headers['X-API-Key'] ?? $headers['X-Api-Key'] ?? null;

        // Get configured API keys
        $configuredKeys = $this->config['api']['keys'] ?? [];

        // If no keys configured, allow all requests (backward compatibility)
        if (empty($configuredKeys)) {
            return true;
        }

        // Validate API key
        return in_array($apiKey, $configuredKeys);
    }
}
