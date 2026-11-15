<?php
declare(strict_types=1);

use RankMathWebapp\Core\ResponseException;

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
        'host' => getenv('RMW_TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306, 'name' => 'rmw_test',
        'user' => getenv('RMW_TEST_DB_USER') ?: 'root', 'pass' => getenv('RMW_TEST_DB_PASS') ?: '', 'prefix' => 'rm_',
    ];
}

/** Fresh schema via the real migrations; returns the Database. */
function freshDb(): RankMathWebapp\Core\Database
{
    $c = testDbConfig();
    $pdo = RankMathWebapp\Core\Database::connect($c, false);
    $pdo->exec('DROP DATABASE IF EXISTS `rmw_test`');
    $pdo->exec('CREATE DATABASE `rmw_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach (['host', 'port', 'name', 'user', 'pass', 'prefix'] as $k) { RankMathWebapp\Core\Config::set("db.$k", $c[$k]); }
    RankMathWebapp\Core\Database::reset();
    $db = RankMathWebapp\Core\Database::getInstance();
    (new RankMathWebapp\Core\Migrator($db->getConnection(), $c['prefix']))->run();
    return $db;
}

function dbAvailable(): bool
{
    try { RankMathWebapp\Core\Database::connect(testDbConfig(), false); return true; } catch (Throwable) { return false; }
}
