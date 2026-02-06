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
}
