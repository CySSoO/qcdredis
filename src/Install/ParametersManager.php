<?php
/**
 * QCD Redis.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

namespace QcdGone\QcdRedis\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reads and surgically patches app/config/parameters.php.
 *
 * Only the two caching keys (ps_caching, ps_cache_enable) are ever modified;
 * every other parameter is preserved exactly. This is the native PrestaShop
 * mechanism for selecting a cache engine, so no override is required.
 */
final class ParametersManager
{
    public const KEY_CACHING = 'ps_caching';
    public const KEY_CACHE_ENABLE = 'ps_cache_enable';

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? _PS_ROOT_DIR_ . '/app/config/parameters.php';
    }

    /**
     * Absolute path of the managed parameters file.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Whether the parameters file can be read and written.
     */
    public function isWritable(): bool
    {
        return is_file($this->path) && is_readable($this->path) && is_writable($this->path);
    }

    /**
     * Return the current value of a caching parameter.
     */
    public function getParameter(string $key): mixed
    {
        $config = $this->load();

        return $config['parameters'][$key] ?? null;
    }

    /**
     * Set the active caching engine and enable flag, then persist.
     *
     * @return bool True when the file was written successfully.
     */
    public function apply(string $cachingSystem, bool $enable): bool
    {
        return $this->write([
            self::KEY_CACHING => $cachingSystem,
            self::KEY_CACHE_ENABLE => $enable,
        ]);
    }

    /**
     * Restore the caching parameters to previously saved values.
     *
     * @param mixed $previousCaching
     * @param mixed $previousEnable
     */
    public function restore(mixed $previousCaching, mixed $previousEnable): bool
    {
        return $this->write([
            self::KEY_CACHING => is_string($previousCaching) && $previousCaching !== '' ? $previousCaching : 'CacheFs',
            self::KEY_CACHE_ENABLE => (bool) $previousEnable,
        ]);
    }

    /**
     * Merge the given caching values into the parameters file.
     *
     * @param array<string, mixed> $values
     */
    private function write(array $values): bool
    {
        if (!$this->isWritable()) {
            return false;
        }

        $config = $this->load();

        if (!isset($config['parameters']) || !is_array($config['parameters'])) {
            return false;
        }

        foreach ($values as $key => $value) {
            $config['parameters'][$key] = $value;
        }

        $export = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        $bytes = file_put_contents($this->path, $export, LOCK_EX);

        if ($bytes === false) {
            return false;
        }

        $this->invalidateOpcache();

        return true;
    }

    /**
     * Load the parameters file as an array.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return ['parameters' => []];
        }

        /** @var mixed $config */
        $config = include $this->path;

        return is_array($config) ? $config : ['parameters' => []];
    }

    /**
     * Invalidate the opcode cache for the rewritten file when possible.
     */
    private function invalidateOpcache(): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
    }
}
