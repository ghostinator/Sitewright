<?php
declare(strict_types=1);

/**
 * Minimal PSR-4-style autoloader.
 *   RankMathWebapp\Core\Foo            -> core/Foo.php
 *   RankMathWebapp\Modules\X\YThing    -> modules/X/YThing.php
 * All "*Exception" classes in Core live together in core/Exceptions.php.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'RankMathWebapp\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel  = substr($class, strlen($prefix));
    $root = dirname(__DIR__);

    if (strncmp($rel, 'Core\\', 5) === 0) {
        $name = substr($rel, 5);
        $file = $root . '/core/' . str_replace('\\', '/', $name) . '.php';
        if (!is_file($file) && substr($name, -9) === 'Exception') {
            $file = $root . '/core/Exceptions.php';
        }
    } elseif (strncmp($rel, 'Modules\\', 8) === 0) {
        $file = $root . '/modules/' . str_replace('\\', '/', substr($rel, 8)) . '.php';
    } else {
        return;
    }

    if (is_file($file)) {
        require_once $file;
    }
});
