<?php
use Sitewright\Core\{Alerts, Config, JobRetryException, Jobs, Logger, Mailer, MailException, Request, Totp};

if (!dbAvailable()) { test('database available', fn () => ok(false, 'MariaDB not reachable')); return; }
$db = freshDb();
$wipe = function () use ($db) { foreach (['jobs', 'rate_limits', 'kv'] as $t) { $db->query("TRUNCATE sw_$t"); } };
$job = fn (int $id) => $db->fetchOne('SELECT * FROM sw_jobs WHERE id = :i', ['i' => $id]);

// ---- queue -----------------------------------------------------------------------------------------------------------------
test('jobs: enqueue + run; unknown type fails; handler crash is "Internal error" and does not leak its message', function () use ($wipe, $job) {
    $wipe();
    Jobs::register('echo', fn (array $p) => ['message' => 'hi', 'data' => $p]);
    Jobs::register('boom', function () { throw new RuntimeException('secret-db-password-in-message'); });
    $a = Jobs::enqueue('echo', ['x' => 1]); $b = Jobs::enqueue('nope', []); $c = Jobs::enqueue('boom', []);
    ok(Jobs::runNext() && Jobs::runNext() && Jobs::runNext()); ok(!Jobs::runNext(), 'queue empty');
    eq($job($a)['status'], 'done'); eq(json_decode($job($a)['result'], true)['data'], ['x' => 1]);
    eq($job($b)['status'], 'failed'); ok(str_contains($job($b)['error'], 'Unknown job type'));
    eq($job($c)['status'], 'failed'); eq($job($c)['error'], 'Internal error');
});
test('jobs: a job is claimed exactly once even if two claimers race for it', function () use ($wipe, $job, $db) {
    $wipe();
    Jobs::register('count', function () { static $n = 0; $n++; return ['message' => 'n', 'data' => $n]; });
    $id = Jobs::enqueue('count', []);
    // a second connection steals the claim between SELECT and UPDATE: the conditional UPDATE must then lose
    $c = testDbConfig(); $other = Sitewright\Core\Database::connect($c, true);
    $other->exec("UPDATE sw_jobs SET status='running', attempts=1, heartbeat_at=NOW() WHERE id=$id");
    ok(!Jobs::runNext(), 'already running elsewhere => not claimed again');
    eq($job($id)['attempts'], 1);
});
test('jobs: a crashed worker is detected: stale running job is re-queued, or failed when attempts are used up', function () use ($wipe, $job, $db) {
    $wipe();
    Jobs::register('ok', fn () => ['message' => 'fine', 'data' => null]);
    $retry = Jobs::enqueue('ok', [], null, 0, 3); $dead = Jobs::enqueue('ok', [], null, 0, 1);
    $db->query("UPDATE sw_jobs SET status='running', attempts=1, heartbeat_at = NOW() - INTERVAL 1 HOUR");
    ok(Jobs::runNext(), 'the retryable one ran again'); eq($job($retry)['status'], 'done');
    eq($job($dead)['status'], 'failed'); ok(str_contains($job($dead)['error'], 'Worker stopped'));
});
test('jobs: JobRetryException re-queues with delay until attempts run out, then fails', function () use ($wipe, $job, $db) {
    $wipe();
    Jobs::register('flaky', function () { throw new JobRetryException('later', 0); });
    $id = Jobs::enqueue('flaky', [], null, 0, 3);
    ok(Jobs::runNext()); eq($job($id)['status'], 'queued'); ok(Jobs::runNext()); eq($job($id)['status'], 'queued');
    ok(Jobs::runNext()); eq($job($id)['status'], 'failed'); eq($job($id)['attempts'], 3);
});
test('jobs: per-user quota (429) leaves other users unaffected', function () use ($wipe) {
    $wipe();
    for ($i = 0; $i < 5; $i++) { Jobs::enqueue('x', [], 7); }
    $r = resp(fn () => Jobs::enqueue('x', [], 7));
    eq($r['status'], 429); eq(Jobs::enqueue('x', [], 8) > 0, true);
});
test('jobs: oversized payload is refused', function () {
    throws(InvalidArgumentException::class, fn () => Jobs::enqueue('x', ['a' => str_repeat('a', 70000)]));
});
test('jobs: maintenance removes only old finished jobs', function () use ($wipe, $db) {
    $wipe();
    $db->query("INSERT INTO sw_jobs (type,payload,status,finished_at) VALUES ('x','{}','done',NOW() - INTERVAL 30 DAY),('x','{}','done',NOW()),('x','{}','queued',NULL)");
    eq(Jobs::maintenance()['jobs'], 1); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_jobs')['n'], 2);
});

// ---- logger ----------------------------------------------------------------------------------------------------------------
test('logger: JSON lines, secrets masked, long text cut, level filter, file destination', function () {
    $f = tempnam(sys_get_temp_dir(), 'swlog'); Config::set('log.file', $f); Config::set('log.level', 'info');
    Logger::debug('hidden'); Logger::info('hello', ['api_key' => 'AIzaSECRET', 'password' => 'p', 'telegram_token' => 't', 'note' => str_repeat('x', 1000) . "\nforged line"]);
    $lines = array_filter(explode("\n", (string)file_get_contents($f)));
    eq(count($lines), 1); $j = json_decode(reset($lines), true);
    eq($j['event'], 'hello'); eq($j['level'], 'info'); eq($j['api_key'], '[masked]'); eq($j['password'], '[masked]'); eq($j['telegram_token'], '[masked]');
    ok(strlen($j['note']) <= 300 && !str_contains($j['note'], "\n")); ok(!str_contains((string)file_get_contents($f), 'AIzaSECRET'));
    unlink($f); Config::set('log.file', '/dev/null');
});

// ---- mailer against a fake SMTP server -------------------------------------------------------------------------------------
$smtp = function (string $mode, string $user = 'postal', string $pass = 'p@ss', string $pem = '') {
    $sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
    $out = tempnam(sys_get_temp_dir(), 'swsmtp');
    $p = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/smtp_server.php', (string)$port, $mode, $user, $pass, $out, $pem], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
    return ['port' => $port, 'out' => $out, 'proc' => $p];
};
$cfgMail = function (int $port, array $over = []) { foreach (['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'postal', 'password' => 'p@ss', 'from' => 'sitewright@example.test', 'from_name' => 'Sitewright', 'verify_tls' => true, 'timeout' => 5] as $k => $v) { Config::set("mail.$k", $over[$k] ?? $v); } };
$messages = fn (array $s) => array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($s['out']))));

test('mail: delivers with AUTH PLAIN, UTF-8 subject encoded, body quoted-printable, dot-stuffed', function () use ($smtp, $cfgMail, $messages) {
    $s = $smtp('plain'); $cfgMail($s['port']);
    Mailer::send('ops@example.test', 'Grüße from Sitewright', "line one\n.starts with a dot\nüber");
    $m = $messages($s)[0]; proc_terminate($s['proc']);
    ok($m['auth_ok']); eq($m['user'], 'postal'); ok(str_contains($m['from'], '<sitewright@example.test>')); ok(str_contains($m['to'], '<ops@example.test>'));
    ok(str_contains($m['data'], 'Subject: =?UTF-8?B?'), 'subject encoded'); ok(str_contains($m['data'], "\r\n..starts with a dot"), 'dot-stuffed');
    ok(str_contains($m['data'], 'Content-Transfer-Encoding: quoted-printable') && str_contains($m['data'], '=C3=BCber'));
});
test('mail: CR/LF in header values or recipients is rejected (no header injection)', function () use ($cfgMail) {
    $cfgMail(1);
    foreach ([['a@example.test', "Hi\r\nBcc: evil@example.test"], ["a@example.test\r\nRCPT TO:<evil@example.test>", 'x'], ["a@example.test>\nX", 'x']] as [$to, $subj]) {
        throws(MailException::class, fn () => Mailer::send($to, $subj, 'b'));
    }
    Config::set('mail.from_name', "Evil\r\nBcc: x@example.test");
    throws(MailException::class, fn () => Mailer::send('a@example.test', 's', 'b'));
});
test('mail: wrong password => rejected, message says so without echoing the password', function () use ($smtp, $cfgMail) {
    $s = $smtp('plain'); $cfgMail($s['port'], ['password' => 'WRONG-pass-123']);
    try { Mailer::send('ops@example.test', 's', 'b'); ok(false, 'should fail'); } catch (MailException $e) { ok(str_contains($e->getMessage(), 'Authentication was rejected') && !str_contains($e->getMessage(), 'WRONG')); }
    proc_terminate($s['proc']);
});
test('mail: STARTTLS verifies the certificate by default (self-signed refused) and works when verification is off', function () use ($smtp, $cfgMail, $messages) {
    $key = openssl_pkey_new(['private_key_bits' => 2048]); $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
    $crt = openssl_csr_sign($csr, null, $key, 1); openssl_x509_export($crt, $c); openssl_pkey_export($key, $k);
    $pem = tempnam(sys_get_temp_dir(), 'swpem'); file_put_contents($pem, $c . $k);
    $s = $smtp('starttls', 'postal', 'p@ss', $pem);
    $cfgMail($s['port'], ['encryption' => 'starttls', 'verify_tls' => true]);
    try { Mailer::send('ops@example.test', 's', 'b'); ok(false, 'self-signed must be refused'); } catch (MailException $e) { ok(str_contains($e->getMessage(), 'TLS')); }
    $cfgMail($s['port'], ['encryption' => 'starttls', 'verify_tls' => false]);
    Mailer::send('ops@example.test', 'over tls', 'b');
    $m = $messages($s); proc_terminate($s['proc']); unlink($pem);
    ok(end($m)['tls'] === true && end($m)['auth_ok'], 'delivered over TLS after AUTH');
});
test('mail: queued mail is delivered by the worker; server down => retried, not lost', function () use ($smtp, $cfgMail, $messages, $wipe, $db) {
    $wipe(); $s = $smtp('plain'); $cfgMail($s['port']);
    Mailer::queue('ops@example.test', 'queued', 'body'); ok(Jobs::runNext()); proc_terminate($s['proc']);
    eq(count($messages($s)), 1);
    $cfgMail(1); Mailer::queue('ops@example.test', 'later', 'body'); Jobs::runNext();
    $r = $db->fetchOne("SELECT * FROM sw_jobs ORDER BY id DESC LIMIT 1");
    eq($r['status'], 'queued'); ok($r['error'] !== null && str_contains($r['error'], 'mail:'));
});

// ---- alerts ----------------------------------------------------------------------------------------------------------------
$alertCfg = function (array $o = []) { Config::set('alerts', $o + ['email_to' => [], 'teams_webhook' => '', 'telegram_token' => '', 'telegram_chat_id' => '', 'cooldown' => 300, 'login_failures' => 3]); Config::set('mail.host', ''); };
$TG = 'https://api.telegram.org/bot123456:SECRET-TOKEN/sendMessage';
test('alerts: no channel configured => nothing is queued', function () use ($wipe, $alertCfg, $db) {
    $wipe(); $alertCfg(); Alerts::notify('x', 'm'); eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_jobs')['n'], 0); eq(Alerts::channels(), []);
});
test('alerts: Teams gets an Adaptive Card message; Telegram gets chat_id + text; one job per channel', function () use ($wipe, $alertCfg, $TG, $db) {
    $wipe(); $alertCfg(['teams_webhook' => 'https://example.webhook.office.com/webhookb2/abc', 'telegram_token' => '123456:SECRET-TOKEN', 'telegram_chat_id' => '-100123']);
    fakeWeb(['https://example.webhook.office.com/webhookb2/abc' => [202, [], ''], $TG => [200, [], '{"ok":true}']]);
    Alerts::notify('server_error', 'HTTP 500 on GET /x', 'error');
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_jobs')['n'], 2);
    while (Jobs::runNext()) {}
    $calls = $GLOBALS['__calls']; eq(count($calls), 2);
    $teams = json_decode($calls[0]['body'], true);
    eq($teams['type'], 'message'); eq($teams['attachments'][0]['contentType'], 'application/vnd.microsoft.card.adaptive');
    eq($teams['attachments'][0]['content']['type'], 'AdaptiveCard'); ok(str_contains($teams['attachments'][0]['content']['body'][1]['text'], 'HTTP 500'));
    $tg = json_decode($calls[1]['body'], true); eq($tg['chat_id'], '-100123'); ok(str_contains($tg['text'], 'HTTP 500'));
    eq($calls[0]['method'], 'POST'); eq($db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE status='done'")['n'], 2);
});
test('alerts: cooldown suppresses repeats per event+key; a different key still alerts', function () use ($wipe, $alertCfg, $db) {
    $wipe(); $alertCfg(['teams_webhook' => 'https://example.webhook.office.com/x']);
    Alerts::notify('e', 'one'); Alerts::notify('e', 'two'); Alerts::notify('e', 'three', 'warning', 'other-key');
    eq((int)$db->fetchOne('SELECT COUNT(*) n FROM sw_jobs')['n'], 2);
});
test('alerts: a failing channel is retried; the Telegram token never appears in job rows or logs', function () use ($wipe, $alertCfg, $TG, $db) {
    $wipe(); $alertCfg(['telegram_token' => '123456:SECRET-TOKEN', 'telegram_chat_id' => '1']);
    $f = tempnam(sys_get_temp_dir(), 'swlog'); Config::set('log.file', $f);
    fakeWeb([$TG => [500, [], 'oops']]);
    Alerts::notify('e', 'm'); Jobs::runNext();
    $r = $db->fetchOne('SELECT status, error, payload FROM sw_jobs'); eq($r['status'], 'queued'); ok(str_contains($r['error'], 'HTTP 500'));
    ok(!str_contains(json_encode($r), 'SECRET-TOKEN') && !str_contains((string)file_get_contents($f), 'SECRET-TOKEN'));
    unlink($f); Config::set('log.file', '/dev/null');
});
test('alerts: watch() alerts once when the threshold is reached inside the window', function () use ($wipe, $alertCfg, $db) {
    $wipe(); $alertCfg(['teams_webhook' => 'https://example.webhook.office.com/x']);
    for ($i = 0; $i < 6; $i++) { Alerts::watch('t', 3, 600, 'burst', 'many'); }
    eq((int)$db->fetchOne("SELECT COUNT(*) n FROM sw_jobs WHERE type='alert'")['n'], 1);
});

// ---- HTTP + CLI ------------------------------------------------------------------------------------------------------------
seedAdmin('alice', 'alice passphrase long', 'editor'); seedAdmin('bob', 'bob passphrase long here', 'editor'); seedAdmin('root', 'root passphrase long here', 'admin');
$srv = startServer(); $base = $srv['base'];
test('jobs over HTTP: 202 + job id; only the owner can read it; 6th concurrent job is 429', function () use ($base, $wipe) {
    $wipe();
    $a = new TestHttp($base); $a->login('alice', 'alice passphrase long');
    $ids = [];
    for ($i = 0; $i < 5; $i++) { $r = $a->api('POST', '/api/sitemap/crawl', ['base_url' => "https://site$i.example/"]); eq($r['status'], 202, $r['body']); $ids[] = $r['json']['data']['job_id']; }
    $r = $a->api('POST', '/api/sitemap/crawl', ['base_url' => 'https://site9.example/']); eq($r['status'], 429); ok(isset($r['headers']['retry-after']));
    $s = $a->api('GET', '/api/jobs/' . $ids[0]); eq($s['status'], 200); eq($s['json']['data']['status'], 'queued'); eq($s['json']['data']['type'], 'crawl');
    $b = new TestHttp($base); $b->login('bob', 'bob passphrase long here');
    eq($b->api('GET', '/api/jobs/' . $ids[0])['status'], 404, 'another user cannot see it');
    $adm = new TestHttp($base); $adm->login('root', 'root passphrase long here');
    eq($adm->api('GET', '/api/jobs/' . $ids[0])['status'], 200, 'an administrator can');
    eq($a->api('POST', '/api/sitemap/crawl', ['base_url' => 'ftp://x'])['status'], 400);
});
test('bin/worker.php --once drains the queue and records a heartbeat; bin/healthcheck.php sees a dead worker and recovers', function () use ($wipe, $db) {
    $wipe(); $c = testDbConfig();
    $env = sprintf('SW_DB_HOST=%s SW_DB_NAME=%s SW_DB_USER=%s SW_DB_PASS=%s SW_LOG_FILE=/dev/null', escapeshellarg($c['host']), 'sw_test', escapeshellarg($c['user']), escapeshellarg($c['pass']));
    $run = fn (string $s) => (string)shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . " && $env php bin/$s 2>&1; echo \"exit=\$?\"");
    @unlink(dirname(__DIR__, 2) . '/storage/health.state');
    $db->query("INSERT INTO sw_jobs (type,payload,run_after) VALUES ('crawl', '{\"base_url\":\"http://127.0.0.1/\"}', NOW() - INTERVAL 10 MINUTE)");
    $out = $run('healthcheck.php'); ok(str_contains($out, 'worker is not running') && str_contains($out, 'exit=1'), $out);
    $out = $run('worker.php --once'); ok(str_contains($out, 'exit=0'), $out);
    eq($db->fetchOne('SELECT status FROM sw_jobs')['status'], 'failed', 'loopback target refused, job failed cleanly');
    ok(Jobs::workerAge() !== null && Jobs::workerAge() < 60);
    $out = $run('healthcheck.php'); ok(str_contains($out, 'ok') && str_contains($out, 'exit=0'), $out);
    @unlink(dirname(__DIR__, 2) . '/storage/health.state');
    ok(str_contains($run('alert-test.php'), 'No alert channel'), 'alert-test explains how to configure');
});
