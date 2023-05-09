<?php

declare(strict_types=1);

namespace Divinity76\Phprouter;

class Phprouter
{

    private const ROUTE_INDEX_METHODS = 0;

    private const ROUTE_INDEX_REX = 1;

    private const ROUTE_INDEX_CALLBACK = 2;

    /** @var bool */
    public $ignoreGetParams = false;

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
        $rex = '#^' . $rex . '$#';
        $this->matchRawRex($methods, $rex, $cb);
    }

    public function matchRawRex($methods, string $rex, callable $cb): void
    {
        if (empty($methods)) {
            throw new \InvalidArgumentException("method cannot be empty..");
        }
        if (is_array($methods)) {
        } elseif (is_string($methods)) {
            $methods = explode('|', $methods);
        } else {
            throw new \InvalidArgumentException("methods must be array or | separated string");
        }
        if (empty($methods)) {
            throw new \InvalidArgumentException("empty methods");
        }
        $this->routes[] = [
            self::ROUTE_INDEX_METHODS => $methods,
            self::ROUTE_INDEX_REX => $rex,
            self::ROUTE_INDEX_CALLBACK => $cb
        ];
    }
    public function set404(callable $cb): void
    {
        $this->not_found_cb = $cb;
    }

    public function trigger404(...$args): void
    {
        http_response_code(404);
        if (!empty($this->not_found_cb)) {
            ($this->not_found_cb)(...$args);
        }
    }

    public function getRequestUri(): string
    {
        $ret = $_SERVER['REQUEST_URI'];
        if($this->ignoreGetParams && ($pos = strpos($ret, '?')) !== false) {
            return substr($ret, 0, $pos);
        }
        return $ret;
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
            if (
                in_array($requestMethod, $route[self::ROUTE_INDEX_METHODS], true)
                && preg_match($route[self::ROUTE_INDEX_REX], $requestUri, $matches)
            ) {
                if ($this->array_is_list_compat($matches)) {
                    // list mode
                    unset($matches[0]); // remove the full match
                } else {
                    // assoc mode, remove all integer keys..
                    // todo: this can probably be optimized with array_splice
                    $i = 0;
                    do {
                        unset($matches[$i]);
                        ++$i;
                    } while (isset($matches[$i]));
                }
                http_response_code(200);
                ($route[self::ROUTE_INDEX_CALLBACK])(...$matches);
                return;
            }
        }
        $this->trigger404();
    }
    /**
     * php < 8.1.0 compatibility for array_is_list
     * 
     * @param array $array
     * @return bool
     */
    private static function array_is_list_compat(array $array): bool
    {
        if (PHP_VERSION_ID >= 80100) {
            // PHP >= 8.1.0 has a native and faster array_is_list
            return array_is_list($array);
        }
        $i = -1;
        foreach ($array as $k => $_) {
            ++$i;
            if ($k !== $i) {
                return false;
            }
        }
        return true;
    }
}
