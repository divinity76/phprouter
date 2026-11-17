<?php

declare(strict_types=1);

namespace Divinity76\Phprouter;

class Phprouter
{

    private const ROUTE_INDEX_METHODS = 0;

    private const ROUTE_INDEX_REX = 1;

    private const ROUTE_INDEX_CALLBACK = 2;

    private const ROUTE_INDEX_ANCHORED = 3;

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
        // \x01 as the delimiter, because '#' can legitimately appear inside a
        // route: [#], (?#comment), or a (?x) mode comment.
        // (?:...) keeps a top-level alternation inside the anchors.
        // \z instead of $ because $ also matches before a trailing newline.
        $rex = "\x01^(?:" . $rex . ")\z\x01";
        $this->addRoute($methods, $rex, $cb, true);
    }

    /**
     * add a route whose pattern is used exactly as given: no anchoring, no
     * whole-path enforcement. use this when you need a construct the anchored
     * form rejects, such as \K.
     */
    public function matchRawRex($methods, string $rex, callable $cb): void
    {
        $this->addRoute($methods, $rex, $cb, false);
    }

    private function addRoute($methods, string $rex, callable $cb, bool $anchored): void
    {
        if (empty($methods)) {
            throw new \InvalidArgumentException("method cannot be empty..");
        }
        if (is_array($methods)) {
        } elseif (is_string($methods)) {
            $methods = array_map(trim(...), explode('|', $methods));
        } else {
            throw new \InvalidArgumentException("methods must be array or | separated string");
        }
        if (empty($methods)) {
            throw new \InvalidArgumentException("empty methods");
        }
        // fail at registration, not on a request, when the regex is broken.
        if (@preg_match($rex, '') === false) {
            throw new \InvalidArgumentException(
                "invalid route regex (" . preg_last_error_msg() . "): " . $rex
            );
        }
        $this->routes[] = [
            self::ROUTE_INDEX_METHODS => $methods,
            self::ROUTE_INDEX_REX => $rex,
            self::ROUTE_INDEX_CALLBACK => $cb,
            self::ROUTE_INDEX_ANCHORED => $anchored
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

    /**
     * the raw request target, query string included.
     */
    public function getRequestUri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    /**
     * the request path: the request target with the query string and fragment
     * removed. this is what routes are matched against, and it is returned
     * exactly as sent: nothing is percent-decoded, so %2F stays %2F.
     *
     * returns '' when the request target cannot be parsed at all; run() treats
     * that as a 404.
     */
    public function getRequestPath(): string
    {
        $uri = $this->getRequestUri();
        if ($uri !== '' && $uri[0] === '/') {
            // origin-form. '?' starts the query string and '#' would start a
            // fragment (which a conforming client never sends); neither can
            // appear unencoded in a path.
            $end = strcspn($uri, '?#');
            return $end === strlen($uri) ? $uri : substr($uri, 0, $end);
        }
        // absolute-form (proxy-style requests) and anything else.
        // parse_url() must not see an origin-form path: it would read a
        // leading "//" as the start of an authority component.
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false) {
            return '';
        }
        return is_string($path) && $path !== '' ? $path : '/';
    }

    public static function getRequestMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? '';
    }

    public function run(): void
    {
        $requestPath = $this->getRequestPath();
        $requestMethod = $this->getRequestMethod();
        if ($requestPath === '') {
            $this->trigger404($requestPath, $requestMethod);
            return;
        }
        $matches = [];
        foreach ($this->routes as $route) {
            if (!in_array($requestMethod, $route[self::ROUTE_INDEX_METHODS], true)) {
                continue;
            }
            $result = preg_match($route[self::ROUTE_INDEX_REX], $requestPath, $matches, PREG_UNMATCHED_AS_NULL);
            if ($result === false) {
                // a pcre limit was hit (the regex itself was checked at
                // registration). do not let the request fall through to a
                // later, broader route because of it.
                $this->trigger404($requestPath, $requestMethod);
                return;
            }
            if ($result !== 1) {
                continue;
            }
            if ($route[self::ROUTE_INDEX_ANCHORED] && $matches[0] !== $requestPath) {
                // the route matched only part of the path. a route with an
                // unbalanced ")" can close the anchor wrapper early. either
                // way, an anchored route must cover the whole path.
                continue;
            }
            http_response_code(200);
            ($route[self::ROUTE_INDEX_CALLBACK])(...self::buildArguments($matches));
            return;
        }
        $this->trigger404($requestPath, $requestMethod);
    }

    /**
     * turn a preg_match() result into the arguments for the route callback.
     *
     * named captures (?<name>..) become named arguments, positional captures
     * (..) become positional arguments. preg_match() reports a named group
     * under both its name and its index, so the integer keys are dropped when
     * the route uses names; php cannot unpack a mix of the two anyway.
     *
     * a handler receiving named captures must declare every named group by the
     * same name: php raises "Unknown named parameter" otherwise.
     *
     * @param array $matches
     * @return array
     */
    private static function buildArguments(array $matches): array
    {
        unset($matches[0]); // the full match
        $named = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
        return $named ?: $matches;
    }
}
