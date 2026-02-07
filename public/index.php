<?php

require_once __DIR__ . '/../vendor/autoload.php';

use NunezReplication\Config\ConfigLoader;
use NunezReplication\Database\DatabaseManager;
use NunezReplication\Replication\ReplicationEngine;
use NunezReplication\Api\Router;
use NunezReplication\Api\ApiController;

// Load configuration
$configLoader = new ConfigLoader();
$config = $configLoader->load();

// Initialize database connections (only if not in demo mode)
$dbManager = new DatabaseManager();
$demoMode = $config['demoMode'] ?? false;

if (!$demoMode) {
    try {
        $dbManager->connect('master', $config['databases']['master']);
        if (isset($config['databases']['slave'])) {
            $dbManager->connect('slave', $config['databases']['slave']);
        }
    } catch (Exception $e) {
        error_log("Database connection error: " . $e->getMessage());
        // Continue in demo mode if connections fail
        $demoMode = true;
    }
}

// Initialize replication engine
$engine = new ReplicationEngine($dbManager, $config);

// Set up API routes
$router = new Router();
$apiController = new ApiController($engine, $config);

$router->get('/api/status', [$apiController, 'getStatus']);
$router->get('/api/config', [$apiController, 'getConfig']);
$router->post('/api/sync', [$apiController, 'triggerSync']);
$router->post('/api/push', [$apiController, 'pushData']);
$router->get('/api/pull', [$apiController, 'pullData']);

$router->get('/api/metadata', [$apiController, 'getMetadata']);

$router->get('/api/stats/history', [$apiController, 'getStatsHistory']);
$router->get('/api/stats/table', [$apiController, 'getTableStats']);
$router->get('/api/stats/errors', [$apiController, 'getRecentErrors']);

// Handle API requests
$requestUri = $_SERVER['REQUEST_URI'];
if (strpos($requestUri, '/api/') === 0) {
    $router->handleRequest();
    exit;
}

// Serve static files or index.html
$publicDir = __DIR__ . '/../public';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

if ($requestPath === '/' || $requestPath === '/index.html') {
    header('Content-Type: text/html');
    readfile($publicDir . '/index.html');
    exit;
}

$filePath = $publicDir . $requestPath;
if (file_exists($filePath) && is_file($filePath)) {
    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
    $contentTypes = [
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
    ];
    
    $contentType = $contentTypes[$extension] ?? 'application/octet-stream';
    header("Content-Type: $contentType");
    readfile($filePath);
    exit;
}

// 404
http_response_code(404);
echo '404 Not Found';
