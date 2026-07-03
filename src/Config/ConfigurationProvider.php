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
use QcdGone\QcdRedis\Context\ContextResolver;

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

        foreach (ContextResolver::configurationKeys() as $key) {
            $values[$key] = $this->configuration->get($key, ContextResolver::getDefault($key));
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
     * Module-wide key prefix covering every shop segment (":s0:", ":s1:", …).
     * Used by the full purge so it clears the whole module namespace - including
     * keys left by other shops or by an earlier (misaligned) suffix - instead of
     * only the current shop, so a manual redis-cli FLUSHDB is never required.
     */
    public function getModulePrefix(): string
    {
        return rtrim($this->getConfig()->getPrefix(), ':') . ':s';
    }

    /**
     * Current shop id, resolved through the exact same call as the cache engine
     * (QcdRedisCache::resolveShopId) so the ':sN:' key suffix is identical on
     * both sides. Without this, purge/stats/warmup would target a different
     * namespace than the one the front engine actually writes to.
     */
    public function getShopId(): int
    {
        if (class_exists('Shop') && method_exists('Shop', 'getContextShopID')) {
            return max(0, (int) \Shop::getContextShopID());
        }

        $shop = $this->legacyContext->getContext()->shop ?? null;

        return $shop !== null ? max(0, (int) $shop->id) : 0;
    }
}
