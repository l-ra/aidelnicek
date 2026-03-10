<?php

namespace Aidelnicek;

class Router
{
    private array $routes = [];
    private string $urlBasePath = '';
    private string $projectRoot;

    public function __construct(string $projectRoot, string $urlBasePath = '')
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->urlBasePath = rtrim($urlBasePath, '/');
    }

    public function get(string $path, callable $handler): self
    {
        $this->routes['GET'][$this->normalizePath($path)] = $handler;
        return $this;
    }

    public function post(string $path, callable $handler): self
    {
        $this->routes['POST'][$this->normalizePath($path)] = $handler;
        return $this;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    public function dispatch(): void
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = $this->normalizePath($requestUri);

        if ($this->urlBasePath !== '' && str_starts_with($path, $this->urlBasePath . '/')) {
            $path = substr($path, strlen($this->urlBasePath)) ?: '/';
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $routes = $this->routes[$method] ?? [];

        if (isset($routes[$path])) {
            $handler = $routes[$path];
            if (is_callable($handler)) {
                $handler();
                return;
            }
        }

        http_response_code(404);
        echo $this->render404();
    }

    private function render404(): string
    {
        $template = $this->projectRoot . '/templates/404.php';
        if (file_exists($template)) {
            ob_start();
            require $template;
            return ob_get_clean() ?: '<h1>404 Not Found</h1>';
        }
        return '<h1>404 Not Found</h1>';
    }
}
