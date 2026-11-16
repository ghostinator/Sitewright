<?php
declare(strict_types=1);

/** CLI-only environment check (replaces the old web-reachable check.php / phpinfo.php). */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';

use Sitewright\Core\{Config, Database};

$ok = true;
$line = static function (string $label, bool $pass, string $extra = '') use (&$ok): void { $ok = $ok && $pass; echo ($pass ? '[ok]   ' : '[FAIL] ') . $label . ($extra !== '' ? " - $extra" : '') . "\n"; };

$line('PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION);
foreach (['pdo_mysql', 'curl', 'mbstring', 'dom', 'json', 'libxml'] as $ext) { $line("extension $ext", extension_loaded($ext)); }
$line('config/config.php present (or SW_DB_* set)', is_file(Config::root() . '/config/config.php') || getenv('SW_DB_NAME') !== false);
try { Database::getInstance(); $line('database connection', true); } catch (\Throwable $e) { $line('database connection', false, 'see PHP error log'); }
$line('allowed hosts', true, implode(', ', Config::allowedHosts()));
$line('Gemini configured', true, Config::get('gemini.api_key') !== '' ? 'yes (' . Config::get('gemini.model') . ')' : 'no (templates only)');
$line('geocoding (Nominatim) enabled', true, Config::get('enable_geocoding') ? 'yes' : 'no');
exit($ok ? 0 : 1);
