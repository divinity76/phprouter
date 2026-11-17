<?php

declare(strict_types=1);

namespace Divinity76\Phprouter;

/**
 * a small regex router.
 *
 * routes are checked in registration order and the first match wins, so
 * register the specific ones before the catch-alls.
 */
class Phprouter
{

    private const ROUTE_INDEX_METHODS = 0;

    private const ROUTE_INDEX_REX = 1;

    private const ROUTE_INDEX_CALLBACK = 2;

    private const ROUTE_INDEX_ANCHORED = 3;

    private array $routes = [];

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

    public function match(array|string $methods, string $rex, callable $cb): void
    {
        // pcre only accepts leading verbs like (*UTF) or (*UCP) at the very
        // start of a pattern, so hoist them in front of the anchors.
        $verbs = '';
        if (preg_match('/\A(?:\(\*[A-Z_0-9=]+\))+/', $rex, $m)) {
            $verbs = $m[0];
            $rex = substr($rex, strlen($verbs));
        }
        // \x01 as the delimiter. '#' would collide with regex syntax that can
        // appear inside a route, such as (?#comment) or a (?x) mode comment;
        // a request path itself can never contain a raw '#'.
        // (?:...) keeps a top-level alternation inside the anchors.
        // \z instead of $ because $ also matches before a trailing newline.
        $rex = "\x01" . $verbs . "^(?:" . $rex . ")\z\x01";
        $this->addRoute($methods, $rex, $cb, true);
    }

    /**
     * add a route whose pattern is used exactly as given: no anchoring, no
     * whole-path enforcement. use this when you need a construct the anchored
     * form rejects, such as \K.
     */
    public function matchRawRex(array|string $methods, string $rex, callable $cb): void
    {
        $this->addRoute($methods, $rex, $cb, false);
    }

    private function addRoute(array|string $methods, string $rex, callable $cb, bool $anchored): void
    {
        if (is_string($methods)) {
            $methods = $methods === '' ? [] : explode('|', $methods);
        }
        if ($methods === []) {
            throw new \InvalidArgumentException("methods cannot be empty");
        }
        // fail at registration, not on a request, when the regex is broken.
        // the pcre warning is deliberately not suppressed: it names the
        // offending pattern, and the exception follows it.
        if (preg_match($rex, '') === false) {
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
        if ($this->not_found_cb !== null) {
            ($this->not_found_cb)(...$args);
        }
    }

    /**
     * the request path: the request target with the query string removed.
     * this is what routes are matched against.
     *
     * for origin-form targets (the normal case) the path is returned exactly
     * as sent: nothing is percent-decoded, so %2F stays %2F, and no other byte
     * is touched either. the raw target is still in $_SERVER['REQUEST_URI'].
     *
     * absolute-form targets (proxy-style) and other non-origin forms go
     * through parse_url(), which reports the path per uri rules: that is not a
     * byte-preserving contract, and it also accepts things that are not
     * ordinary request paths ('*', authority-form, relative targets).
     *
     * returns '' when the target is empty or cannot be parsed; run() reports
     * 400.
     */
    public function getRequestPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if ($uri === '') {
            return '';
        }
        if ($uri[0] === '/') {
            // origin-form. the first '?' always starts the query string;
            // a '?' cannot appear unencoded in a path. nothing else is
            // stripped: in particular a '#' is kept as a literal byte, even
            // though a conforming client never sends one.
            $pos = strpos($uri, '?');
            return $pos === false ? $uri : substr($uri, 0, $pos);
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

    /**
     * dispatch the request and return the status the router decided on:
     * 200 when a route matched, 404 when the 404 handler ran, 400 for an
     * unusable request (empty target, missing method, target that could not be
     * parsed, or a path a `u`-mode route could not decode), 500 when pcre
     * failed.
     *
     * on a match the router sets 200 before invoking the handler, so a status
     * set before run() is overwritten; the handler can set its own afterwards.
     * the return value is the router's decision, not necessarily what ends up
     * in the response.
     */
    public function run(): int
    {
        $requestPath = $this->getRequestPath();
        $requestMethod = $this->getRequestMethod();
        if ($requestPath === '' || $requestMethod === '') {
            // malformed request: no usable path or method.
            http_response_code(400);
            return 400;
        }
        $matches = [];
        foreach ($this->routes as $route) {
            if (!in_array($requestMethod, $route[self::ROUTE_INDEX_METHODS], true)) {
                continue;
            }
            $result = preg_match($route[self::ROUTE_INDEX_REX], $requestPath, $matches, PREG_UNMATCHED_AS_NULL);
            if ($result === false) {
                // pcre refused to evaluate the route. invalid utf-8 in the
                // path is the client's fault; the limit errors are ours.
                // either way this is not a 404: we do not know whether the
                // route would have matched, and it must not fall through to a
                // broader route.
                $status = preg_last_error() === PREG_BAD_UTF8_ERROR ? 400 : 500;
                http_response_code($status);
                return $status;
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
            return 200;
        }
        $this->trigger404($requestPath, $requestMethod);
        return 404;
    }

    /**
     * turn a preg_match() result into the arguments for the route callback.
     *
     * positional captures (..) become positional arguments, named captures
     * (?<name>..) become named arguments.
     *
     * a route uses one style or the other. preg_match() reports a named group
     * under both its name and its index, and a mixed result cannot be unpacked
     * at all: the numeric duplicates collide with the named parameters, and
     * positional entries would follow named ones. so when a route uses named
     * groups, only the named captures are passed, and any unnamed capture in
     * the same route is discarded.
     *
     * a handler receiving named captures must declare every named group by the
     * same name: php raises "Unknown named parameter" otherwise. an optional
     * group that did not participate is null, so type it `?string`.
     *
     * @param array $matches
     * @return array
     */
    private static function buildArguments(array $matches): array
    {
        unset($matches[0]); // the full match
        // a plain loop, not array_filter(): on php 8.5 this measured ~1.8x
        // faster for positional routes and ~1.3x for named ones, since
        // array_filter invokes a callable per element. it also collects the
        // named captures in the same pass.
        $named = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            }
        }
        return $named ?: $matches;
    }
}
