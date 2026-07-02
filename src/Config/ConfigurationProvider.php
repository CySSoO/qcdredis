<?php
/**
 * QCD Redis.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

namespace QcdGone\QcdRedis\Config;

use PrestaShop\PrestaShop\Adapter\Configuration;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use QcdGone\QcdRedis\Cache\RedisConfig;
use QcdGone\QcdRedis\Cache\RedisConfigFactory;

/**
 * Reads the module configuration through PrestaShop's Configuration service and
 * exposes it both as raw values (for forms) and as an immutable
 * {@see RedisConfig} value object (for the Redis services).
 */
final class ConfigurationProvider
{
    private Configuration $configuration;

    private LegacyContext $legacyContext;

    public function __construct(Configuration $configuration, LegacyContext $legacyContext)
    {
        $this->configuration = $configuration;
        $this->legacyContext = $legacyContext;
    }

    /**
     * Build the effective Redis configuration value object.
     */
    public function getConfig(): RedisConfig
    {
        return RedisConfigFactory::fromValues($this->getRawValues());
    }

    /**
     * Raw configuration values keyed by the QCDREDIS_* keys, with defaults.
     *
     * @return array<string, mixed>
     */
    public function getRawValues(): array
    {
        $values = [];

        foreach (RedisConfigFactory::allKeys() as $key) {
            $values[$key] = $this->configuration->get($key, RedisConfigFactory::getDefault($key));
        }

        return $values;
    }

    /**
     * Effective per-shop key prefix for the current context.
     */
    public function getKeyPrefix(): string
    {
        return $this->getConfig()->getKeyPrefix($this->getShopId());
    }

    /**
     * Current shop id (0 in single-shop or "all shops" context).
     */
    public function getShopId(): int
    {
        $shop = $this->legacyContext->getContext()->shop ?? null;

        return $shop !== null ? (int) $shop->id : 0;
    }
}
