<?php
use Sitewright\Core\{Database, Installer};

// ---- input validation (no DB needed) -----------------------------------------------------------
$okDb = ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'sitewright', 'user' => 'u', 'pass' => 'p', 'prefix' => 'sw_'];
foreach ([
    ['port' => "3306;system('id')"], ['port' => '0'], ['port' => '70000'], ['port' => '33a'], ['port' => ' 3306'], ['port' => '-1'],
    ['name' => 'db`; DROP DATABASE x;--'], ['name' => ''], ['name' => str_repeat('a', 65)], ['name' => 'a-b'],
    ['prefix' => "sw_'); system('id'); //"], ['prefix' => 'abcdefghijklmnopq'], ['prefix' => 'rm-'],
    ['host' => "h';system('id');//"], ['host' => 'a b'], ['host' => "h\n"], ['host' => ''],
    ['user' => ''], ['user' => "u\nx"], ['pass' => str_repeat('p', 256)], ['pass' => "a\0b"],
] as $bad) {
    test('installer rejects ' . json_encode($bad), fn () => throws(InvalidArgumentException::class, fn () => Installer::validateDb($bad + $okDb)));
}
test('installer accepts a sane configuration, including IPv6 and an empty prefix', function () use ($okDb) {
    eq(Installer::validateDb($okDb)['port'], 3306);
    eq(Installer::validateDb(['host' => '::1', 'prefix' => ''] + $okDb)['prefix'], '');
});
foreach ([['username' => 'a'], ['username' => 'bad name'], ['username' => "adm'in"], ['email' => 'nope'], ['password' => 'short'], ['password' => '']] as $bad) {
    test('installer rejects admin ' . json_encode($bad), fn () => throws(InvalidArgumentException::class, fn () => Installer::validateAdmin($bad + ['username' => 'admin', 'email' => 'a@example.test', 'password' => 'long enough password'])));
}
test('generated config is data, not code: injection payloads round-trip verbatim (SEC-001)', function () {
    $evil = ["');system('id');//", '"; phpinfo(); //', "\\'; echo 1; //", '$x = `id`;', "multi\nline", '?>' . '<?php die("x");'];
    foreach ($evil as $pw) {
        $src = Installer::renderConfig(['host' => '127.0.0.1', 'port' => 3306, 'name' => 'n', 'user' => $pw, 'pass' => $pw, 'prefix' => 'sw_']);
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_STRING, T_INLINE_HTML, T_EVAL, T_INCLUDE, T_REQUIRE], true) ) { throw new RuntimeException('executable token ' . token_name($t[0]) . ' in config'); }
        }
        $f = tempnam(sys_get_temp_dir(), 'cfg'); file_put_contents($f, $src);
        $cfg = (static fn () => include $f)();
        unlink($f);
        eq($cfg['db']['pass'], $pw); eq($cfg['db']['user'], $pw); eq($cfg['db']['port'], 3306);
    }
});

// ---- real install against the test DB ------------------------------------------------------------
if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$c = testDbConfig();
$tmp = sys_get_temp_dir() . '/sw-inst-' . getmypid(); @mkdir($tmp);
$opts = fn (array $o = []) => $o + ['create_db' => true, 'config_path' => "$tmp/config.php", 'lock_path' => "$tmp/install.lock",
    'db' => ['host' => $c['host'], 'port' => '3306', 'name' => 'sw_inst', 'user' => $c['user'], 'pass' => $c['pass'], 'prefix' => 'sw_'],
    'admin' => ['username' => 'root_admin', 'email' => 'root@example.test', 'password' => 'a very long passphrase']];

test('install: creates schema + admin, writes 0600 config + lock, password is hashed', function () use ($opts, $tmp, $c) {
    Database::connect($c, false)->exec('DROP DATABASE IF EXISTS sw_inst');
    $r = Installer::run($opts());
    eq(count($r["applied"]), count(migrationNames()));
    eq(substr(sprintf('%o', fileperms("$tmp/config.php")), -4), '0600');
    ok(file_exists("$tmp/install.lock"));
    $pdo = Database::connect(['name' => 'sw_inst'] + $c);
    $row = $pdo->query('SELECT password FROM sw_users')->fetch();
    ok(password_verify('a very long passphrase', $row['password']) && $row['password'] !== 'a very long passphrase');
});
test('install: second run is refused (config or lock exists) and changes nothing', function () use ($opts, $tmp, $c) {
    throws(RuntimeException::class, fn () => Installer::run($opts()));
    unlink("$tmp/config.php");
    throws(RuntimeException::class, fn () => Installer::run($opts()), 'lock file alone also blocks');
    $pdo = Database::connect(['name' => 'sw_inst'] + $c);
    eq((int)$pdo->query('SELECT COUNT(*) FROM sw_users')->fetchColumn(), 1);
});
test('install: invalid input fails before touching the database', function () use ($opts, $tmp, $c) {
    @unlink("$tmp/install.lock");
    Database::connect($c, false)->exec('DROP DATABASE IF EXISTS sw_inst');
    throws(InvalidArgumentException::class, fn () => Installer::run($opts(['db' => ['port' => '3306;x'] + $opts()['db']])));
    $exists = Database::connect($c, false)->query("SHOW DATABASES LIKE 'sw_inst'")->fetchAll();
    eq($exists, []);
    ok(!file_exists("$tmp/config.php"));
});
test('install: re-running migrations on an installed DB is idempotent (FUNC-014)', function () use ($opts, $tmp, $c) {
    Installer::run($opts(['admin' => ['username' => 'second_admin', 'email' => 's@example.test', 'password' => 'another long passphrase']]));
    $pdo = Database::connect(['name' => 'sw_inst'] + $c);
    eq(array_keys((new Sitewright\Core\Migrator($pdo, 'sw_'))->status()), migrationNames());
    eq((new Sitewright\Core\Migrator($pdo, 'sw_'))->run(), []);
});
test('install: custom prefix is applied via placeholder (not blind str_replace)', function () use ($opts, $tmp, $c) {
    @unlink("$tmp/install.lock"); @unlink("$tmp/config.php");
    Database::connect($c, false)->exec('DROP DATABASE IF EXISTS sw_inst');
    Installer::run($opts(['db' => ['prefix' => 'x_'] + $opts()['db']]));
    $names = Database::connect(['name' => 'sw_inst'] + $c)->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    ok(in_array('x_users', $names, true) && !in_array('sw_users', $names, true), implode(',', $names));
});
Database::connect($c, false)->exec('DROP DATABASE IF EXISTS sw_inst');
array_map('unlink', glob("$tmp/*") ?: []); @rmdir($tmp);

test('bin/install.php is CLI-only and rejects a malicious port without writing config', function () {
    $before = file_exists(Installer::configPath());
    $out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && SW_DB_PASS=x SW_ADMIN_PASSWORD="long enough password!" php bin/install.php --db-host=127.0.0.1 --db-port="3306;system(1)" --db-name=a --db-user=u --admin-user=admin --admin-email=a@b.co 2>&1; echo "exit=$?"');
    ok(str_contains((string)$out, 'Invalid database port') && str_contains((string)$out, 'exit=1'), (string)$out);
    eq(file_exists(Installer::configPath()), $before);
});

test('bin/user.php: create, duplicate refused, passwd changes hash, list works, weak password rejected', function () {
    $c = testDbConfig();
    $db = freshDb();
    $env = sprintf('SW_DB_HOST=%s SW_DB_NAME=%s SW_DB_USER=%s SW_DB_PASS=%s', escapeshellarg($c['host']), 'sw_test', escapeshellarg($c['user']), escapeshellarg($c['pass']));
    $run = fn (string $pw, string $args) => (string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . " && $env SW_USER_PASSWORD=" . escapeshellarg($pw) . " php bin/user.php $args 2>&1; echo \"exit=\$?\"");
    ok(str_contains($run('a long passphrase 1', 'create alice alice@example.test'), 'Created alice'));
    ok(str_contains($run('a long passphrase 1', 'create alice alice@example.test'), 'exit=1'), 'duplicate refused');
    ok(str_contains($run('short', 'create bob bob@example.test'), 'exit=1'), 'weak password refused');
    $old = $db->fetchOne("SELECT password FROM sw_users WHERE username = 'alice'")['password'];
    ok(password_verify('a long passphrase 1', $old));
    ok(str_contains($run('another long passphrase 2', 'passwd alice'), 'Password updated'));
    $new = $db->fetchOne("SELECT password FROM sw_users WHERE username = 'alice'")['password'];
    ok(password_verify('another long passphrase 2', $new) && !password_verify('a long passphrase 1', $new));
    ok(str_contains($run('x', 'list'), "alice\talice@example.test"));
});
