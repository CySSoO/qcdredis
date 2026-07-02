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

    private string $host;

    private int $port;

    private string $password;

    private int $database;

    private float $timeout;

    private bool $tls;

    private bool $enabled;

    private int $defaultTtl;

    private string $prefix;

    private bool $compression;

    private bool $compressionAuto;

    private int $compressionThreshold;

    private string $serializer;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $password = '',
        int $database = 0,
        float $timeout = 2.0,
        bool $tls = false,
        bool $enabled = true,
        int $defaultTtl = 0,
        string $prefix = 'ps_',
        bool $compression = false,
        bool $compressionAuto = true,
        int $compressionThreshold = 1024,
        string $serializer = self::SERIALIZER_PHP
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->database = $database;
        $this->timeout = $timeout;
        $this->tls = $tls;
        $this->enabled = $enabled;
        $this->defaultTtl = $defaultTtl;
        $this->prefix = $prefix;
        $this->compression = $compression;
        $this->compressionAuto = $compressionAuto;
        $this->compressionThreshold = $compressionThreshold;
        $this->serializer = $serializer;
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
