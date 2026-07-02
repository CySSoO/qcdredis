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
            ? $this->result('Extension PHP redis', self::LEVEL_OK, 'Chargée (v' . phpversion('redis') . ').')
            : $this->result('Extension PHP redis', self::LEVEL_ERROR, 'Extension phpredis absente.');
    }

    private function checkConnection(): array
    {
        $connection = $this->connection();

        return $connection->isAvailable()
            ? $this->result('Connexion', self::LEVEL_OK, 'Redis répond au PING.')
            : $this->result('Connexion', self::LEVEL_ERROR, $connection->getLastError() ?: 'Injoignable.');
    }

    private function checkRedisVersion(): array
    {
        $version = (string) ($this->connection()->info()['redis_version'] ?? '');

        if ($version === '') {
            return $this->result('Version de Redis', self::LEVEL_ERROR, 'Inconnue.');
        }

        $level = version_compare($version, '6.0.0', '>=') ? self::LEVEL_OK : self::LEVEL_WARNING;

        return $this->result('Version de Redis', $level, $version . ' (6.x/7.x recommandé).');
    }

    private function checkPhpVersion(): array
    {
        $level = version_compare(PHP_VERSION, '8.1.0', '>=') ? self::LEVEL_OK : self::LEVEL_ERROR;

        return $this->result('Version de PHP', $level, PHP_VERSION . ' (>= 8.1 requis).');
    }

    private function checkPrestaShopVersion(): array
    {
        $level = version_compare(_PS_VERSION_, '8.0.0', '>=') ? self::LEVEL_OK : self::LEVEL_ERROR;

        return $this->result('Version de PrestaShop', $level, _PS_VERSION_ . ' (8.x/9.x pris en charge).');
    }

    private function checkMemory(): array
    {
        $info = $this->connection()->info();
        $max = (int) ($info['maxmemory'] ?? 0);
        $used = (int) ($info['used_memory'] ?? 0);

        if ($max === 0) {
            return $this->result('Mémoire', self::LEVEL_WARNING, 'Aucune limite maxmemory configurée sur Redis.');
        }

        $usage = $used / $max;
        $level = $usage < 0.8 ? self::LEVEL_OK : ($usage < 0.95 ? self::LEVEL_WARNING : self::LEVEL_ERROR);

        return $this->result('Mémoire', $level, round($usage * 100, 1) . '% utilisée.');
    }

    private function checkPermissions(): array
    {
        $classDir = _PS_ROOT_DIR_ . '/classes/cache';
        $parameters = _PS_ROOT_DIR_ . '/app/config/parameters.php';
        $writable = is_writable($classDir) && (is_writable($parameters) || is_writable(dirname($parameters)));

        return $writable
            ? $this->result('Permissions', self::LEVEL_OK, 'Le dossier classes/cache et parameters.php sont accessibles en écriture.')
            : $this->result('Permissions', self::LEVEL_WARNING, 'classes/cache ou parameters.php n\'est pas accessible en écriture.');
    }

    private function checkCacheActive(): array
    {
        $active = defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ === 'CacheRedis';

        return $active
            ? $this->result('Moteur de cache', self::LEVEL_OK, 'CacheRedis est le moteur actif.')
            : $this->result('Moteur de cache', self::LEVEL_WARNING, "Redis n'est pas le moteur de cache actif.");
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
