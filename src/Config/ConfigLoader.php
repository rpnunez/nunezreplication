<?php

namespace NunezReplication\Config;

class ConfigLoader
{
    private $config = null;

    public function load($configPath = null)
    {
        $defaultPath = __DIR__ . '/../../config.json';
        $examplePath = __DIR__ . '/../../config.example.json';
        $localPath = __DIR__ . '/../../config.local.json';
        
        $targetPath = $configPath ?: $localPath;
        
        // Try local config first, then default, then example
        if (!file_exists($targetPath)) {
            $targetPath = $defaultPath;
        }
        if (!file_exists($targetPath)) {
            $targetPath = $examplePath;
        }
        
        if (!file_exists($targetPath)) {
            throw new \Exception('No configuration file found. Please create config.json or config.local.json');
        }
        
        $configData = file_get_contents($targetPath);
        $this->config = json_decode($configData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in configuration file: ' . json_last_error_msg());
        }
        
        // Validate configuration
        $this->validate();
        
        return $this->config;
    }

    private function validate()
    {
        if (!isset($this->config['mode']) || !in_array($this->config['mode'], ['master-slave', 'master-master'])) {
            throw new \Exception('Invalid mode. Must be "master-slave" or "master-master"');
        }
        
        if (!isset($this->config['databases']['master'])) {
            throw new \Exception('Master database configuration is required');
        }
        
        if ($this->config['mode'] === 'master-slave' && !isset($this->config['databases']['slave'])) {
            throw new \Exception('Slave database configuration is required for master-slave mode');
        }
        
        if (!isset($this->config['replication']['tables']) || count($this->config['replication']['tables']) === 0) {
            throw new \Exception('At least one table must be configured for replication');
        }
    }

    public function getConfig()
    {
        if ($this->config === null) {
            $this->load();
        }
        return $this->config;
    }
}
