<?php

namespace Headcount;

/**
 * Simple Router Class
 * Handles routing for the application
 */
class Router
{
    private $routes = [];
    private $middleware = [];

    /**
     * Add a route
     */
    public function add(string $method, string $path, $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }

    /**
     * Add GET route
     */
    public function get(string $path, $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * Add POST route
     */
    public function post(string $path, $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * Add PUT route
     */
    public function put(string $path, $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /**
     * Add DELETE route
     */
    public function delete(string $path, $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * Dispatch request
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Remove base path if exists
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath !== '/' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }
        
        $path = rtrim($path, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $this->matchPath($route['path'], $path)) {
                // Execute middleware
                foreach ($route['middleware'] as $middleware) {
                    if (is_callable($middleware)) {
                        $result = $middleware();
                        if ($result === false) {
                            return;
                        }
                    } elseif (is_string($middleware) && class_exists($middleware)) {
                        $middlewareInstance = new $middleware();
                        if (method_exists($middlewareInstance, 'handle')) {
                            $result = $middlewareInstance->handle();
                            if ($result === false) {
                                return;
                            }
                        }
                    }
                }

                // Execute handler
                if (is_callable($route['handler'])) {
                    call_user_func($route['handler']);
                } elseif (is_string($route['handler']) && strpos($route['handler'], '@') !== false) {
                    list($class, $method) = explode('@', $route['handler']);
                    $controller = new $class();
                    if (method_exists($controller, $method)) {
                        $controller->$method();
                    } else {
                        $this->notFound();
                    }
                } else {
                    $this->notFound();
                }
                return;
            }
        }

        $this->notFound();
    }

    /**
     * Match path with route pattern
     */
    private function matchPath(string $pattern, string $path): bool
    {
        // Convert route pattern to regex
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $pattern);
        $pattern = '#^' . $pattern . '$#';
        
        return preg_match($pattern, $path);
    }

    /**
     * Get route parameters
     */
    public function getParams(string $pattern, string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $pattern, $paramNames);
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $pattern);
        $pattern = '#^' . $pattern . '$#';
        
        if (preg_match($pattern, $path, $matches)) {
            array_shift($matches);
            return array_combine($paramNames[1], $matches);
        }
        
        return [];
    }

    /**
     * 404 Not Found
     */
    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Route not found',
            'errors' => []
        ]);
        exit;
    }
}
