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
}
