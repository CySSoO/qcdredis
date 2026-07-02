<?php
/**
 * QCD Redis - Early PSR-4 autoloader bootstrap.
 *
 * The cache engine (QcdGone\QcdRedis\Cache\QcdRedisCache) is instantiated by
 * PrestaShop before the Symfony container - and therefore before Composer's
 * runtime autoloader - is guaranteed to be available. This file makes the
 * module's PSR-4 classes loadable at that early stage: it prefers Composer's
 * autoloader when the module ships its dependencies, and otherwise registers a
 * minimal SPL autoloader so the module stays fully autonomous.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (!defined('QCDREDIS_DIR')) {
    define('QCDREDIS_DIR', dirname(__DIR__));
}

$qcdRedisComposer = QCDREDIS_DIR . '/vendor/autoload.php';

if (is_file($qcdRedisComposer)) {
    require_once $qcdRedisComposer;

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'QcdGone\\QcdRedis\\';
    $length = strlen($prefix);

    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, $length));
    $file = QCDREDIS_DIR . '/src/' . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
