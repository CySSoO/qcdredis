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

    private LegacyContext $legacyContext;

    private RedisConnectionFactory $connectionFactory;

    private ConfigurationProvider $provider;

    public function __construct(
        LegacyContext $legacyContext,
        RedisConnectionFactory $connectionFactory,
        ConfigurationProvider $provider
    ) {
        $this->legacyContext = $legacyContext;
        $this->connectionFactory = $connectionFactory;
        $this->provider = $provider;
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
        return $this->buildAndStoreQueueDetailed($types)['total'];
    }

    /**
     * Build + store the queue and return a verbose breakdown for the UI so an
     * empty result can be told apart from a real error and pinpointed per type.
     *
     * @param string[] $types
     *
     * @return array{total:int,counts:array<string,int>,errors:array<string,string>,sample:?string}
     */
    public function buildAndStoreQueueDetailed(array $types): array
    {
        $built = $this->buildQueueDetailed($types);
        $queue = $built['urls'];
        $key = $this->provider->getKeyPrefix() . self::QUEUE_KEY;

        $this->connectionFactory->create()->execute(
            static fn (\Redis $r) => $r->setex($key, 3600, (string) json_encode($queue))
        );

        return [
            'total' => count($queue),
            'counts' => $built['counts'],
            'errors' => $built['errors'],
            'sample' => $queue[0] ?? null,
        ];
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
    /**
     * @param string[] $types
     *
     * @return array{urls:string[],counts:array<string,int>,errors:array<string,string>}
     */
    private function buildQueueDetailed(array $types): array
    {
        $context = $this->legacyContext->getContext();
        $link = $context->link ?? null;

        if (!$link instanceof \Link) {
            throw new \RuntimeException('Le générateur de liens front est indisponible dans ce contexte.');
        }

        $langId = (int) ($context->language->id ?? 0);

        $builders = [
            self::TYPE_HOME => fn (): array => array_filter([(string) $link->getPageLink('index', true)]),
            self::TYPE_CATEGORIES => fn (): array => $this->categoryUrls($link, $langId),
            self::TYPE_PRODUCTS => fn (): array => $this->productUrls($link, $langId),
            self::TYPE_CMS => fn (): array => $this->cmsUrls($link, $langId),
        ];

        $urls = [];
        $counts = [];
        $errors = [];

        foreach ($builders as $type => $builder) {
            if (!in_array($type, $types, true)) {
                continue;
            }

            try {
                $sectionUrls = array_values(array_filter(array_map('strval', $builder())));
                $counts[$type] = count($sectionUrls);
                $urls = array_merge($urls, $sectionUrls);
            } catch (\Throwable $e) {
                $counts[$type] = 0;
                $errors[$type] = $e->getMessage();
            }
        }

        return [
            'urls' => array_values(array_unique($urls)),
            'counts' => $counts,
            'errors' => $errors,
        ];
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
