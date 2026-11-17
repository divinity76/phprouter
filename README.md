# phprouter
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
request target with the query string and fragment removed. `GET /test?x=1`
matches the route `/test`; the query string is still available to the handler
through `$_GET`.

the path is **not normalized and not decoded**. `%2F` stays `%2F`, a trailing
`/` stays significant, and `/foo` and `/foo%2F` are different paths. that is the
difference from bramus/router, which trims them before matching.

`getRequestUri()` returns the raw request target (query included) if you need
it, `getRequestPath()` returns the path that routes are matched against. the
404 handler receives the path and the method.

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

# upgrading from 1.x

- `$ignoreGetParams` is gone. the query string was never part of the path, so
  it is now always removed before matching; the flag only ever existed to opt
  into that. delete any line setting it. if you matched query strings in your
  route regexes, read `$_GET` in the handler instead, or match on
  `getRequestUri()` yourself.
- routes now also strip the fragment, and a route that matches only part of the
  path no longer matches.
- a route with a broken regex throws `InvalidArgumentException` at
  registration instead of silently 404ing on every request.
- the 404 handler receives `(string $path, string $method)`.
- php 8.2 is required.
