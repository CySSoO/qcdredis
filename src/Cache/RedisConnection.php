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
 * Resilient wrapper around the phpredis extension (\Redis).
 *
 * Owns the connection lifecycle only — connecting, authenticating, selecting
 * the database and transparently reconnecting once when a command fails.
 * Predis is never used. Serialization and compression live in QcdRedisCache.
 */
final class RedisConnection
{
    private ?\Redis $client = null;

    private bool $connected = false;

    private string $lastError = '';

    private RedisConfig $config;

    public function __construct(RedisConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Whether the phpredis extension is available on this server.
     */
    public static function isExtensionAvailable(): bool
    {
        return extension_loaded('redis') && class_exists('Redis');
    }

    /**
     * Return a connected client, connecting lazily on first use.
     *
     * @throws \RuntimeException
     */
    public function getClient(): \Redis
    {
        if ($this->connected && $this->client instanceof \Redis) {
            return $this->client;
        }

        return $this->connect();
    }

    /**
     * Attempt to (re)connect and return the live client.
     *
     * @throws \RuntimeException
     */
    public function connect(): \Redis
    {
        if (!self::isExtensionAvailable()) {
            throw new \RuntimeException('The PHP "redis" extension is not installed.');
        }

        $params = $this->config->getConnectionParameters();
        $client = new \Redis();

        try {
            $host = $params['tls'] ? 'tls://' . $params['host'] : $params['host'];

            if (!$client->connect($host, $params['port'], $params['timeout'])) {
                throw new \RuntimeException(sprintf('Cannot reach Redis at %s:%d.', $params['host'], $params['port']));
            }

            if ($params['password'] !== '') {
                $client->auth($params['password']);
            }

            $client->select($params['database']);
        } catch (\Throwable $e) {
            $this->connected = false;
            $this->lastError = $e->getMessage();

            throw new \RuntimeException('Redis connection failed: ' . $e->getMessage(), 0, $e);
        }

        $this->client = $client;
        $this->connected = true;
        $this->lastError = '';

        return $client;
    }

    /**
     * Run a command with a single transparent reconnect on connection loss.
     *
     * @param callable(\Redis):mixed $callback
     */
    public function execute(callable $callback): mixed
    {
        try {
            return $callback($this->getClient());
        } catch (\RedisException $e) {
            $this->connected = false;

            try {
                return $callback($this->connect());
            } catch (\Throwable $retry) {
                $this->lastError = $retry->getMessage();

                throw new \RuntimeException('Redis command failed after reconnect: ' . $retry->getMessage(), 0, $retry);
            }
        }
    }

    /**
     * Non-throwing connectivity check.
     */
    public function isAvailable(): bool
    {
        try {
            $pong = $this->getClient()->ping();

            return $pong === true || $pong === '+PONG' || $pong === 'PONG';
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    /**
     * Return the raw INFO payload as an associative array.
     *
     * @return array<string, string>
     */
    public function info(?string $section = null): array
    {
        try {
            $info = $section !== null ? $this->getClient()->info($section) : $this->getClient()->info();

            return is_array($info) ? $info : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return [];
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function close(): void
    {
        try {
            $this->client?->close();
        } catch (\Throwable) {
            // Closing a dead socket is harmless.
        } finally {
            $this->connected = false;
            $this->client = null;
        }
    }
}
