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

use QcdGone\QcdRedis\Cache\RedisConnection;
use QcdGone\QcdRedis\Config\ConfigurationProvider;

/**
 * Runs environment/connectivity checks and read/write/delete benchmarks. Each
 * check is normalised to a severity level (ok / warning / error) for a
 * green / orange / red rendering.
 */
final class DiagnosticsRunner
{
    public const LEVEL_OK = 'ok';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    private const BENCH_ITERATIONS = 1000;

    private ?RedisConnection $connection = null;

    private RedisConnectionFactory $connectionFactory;

    private ConfigurationProvider $provider;

    public function __construct(RedisConnectionFactory $connectionFactory, ConfigurationProvider $provider)
    {
        $this->connectionFactory = $connectionFactory;
        $this->provider = $provider;
    }

    /**
     * @return array<int, array{label:string,level:string,message:string}>
     */
    public function run(): array
    {
        return [
            $this->checkExtension(),
            $this->checkConnection(),
            $this->checkRedisVersion(),
            $this->checkPhpVersion(),
            $this->checkPrestaShopVersion(),
            $this->checkMemory(),
            $this->checkPermissions(),
            $this->checkCacheActive(),
        ];
    }

    /**
     * @return array<string, float>
     */
    public function benchmark(): array
    {
        try {
            $client = $this->connection()->getClient();
        } catch (\Throwable) {
            return ['write' => -1.0, 'read' => -1.0, 'delete' => -1.0];
        }

        $prefix = $this->provider->getKeyPrefix() . '__bench__:';

        return [
            'write' => $this->timePass(static function () use ($client, $prefix): void {
                for ($i = 0; $i < self::BENCH_ITERATIONS; ++$i) {
                    $client->set($prefix . $i, 'x');
                }
            }),
            'read' => $this->timePass(static function () use ($client, $prefix): void {
                for ($i = 0; $i < self::BENCH_ITERATIONS; ++$i) {
                    $client->get($prefix . $i);
                }
            }),
            'delete' => $this->timePass(static function () use ($client, $prefix): void {
                for ($i = 0; $i < self::BENCH_ITERATIONS; ++$i) {
                    $client->del($prefix . $i);
                }
            }),
        ];
    }

    private function checkExtension(): array
    {
        return RedisConnection::isExtensionAvailable()
            ? $this->result('PHP redis extension', self::LEVEL_OK, 'Loaded (v' . phpversion('redis') . ').')
            : $this->result('PHP redis extension', self::LEVEL_ERROR, 'The phpredis extension is missing.');
    }

    private function checkConnection(): array
    {
        $connection = $this->connection();

        return $connection->isAvailable()
            ? $this->result('Connection', self::LEVEL_OK, 'Redis responds to PING.')
            : $this->result('Connection', self::LEVEL_ERROR, $connection->getLastError() ?: 'Unreachable.');
    }

    private function checkRedisVersion(): array
    {
        $version = (string) ($this->connection()->info()['redis_version'] ?? '');

        if ($version === '') {
            return $this->result('Redis version', self::LEVEL_ERROR, 'Unknown.');
        }

        $level = version_compare($version, '6.0.0', '>=') ? self::LEVEL_OK : self::LEVEL_WARNING;

        return $this->result('Redis version', $level, $version . ' (6.x/7.x recommended).');
    }

    private function checkPhpVersion(): array
    {
        $level = version_compare(PHP_VERSION, '8.0.0', '>=') ? self::LEVEL_OK : self::LEVEL_ERROR;

        return $this->result('PHP version', $level, PHP_VERSION . ' (>= 8.0 required).');
    }

    private function checkPrestaShopVersion(): array
    {
        $level = version_compare(_PS_VERSION_, '8.0.0', '>=') ? self::LEVEL_OK : self::LEVEL_ERROR;

        return $this->result('PrestaShop version', $level, _PS_VERSION_ . ' (8.x/9.x supported).');
    }

    private function checkMemory(): array
    {
        $info = $this->connection()->info();
        $max = (int) ($info['maxmemory'] ?? 0);
        $used = (int) ($info['used_memory'] ?? 0);

        if ($max === 0) {
            return $this->result('Memory', self::LEVEL_WARNING, 'No maxmemory limit configured on Redis.');
        }

        $usage = $used / $max;
        $level = $usage < 0.8 ? self::LEVEL_OK : ($usage < 0.95 ? self::LEVEL_WARNING : self::LEVEL_ERROR);

        return $this->result('Memory', $level, round($usage * 100, 1) . '% used.');
    }

    private function checkPermissions(): array
    {
        $classDir = _PS_ROOT_DIR_ . '/classes/cache';
        $parameters = _PS_ROOT_DIR_ . '/app/config/parameters.php';
        $writable = is_writable($classDir) && (is_writable($parameters) || is_writable(dirname($parameters)));

        return $writable
            ? $this->result('Permissions', self::LEVEL_OK, 'Cache class dir and parameters file are writable.')
            : $this->result('Permissions', self::LEVEL_WARNING, 'classes/cache or parameters.php is not writable.');
    }

    private function checkCacheActive(): array
    {
        $active = defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ === 'CacheRedis';

        return $active
            ? $this->result('Cache engine', self::LEVEL_OK, 'CacheRedis is the active engine.')
            : $this->result('Cache engine', self::LEVEL_WARNING, 'Redis is not the active cache engine.');
    }

    private function timePass(callable $pass): float
    {
        try {
            $start = microtime(true);
            $pass();

            return round((microtime(true) - $start) * 1000, 3);
        } catch (\Throwable) {
            return -1.0;
        }
    }

    /**
     * @return array{label:string,level:string,message:string}
     */
    private function result(string $label, string $level, string $message): array
    {
        return ['label' => $label, 'level' => $level, 'message' => $message];
    }

    private function connection(): RedisConnection
    {
        return $this->connection ??= $this->connectionFactory->create();
    }
}
