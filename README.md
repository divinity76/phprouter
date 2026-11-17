# phprouter
[![tests](https://github.com/divinity76/phprouter/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/divinity76/phprouter/actions/workflows/tests.yml)

php router
api inspried by https://github.com/bramus/router
but this router is not affected by https://github.com/bramus/router/issues/137 ... which is why i made this router

# installation
the router is just a standalone .php file, you can just copypaste it. 

another alternative is to use composer:
```
composer require 'divinity76/phprouter'
```

requires PHP 8.2 or newer.

# usage

```
<?php
require_once(__DIR__ . '/vendor/autoload.php');
$router = new \Divinity76\Phprouter\Phprouter();
$router->get('/test', function() {
    echo 'test GET page';
});
$router->post("/test",function(){
    echo "test POST page";
});
$router->match("PATCH",'/user/(\d+)/([^/]+)?',function(string $user_id, ?string $user_name_supplied){
    echo "you accessed user #" . htmlentities($user_id)
        . ", you think his name is " . htmlentities($user_name_supplied ?? 'unknown') . "...";
});
$router->set404(function(string $path, string $method){
    echo "404 not found: ".htmlentities($path);
});
$router->run();
```

# matching

routes are regular expressions, matched against the request **path**: the
request target with the query string removed. `GET /test?x=1` matches the route
`/test`; the query string is still available to the handler through `$_GET`.

for origin-form targets (the practically universal case) the path is **not
normalized and not decoded**. `%2F` stays `%2F`, a trailing `/` stays
significant, and `/foo` and `/foo%2F` are different paths. that is the
difference from bramus/router, which trims them before matching. absolute-form
targets (proxy-style) are parsed as uris instead, and are not byte-preserving.

`getRequestPath()` returns the path that routes are matched against. there is no
accessor for the raw target: read `$_SERVER['REQUEST_URI']` directly if you need
the query string. the 404 handler receives the path and the method.

routes are anchored for you: a route is wrapped as `\x01^(?:...)\z\x01`, so it
has to match the whole path and nothing else. `\z` is used rather than `$`,
because `$` also matches before a trailing newline. the wrapper is
non-capturing, so a top-level alternation stays anchored and your own capture
groups and backreferences keep their numbering.

an anchored route must match the whole path, so constructs that end the match
early or move where it appears to start do not do what you might expect: `\K`
shifts the reported start, and `(*ACCEPT)` ends the match early, and either way
the route only matches when the reported match covers the entire path. use
`matchRawRex()` for anything like that. it takes the pattern exactly as given,
with your own delimiters and anchors, and does not require whole-path coverage.

a route using `(?x)` must not end with a `#` comment: the wrapper's closing
`)` would be swallowed by the comment and registration would throw. put a
newline before the end, or use `matchRawRex()`.

if pcre refuses to evaluate a route (the backtrack or jit limit), `run()` sets
500 and returns. a path that a `u`-mode route cannot decode as utf-8 is 400
instead; a byte-oriented route accepts the same bytes and matches normally. in
either error case the request does not fall through to a later route and the
404 handler is not called: this is a bug in a route rather than a request that
matched nothing, so the application's own error handling is expected to take
over. note that a limit failure produces no php warning of its own.

`run()` returns the status it decided on: `200` when a route matched, `404`
when the 404 handler ran, `400` when the request target could not be parsed (or
a `u`-mode route could not decode the path), `500` when pcre failed. so if you
want to handle the error cases yourself:

```
$status = $router->run();
if ($status === 400 || $status === 500) {
    // your own error page
}
```

that is the router's decision, not necessarily the final response code: a
handler is free to set something else after a match.

capture groups are passed to the handler as arguments:

```
// positional captures become positional arguments
$router->get('/user/(\d+)/([^/]+)?', function ($id, $name) { /* ... */ });

// named captures become named arguments
$router->get('/user/(?<id>\d+)/(?<name>[^/]+)?', function ($id, $name) { /* ... */ });
```

captures are always **strings**, and an optional group that did not participate
is passed as `null`, so the handler always receives one argument per group.
type them `string` / `?string`, not `int`. with named groups, the handler must
declare every group by the same name, or php raises "Unknown named parameter".

a route uses positional captures or named captures, not both: when it has any
named group, only the named captures are passed and unnamed captures in the
same route are discarded. php cannot unpack the mixed result anyway, since
`preg_match()` reports a named group under both its name and its index.

# upgrading from 1.x

- `$ignoreGetParams` is gone. the query string was never part of the path, so
  it is now always removed before matching; the flag only ever existed to opt
  into that. delete any line setting it. if you matched query strings in your
  route regexes, read `$_GET` in the handler instead.
- `getRequestUri()` is gone; `getRequestPath()` returns the path that is
  matched. read `$_SERVER['REQUEST_URI']` if you need the raw target.
- routes now require a whole-path match, and a route that matches only part of
  the path no longer matches.
- a route with a broken regex throws `InvalidArgumentException` at
  registration instead of silently 404ing on every request.
- if pcre fails on a request, the response is 500 (or 400 for invalid utf-8)
  rather than 404, and the request does not fall through to a broader route.
- `run()` now returns the status it decided on instead of `void`.
- the 404 handler receives `(string $path, string $method)`.
- php 8.2 is required.
