<?php
/**
 * QCD Redis - Constants stub for static analysis / standalone unit tests.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}

if (!defined('_PS_ROOT_DIR_')) {
    define('_PS_ROOT_DIR_', dirname(__DIR__, 3));
}

if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', dirname(__DIR__, 2) . '/');
}

if (!defined('_PS_CACHING_SYSTEM_')) {
    define('_PS_CACHING_SYSTEM_', 'CacheRedis');
}
