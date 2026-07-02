<?php
/**
 * QCD Redis.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

namespace QcdGone\QcdRedis\Cache;

/**
 * Builds {@see RedisConfig} value objects and owns the mapping to PrestaShop
 * configuration keys and their defaults.
 */
final class RedisConfigFactory
{
    // Connection.
    public const KEY_HOST = 'QCDREDIS_HOST';
    public const KEY_PORT = 'QCDREDIS_PORT';
    public const KEY_PASSWORD = 'QCDREDIS_PASSWORD';
    public const KEY_DB = 'QCDREDIS_DB';
    public const KEY_TIMEOUT = 'QCDREDIS_TIMEOUT';
    public const KEY_TLS = 'QCDREDIS_TLS';

    // Cache behaviour.
    public const KEY_ENABLED = 'QCDREDIS_ENABLED';
    public const KEY_TTL = 'QCDREDIS_TTL';
    public const KEY_PREFIX = 'QCDREDIS_PREFIX';
    public const KEY_COMPRESSION = 'QCDREDIS_COMPRESSION';
    public const KEY_COMPRESSION_AUTO = 'QCDREDIS_COMPRESSION_AUTO';
    public const KEY_COMPRESSION_THRESHOLD = 'QCDREDIS_COMPRESSION_THRESHOLD';
    public const KEY_SERIALIZER = 'QCDREDIS_SERIALIZER';
    public const KEY_FLUSH_ON_CLEAR = 'QCDREDIS_FLUSH_ON_CLEAR';

    // Restore points.
    public const KEY_PREVIOUS_CACHE = 'QCDREDIS_PREVIOUS_CACHE';
    public const KEY_PREVIOUS_CACHE_ENABLE = 'QCDREDIS_PREVIOUS_CACHE_ENABLE';

    /** @var array<string, scalar> */
    private const DEFAULTS = [
        self::KEY_HOST => '127.0.0.1',
        self::KEY_PORT => 6379,
        self::KEY_PASSWORD => '',
        self::KEY_DB => 0,
        self::KEY_TIMEOUT => 2.0,
        self::KEY_TLS => false,
        self::KEY_ENABLED => true,
        self::KEY_TTL => 0,
        self::KEY_PREFIX => 'ps_',
        self::KEY_COMPRESSION => false,
        self::KEY_COMPRESSION_AUTO => true,
        self::KEY_COMPRESSION_THRESHOLD => 1024,
        self::KEY_SERIALIZER => RedisConfig::SERIALIZER_PHP,
        self::KEY_FLUSH_ON_CLEAR => true,
    ];

    /**
     * Build a config from an associative array keyed by the QCDREDIS_* keys.
     * Missing keys fall back to defaults.
     *
     * @param array<string, mixed> $values
     */
    public static function fromValues(array $values): RedisConfig
    {
        $read = static fn (string $key) => $values[$key] ?? self::DEFAULTS[$key] ?? null;

        return new RedisConfig(
            (string) $read(self::KEY_HOST),
            (int) $read(self::KEY_PORT),
            (string) $read(self::KEY_PASSWORD),
            (int) $read(self::KEY_DB),
            (float) $read(self::KEY_TIMEOUT),
            (bool) $read(self::KEY_TLS),
            (bool) $read(self::KEY_ENABLED),
            (int) $read(self::KEY_TTL),
            (string) $read(self::KEY_PREFIX),
            (bool) $read(self::KEY_COMPRESSION),
            (bool) $read(self::KEY_COMPRESSION_AUTO),
            (int) $read(self::KEY_COMPRESSION_THRESHOLD),
            (string) $read(self::KEY_SERIALIZER),
            (bool) $read(self::KEY_FLUSH_ON_CLEAR),
        );
    }

    /**
     * Build a config by reading the global PrestaShop Configuration class.
     * Used exclusively by the early-boot cache engine.
     */
    public static function fromLegacyConfiguration(): RedisConfig
    {
        $values = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = self::readLegacy($key);
        }

        return self::fromValues($values);
    }

    /**
     * All persisted configuration keys (used on uninstall).
     *
     * @return string[]
     */
    public static function allKeys(): array
    {
        return array_merge(
            array_keys(self::DEFAULTS),
            [self::KEY_PREVIOUS_CACHE, self::KEY_PREVIOUS_CACHE_ENABLE]
        );
    }

    public static function getDefault(string $key): mixed
    {
        return self::DEFAULTS[$key] ?? null;
    }

    /**
     * Read a single value from the global Configuration class, defensively.
     */
    private static function readLegacy(string $key): mixed
    {
        try {
            if (class_exists('Configuration', false) && \Configuration::hasKey($key)) {
                return \Configuration::get($key);
            }
        } catch (\Throwable) {
            // Database not ready during early boot: fall back to defaults.
        }

        return self::DEFAULTS[$key] ?? null;
    }
}
