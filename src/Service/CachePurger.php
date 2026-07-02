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

use QcdGone\QcdRedis\Config\ConfigurationProvider;

/**
 * Purges cache entries within the current shop's namespace (multishop safe)
 * using non-blocking SCAN loops.
 */
final class CachePurger
{
    private RedisConnectionFactory $connectionFactory;

    private ConfigurationProvider $provider;

    public function __construct(RedisConnectionFactory $connectionFactory, ConfigurationProvider $provider)
    {
        $this->connectionFactory = $connectionFactory;
        $this->provider = $provider;
    }

    public function purgeAll(): int
    {
        return $this->purgePattern($this->provider->getKeyPrefix() . '*');
    }

    public function purgeByPrefix(string $prefix): int
    {
        return $this->purgePattern($this->provider->getKeyPrefix() . $prefix . '*');
    }

    /**
     * @param string[] $tags
     */
    public function purgeByTags(array $tags): int
    {
        $deleted = 0;

        foreach ($tags as $tag) {
            $tag = trim($tag);

            if ($tag !== '') {
                $deleted += $this->purgePattern($this->provider->getKeyPrefix() . '*' . $tag . '*');
            }
        }

        return $deleted;
    }

    public function reportExpired(): int
    {
        return (int) ($this->connectionFactory->create()->info('stats')['expired_keys'] ?? 0);
    }

    private function purgePattern(string $pattern): int
    {
        try {
            $client = $this->connectionFactory->create()->getClient();
            $deleted = 0;
            $iterator = null;
            $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

            do {
                $batch = $client->scan($iterator, $pattern, 500);

                if (is_array($batch) && $batch !== []) {
                    $deleted += (int) $client->del($batch);
                }
            } while ($iterator > 0);

            return $deleted;
        } catch (\Throwable) {
            return 0;
        }
    }
}
