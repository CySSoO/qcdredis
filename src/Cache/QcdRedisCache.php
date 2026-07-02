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

use QcdGone\QcdRedis\Context\ContextResolver;

/**
 * Concrete PrestaShop cache backend backed by Redis (phpredis).
 *
 * This is the single class that must remain a plain (non container-managed)
 * object: PrestaShop instantiates the cache backend through
 * `Cache::getInstance()` extremely early in the request, before the Symfony
 * container is available. It extends the native \Cache class so a tiny global
 * `CacheRedis` stub - generated in classes/cache on install - can point at it
 * without any override.
 *
 * Payloads carry a 2-byte self-describing header (serializer flag + compression
 * flag) so serializer/compression changes never corrupt reads. Connection loss
 * degrades gracefully to a permanent cache miss.
 */
class QcdRedisCache extends \Cache
{
    /** @var bool Public flag mirroring the PrestaShop cache backends contract. */
    public $is_connected = false;

    private const KEYS_INDEX = '__qcdredis_keys__';

    private ?RedisConfig $qcdConfig = null;

    private ?RedisConnection $qcdConnection = null;

    private string $prefix = '';

    /** @var bool Whether the in-memory keys index changed and must be persisted. */
    private bool $keysDirty = false;

    /** @var bool Guards single registration of the shutdown flush. */
    private bool $shutdownRegistered = false;

    private static bool $isConstructing = false;

    public function __construct()
    {
        $this->keys = [];

        if (self::$isConstructing) {
            return;
        }

        if (!(new ContextResolver())->isRedisAllowed()) {
            return;
        }

        self::$isConstructing = true;

        try {
            $this->qcdConfig = RedisConfigFactory::fromLegacyConfiguration();

            if (!$this->qcdConfig->isEnabled()) {
                return;
            }

            $this->qcdConnection = new RedisConnection($this->qcdConfig);
            $this->prefix = $this->qcdConfig->getKeyPrefix(self::resolveShopId());

            $this->is_connected = $this->qcdConnection->isAvailable();

            if ($this->is_connected) {
                $stored = $this->readKeysIndex();
                $this->keys = is_array($stored) ? $stored : [];
            }
        } finally {
            self::$isConstructing = false;
        }
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl
     *
     * @return bool
     */
    protected function _set($key, $value, $ttl = 0)
    {
        if (!$this->is_connected || !$this->qcdConfig instanceof RedisConfig) {
            return false;
        }

        $payload = $this->encode($value);
        $redisKey = $this->prefix . $key;
        $effectiveTtl = ((int) $ttl) > 0 ? (int) $ttl : $this->qcdConfig->getDefaultTtl();

        return (bool) $this->run(
            static function (\Redis $r) use ($redisKey, $payload, $effectiveTtl) {
                return $effectiveTtl > 0
                    ? $r->setex($redisKey, $effectiveTtl, $payload)
                    : $r->set($redisKey, $payload);
            },
            false
        );
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    protected function _get($key)
    {
        if (!$this->is_connected) {
            return false;
        }

        $redisKey = $this->prefix . $key;
        $raw = $this->run(static fn (\Redis $r) => $r->get($redisKey), false);

        if (!is_string($raw) || $raw === '') {
            return false;
        }

        return $this->decode($raw);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    protected function _exists($key)
    {
        if (!$this->is_connected) {
            return false;
        }

        $redisKey = $this->prefix . $key;

        return (bool) $this->run(static fn (\Redis $r) => $r->exists($redisKey), false);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    protected function _delete($key)
    {
        if (!$this->is_connected) {
            return false;
        }

        $redisKey = $this->prefix . $key;

        return (bool) $this->run(static fn (\Redis $r) => $r->del($redisKey), false);
    }

    /**
     * Mark the keys index dirty and defer its persistence to request shutdown.
     *
     * PrestaShop's native Cache::set()/delete() call _writeKeys() after *every*
     * mutation. Persisting the whole (unbounded, growing) index to Redis on each
     * call turns a page doing N cache writes into N full serialize()+SET cycles
     * of an ever-larger payload - a quadratic cost that dominates the request.
     *
     * The in-memory {@see $this->keys} array is authoritative for the current
     * request (native get()/exists()/delete() only consult it), so the on-Redis
     * index only needs to reflect the final state once, at end of request. A
     * missing/stale index entry can only cause a benign cache miss on a later
     * request (values themselves are written immediately by _set()), never data
     * corruption. This preserves behaviour while collapsing N writes into 1.
     *
     * @return bool
     */
    protected function _writeKeys()
    {
        if (!$this->is_connected) {
            return false;
        }

        $this->keysDirty = true;
        $this->registerShutdownFlush();

        return true;
    }

    /**
     * Register (once) the shutdown callback that persists the keys index.
     */
    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        // A closure defined here is bound to $this and this class scope, so it
        // can call the private persistKeysIndex() without widening the API.
        register_shutdown_function(function (): void {
            $this->persistKeysIndex();
        });
    }

    /**
     * Persist the in-memory keys index to Redis if it changed. Safe to call
     * multiple times; only writes when dirty.
     */
    private function persistKeysIndex(): bool
    {
        if (!$this->keysDirty || !$this->is_connected) {
            return false;
        }

        $this->keysDirty = false;
        $payload = $this->encode($this->keys);
        $indexKey = $this->prefix . self::KEYS_INDEX;

        return (bool) $this->run(static fn (\Redis $r) => $r->set($indexKey, $payload), false);
    }

    /**
     * Flush this shop's namespace only (multishop safe).
     *
     * @return bool
     */
    public function flush()
    {
        if (!$this->is_connected) {
            return false;
        }

        $deleted = $this->deleteByPattern($this->prefix . '*');
        $this->keys = [];
        // The pattern delete above also removed the index key; nothing pending.
        $this->keysDirty = false;

        return $deleted !== false;
    }

    /**
     * Delete every Redis key matching a pattern using a non-blocking SCAN.
     * Returns the number of deleted keys, or false on failure.
     */
    public function deleteByPattern(string $pattern): int|false
    {
        if (!$this->is_connected || !$this->qcdConnection instanceof RedisConnection) {
            return false;
        }

        try {
            $client = $this->qcdConnection->getClient();
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
            return false;
        }
    }

    /**
     * Execute a Redis command, degrading to a default value on any failure so
     * the shop never fatals when Redis becomes unavailable mid-request.
     *
     * @param callable(\Redis):mixed $callback
     */
    private function run(callable $callback, mixed $default): mixed
    {
        if (!$this->qcdConnection instanceof RedisConnection) {
            return $default;
        }

        try {
            return $this->qcdConnection->execute($callback);
        } catch (\Throwable) {
            $this->is_connected = false;

            return $default;
        }
    }

    /**
     * @return array<string, int>
     */
    private function readKeysIndex(): array
    {
        $indexKey = $this->prefix . self::KEYS_INDEX;
        $raw = $this->run(static fn (\Redis $r) => $r->get($indexKey), false);

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = $this->decode($raw);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Serialize (and optionally compress) a value into a self-describing string.
     */
    private function encode(mixed $value): string
    {
        [$serializerFlag, $data] = $this->serializeValue($value);
        $compressionFlag = '0';

        if ($this->shouldCompress($data)) {
            $compressed = gzencode($data, 6);

            if ($compressed !== false) {
                $data = $compressed;
                $compressionFlag = 'G';
            }
        }

        return $serializerFlag . $compressionFlag . $data;
    }

    /**
     * Reverse encode(): decompress then unserialize. Returns false on any error.
     */
    private function decode(string $raw): mixed
    {
        if (strlen($raw) < 2) {
            return false;
        }

        $serializerFlag = $raw[0];
        $compressionFlag = $raw[1];
        $data = substr($raw, 2);

        if ($compressionFlag === 'G') {
            $data = gzdecode($data);

            if ($data === false) {
                return false;
            }
        }

        return $this->unserializeValue($serializerFlag, $data);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function serializeValue(mixed $value): array
    {
        $serializer = $this->qcdConfig instanceof RedisConfig
            ? $this->qcdConfig->getSerializer()
            : RedisConfig::SERIALIZER_PHP;

        if ($serializer === RedisConfig::SERIALIZER_IGBINARY && function_exists('igbinary_serialize')) {
            return ['I', (string) igbinary_serialize($value)];
        }

        if ($serializer === RedisConfig::SERIALIZER_JSON) {
            return ['J', (string) json_encode($value)];
        }

        return ['P', serialize($value)];
    }

    private function unserializeValue(string $flag, string $data): mixed
    {
        if ($flag === 'I') {
            return function_exists('igbinary_unserialize') ? igbinary_unserialize($data) : false;
        }

        if ($flag === 'J') {
            return json_decode($data, true);
        }

        return @unserialize($data);
    }

    private function shouldCompress(string $data): bool
    {
        if (!$this->qcdConfig instanceof RedisConfig
            || !$this->qcdConfig->isCompressionEnabled()
            || !function_exists('gzencode')) {
            return false;
        }

        if ($this->qcdConfig->isCompressionAuto()) {
            return strlen($data) >= $this->qcdConfig->getCompressionThreshold();
        }

        return true;
    }

    /**
     * Resolve the current shop id without hard-depending on PrestaShop.
     */
    private static function resolveShopId(): int
    {
        if (class_exists('Shop', false) && method_exists('Shop', 'getContextShopID')) {
            return (int) \Shop::getContextShopID(true);
        }

        return 0;
    }
}
