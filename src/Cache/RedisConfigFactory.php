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
 *
 * Two entry points exist on purpose:
 *  - {@see fromValues()} for the Symfony layer, which reads values through the
 *    ConfigurationInterface service and passes them here;
 *  - {@see fromLegacyConfiguration()} for the cache engine, which boots before
 *    the container exists and must therefore read the global Configuration
 *    class directly. This is the only sanctioned static access in the engine
 *    path.
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
    ];

    /** @var array<string, mixed>|null */
    private static ?array $legacyValues = null;

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
        );
    }

    /**
     * Build a config by reading persisted configuration without using
     * PrestaShop's Configuration class, which itself may touch the cache engine
     * and recursively instantiate CacheRedis during early boot.
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
     * Read a single value from the configuration table, defensively.
     */
    private static function readLegacy(string $key): mixed
    {
        try {
            $values = self::readLegacyValues();

            if (array_key_exists($key, $values)) {
                return $values[$key];
            }
        } catch (\Throwable) {
            // Database not ready during early boot: fall back to defaults.
        }

        return self::DEFAULTS[$key] ?? null;
    }

    /**
     * Read all known QCDREDIS_* keys directly through PDO. Using PrestaShop's
     * Db or Configuration services here can recursively boot the cache engine.
     *
     * @return array<string, mixed>
     */
    private static function readLegacyValues(): array
    {
        if (self::$legacyValues !== null) {
            return self::$legacyValues;
        }

        self::$legacyValues = [];

        if (
            !class_exists('PDO')
            || !defined('_DB_SERVER_')
            || !defined('_DB_NAME_')
            || !defined('_DB_USER_')
            || !defined('_DB_PASSWD_')
            || !defined('_DB_PREFIX_')
        ) {
            return self::$legacyValues;
        }

        $host = (string) _DB_SERVER_;
        $port = defined('_DB_PORT_') ? (string) _DB_PORT_ : '';

        if ($port === '' && str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        $dsn = 'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . _DB_NAME_ . ';charset=utf8mb4';
        $pdo = new \PDO($dsn, (string) _DB_USER_, (string) _DB_PASSWD_, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 2,
        ]);

        $keys = array_keys(self::DEFAULTS);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $table = str_replace('`', '``', (string) _DB_PREFIX_ . 'configuration');
        $statement = $pdo->prepare(
            'SELECT `name`, `value` FROM `' . $table . '`'
            . ' WHERE `name` IN (' . $placeholders . ')'
            . ' ORDER BY `id_shop` DESC, `id_shop_group` DESC'
        );
        $statement->execute($keys);

        foreach ($statement->fetchAll() as $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name !== '' && !array_key_exists($name, self::$legacyValues)) {
                self::$legacyValues[$name] = $row['value'] ?? null;
            }
        }

        return self::$legacyValues;
    }
}
