<?php

namespace NunezReplication\Api;

class ApiClient
{
    private $baseUrl;
    private $apiKey;
    private $timeout;

    public function __construct($baseUrl, $apiKey = null, $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * Get replication status from remote environment
     */
    public function getStatus()
    {
        return $this->request('GET', '/api/status');
    }

    /**
     * Get configuration from remote environment
     */
    public function getConfig()
    {
        return $this->request('GET', '/api/config');
    }

    /**
     * Trigger sync on remote environment
     */
    public function triggerSync()
    {
        return $this->request('POST', '/api/sync');
    }

    /**
     * Push data to remote environment
     */
    public function pushData($tableName, $data)
    {
        return $this->request('POST', '/api/push', [
            'table' => $tableName,
            'data' => $data
        ]);
    }

    /**
     * Pull data from remote environment
     */
    public function pullData($tableName, $since = null)
    {
        $params = ['table' => $tableName];
        if ($since !== null) {
            $params['since'] = $since;
        }
        return $this->request('GET', '/api/pull', $params);
    }

    /**
     * Get sync metadata from remote environment
     */
    public function getMetadata($tableName)
    {
        return $this->request('GET', '/api/metadata', ['table' => $tableName]);
    }

    /**
     * Make an HTTP request to the API
     */
    private function request($method, $endpoint, $data = null)
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        if ($this->apiKey) {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'GET' && $data !== null) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("API request failed: $error");
        }
        
        if ($httpCode >= 400) {
            throw new \Exception("API request failed with status $httpCode: $response");
        }
        
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response: " . json_last_error_msg());
        }
        
        return $result;
    }
}
