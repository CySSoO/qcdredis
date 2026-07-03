<?php
/**
 * QCD Redis - upgrade to 1.0.2.
 *
 * Applies the new default TTL (24h) to installs that never set one. The old
 * default was 0 (no expiry), which let cached entries pile up forever. A custom,
 * non-zero TTL chosen by the merchant is left untouched.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

use QcdGone\QcdRedis\Cache\RedisConfigFactory;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param \Module $module
 */
function upgrade_module_1_0_2($module): bool
{
    require_once __DIR__ . '/../config/autoload.php';

    $current = (int) \Configuration::get(RedisConfigFactory::KEY_TTL);

    if ($current <= 0) {
        \Configuration::updateValue(RedisConfigFactory::KEY_TTL, 86400);
    }

    return true;
}
