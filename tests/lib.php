<?php
declare(strict_types=1);

use Sitewright\Core\ResponseException;

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0, 'failures' => []];

function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__t']['pass']++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $GLOBALS['__t']['fail']++;
        $GLOBALS['__t']['failures'][] = $name;
        echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) { throw new RuntimeException($msg); }
}

function eq(mixed $actual, mixed $expected, string $msg = ''): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** Assert $fn throws an instance of $class; returns the exception. */
function throws(string $class, callable $fn): Throwable
{
    try { $fn(); } catch (Throwable $e) {
        if ($e instanceof $class) { return $e; }
        throw new RuntimeException("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new RuntimeException("expected $class, nothing thrown");
}

/** Run controller code and capture the Response it throws. @return array{status:int,json:?array,headers:array,body:string} */
function resp(callable $fn): array
{
    try { $fn(); } catch (ResponseException $r) {
        return ['status' => $r->status, 'json' => json_decode($r->body, true), 'headers' => $r->headers, 'body' => $r->body];
    }
    throw new RuntimeException('controller did not produce a response');
}

function testDbConfig(): array
{
    return [
        'host' => getenv('SW_TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306, 'name' => 'sw_test',
        'user' => getenv('SW_TEST_DB_USER') ?: 'root', 'pass' => getenv('SW_TEST_DB_PASS') ?: '', 'prefix' => 'sw_',
    ];
}

/** Fresh schema via the real migrations; returns the Database. */
function freshDb(): Sitewright\Core\Database
{
    $c = testDbConfig();
    $pdo = Sitewright\Core\Database::connect($c, false);
    $pdo->exec('DROP DATABASE IF EXISTS `sw_test`');
    $pdo->exec('CREATE DATABASE `sw_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach (['host', 'port', 'name', 'user', 'pass', 'prefix'] as $k) { Sitewright\Core\Config::set("db.$k", $c[$k]); }
    Sitewright\Core\Database::reset();
    $db = Sitewright\Core\Database::getInstance();
    (new Sitewright\Core\Migrator($db->getConnection(), $c['prefix']))->run();
    return $db;
}

function dbAvailable(): bool
{
    try { Sitewright\Core\Database::connect(testDbConfig(), false); return true; } catch (Throwable) { return false; }
}

/** Start the real app under php -S. @return array{port:int,proc:resource,base:string} */
function startServer(array $env = []): array
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    $port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $c = testDbConfig();
    $env = $env + ['SW_CONFIG_FILE' => '/nonexistent/sitewright-test-config.php', 'SW_DB_HOST' => $c['host'], 'SW_DB_NAME' => $c['name'], 'SW_DB_USER' => $c['user'], 'SW_DB_PASS' => $c['pass'], 'PATH' => (string)getenv('PATH')];
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public', __DIR__ . '/router.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/tmp/sw-server.log', 'a']], $pipes, dirname(__DIR__), $env);
    for ($i = 0; $i < 60; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
    return ['port' => $port, 'proc' => $proc, 'base' => "http://127.0.0.1:$port"];
}

function stopServer(array $s): void
{
    if (is_resource($s['proc'])) { proc_terminate($s['proc']); proc_close($s['proc']); }
}

/** Minimal test HTTP client (test-only; the application itself only uses SafeHttpClient). Keeps a cookie jar. */
final class TestHttp
{
    public array $cookies = [];
    public ?string $csrf = null;
    public function __construct(private string $base) {}

    /** @return array{status:int,headers:array<string,string>,body:string,json:?array,ms:int} */
    public function req(string $method, string $path, ?array $json = null, array $headers = [], ?string $rawBody = null): array
    {
        $ch = curl_init($this->base . $path);
        $h = [];
        foreach ($headers as $k => $v) { $h[] = "$k: $v"; }
        if ($this->cookies) { $h[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies)); }
        $resp = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$resp) {
                if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $resp[strtolower(trim($k))][] = trim($v); }
                return strlen($line);
            },
        ]);
        if ($json !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json)); }
        if ($rawBody !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody); }
        $t = microtime(true);
        $body = (string)curl_exec($ch);
        $ms = (int)((microtime(true) - $t) * 1000);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        foreach ($resp['set-cookie'] ?? [] as $sc) { [$kv] = explode(';', $sc, 2); [$k, $v] = explode('=', $kv, 2); $this->cookies[$k] = $v; }
        return ['status' => $status, 'headers' => array_map(fn ($v) => implode(', ', $v), $resp), 'body' => $body, 'json' => json_decode($body, true), 'ms' => $ms, 'raw_set_cookie' => $resp['set-cookie'] ?? []];
    }

    /** Authenticated JSON request with the CSRF header and correct content type. */
    public function api(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $headers += ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string)$this->csrf];
        return $this->req($method, $path, $json ?? ($method === 'GET' ? null : []), $headers);
    }

    /** Load the shell page (creates the session + CSRF token) and return the embedded config. */
    public function bootstrap(string $path = '/'): array
    {
        $r = $this->req('GET', $path);
        preg_match('#<script type="application/json" id="app-config">(.*?)</script>#s', $r['body'], $m);
        $cfg = json_decode($m[1] ?? 'null', true) ?: [];
        $this->csrf = $cfg['csrfToken'] ?? null;
        return $cfg + ['_response' => $r];
    }

    public function login(string $user, string $pass): array
    {
        $this->bootstrap();
        $r = $this->req('POST', '/api/auth/login', ['username' => $user, 'password' => $pass], ['Content-Type' => 'application/json', 'X-CSRF-Token' => (string)$this->csrf]);
        if ($r['status'] === 200 && isset($r['json']['data']['csrf_token'])) { $this->csrf = $r['json']['data']['csrf_token']; }  // absent while an MFA code is pending
        return $r;
    }
}

function seedAdmin(string $user = 'admin', string $pass = 'correct horse battery', string $role = 'admin'): void
{
    $db = Sitewright\Core\Database::getInstance();
    $db->insert('users', ['username' => $user, 'email' => "$user@example.test", 'password' => password_hash($pass, PASSWORD_DEFAULT), 'display_name' => $user, 'role' => $role]);
}

/**
 * Install a scripted SafeHttpClient as the application default. $pages: url => [status, headers, body] | Throwable | callable(array $req): array.
 * Every transport call is appended to $GLOBALS['__calls'].
 */
function fakeWeb(array $pages, array $dns = [], array $cfg = []): void
{
    $GLOBALS['__calls'] = [];
    $resolver = static fn (string $h): array => $dns[$h] ?? ['93.184.216.34'];
    $transport = static function (array $r) use ($pages): array {
        $GLOBALS['__calls'][] = $r;
        $p = $pages[$r['url']] ?? [404, [], 'not found'];
        if ($p instanceof Throwable) { throw $p; }
        if (is_callable($p)) { $p = $p($r); }
        return ['status' => $p[0], 'headers' => $p[1], 'body' => $p[2]];
    };
    Sitewright\Core\SafeHttpClient::setDefault(new Sitewright\Core\SafeHttpClient($resolver, $transport, array_replace(Sitewright\Core\Config::get('fetch'), $cfg)));
}

function html(string $body, string $head = ''): array
{
    return [200, ['Content-Type' => 'text/html; charset=utf-8'], "<!doctype html><html lang=\"en\"><head><title>T</title>$head</head><body>$body</body></html>"];
}

function call(string $class, string $method, array $request = [], array $params = []): array
{
    Sitewright\Core\Request::fake($request);
    return resp(fn () => (new $class())->$method($params));
}

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';


/** Names of all migration files (without .sql), in order. */
function migrationNames(): array
{
    return array_map(static fn (string $f): string => basename($f, '.sql'), (array)glob(dirname(__DIR__) . '/database/migrations/*.sql'));
}

/**
 * Submit a background job the way the HTTP layer does, run the worker until the queue is empty, and return the outcome in the same
 * shape call() returns for a synchronous controller: ['status' => int, 'json' => ['success','message','data']].
 */
function callJob(string $endpoint, array $request): array
{
    $submit = call(Sitewright\Core\JobController::class, $endpoint, ['method' => 'POST', 'body' => $request['body'] ?? []]);
    if ($submit['status'] !== 202) { return $submit; }
    $id = (int)$submit['json']['data']['job_id'];
    while (Sitewright\Core\Jobs::runNext()) { /* drain */ }
    $db = Sitewright\Core\Database::getInstance();
    $row = $db->fetchOne('SELECT status, result, error, error_status FROM `' . $db->t('jobs') . '` WHERE id = :id', ['id' => $id]);
    if ($row['status'] === 'done') {
        $res = json_decode((string)$row['result'], true);
        return ['status' => 200, 'json' => ['success' => true, 'message' => $res['message'], 'data' => $res['data']], 'body' => (string)$row['result']];
    }
    return ['status' => (int)$row['error_status'], 'json' => ['success' => false, 'message' => $row['error']], 'body' => (string)$row['error']];
}
