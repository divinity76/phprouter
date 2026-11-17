<?php

declare(strict_types=1);

/**
 * dependency-free test runner: php tests/run.php
 */

require_once __DIR__ . '/../src/Divinity76/Phprouter/Phprouter.php';

use Divinity76\Phprouter\Phprouter;

$failures = [];
$passes = 0;

function check(string $label, $actual, $expected): void
{
    global $failures, $passes;
    if ($actual === $expected) {
        ++$passes;
        return;
    }
    $failures[] = "$label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true);
}

/**
 * run one request through a fresh router.
 *
 * @return array{hit: bool|string, args: array, status: int|false}
 */
function request(string $method, string $uri, callable $register): array
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $router = new Phprouter();
    $result = [
        'hit' => false,
        'args' => [],
        'status' => false
    ];
    $register($router, $result);
    $router->set404(function () use (&$result) {
        $result['hit'] = '404';
    });
    ob_start();
    try {
        $status = $router->run();
    } finally {
        ob_end_clean();
    }
    $result['status'] = $status;
    $result['hit'] = $status === 200 ? true : ($result['hit'] === '404' ? '404' : false);
    return $result;
}

function expect(string $label, string $method, string $uri, callable $register, bool|string $hit, array $args, int $status): void
{
    $r = request($method, $uri, $register);
    check($label, $r, [
        'hit' => $hit === false ? '404' : $hit,
        'args' => $args,
        'status' => $status
    ]);
}

function pathOf(string $uri): string
{
    $_SERVER['REQUEST_URI'] = $uri;
    return (new Phprouter())->getRequestPath();
}

// --- the founding use case: the path is not normalized or decoded ---------

expect('path without query', 'GET', '/test', function ($r) {
    $r->get('/test', function () {
    });
}, true, [], 200);

expect('path with query', 'GET', '/test?x=1', function ($r) {
    $r->get('/test', function () {
    });
}, true, [], 200);

expect('%2F is preserved, not trimmed', 'GET', '/foo%2F', function ($r, &$o) {
    $r->get('/([\s\S]+)', function ($p) use (&$o) {
        $o['args'] = [$p];
    });
}, true, ['foo%2F'], 200);

expect('%2f%2f is preserved', 'GET', '/foo%2f%2f', function ($r, &$o) {
    $r->get('/([\s\S]+)', function ($p) use (&$o) {
        $o['args'] = [$p];
    });
}, true, ['foo%2f%2f'], 200);

expect('trailing slash is significant', 'GET', '/foo/', function ($r) {
    $r->get('/foo', function () {
    });
}, false, [], 404);

expect('%2F is not a separator', 'GET', '/a%2Fb', function ($r) {
    $r->get('/a/([^/]+)', function () {
    });
}, false, [], 404);

expect('real slash is a separator', 'GET', '/a/b', function ($r, &$o) {
    $r->get('/a/([^/]+)', function ($b) use (&$o) {
        $o['args'] = [$b];
    });
}, true, ['b'], 200);

// --- request target accessor ---------------------------------------------

check('getRequestPath drops the query', pathOf('/a/b?c=d'), '/a/b');
check('getRequestPath keeps // in the path', pathOf('//foo/bar?x=1'), '//foo/bar');
check('getRequestPath on absolute-form', pathOf('http://kai.loltek.net/test?x=1'), '/test');
check('getRequestPath on empty uri', pathOf(''), '');
check('getRequestPath with no query', pathOf('/test'), '/test');
check('getRequestPath keeps a raw # in origin-form', pathOf('/foo#frag'), '/foo#frag');
check('absolute-form fragment is dropped by uri parsing', pathOf('http://h/foo#frag'), '/foo');
check('getRequestPath ? before #', pathOf('/a?b#c'), '/a');
check('getRequestPath rejects unparseable target', pathOf('http:///admin'), '');

// --- anchors --------------------------------------------------------------

expect('trailing newline does not match (\z not $)', 'GET', "/test\n", function ($r) {
    $r->get('/test', function () {
    });
}, false, [], 404);

expect('top-level alternation matches second branch', 'GET', '/other', function ($r) {
    $r->get('/test|/other', function () {
    });
}, true, [], 200);

expect('top-level alternation stays anchored', 'GET', '/testXYZ', function ($r) {
    $r->get('/test|/other', function () {
    });
}, false, [], 404);

expect('unbalanced ) cannot escape the anchor (prefix)', 'GET', '/adminXYZ', function ($r) {
    $r->get('/admin)|(/x', function () {
    });
}, false, [], 404);

expect('unbalanced ) cannot escape the anchor (suffix)', 'GET', '/xbar', function ($r) {
    $r->get('foo)|(bar', function () {
    });
}, false, [], 404);

expect('matchRawRex is a raw escape hatch (\\K)', 'GET', '/foobar', function ($r, &$o) {
    $r->matchRawRex("GET", "\x01^/foo\\K(bar)\\z\x01", function ($rest) use (&$o) {
        $o['args'] = [$rest];
    });
}, true, ['bar'], 200);

// --- # is regex syntax, not a delimiter -----------------------------------

expect('# inside (?#comment) works', 'GET', '/foo', function ($r) {
    $r->get('/foo(?#not a delimiter)', function () {
    });
}, true, [], 200);

// --- broken regexes fail at registration ----------------------------------

check('invalid regex throws at registration', (function () {
    $router = new Phprouter();
    // the compile warning is part of the failure mode; swallow it here so the
    // test output stays readable.
    set_error_handler(static function (): bool {
        return true;
    });
    try {
        try {
            $router->get('/admin(', function () {
            });
            return 'no exception';
        } catch (\InvalidArgumentException $e) {
            return 'InvalidArgumentException';
        }
    } finally {
        restore_error_handler();
    }
})(), 'InvalidArgumentException');

// --- a pcre failure is not a 404 ------------------------------------------

check('bad utf-8 in the path is 400', (function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/\xFF";
    $router = new Phprouter();
    $router->matchRawRex('GET', '/.*/u', function () {
    });
    $router->set404(function () {
    });
    ob_start();
    try {
        return $router->run();
    } finally {
        ob_end_clean();
    }
})(), 400);

check('a byte-oriented route accepts invalid utf-8', (function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = "/\xFF";
    $router = new Phprouter();
    $router->get('/.*', function () {
    });
    ob_start();
    try {
        return $router->run();
    } finally {
        ob_end_clean();
    }
})(), 200);

check('a pcre limit is 500, not 404', (function () {
    $old = ini_set('pcre.backtrack_limit', '1');
    try {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/' . str_repeat('ab', 2000) . '!';
        $router = new Phprouter();
        $router->matchRawRex('GET', '/(a+)+b/', function () {
        });
        $router->set404(function () {
        });
        ob_start();
        try {
            return $router->run();
        } finally {
            ob_end_clean();
        }
    } finally {
        ini_set('pcre.backtrack_limit', (string) $old);
    }
})(), 500);

check('an unparseable request target is 400', (function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = 'http:///admin';
    $router = new Phprouter();
    $router->get('/.*', function () {
    });
    $router->set404(function () {
        return 'unexpected 404';
    });
    ob_start();
    try {
        return $router->run();
    } finally {
        ob_end_clean();
    }
})(), 400);

// --- positional captures --------------------------------------------------

expect('positional captures', 'GET', '/user/42/bob', function ($r, &$o) {
    $r->get('/user/(\d+)/([^/]+)', function ($id, $name) use (&$o) {
        $o['args'] = [$id, $name];
    });
}, true, ['42', 'bob'], 200);

expect('optional positional capture present', 'GET', '/user/42/bob', function ($r, &$o) {
    $r->get('/user/(\d+)/([^/]+)?', function ($id, $name) use (&$o) {
        $o['args'] = [$id, $name];
    });
}, true, ['42', 'bob'], 200);

expect('optional positional capture absent is null', 'GET', '/user/42/', function ($r, &$o) {
    $r->get('/user/(\d+)/([^/]+)?', function ($id, $name) use (&$o) {
        $o['args'] = [$id, $name];
    });
}, true, ['42', null], 200);

expect('no capture groups passes no arguments', 'GET', '/test', function ($r) {
    $r->get('/test', function () {
    });
}, true, [], 200);

// --- named captures -------------------------------------------------------

expect('named captures', 'GET', '/user/42/bob', function ($r, &$o) {
    $r->get('/user/(?<id>\d+)/(?<name>[^/]+)', function ($id, $name) use (&$o) {
        $o['args'] = [$id, $name];
    });
}, true, ['42', 'bob'], 200);

expect('named captures, out of order', 'GET', '/user/42/bob', function ($r, &$o) {
    $r->get('/user/(?<id>\d+)/(?<name>[^/]+)', function ($name, $id) use (&$o) {
        $o['args'] = [$name, $id];
    });
}, true, ['bob', '42'], 200);

expect('optional named capture absent is null', 'GET', '/user/42/', function ($r, &$o) {
    $r->get('/user/(?<id>\d+)/(?<name>[^/]+)?', function ($id, $name) use (&$o) {
        $o['args'] = [$id, $name];
    });
}, true, ['42', null], 200);

expect('named captures do not leak integer keys', 'GET', '/user/42/bob', function ($r, &$o) {
    $r->get('/user/(?<id>\d+)/(?<name>[^/]+)', function (...$args) use (&$o) {
        $o['args'] = $args;
    });
}, true, ['id' => '42', 'name' => 'bob'], 200);

expect('mixed captures forward the named ones', 'GET', '/user/42/bob', function ($r, &$o) {
    $r->get('/user/(?<id>\d+)/([^/]+)', function ($id) use (&$o) {
        $o['args'] = [$id];
    });
}, true, ['42'], 200);

// --- methods --------------------------------------------------------------

expect('method mismatch is 404', 'POST', '/test', function ($r) {
    $r->get('/test', function () {
    });
}, false, [], 404);

expect('pipe separated methods', 'PATCH', '/test', function ($r) {
    $r->match('GET|PATCH', '/test', function () {
    });
}, true, [], 200);

// --- 404 ------------------------------------------------------------------

check('404 handler receives path and method', (function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/nope';
    $router = new Phprouter();
    $got = [];
    $router->set404(function ($path, $method) use (&$got) {
        $got = [$path, $method];
    });
    ob_start();
    try {
        $router->run();
    } finally {
        ob_end_clean();
    }
    return [$got, http_response_code()];
})(), [['/nope', 'GET'], 404]);

expect('404 handler with no parameters still works', 'GET', '/nope', function ($r) {
}, false, [], 404);

expect('empty router 404s', 'GET', '/', function () {
}, false, [], 404);

check('missing REQUEST_METHOD is 400', (function () {
    $_SERVER['REQUEST_URI'] = '/test';
    unset($_SERVER['REQUEST_METHOD']);
    $router = new Phprouter();
    $hit = false;
    $router->get('/test', function () use (&$hit) {
        $hit = true;
    });
    ob_start();
    try {
        $status = $router->run();
    } finally {
        ob_end_clean();
    }
    return [$hit, $status];
})(), [false, 400]);

check('empty request target is 400', (function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '';
    $router = new Phprouter();
    $router->get('/.*', function () {
    });
    ob_start();
    try {
        return $router->run();
    } finally {
        ob_end_clean();
    }
})(), 400);

check('a leading (*UTF) verb is hoisted', (function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/héllo';
    $router = new Phprouter();
    $router->get('(*UTF)/\p{L}+', function () {
    });
    ob_start();
    try {
        return $router->run();
    } finally {
        ob_end_clean();
    }
})(), 200);

// --- the return value and the response code agree -------------------------

check('http status matches the run() return value', (function () {
    $seen = [];
    foreach ([
        ['GET', '/hit', 200],
        ['GET', '/miss', 404],
        ['GET', 'http:///bad', 400],
    ] as [$method, $uri, $expected]) {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $router = new Phprouter();
        $router->get('/hit', function () {
        });
        $router->set404(function () {
        });
        ob_start();
        try {
            $returned = $router->run();
        } finally {
            ob_end_clean();
        }
        $seen[] = [$expected, $returned, http_response_code()];
    }
    return $seen;
})(), [[200, 200, 200], [404, 404, 404], [400, 400, 400]]);

echo "\n$passes passed, " . count($failures) . " failed\n";
foreach ($failures as $failure) {
    echo "FAIL: $failure\n";
}
exit(empty($failures) ? 0 : 1);
