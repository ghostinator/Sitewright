<?php
// php -S router emulating public/.htaccess (real files served as-is, /api/* -> api.php, everything else -> index.php).
$pub = dirname(__DIR__) . '/public';
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($pub . $path) && !str_ends_with($path, '.php')) { return false; }
if (str_starts_with($path, '/api/')) { $_SERVER['SCRIPT_NAME'] = '/api.php'; require $pub . '/api.php'; return true; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $pub . '/index.php';
return true;
