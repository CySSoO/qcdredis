<?php
/**
 * QCD Redis.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

namespace QcdGone\QcdRedis\Service;

use QcdGone\QcdRedis\Cache\RedisConfig;
use QcdGone\QcdRedis\Cache\RedisConnection;
use QcdGone\QcdRedis\Config\ConfigurationProvider;

/**
 * Creates {@see RedisConnection} instances from the current configuration, or
 * from an ad-hoc {@see RedisConfig} (used by the "test connection" action).
 */
final class RedisConnectionFactory
{
    private ConfigurationProvider $provider;

    public function __construct(ConfigurationProvider $provider)
    {
        $this->provider = $provider;
    }

    public function create(?RedisConfig $override = null): RedisConnection
    {
        return new RedisConnection($override ?? $this->provider->getConfig());
    }
}
