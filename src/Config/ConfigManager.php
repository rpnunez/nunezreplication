<?php

namespace NunezReplication\Config;

class ConfigManager
{
    private $configDir;
    
    public function __construct()
    {
        $this->configDir = __DIR__ . '/../../';
    }
    
    /**
     * List all config files
     */
    public function listConfigs()
    {
        $configs = [];
        $files = glob($this->configDir . '*.json');
        
        foreach ($files as $file) {
            $filename = basename($file);
            // Skip composer.json and other non-config files
            if (strpos($filename, 'config') !== false && strpos($filename, 'composer') === false) {
                $configs[] = [
                    'filename' => $filename,
                    'path' => $file,
                    'size' => filesize($file),
                    'modified' => filemtime($file),
                    'writable' => is_writable($file)
                ];
            }
        }
        
        return $configs;
    }
    
    /**
     * Get config file content
     */
    public function getConfig($filename)
    {
        $filepath = $this->getFilePath($filename);
        
        if (!file_exists($filepath)) {
            throw new \Exception('Config file not found');
        }
        
        $content = file_get_contents($filepath);
        
        // Validate JSON
        $json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Return raw content even if invalid JSON for editing
            return [
                'filename' => $filename,
                'content' => $content,
                'valid' => false,
                'error' => json_last_error_msg()
            ];
        }
        
        return [
            'filename' => $filename,
            'content' => $content,
            'valid' => true,
            'parsed' => $json
        ];
    }
    
    /**
     * Save config file
     */
    public function saveConfig($filename, $content)
    {
        // Validate filename - prevent consecutive special characters
        if (!preg_match('/^[a-zA-Z0-9]+([._-][a-zA-Z0-9]+)*\.json$/', $filename)) {
            throw new \Exception('Invalid filename. Only alphanumeric characters with single dots, dashes, or underscores between them are allowed.');
        }
        
        // Validate JSON
        $json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON: ' . json_last_error_msg());
        }
        
        // Basic validation for replication config
        if (isset($json['mode']) && !in_array($json['mode'], ['master-slave', 'master-master'])) {
            throw new \Exception('Invalid mode. Must be "master-slave" or "master-master"');
        }
        
        $filepath = $this->getFilePath($filename);
        
        // Create backup if file exists
        if (file_exists($filepath)) {
            $backupPath = $filepath . '.backup.' . time();
            copy($filepath, $backupPath);
        }
        
        // Save file
        $result = file_put_contents($filepath, $content);
        
        if ($result === false) {
            throw new \Exception('Failed to save config file');
        }
        
        return [
            'success' => true,
            'filename' => $filename,
            'path' => $filepath
        ];
    }
    
    /**
     * Create new config from template
     */
    public function createFromTemplate($type, $filename = null)
    {
        $template = $this->getTemplate($type);
        
        if ($filename === null) {
            $filename = 'config.' . strtolower(str_replace(' ', '-', $type)) . '.' . time() . '.json';
        }
        
        return $this->saveConfig($filename, json_encode($template, JSON_PRETTY_PRINT));
    }
    
    /**
     * Get config template by type
     */
    public function getTemplate($type)
    {
        $templates = [
            'master-slave' => [
                'mode' => 'master-slave',
                'syncInterval' => '*/5 * * * *',
                'port' => 8080,
                'demoMode' => false,
                'databases' => [
                    'master' => [
                        'host' => 'localhost',
                        'port' => 3306,
                        'user' => 'root',
                        'password' => 'password',
                        'database' => 'master_db'
                    ],
                    'slave' => [
                        'host' => 'localhost',
                        'port' => 3307,
                        'user' => 'root',
                        'password' => 'password',
                        'database' => 'slave_db'
                    ]
                ],
                'replication' => [
                    'tables' => [
                        [
                            'name' => 'users',
                            'ignoreColumns' => [],
                            'primaryKey' => 'id',
                            'timestampColumn' => 'updated_at'
                        ]
                    ]
                ]
            ],
            'master-master' => [
                'mode' => 'master-master',
                'syncInterval' => '*/5 * * * *',
                'port' => 8080,
                'demoMode' => false,
                'databases' => [
                    'master' => [
                        'host' => 'localhost',
                        'port' => 3306,
                        'user' => 'root',
                        'password' => 'password',
                        'database' => 'db1'
                    ],
                    'slave' => [
                        'host' => 'localhost',
                        'port' => 3307,
                        'user' => 'root',
                        'password' => 'password',
                        'database' => 'db2'
                    ]
                ],
                'replication' => [
                    'tables' => [
                        [
                            'name' => 'users',
                            'ignoreColumns' => [],
                            'primaryKey' => 'id',
                            'timestampColumn' => 'updated_at'
                        ]
                    ]
                ]
            ],
            'multi-environment' => [
                'mode' => 'master-slave',
                'syncInterval' => '*/5 * * * *',
                'port' => 8080,
                'demoMode' => false,
                'api' => [
                    'keys' => ['your-api-key-here']
                ],
                'remoteEnvironments' => [
                    'production' => [
                        'url' => 'https://prod-server.example.com',
                        'apiKey' => 'prod-api-key',
                        'syncMode' => 'bidirectional',
                        'timeout' => 30
                    ],
                    'staging' => [
                        'url' => 'https://staging-server.example.com',
                        'apiKey' => 'staging-api-key',
                        'syncMode' => 'pull',
                        'timeout' => 30
                    ]
                ],
                'databases' => [
                    'master' => [
                        'host' => 'localhost',
                        'port' => 3306,
                        'user' => 'root',
                        'password' => 'password',
                        'database' => 'master_db'
                    ]
                ],
                'replication' => [
                    'tables' => [
                        [
                            'name' => 'users',
                            'ignoreColumns' => [],
                            'primaryKey' => 'id',
                            'timestampColumn' => 'updated_at'
                        ]
                    ]
                ]
            ],
            'with-stats' => [
                'mode' => 'master-slave',
                'syncInterval' => '*/5 * * * *',
                'port' => 8080,
                'demoMode' => false,
                'databases' => [
                    'master' => [
                        'host' => 'localhost',
                        'port' => 3306,
                        'user' => 'root',
                        'password' => 'password',
                        'database' => 'master_db'
                    ],
                    'slave' => [
                        'host' => 'localhost',
                        'port' => 3307,
                        'user' => 'root',
                        'password' => 'password',
                        'database' => 'slave_db'
                    ],
                    'stats' => [
                        'host' => 'localhost',
                        'port' => 3306,
                        'user' => 'root',
                        'password' => 'password',
                        'database' => 'replication_stats'
                    ]
                ],
                'replication' => [
                    'tables' => [
                        [
                            'name' => 'users',
                            'ignoreColumns' => [],
                            'primaryKey' => 'id',
                            'timestampColumn' => 'updated_at'
                        ]
                    ]
                ]
            ]
        ];
        
        if (!isset($templates[$type])) {
            throw new \Exception('Unknown template type: ' . $type);
        }
        
        return $templates[$type];
    }
    
    /**
     * Get all available templates
     */
    public function getAvailableTemplates()
    {
        return [
            [
                'id' => 'master-slave',
                'name' => 'Master-Slave Replication',
                'description' => 'Basic one-way replication from master to slave database'
            ],
            [
                'id' => 'master-master',
                'name' => 'Master-Master Replication',
                'description' => 'Bidirectional replication between two databases'
            ],
            [
                'id' => 'multi-environment',
                'name' => 'Multi-Environment Setup',
                'description' => 'Synchronization across multiple remote environments via API'
            ],
            [
                'id' => 'with-stats',
                'name' => 'With Statistics Database',
                'description' => 'Includes dedicated statistics database for tracking sync history'
            ]
        ];
    }
    
    /**
     * Get config schema documentation
     */
    public function getConfigSchema()
    {
        return [
            'mode' => [
                'type' => 'string',
                'required' => true,
                'values' => ['master-slave', 'master-master'],
                'description' => 'Replication mode'
            ],
            'syncInterval' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Cron expression for automatic sync (e.g., "*/5 * * * *" for every 5 minutes)'
            ],
            'port' => [
                'type' => 'number',
                'required' => false,
                'default' => 8080,
                'description' => 'Port for the web dashboard'
            ],
            'demoMode' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'description' => 'Run in demo mode without real database connections'
            ],
            'api.keys' => [
                'type' => 'array',
                'required' => false,
                'description' => 'API keys for authentication. If empty, authentication is disabled.'
            ],
            'remoteEnvironments' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Configuration for multi-environment synchronization',
                'properties' => [
                    'url' => 'Remote server URL',
                    'apiKey' => 'API key for authentication',
                    'syncMode' => 'Sync mode: bidirectional, push, or pull',
                    'timeout' => 'Request timeout in seconds'
                ]
            ],
            'databases.master' => [
                'type' => 'object',
                'required' => true,
                'description' => 'Master database configuration',
                'properties' => [
                    'host' => 'Database host',
                    'port' => 'Database port',
                    'user' => 'Database username',
                    'password' => 'Database password',
                    'database' => 'Database name'
                ]
            ],
            'databases.slave' => [
                'type' => 'object',
                'required' => 'For master-slave mode',
                'description' => 'Slave database configuration (same structure as master)'
            ],
            'databases.stats' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Optional statistics database configuration'
            ],
            'replication.tables' => [
                'type' => 'array',
                'required' => true,
                'description' => 'Tables to replicate',
                'properties' => [
                    'name' => 'Table name',
                    'primaryKey' => 'Primary key column',
                    'timestampColumn' => 'Timestamp column for conflict resolution',
                    'ignoreColumns' => 'Array of column names to exclude from replication'
                ]
            ]
        ];
    }
    
    /**
     * Delete config file
     */
    public function deleteConfig($filename)
    {
        // Don't allow deleting example files
        if ($filename === 'config.example.json' || $filename === 'composer.json') {
            throw new \Exception('Cannot delete system files');
        }
        
        $filepath = $this->getFilePath($filename);
        
        if (!file_exists($filepath)) {
            throw new \Exception('Config file not found');
        }
        
        // Create backup before deleting
        $backupPath = $filepath . '.deleted.' . time();
        copy($filepath, $backupPath);
        
        if (!unlink($filepath)) {
            throw new \Exception('Failed to delete config file');
        }
        
        return ['success' => true, 'backup' => $backupPath];
    }
    
    /**
     * Get safe file path
     */
    private function getFilePath($filename)
    {
        // Prevent directory traversal
        $filename = basename($filename);
        return $this->configDir . $filename;
    }
}
