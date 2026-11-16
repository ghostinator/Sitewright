<?php
use Sitewright\Core\{Database, Migrator, Config};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable (use tests/docker/run.sh)')); return; }

test('migrations apply on an empty database and are idempotent', function () {
    $db = freshDb();
    $m = new Migrator($db->getConnection(), 'sw_');
    eq($m->run(), [], 'second run applies nothing');
    eq(array_values($m->status()), array_fill(0, count(migrationNames()), true));
});
test('schema: unused tables dropped, auth/rate-limit tables present', function () {
    $db = freshDb();
    $tables = array_column($db->fetchAll('SHOW TABLES'), 'Tables_in_sw_test');
    foreach (['sw_users', 'sw_rate_limits', 'sw_schema_migrations', 'sw_404_monitor', 'sw_redirections', 'sw_sitemaps', 'sw_audit_log', 'sw_recovery_codes'] as $t) { ok(in_array($t, $tables, true), "missing $t"); }
    foreach (['sw_projects', 'sw_schema', 'sw_analytics_pages'] as $t) { ok(!in_array($t, $tables, true), "$t should be dropped"); }
    // 003 drops the legacy `settings` table; 009 creates the new one (key/value) under the same name
    ok(in_array('sw_settings', $tables, true) && in_array('skey', array_column($db->fetchAll('SHOW COLUMNS FROM sw_settings'), 'Field'), true), 'new settings table has the skey column');
});
test('unique keys enforce one row per natural key (DB-001)', function () {
    $db = freshDb();
    $db->insert('redirections', ['source_url' => '/a', 'target_url' => '/b', 'redirect_type' => '301']);
    $e = throws(Sitewright\Core\DatabaseException::class, fn () => $db->insert('redirections', ['source_url' => '/a', 'target_url' => '/c', 'redirect_type' => '301']));
    $db->insert('redirections', ['project_id' => 2, 'source_url' => '/a', 'target_url' => '/b', 'redirect_type' => '301']);
    $db->insert('404_monitor', ['uri' => '/x']);
    throws(Sitewright\Core\DatabaseException::class, fn () => $db->insert('404_monitor', ['uri' => '/x']));
});
test('legacy v1.0 database is adopted: 001 marked applied, 002+ run, data kept and de-duplicated', function () {
    $c = testDbConfig();
    $pdo = Database::connect($c, false);
    $pdo->exec('DROP DATABASE IF EXISTS sw_test'); $pdo->exec('CREATE DATABASE sw_test'); $pdo->exec('USE sw_test');
    $sql = str_replace('{{prefix}}', 'sw_', file_get_contents(__DIR__ . '/../../database/migrations/001_initial.sql'));
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^\s*--.*$/m', '', $sql)))) as $q) { $pdo->exec($q); }
    $pdo->exec("INSERT INTO sw_redirections (project_id, source_url, target_url) VALUES (NULL,'/dup','/a'),(NULL,'/dup','/b'),(NULL,'/ok','/c')");
    $pdo->exec("INSERT INTO sw_sitemaps (type, url) VALUES ('news','http://x/a'),('general','http://x/a')");
    $applied = (new Migrator($pdo, 'sw_'))->run();
    eq($applied, array_slice(migrationNames(), 1));
    eq((int)$pdo->query('SELECT COUNT(*) FROM sw_redirections')->fetchColumn(), 2);
    eq((int)$pdo->query('SELECT COUNT(*) FROM sw_sitemaps')->fetchColumn(), 1);
});
test('prefix is validated (no SQL via prefix)', function () {
    $db = freshDb();
    throws(Sitewright\Core\ConfigException::class, fn () => new Migrator($db->getConnection(), "sw_`; DROP TABLE x;--"));
});
