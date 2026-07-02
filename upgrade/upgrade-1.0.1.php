<?php
/**
 * QCD Redis - upgrade to 1.0.1.
 *
 * Registers the object-lifecycle hooks that drive the automatic per-object
 * Redis purge + targeted warmup. Idempotent: registerHook() ignores hooks the
 * module is already attached to.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

use QcdGone\QcdRedis\Service\ObjectCacheRefresher;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param \Module $module
 */
function upgrade_module_1_0_1($module): bool
{
    require_once __DIR__ . '/../config/autoload.php';

    return (bool) $module->registerHook(ObjectCacheRefresher::hooks());
}
