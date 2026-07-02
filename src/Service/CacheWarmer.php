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

use PrestaShop\PrestaShop\Adapter\LegacyContext;
use QcdGone\QcdRedis\Config\ConfigurationProvider;

/**
 * Discovers front-office URLs (home, categories, products, CMS) and fetches
 * them in batches to populate the cache. The queue is persisted in Redis (with
 * a TTL) between AJAX batches so no server-side session state is required.
 */
final class CacheWarmer
{
    public const TYPE_HOME = 'home';
    public const TYPE_CATEGORIES = 'categories';
    public const TYPE_PRODUCTS = 'products';
    public const TYPE_CMS = 'cms';

    private const QUEUE_KEY = '__qcdredis_warmup_queue__';

    public function __construct(
        private readonly LegacyContext $legacyContext,
        private readonly RedisConnectionFactory $connectionFactory,
        private readonly ConfigurationProvider $provider,
    ) {
    }

    /**
     * Build the URL queue for the given types and store it in Redis.
     *
     * @param string[] $types
     *
     * @return int Total number of URLs queued.
     */
    public function buildAndStoreQueue(array $types): int
    {
        $queue = $this->buildQueue($types);
        $key = $this->provider->getKeyPrefix() . self::QUEUE_KEY;

        $this->connectionFactory->create()->execute(
            static fn (\Redis $r) => $r->setex($key, 3600, (string) json_encode($queue))
        );

        return count($queue);
    }

    /**
     * Warm a batch from the stored queue.
     *
     * @return array{processed:int,succeeded:int,failed:int,total:int}
     */
    public function warmStoredBatch(int $offset, int $size): array
    {
        $key = $this->provider->getKeyPrefix() . self::QUEUE_KEY;
        $raw = $this->connectionFactory->create()->execute(static fn (\Redis $r) => $r->get($key));
        $queue = is_string($raw) ? (array) json_decode($raw, true) : [];

        $slice = array_slice($queue, $offset, max(1, $size));
        $succeeded = 0;
        $failed = 0;

        foreach ($slice as $url) {
            $this->fetch((string) $url) ? ++$succeeded : ++$failed;
        }

        return ['processed' => count($slice), 'succeeded' => $succeeded, 'failed' => $failed, 'total' => count($queue)];
    }

    /**
     * @param string[] $types
     *
     * @return string[]
     */
    private function buildQueue(array $types): array
    {
        $context = $this->legacyContext->getContext();
        $link = $context->link;
        $langId = (int) $context->language->id;
        $urls = [];

        if (in_array(self::TYPE_HOME, $types, true)) {
            $urls[] = $link->getPageLink('index', true);
        }

        if (in_array(self::TYPE_CATEGORIES, $types, true)) {
            $urls = array_merge($urls, $this->categoryUrls($link, $langId));
        }

        if (in_array(self::TYPE_PRODUCTS, $types, true)) {
            $urls = array_merge($urls, $this->productUrls($link, $langId));
        }

        if (in_array(self::TYPE_CMS, $types, true)) {
            $urls = array_merge($urls, $this->cmsUrls($link, $langId));
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * @return string[]
     */
    private function categoryUrls(\Link $link, int $langId): array
    {
        $rootId = (int) \Category::getRootCategory($langId)->id;
        $categories = (new \Category($rootId, $langId))->getAllChildren($langId);
        $urls = [];

        foreach ($categories as $category) {
            $urls[] = $link->getCategoryLink((int) $category->id, null, $langId);
        }

        return $urls;
    }

    /**
     * @return string[]
     */
    private function productUrls(\Link $link, int $langId): array
    {
        $products = \Product::getProducts($langId, 0, 0, 'id_product', 'ASC', false, true);
        $urls = [];

        foreach ($products as $product) {
            $urls[] = $link->getProductLink((int) $product['id_product'], null, null, null, $langId);
        }

        return $urls;
    }

    /**
     * @return string[]
     */
    private function cmsUrls(\Link $link, int $langId): array
    {
        $urls = [];

        foreach (\CMS::getCMSPages($langId) as $page) {
            $urls[] = $link->getCMSLink((int) $page['id_cms'], null, null, $langId);
        }

        return $urls;
    }

    private function fetch(string $url): bool
    {
        try {
            $content = \Tools::file_get_contents($url, false, null, 10);

            return $content !== false && $content !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
