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
 * Collects runtime Redis metrics (INFO-derived indicators, key inspection) and
 * exposes CSV export. Heavy key inspection uses a bounded SCAN sample so it
 * stays safe on large databases.
 */
final class StatisticsProvider
{
    private const SAMPLE_LIMIT = 500;

    private ?RedisConnection $connection = null;

    private RedisConnectionFactory $connectionFactory;

    private ConfigurationProvider $provider;

    public function __construct(RedisConnectionFactory $connectionFactory, ConfigurationProvider $provider)
    {
        $this->connectionFactory = $connectionFactory;
        $this->provider = $provider;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        $info = $this->connection()->info();

        if ($info === []) {
            return ['available' => false];
        }

        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $max = (int) ($info['maxmemory'] ?? 0);
        $used = (int) ($info['used_memory'] ?? 0);

        return [
            'available' => true,
            'version' => (string) ($info['redis_version'] ?? '?'),
            'latency_ms' => $this->measureLatency(),
            'used_memory' => $used,
            'used_memory_human' => (string) ($info['used_memory_human'] ?? '?'),
            'max_memory' => $max,
            'free_memory' => $max > 0 ? max(0, $max - $used) : 0,
            'keys' => $this->countKeys(),
            'connected_clients' => (int) ($info['connected_clients'] ?? 0),
            'hit_ratio' => $this->ratio($hits, $hits + $misses),
            'miss_ratio' => $this->ratio($misses, $hits + $misses),
            'hits' => $hits,
            'misses' => $misses,
            'uptime_seconds' => (int) ($info['uptime_in_seconds'] ?? 0),
            'fragmentation_ratio' => (float) ($info['mem_fragmentation_ratio'] ?? 0),
            'evicted_keys' => (int) ($info['evicted_keys'] ?? 0),
            'expired_keys' => (int) ($info['expired_keys'] ?? 0),
        ];
    }

    public function measureLatency(): float
    {
        try {
            $client = $this->connection()->getClient();
            $start = microtime(true);
            $client->ping();

            return round((microtime(true) - $start) * 1000, 3);
        } catch (\Throwable) {
            return -1.0;
        }
    }

    public function countKeys(): int
    {
        try {
            $client = $this->connection()->getClient();
            $count = 0;
            $iterator = null;
            $pattern = $this->provider->getKeyPrefix() . '*';
            $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

            do {
                $batch = $client->scan($iterator, $pattern, 1000);
                $count += is_array($batch) ? count($batch) : 0;
            } while ($iterator > 0);

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int, array{key:string,bytes:int,ttl:int}>
     */
    public function getHeavyKeys(int $limit = 20): array
    {
        $sample = $this->sampleKeys();
        usort($sample, static fn (array $a, array $b) => $b['bytes'] <=> $a['bytes']);

        return array_slice($sample, 0, max(1, $limit));
    }

    public function getAverageTtl(): float
    {
        $ttls = array_filter(array_column($this->sampleKeys(), 'ttl'), static fn (int $ttl) => $ttl > 0);

        return $ttls === [] ? 0.0 : round(array_sum($ttls) / count($ttls), 1);
    }

    public function toCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['metric', 'value']);

        foreach ($this->getOverview() as $metric => $value) {
            fputcsv($handle, [$metric, is_scalar($value) ? (string) $value : json_encode($value)]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['key', 'bytes', 'ttl']);

        foreach ($this->getHeavyKeys() as $row) {
            fputcsv($handle, [$row['key'], $row['bytes'], $row['ttl']]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @return array<int, array{key:string,bytes:int,ttl:int}>
     */
    private function sampleKeys(): array
    {
        try {
            $client = $this->connection()->getClient();
            $rows = [];
            $iterator = null;
            $pattern = $this->provider->getKeyPrefix() . '*';
            $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

            do {
                $batch = $client->scan($iterator, $pattern, 200);

                foreach (is_array($batch) ? $batch : [] as $key) {
                    $rows[] = $this->describeKey($client, (string) $key);

                    if (count($rows) >= self::SAMPLE_LIMIT) {
                        return $rows;
                    }
                }
            } while ($iterator > 0);

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{key:string,bytes:int,ttl:int}
     */
    private function describeKey(\Redis $client, string $key): array
    {
        try {
            $bytes = (int) $client->rawCommand('MEMORY', 'USAGE', $key);
        } catch (\Throwable) {
            $bytes = 0;
        }

        return ['key' => $key, 'bytes' => $bytes, 'ttl' => max(0, (int) $client->ttl($key))];
    }

    private function ratio(int $part, int $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 2) : 0.0;
    }

    private function connection(): RedisConnection
    {
        return $this->connection ??= $this->connectionFactory->create();
    }
}
