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

use Configuration;
use Language;
use Module;
use PrestaShopAutoload;
use QcdGone\QcdRedis\Cache\RedisConfigFactory;
use QcdGone\QcdRedis\Cache\RedisConnection;
use QcdGone\QcdRedis\Context\ContextResolver;
use QcdGone\QcdRedis\Service\ObjectCacheRefresher;
use Tab;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Orchestrates the full, reversible install/uninstall lifecycle.
 *
 * Install: verify Redis, persist defaults, remember the previous cache engine,
 * generate classes/cache/CacheRedis.php, regenerate the class index, patch
 * parameters.php and clear the Symfony cache.
 *
 * Uninstall: restore parameters.php, delete the generated stub, regenerate the
 * class index, delete configuration and clear the Symfony cache. No trace left.
 */
final class Installer
{
    private const STUB_RELATIVE = '/classes/cache/CacheRedis.php';

    private const ADMIN_TAB = 'AdminQcdRedis';

    private Module $module;

    private ParametersManager $parameters;

    private string $lastError = '';

    public function __construct(Module $module, ?ParametersManager $parameters = null)
    {
        $this->module = $module;
        $this->parameters = $parameters ?? new ParametersManager();
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Verify local requirements without opening a Redis socket.
     */
    public function checkRequirements(): bool
    {
        if (!RedisConnection::isExtensionAvailable()) {
            $this->lastError = 'The PHP "redis" extension is not installed (extension_loaded("redis") failed).';

            return false;
        }

        if (!$this->parameters->isWritable()) {
            $this->lastError = 'app/config/parameters.php is not writable.';

            return false;
        }

        return true;
    }

    /**
     * Run the full install sequence.
     */
    public function install(): bool
    {
        if (!$this->checkRequirements()) {
            return false;
        }

        $this->saveDefaults();
        $this->savePreviousCacheState();

        if (!$this->generateStub()) {
            $this->lastError = 'Unable to write ' . self::STUB_RELATIVE . '.';

            return false;
        }

        $this->regenerateClassIndex();

        if (!$this->parameters->apply('CacheRedis', true)) {
            $this->lastError = 'Unable to patch parameters.php.';
            $this->removeStub();

            return false;
        }

        $this->clearSymfonyCache();
        $this->registerHooks();

        return $this->installTab();
    }

    /**
     * Attach the module to the object-lifecycle hooks that drive the automatic
     * per-object purge + warmup. Best-effort: a hook that cannot be registered
     * must not fail the install.
     */
    private function registerHooks(): void
    {
        try {
            $this->module->registerHook(ObjectCacheRefresher::hooks());
        } catch (\Throwable $e) {
            $this->lastError = 'Hook registration warning: ' . $e->getMessage();
        }
    }

    /**
     * Run the full uninstall sequence, restoring everything.
     */
    public function uninstall(): bool
    {
        $this->parameters->restore(
            Configuration::get(RedisConfigFactory::KEY_PREVIOUS_CACHE),
            Configuration::get(RedisConfigFactory::KEY_PREVIOUS_CACHE_ENABLE)
        );

        $this->removeStub();
        $this->regenerateClassIndex();
        $this->deleteConfiguration();
        $this->clearSymfonyCache();

        return $this->uninstallTab();
    }

    /**
     * Persist default configuration values for every module key.
     */
    private function saveDefaults(): void
    {
        foreach (RedisConfigFactory::allKeys() as $key) {
            $default = RedisConfigFactory::getDefault($key);

            if ($default !== null && !Configuration::hasKey($key)) {
                Configuration::updateValue($key, is_bool($default) ? (int) $default : $default);
            }
        }

        foreach (ContextResolver::configurationKeys() as $key) {
            $default = ContextResolver::getDefault($key);

            if ($default !== null && !Configuration::hasKey($key)) {
                Configuration::updateValue($key, (int) $default);
            }
        }
    }

    /**
     * Remember the previously active cache engine and enable flag.
     */
    private function savePreviousCacheState(): void
    {
        $previousCaching = $this->parameters->getParameter(ParametersManager::KEY_CACHING);
        $previousEnable = $this->parameters->getParameter(ParametersManager::KEY_CACHE_ENABLE);

        if (!is_string($previousCaching) || $previousCaching === '' || $previousCaching === 'CacheRedis') {
            $previousCaching = defined('_PS_CACHING_SYSTEM_') && _PS_CACHING_SYSTEM_ !== 'CacheRedis'
                ? _PS_CACHING_SYSTEM_
                : 'CacheFs';
        }

        Configuration::updateValue(RedisConfigFactory::KEY_PREVIOUS_CACHE, $previousCaching);
        Configuration::updateValue(RedisConfigFactory::KEY_PREVIOUS_CACHE_ENABLE, (int) (bool) $previousEnable);
    }

    /**
     * Generate the tiny CacheRedis stub in the core classes/cache directory.
     */
    private function generateStub(): bool
    {
        $path = _PS_ROOT_DIR_ . self::STUB_RELATIVE;
        $dir = dirname($path);

        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $contents = "<?php\n"
            . "/* Generated by the qcdredis module. Removed automatically on uninstall. Do not edit. */\n"
            . "if (!defined('_PS_VERSION_')) { exit; }\n\n"
            . "require_once _PS_MODULE_DIR_ . 'qcdredis/config/autoload.php';\n\n"
            . "class CacheRedis extends \\QcdGone\\QcdRedis\\Cache\\QcdRedisCache\n{\n}\n";

        return file_put_contents($path, $contents, LOCK_EX) !== false;
    }

    /**
     * Delete the generated CacheRedis stub if present.
     */
    private function removeStub(): void
    {
        $path = _PS_ROOT_DIR_ . self::STUB_RELATIVE;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Regenerate PrestaShop's class index so CacheRedis becomes autoloadable.
     */
    private function regenerateClassIndex(): void
    {
        try {
            if (class_exists('PrestaShopAutoload')) {
                PrestaShopAutoload::getInstance()->generateIndex();
            }
        } catch (\Throwable $e) {
            $this->lastError = 'Class index regeneration warning: ' . $e->getMessage();
        }
    }

    /**
     * Clear the Symfony cache by removing the compiled cache directories.
     */
    private function clearSymfonyCache(): void
    {
        foreach (['dev', 'prod'] as $env) {
            $dir = _PS_ROOT_DIR_ . '/var/cache/' . $env;

            if (is_dir($dir)) {
                Tools::deleteDirectory($dir, true);
            }
        }
    }

    /**
     * Remove every QCDREDIS_* configuration entry.
     */
    private function deleteConfiguration(): void
    {
        foreach (RedisConfigFactory::allKeys() as $key) {
            Configuration::deleteByName($key);
        }

        foreach (ContextResolver::configurationKeys() as $key) {
            Configuration::deleteByName($key);
        }
    }

    /**
     * Create the back office admin tab.
     */
    private function installTab(): bool
    {
        if (Tab::getIdFromClassName(self::ADMIN_TAB)) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = self::ADMIN_TAB;
        $tab->route_name = 'qcdredis_admin_configure';
        $tab->module = $this->module->name;
        $tab->icon = 'memory';
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminAdvancedParameters');

        $tab->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'QCD Redis';
        }

        return (bool) $tab->add();
    }

    /**
     * Delete the back office admin tab.
     */
    private function uninstallTab(): bool
    {
        $id = (int) Tab::getIdFromClassName(self::ADMIN_TAB);

        if ($id === 0) {
            return true;
        }

        return (bool) (new Tab($id))->delete();
    }
}
