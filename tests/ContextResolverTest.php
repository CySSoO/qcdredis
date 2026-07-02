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
use QcdGone\QcdRedis\Context\CacheContext;
use QcdGone\QcdRedis\Context\ContextResolver;

/**
 * Unit tests for the early context gate. They avoid PrestaShop dependencies and
 * cover the hard defaults that keep non-FO contexts away from Redis.
 */
final class ContextResolverTest extends TestCase
{
    public function testFrontIsAllowedByDefault(): void
    {
        self::assertTrue((new ContextResolver())->isRedisAllowedFor(CacheContext::FRONT));
    }

    public function testNonFrontContextsAreDeniedByDefault(): void
    {
        $resolver = new ContextResolver();

        self::assertFalse($resolver->isRedisAllowedFor(CacheContext::BACK));
        self::assertFalse($resolver->isRedisAllowedFor(CacheContext::CLI));
        self::assertFalse($resolver->isRedisAllowedFor(CacheContext::CRON));
        self::assertFalse($resolver->isRedisAllowedFor(CacheContext::INSTALL));
        self::assertFalse($resolver->isRedisAllowedFor(CacheContext::UNKNOWN));
    }

    public function testContextConfigurationKeysExposeExpectedDefaults(): void
    {
        self::assertSame([
            ContextResolver::KEY_FRONT,
            ContextResolver::KEY_FRONT_AJAX,
            ContextResolver::KEY_BACK,
            ContextResolver::KEY_CLI,
            ContextResolver::KEY_CRON,
        ], ContextResolver::configurationKeys());

        self::assertTrue(ContextResolver::getDefault(ContextResolver::KEY_FRONT));
        self::assertTrue(ContextResolver::getDefault(ContextResolver::KEY_FRONT_AJAX));
        self::assertFalse(ContextResolver::getDefault(ContextResolver::KEY_BACK));
        self::assertFalse(ContextResolver::getDefault(ContextResolver::KEY_CLI));
        self::assertFalse(ContextResolver::getDefault(ContextResolver::KEY_CRON));
    }
}
