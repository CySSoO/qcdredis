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

/**
 * Automatically refreshes the Redis cache of a single PrestaShop object right
 * after it is saved in the Back Office.
 *
 * Strategy (per saved object, never global, never a flush):
 *  1. PURGE — invalidate only the cache entries related to the object, using
 *     PrestaShop's own table-scoped invalidation ({@see \Cache::deleteQuery()}).
 *     This removes every cached SELECT touching the object's tables and nothing
 *     else. No pattern guessing, no FLUSH.
 *  2. WARMUP — reload the object through the Core classes (new Product/Category/
 *     …). Those normal, cache-enabled reads repopulate Redis naturally, so the
 *     first visitor never pays the reconstruction cost. Nothing is ever written
 *     to Redis directly.
 *
 * The work is deferred to request shutdown so the administrator's save returns
 * instantly. It is intentionally framework-light (no container) because it is
 * created from the legacy module hook layer.
 */
final class ObjectCacheRefresher
{
    /** @var array<string, string[]> Object type => tables whose cached queries must be dropped. */
    private const TABLES = [
        'Product' => ['product', 'product_lang', 'product_shop'],
        'Category' => ['category', 'category_lang', 'category_shop'],
        'CMS' => ['cms', 'cms_lang', 'cms_shop'],
        'Manufacturer' => ['manufacturer', 'manufacturer_lang', 'manufacturer_shop'],
        'Supplier' => ['supplier', 'supplier_lang', 'supplier_shop'],
    ];

    /** @var int Safety ceiling so a bulk operation never turns into a mass reload. */
    private const MAX_QUEUE = 200;

    /** @var array<string, array{type:string,id:int,warm:bool}> */
    private static array $queue = [];

    private static bool $registered = false;

    /**
     * PrestaShop object-lifecycle hooks handled by the module.
     *
     * @return string[]
     */
    public static function hooks(): array
    {
        $hooks = [];

        foreach (array_keys(self::TABLES) as $object) {
            $hooks[] = 'actionObject' . $object . 'AddAfter';
            $hooks[] = 'actionObject' . $object . 'UpdateAfter';
            $hooks[] = 'actionObject' . $object . 'DeleteAfter';
        }

        return $hooks;
    }

    /**
     * Queue an object for refresh at shutdown. Warmup is skipped on delete.
     */
    public function enqueue(string $type, int $id, bool $warm): void
    {
        if ($id <= 0 || !isset(self::TABLES[$type])) {
            return;
        }

        // Only when Redis is the active engine and we are in the Back Office.
        if (!defined('_PS_CACHING_SYSTEM_') || _PS_CACHING_SYSTEM_ !== 'CacheRedis') {
            return;
        }

        if (!defined('_PS_ADMIN_DIR_')) {
            return;
        }

        if (count(self::$queue) >= self::MAX_QUEUE) {
            return;
        }

        // Dedupe per object; a warmup request wins over a purge-only one.
        $key = $type . ':' . $id;
        $warm = $warm || (isset(self::$queue[$key]) && self::$queue[$key]['warm']);
        self::$queue[$key] = ['type' => $type, 'id' => $id, 'warm' => $warm];

        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'processQueue']);
        }
    }

    /**
     * Drain the queue after the response has been sent. Never throws.
     */
    public static function processQueue(): void
    {
        $items = self::$queue;
        self::$queue = [];

        $refresher = new self();

        foreach ($items as $item) {
            try {
                $refresher->process($item['type'], (int) $item['id'], (bool) $item['warm']);
            } catch (\Throwable) {
                // A single object must never break the request teardown.
            }
        }
    }

    private function process(string $type, int $id, bool $warm): void
    {
        $this->purge($type);

        if ($warm) {
            $this->warm($type, $id);
        }
    }

    /**
     * Drop every cached query touching the object's tables (table-scoped, never
     * a flush), through PrestaShop's own cache invalidation.
     */
    private function purge(string $type): void
    {
        if (!class_exists('Cache') || !defined('_DB_PREFIX_')) {
            return;
        }

        $cache = \Cache::getInstance();

        foreach (self::TABLES[$type] ?? [] as $table) {
            try {
                $cache->deleteQuery('SELECT 1 FROM `' . _DB_PREFIX_ . $table . '`');
            } catch (\Throwable) {
                // Ignore: purge is best-effort; the warmup below rebuilds anyway.
            }
        }
    }

    /**
     * Rebuild the object's cache by loading it through the Core in every active
     * language of the current shop. These normal reads repopulate Redis.
     */
    private function warm(string $type, int $id): void
    {
        $shopId = $this->shopId();

        foreach ($this->languageIds() as $idLang) {
            try {
                $this->instantiate($type, $id, $idLang, $shopId);
            } catch (\Throwable) {
                // Skip a failing language; keep warming the others.
            }
        }
    }

    private function instantiate(string $type, int $id, int $idLang, int $shopId): void
    {
        $shop = $shopId > 0 ? $shopId : null;

        switch ($type) {
            case 'Product':
                new \Product($id, true, $idLang, $shop);
                break;
            case 'Category':
                new \Category($id, $idLang, $shop);
                break;
            case 'CMS':
                new \CMS($id, $idLang, $shop);
                break;
            case 'Manufacturer':
                new \Manufacturer($id, $idLang);
                break;
            case 'Supplier':
                new \Supplier($id, $idLang);
                break;
        }
    }

    /**
     * @return int[]
     */
    private function languageIds(): array
    {
        try {
            if (class_exists('Language') && method_exists('Language', 'getIDs')) {
                $ids = \Language::getIDs(true);

                if (is_array($ids) && $ids !== []) {
                    return array_map('intval', $ids);
                }
            }
        } catch (\Throwable) {
            // Fall through to the context language.
        }

        try {
            $id = (int) (\Context::getContext()->language->id ?? 0);

            if ($id > 0) {
                return [$id];
            }
        } catch (\Throwable) {
            // No context available.
        }

        return [];
    }

    private function shopId(): int
    {
        try {
            if (class_exists('Shop') && method_exists('Shop', 'getContextShopID')) {
                // Real context shop id (same call as the cache engine) so the
                // reload warms the correct shop and the ':sN:' namespace matches.
                return max(0, (int) \Shop::getContextShopID());
            }
        } catch (\Throwable) {
            // No shop context.
        }

        return 0;
    }
}
