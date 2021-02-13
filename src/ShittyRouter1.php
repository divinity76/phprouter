<?php
declare(strict_types = 1);

class ShittyRouter1
{

    private const ROUTE_INDEX_METHOD = 0;

    private const ROUTE_INDEX_REX = 1;

    private const ROUTE_INDEX_CALLBACK = 2;

    private $routes = [];

    private $not_found_cb = null;

    public function get(string $rex, callable $cb): void
    {
        $this->match([
            "GET"
        ], $rex, $cb);
    }

    public function post(string $rex, callable $cb): void
    {
        $this->match([
            "POST"
        ], $rex, $cb);
    }

    public function match($methods, string $rex, callable $cb): void
    {
        if (empty($methods)) {
            throw new \InvalidArgumentException("method cannot be empty..");
        }
        if (is_array($methods)) {} elseif (is_string($methods)) {
            $methods = explode('|', $methods);
        } else {
            throw new \InvalidArgumentException("methods must be array or | separated string");
        }
        $rex = '#^' . $rex . '$#';

        foreach ($methods as $index => $method) {
            if (empty($method)) {
                throw new \InvalidArgumentException("empty method in {$index}");
            }
            if (! is_string($method)) {
                throw new \InvalidArgumentException("all methods must be string..");
            }
            $this->routes[] = [
                self::ROUTE_INDEX_METHOD => $method,
                self::ROUTE_INDEX_REX => $rex,
                self::ROUTE_INDEX_CALLBACK => $cb
            ];
        }
    }

    public function set404(callable $cb): void
    {
        $this->not_found_cb = $cb;
    }

    public function trigger404(): void
    {
        http_response_code(404);
        if (! empty($this->not_found_cb)) {
            ($this->not_found_cb)();
        }
    }

    public static function getRequestUri(): string
    {
        return $_SERVER['REQUEST_URI'];
    }

    public static function getRequestMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    public function run(): void
    {
        $requestUri = $this->getRequestUri();
        $requestMethod = $this->getRequestMethod();
        $matches = [];
        foreach ($this->routes as $route) {
            if ($route[self::ROUTE_INDEX_METHOD] !== $requestMethod) {
                continue;
            }
            if (preg_match($route[self::ROUTE_INDEX_REX], $requestUri, $matches)) {
                unset($matches[0]); // contains $requestUri ..
                http_response_code(200);
                ($route[self::ROUTE_INDEX_CALLBACK])(...$matches);
                return;
            }
        }
        $this->trigger404();
    }
}
