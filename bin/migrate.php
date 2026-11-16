<?php
declare(strict_types=1);

/** Apply pending database migrations using the configured account (needs CREATE/ALTER/DROP/INDEX). Usage: php bin/migrate.php */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\{Config, Database, Migrator};

try {
    $m = new Migrator(Database::getInstance()->getConnection(), (string)Config::get('db.prefix'));
    $applied = $m->run();
    echo $applied ? 'Applied: ' . implode(', ', $applied) . "\n" : "Nothing to apply.\n";
} catch (\Throwable $e) {
    error_log('migrate: ' . $e->getMessage());
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
