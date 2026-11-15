<?php
use RankMathWebapp\Core\{Database, Migrator, Config};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable (use tests/docker/run.sh)')); return; }

test('migrations apply on an empty database and are idempotent', function () {
    $db = freshDb();
    $m = new Migrator($db->getConnection(), 'rm_');
    eq($m->run(), [], 'second run applies nothing');
    eq(array_values($m->status()), [true, true, true]);
});
test('schema: unused tables dropped, auth/rate-limit tables present', function () {
    $db = freshDb();
    $tables = array_column($db->fetchAll('SHOW TABLES'), 'Tables_in_rmw_test');
    foreach (['rm_users', 'rm_rate_limits', 'rm_schema_migrations', 'rm_404_monitor', 'rm_redirections', 'rm_sitemaps'] as $t) { ok(in_array($t, $tables, true), "missing $t"); }
    foreach (['rm_projects', 'rm_schema', 'rm_settings', 'rm_analytics_pages'] as $t) { ok(!in_array($t, $tables, true), "$t should be dropped"); }
});
test('unique keys enforce one row per natural key (DB-001)', function () {
    $db = freshDb();
    $db->insert('redirections', ['source_url' => '/a', 'target_url' => '/b', 'redirect_type' => '301']);
    $e = throws(RankMathWebapp\Core\DatabaseException::class, fn () => $db->insert('redirections', ['source_url' => '/a', 'target_url' => '/c', 'redirect_type' => '301']));
    $db->insert('redirections', ['project_id' => 2, 'source_url' => '/a', 'target_url' => '/b', 'redirect_type' => '301']);
    $db->insert('404_monitor', ['uri' => '/x']);
    throws(RankMathWebapp\Core\DatabaseException::class, fn () => $db->insert('404_monitor', ['uri' => '/x']));
});
test('legacy v1.0 database is adopted: 001 marked applied, 002+ run, data kept and de-duplicated', function () {
    $c = testDbConfig();
    $pdo = Database::connect($c, false);
    $pdo->exec('DROP DATABASE IF EXISTS rmw_test'); $pdo->exec('CREATE DATABASE rmw_test'); $pdo->exec('USE rmw_test');
    $sql = str_replace('{{prefix}}', 'rm_', file_get_contents(__DIR__ . '/../../database/migrations/001_initial.sql'));
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^\s*--.*$/m', '', $sql)))) as $q) { $pdo->exec($q); }
    $pdo->exec("INSERT INTO rm_redirections (project_id, source_url, target_url) VALUES (NULL,'/dup','/a'),(NULL,'/dup','/b'),(NULL,'/ok','/c')");
    $pdo->exec("INSERT INTO rm_sitemaps (type, url) VALUES ('news','http://x/a'),('general','http://x/a')");
    $applied = (new Migrator($pdo, 'rm_'))->run();
    eq($applied, ['002_integrity_auth', '003_drop_unused']);
    eq((int)$pdo->query('SELECT COUNT(*) FROM rm_redirections')->fetchColumn(), 2);
    eq((int)$pdo->query('SELECT COUNT(*) FROM rm_sitemaps')->fetchColumn(), 1);
});
test('prefix is validated (no SQL via prefix)', function () {
    $db = freshDb();
    throws(RankMathWebapp\Core\ConfigException::class, fn () => new Migrator($db->getConnection(), "rm_`; DROP TABLE x;--"));
});
