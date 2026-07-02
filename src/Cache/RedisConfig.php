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
 * Immutable, framework-agnostic value object describing an effective Redis
 * configuration. It carries no PrestaShop dependency, which keeps it fully unit
 * testable and safe to build both from the Symfony container and from the very
 * early cache bootstrap.
 */
final class RedisConfig
{
    public const SERIALIZER_PHP = 'php';
    public const SERIALIZER_IGBINARY = 'igbinary';
    public const SERIALIZER_JSON = 'json';

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly string $password = '',
        private readonly int $database = 0,
        private readonly float $timeout = 2.0,
        private readonly bool $tls = false,
        private readonly bool $enabled = true,
        private readonly int $defaultTtl = 0,
        private readonly string $prefix = 'ps_',
        private readonly bool $compression = false,
        private readonly bool $compressionAuto = true,
        private readonly int $compressionThreshold = 1024,
        private readonly string $serializer = self::SERIALIZER_PHP,
        private readonly bool $flushOnCacheClear = true,
    ) {
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getDatabase(): int
    {
        return $this->database;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function isTls(): bool
    {
        return $this->tls;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getDefaultTtl(): int
    {
        return max(0, $this->defaultTtl);
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->compression;
    }

    public function isCompressionAuto(): bool
    {
        return $this->compressionAuto;
    }

    public function getCompressionThreshold(): int
    {
        return max(0, $this->compressionThreshold);
    }

    public function getSerializer(): string
    {
        return in_array($this->serializer, self::availableSerializers(), true)
            ? $this->serializer
            : self::SERIALIZER_PHP;
    }

    public function isFlushOnCacheClear(): bool
    {
        return $this->flushOnCacheClear;
    }

    /**
     * Compute the per-shop key prefix so shops never collide in a shared Redis.
     */
    public function getKeyPrefix(int $shopId): string
    {
        return rtrim($this->prefix, ':') . ':s' . max(0, $shopId) . ':';
    }

    /**
     * @return array{host:string,port:int,password:string,database:int,timeout:float,tls:bool}
     */
    public function getConnectionParameters(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'password' => $this->password,
            'database' => $this->database,
            'timeout' => $this->timeout,
            'tls' => $this->tls,
        ];
    }

    /**
     * @return string[]
     */
    public static function availableSerializers(): array
    {
        return [self::SERIALIZER_PHP, self::SERIALIZER_IGBINARY, self::SERIALIZER_JSON];
    }
}
