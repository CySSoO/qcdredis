<?php
/**
 * QCD Redis - Early PSR-4 autoloader bootstrap.
 *
 * The cache engine (QcdGone\QcdRedis\Cache\QcdRedisCache) is instantiated by
 * PrestaShop before the Symfony container is available - on every single
 * request, front and back. This file makes the module's own PSR-4 classes
 * loadable at that early stage with a minimal, dependency-free SPL autoloader.
 *
 * IMPORTANT: this file deliberately never loads the module's own
 * vendor/autoload.php. The runtime needs no third-party package (only the
 * ext-redis extension and PrestaShop core). Loading Composer's autoloader here
 * would pull dev dependencies (PHPUnit, PHPStan, Symfony console, ...) into
 * every request and clash with PrestaShop's own Symfony, taking the shop down.
 * Dev dependencies are for CI only and must not be deployed to production.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (defined('QCDREDIS_AUTOLOAD_REGISTERED')) {
    return;
}

define('QCDREDIS_AUTOLOAD_REGISTERED', true);

if (!defined('QCDREDIS_DIR')) {
    define('QCDREDIS_DIR', dirname(__DIR__));
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
