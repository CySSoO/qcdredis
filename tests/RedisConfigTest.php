<?php
/**
 * QCD Redis.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

namespace QcdGone\QcdRedis\Tests;

use PHPUnit\Framework\TestCase;
use QcdGone\QcdRedis\Cache\RedisConfig;
use QcdGone\QcdRedis\Cache\RedisConfigFactory;

/**
 * Unit tests for the framework-agnostic configuration layer. They require no
 * PrestaShop instance: RedisConfig is a pure value object and the factory falls
 * back to defaults for any missing key.
 */
final class RedisConfigTest extends TestCase
{
    public function testValueObjectDefaults(): void
    {
        $config = new RedisConfig();

        self::assertSame('127.0.0.1', $config->getHost());
        self::assertSame(6379, $config->getPort());
        self::assertSame(0, $config->getDatabase());
        self::assertTrue($config->isEnabled());
        self::assertSame(RedisConfig::SERIALIZER_PHP, $config->getSerializer());
    }

    public function testFactoryUsesDefaultsForMissingKeys(): void
    {
        $config = RedisConfigFactory::fromValues([]);

        self::assertSame('127.0.0.1', $config->getHost());
        self::assertSame(6379, $config->getPort());
        self::assertSame('ps_', $config->getPrefix());
    }

    public function testFactoryOverrides(): void
    {
        $config = RedisConfigFactory::fromValues([
            RedisConfigFactory::KEY_HOST => 'redis.internal',
            RedisConfigFactory::KEY_PORT => 6380,
            RedisConfigFactory::KEY_PASSWORD => 'secret',
        ]);

        self::assertSame('redis.internal', $config->getHost());
        self::assertSame(6380, $config->getPort());
        self::assertSame('secret', $config->getPassword());
    }

    public function testSerializerFallsBackToPhpWhenInvalid(): void
    {
        $config = new RedisConfig(serializer: 'nope');

        self::assertSame(RedisConfig::SERIALIZER_PHP, $config->getSerializer());
    }

    public function testKeyPrefixIsShopNamespaced(): void
    {
        $config = new RedisConfig(prefix: 'ps_');

        self::assertSame('ps_:s2:', $config->getKeyPrefix(2));
        self::assertSame('ps_:s0:', $config->getKeyPrefix(0));
    }

    public function testConnectionParametersShape(): void
    {
        $params = (new RedisConfig())->getConnectionParameters();

        self::assertSame(['host', 'port', 'password', 'database', 'timeout', 'tls'], array_keys($params));
    }

    public function testFactoryKeysAreUniqueAndIncludeRestorePoints(): void
    {
        $keys = RedisConfigFactory::allKeys();

        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertContains(RedisConfigFactory::KEY_PREVIOUS_CACHE, $keys);
        self::assertContains(RedisConfigFactory::KEY_PREVIOUS_CACHE_ENABLE, $keys);
    }
}
