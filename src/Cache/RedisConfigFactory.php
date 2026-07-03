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
        self::KEY_TTL => 86400,
        self::KEY_PREFIX => 'ps_',
        self::KEY_COMPRESSION => false,
        self::KEY_COMPRESSION_AUTO => true,
        self::KEY_COMPRESSION_THRESHOLD => 1024,
        self::KEY_SERIALIZER => RedisConfig::SERIALIZER_PHP,
    ];

    private static bool $legacyReadInProgress = false;

    private static bool $legacyMysqliInitialized = false;

    private static ?object $legacyMysqli = null;

    private static bool $legacyPdoInitialized = false;

    private static ?\PDO $legacyPdo = null;

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
     * Build a config by reading the PrestaShop configuration table directly.
     * Used exclusively by the early-boot cache engine, before PrestaShop's own
     * Configuration and Db APIs are safe to call without recursing into Cache.
     */
    public static function fromLegacyConfiguration(int $idShop = 0, int $idShopGroup = 0): RedisConfig
    {
        if (self::$legacyReadInProgress) {
            // Re-entrant read: we cannot safely resolve the real configuration
            // here. Refuse rather than returning DEFAULTS (which carry the 'ps_'
            // prefix) and thereby splitting the keyspace.
            throw new \RuntimeException('QCD Redis: re-entrant legacy configuration read.');
        }

        self::$legacyReadInProgress = true;

        try {
            // Read every key in a single round-trip, honouring the current shop
            // scope exactly like Configuration::get would.
            $values = self::readLegacyMany(array_keys(self::DEFAULTS), $idShop, $idShopGroup);
        } finally {
            self::$legacyReadInProgress = false;
        }

        return self::fromValues($values);
    }

    /**
     * Read a single PrestaShop configuration value without using Configuration.
     *
     * This is shared by early-boot services that must avoid Configuration/Db
     * recursion before the regular cache layer is ready.
     */
    public static function readLegacyValue(string $key, mixed $default = null): mixed
    {
        return self::readLegacy($key, $default);
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
    private static function readLegacy(string $key, mixed $default = null): mixed
    {
        try {
            if (!self::hasLegacyDatabaseConstants()) {
                return self::DEFAULTS[$key] ?? $default;
            }

            $value = self::readLegacyWithMysqli($key);

            if ($value === false) {
                $value = self::readLegacyWithPdo($key);
            }

            if ($value !== false) {
                return $value;
            }
        } catch (\Throwable) {
            // Database not ready during early boot: fall back to defaults.
        }

        return self::DEFAULTS[$key] ?? $default;
    }

    /**
     * Read several configuration values in a single query, honouring the current
     * shop scope (shop-specific > shop-group > global), exactly like
     * Configuration::get.
     *
     * IMPORTANT: this never falls back to DEFAULTS on a database failure. If the
     * database cannot be reached it throws, so the caller degrades to a no-op
     * cache instead of running under the default 'ps_' prefix (which would split
     * the keyspace). DEFAULTS are only applied by fromValues() for keys that are
     * genuinely absent from a reachable configuration table (fresh install).
     *
     * @param string[] $keys
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When the configuration table cannot be read.
     */
    private static function readLegacyMany(array $keys, int $idShop, int $idShopGroup): array
    {
        if ($keys === []) {
            return [];
        }

        if (!self::hasLegacyDatabaseConstants()) {
            throw new \RuntimeException('QCD Redis: database constants unavailable for configuration read.');
        }

        $values = null;

        try {
            $values = self::readLegacyManyWithMysqli($keys, $idShop, $idShopGroup);
        } catch (\Throwable) {
            $values = null;
        }

        if ($values === null) {
            try {
                $values = self::readLegacyManyWithPdo($keys, $idShop, $idShopGroup);
            } catch (\Throwable) {
                $values = null;
            }
        }

        if ($values === null) {
            throw new \RuntimeException('QCD Redis: unable to read configuration from the database.');
        }

        return $values;
    }

    /**
     * @param string[] $keys
     *
     * @return array<string, mixed>|null Null when mysqli is unusable (try PDO).
     */
    private static function readLegacyManyWithMysqli(array $keys, int $idShop, int $idShopGroup): ?array
    {
        $connection = self::getLegacyMysqli();

        if (!$connection instanceof \mysqli) {
            return null;
        }

        $prefix = self::getLegacyTablePrefix();

        if ($prefix === null) {
            return null;
        }

        $escaped = array_map(
            static fn (string $key): string => "'" . $connection->real_escape_string($key) . "'",
            $keys
        );

        $sql = sprintf(
            'SELECT `name`, `value`, `id_shop`, `id_shop_group` FROM `%sconfiguration`'
            . ' WHERE `name` IN (%s)'
            . ' AND (`id_shop` = %d OR `id_shop` IS NULL)'
            . ' AND (`id_shop_group` = %d OR `id_shop_group` IS NULL)',
            $prefix,
            implode(',', $escaped),
            $idShop,
            $idShopGroup
        );
        $result = @$connection->query($sql);

        if (!$result instanceof \mysqli_result) {
            return null;
        }

        $rows = [];

        while (is_array($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }

        $result->free();

        return self::resolveScopedRows($rows, $idShop, $idShopGroup);
    }

    /**
     * @param string[] $keys
     *
     * @return array<string, mixed>|null Null when PDO is unusable.
     */
    private static function readLegacyManyWithPdo(array $keys, int $idShop, int $idShopGroup): ?array
    {
        $connection = self::getLegacyPdo();
        $prefix = self::getLegacyTablePrefix();

        if (!$connection instanceof \PDO || $prefix === null) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = sprintf(
            'SELECT `name`, `value`, `id_shop`, `id_shop_group` FROM `%sconfiguration`'
            . ' WHERE `name` IN (%s)'
            . ' AND (`id_shop` = %d OR `id_shop` IS NULL)'
            . ' AND (`id_shop_group` = %d OR `id_shop_group` IS NULL)',
            $prefix,
            $placeholders,
            $idShop,
            $idShopGroup
        );
        $statement = $connection->prepare($sql);

        if (!$statement) {
            return null;
        }

        if (!$statement->execute(array_values($keys))) {
            return null;
        }

        $rows = $statement->fetchAll();

        return self::resolveScopedRows(is_array($rows) ? $rows : [], $idShop, $idShopGroup);
    }

    /**
     * Reduce raw configuration rows to one value per key, choosing the most
     * specific scope available (shop-specific > shop-group > global), mirroring
     * PrestaShop's Configuration::get resolution.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function resolveScopedRows(array $rows, int $idShop, int $idShopGroup): array
    {
        /** @var array<string, array{0:int,1:mixed}> $best */
        $best = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('name', $row) || !array_key_exists('value', $row)) {
                continue;
            }

            $rank = self::scopeRank($row['id_shop'] ?? null, $row['id_shop_group'] ?? null, $idShop, $idShopGroup);

            if ($rank === 0) {
                continue;
            }

            $name = (string) $row['name'];

            if (!isset($best[$name]) || $rank > $best[$name][0]) {
                $best[$name] = [$rank, $row['value']];
            }
        }

        $values = [];

        foreach ($best as $name => $pair) {
            $values[$name] = $pair[1];
        }

        return $values;
    }

    /**
     * Specificity rank of a configuration row for the given context:
     * 3 = shop-specific match, 2 = shop-group match, 1 = global, 0 = not applicable.
     */
    private static function scopeRank(mixed $rowShop, mixed $rowShopGroup, int $idShop, int $idShopGroup): int
    {
        $rowShop = $rowShop === null ? null : (int) $rowShop;
        $rowShopGroup = $rowShopGroup === null ? null : (int) $rowShopGroup;

        if ($rowShop !== null) {
            return ($idShop > 0 && $rowShop === $idShop) ? 3 : 0;
        }

        if ($rowShopGroup !== null) {
            return ($idShopGroup > 0 && $rowShopGroup === $idShopGroup) ? 2 : 0;
        }

        return 1;
    }

    private static function readLegacyWithMysqli(string $key): mixed
    {
        $connection = self::getLegacyMysqli();

        if (!$connection instanceof \mysqli) {
            return false;
        }

        $prefix = self::getLegacyTablePrefix();

        if ($prefix === null) {
            return false;
        }

        $sql = sprintf(
            'SELECT `value` FROM `%sconfiguration` WHERE `name` = \'%s\''
            . ' AND `id_shop` IS NULL AND `id_shop_group` IS NULL'
            . ' ORDER BY `id_shop` DESC, `id_shop_group` DESC LIMIT 1',
            $prefix,
            $connection->real_escape_string($key)
        );
        $result = @$connection->query($sql);

        if (!$result instanceof \mysqli_result) {
            return false;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return is_array($row) && array_key_exists('value', $row) ? $row['value'] : false;
    }

    private static function readLegacyWithPdo(string $key): mixed
    {
        $connection = self::getLegacyPdo();
        $prefix = self::getLegacyTablePrefix();

        if (!$connection instanceof \PDO || $prefix === null) {
            return false;
        }

        $sql = sprintf(
            'SELECT `value` FROM `%sconfiguration` WHERE `name` = :name'
            . ' AND `id_shop` IS NULL AND `id_shop_group` IS NULL'
            . ' ORDER BY `id_shop` DESC, `id_shop_group` DESC LIMIT 1',
            $prefix
        );
        $statement = $connection->prepare($sql);

        if (!$statement) {
            return false;
        }

        $statement->bindValue(':name', $key);

        if (!$statement->execute()) {
            return false;
        }

        $value = $statement->fetchColumn();

        return $value !== false ? $value : false;
    }

    private static function getLegacyMysqli(): ?object
    {
        if (self::$legacyMysqliInitialized) {
            return self::$legacyMysqli;
        }

        self::$legacyMysqliInitialized = true;

        if (!function_exists('mysqli_init')) {
            return null;
        }

        [$host, $port] = self::getLegacyDatabaseHostAndPort();
        $connection = @mysqli_init();

        if (!$connection instanceof \mysqli) {
            return null;
        }

        @$connection->options(\MYSQLI_OPT_CONNECT_TIMEOUT, 1);

        if (!@$connection->real_connect($host, _DB_USER_, _DB_PASSWD_, _DB_NAME_, $port)) {
            return null;
        }

        @$connection->set_charset('utf8mb4');
        self::$legacyMysqli = $connection;

        return self::$legacyMysqli;
    }

    private static function getLegacyPdo(): ?\PDO
    {
        if (self::$legacyPdoInitialized) {
            return self::$legacyPdo;
        }

        self::$legacyPdoInitialized = true;

        if (!class_exists(\PDO::class) || !in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            return null;
        }

        [$host, $port] = self::getLegacyDatabaseHostAndPort();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, _DB_NAME_);

        try {
            self::$legacyPdo = new \PDO($dsn, _DB_USER_, _DB_PASSWD_, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 1,
            ]);
        } catch (\Throwable) {
            self::$legacyPdo = null;
        }

        return self::$legacyPdo;
    }

    /**
     * @return array{0:string,1:int}
     */
    private static function getLegacyDatabaseHostAndPort(): array
    {
        $host = (string) _DB_SERVER_;
        $port = 3306;

        if (preg_match('/^([^:]+):(\d+)$/', $host, $matches)) {
            $host = $matches[1];
            $port = (int) $matches[2];
        }

        return [$host, $port];
    }

    private static function hasLegacyDatabaseConstants(): bool
    {
        return defined('_DB_SERVER_')
            && defined('_DB_USER_')
            && defined('_DB_PASSWD_')
            && defined('_DB_NAME_')
            && defined('_DB_PREFIX_');
    }

    private static function getLegacyTablePrefix(): ?string
    {
        $prefix = (string) _DB_PREFIX_;

        return preg_match('/^[A-Za-z0-9_]*$/', $prefix) ? $prefix : null;
    }
}
