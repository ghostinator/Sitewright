<?php
declare(strict_types=1);

/** Print a new app_key (32 random bytes, base64) for config/config.php: 'app_key' => '…'  (or SW_APP_KEY). */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/core/autoload.php';
echo Sitewright\Core\Crypto::generateKey() . "\n";
