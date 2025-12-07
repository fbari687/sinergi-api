<?php

namespace app\core;

use app\helpers\ResponseFormatter;

class Router {
    protected $routes = [];

    public function addRoute($method, $uri, $action, $middleware = null) {
        // Normalisasi URI
        $uri = trim($uri, '/');
        $this->routes[$method][$uri] = [
            'action' => $action,
            'middleware' => $middleware
        ];
    }

    public function dispatch($uri, $method) {
        $uri = trim(parse_url($uri, PHP_URL_PATH), '/');

        foreach ($this->routes[$method] as $route => $details) {
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $route);
            if (preg_match("#^$pattern$#", $uri, $matches)) {
                array_shift($matches);
                $params = $matches;

                // === PENYESUAIAN MIDDLEWARE DIMULAI DI SINI ===
                if (isset($details['middleware'])) {
                    // Pastikan middleware selalu dalam format array
                    $middlewares = is_array($details['middleware']) ? $details['middleware'] : [$details['middleware']];

                    foreach ($middlewares as $middleware) {
                        $parts = explode(':', $middleware);
                        $middlewareClass = 'app\\middleware\\' . $parts[0] . 'Middleware';
                        $middlewareParams = isset($parts[1]) ? explode(',', $parts[1]) : [];

                        if (class_exists($middlewareClass)) {
                            $instance = new $middlewareClass();
                            // Panggil handle() dengan parameter (jika ada)
                            call_user_func_array([$instance, 'handle'], $middlewareParams);
                        }
                    }
                }
                // === SELESAI PENYESUAIAN ===

                [$controller, $methodName] = explode('@', $details['action']);
                $controllerClass = 'app\\controllers\\' . $controller;

                if (class_exists($controllerClass)) {
                    $controllerInstance = new $controllerClass();
                    if (method_exists($controllerInstance, $methodName)) {
                        call_user_func_array([$controllerInstance, $methodName], $params);
                        return;
                    }
                }
            }
        }
        $this->notFound();
    }

    protected function notFound() {
        ResponseFormatter::Error('404 Not Found', 404);
    }

}
