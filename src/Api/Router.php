<?php

namespace NunezReplication\Api;

class Router
{
    private $routes = [];

    public function get($path, $handler)
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post($path, $handler)
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch($method, $path)
    {
        if (!isset($this->routes[$method][$path])) {
            http_response_code(404);
            return ['error' => 'Route not found'];
        }

        return call_user_func($this->routes[$method][$path]);
    }

    public function handleRequest()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        header('Content-Type: application/json');
        
        try {
            $result = $this->dispatch($method, $path);
            echo json_encode($result);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
