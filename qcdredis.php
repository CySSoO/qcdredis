<?php
/**
 * QCD Redis.
 *
 * Replaces the PrestaShop cache engine with Redis using only the native
 * mechanisms provided by PrestaShop. No overrides, no permanent core changes.
 * Every modification (creation of classes/cache/CacheRedis.php, patch of
 * app/config/parameters.php) is fully reversible on uninstall.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

use QcdGone\QcdRedis\Install\Installer;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/config/autoload.php';

/**
 * Main module class.
 */
class Qcdredis extends Module
{
    /** @var string Admin controller class name (without the "Controller" suffix). */
    public const ADMIN_CONTROLLER = 'AdminQcdRedis';

    /** @var Installer|null Cached installer so error state survives across calls. */
    private ?Installer $installer = null;

    public function __construct()
    {
        $this->name = 'qcdredis';
        $this->tab = 'administration';
        $this->version = '1.0.2';
        $this->author = '410 Gone';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('QCD Redis', [], 'Modules.Qcdredis.Admin');
        $this->description = $this->trans(
            'Replace the PrestaShop cache engine with Redis. Native, reversible, without overrides.',
            [],
            'Modules.Qcdredis.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure? Uninstalling will restore the previous cache engine.',
            [],
            'Modules.Qcdredis.Admin'
        );
    }

    /**
     * Install the module and switch the cache engine to Redis.
     */
    public function install(): bool
    {
        $installer = $this->getInstaller();

        // Refuse installation early if Redis is unavailable, before touching PrestaShop.
        if (!$installer->checkRequirements()) {
            $this->_errors[] = $installer->getLastError();

            return false;
        }

        if (!parent::install()) {
            return false;
        }

        if (!$installer->install()) {
            $this->_errors[] = $installer->getLastError();

            return false;
        }

        return true;
    }

    /**
     * Uninstall the module and fully restore the previous cache configuration.
     */
    public function uninstall(): bool
    {
        // Restore everything first so no trace remains even if parent fails.
        $restored = $this->getInstaller()->uninstall();

        return parent::uninstall() && $restored;
    }

    /**
     * Redirect the module configuration page to the dedicated admin controller.
     */
    public function getContent(): void
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink(
                self::ADMIN_CONTROLLER,
                true,
                ['route' => 'qcdredis_admin_configure']
            )
        );
    }

    /**
     * Expose the last installer error message for the back office.
     */
    public function getInstallerError(): string
    {
        return $this->getInstaller()->getLastError();
    }

    /* ---- Automatic per-object Redis refresh (purge + targeted warmup) ---- */

    public function hookActionObjectProductAddAfter(array $params): void
    {
        $this->qcdRefresh('Product', $params, true);
    }

    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $this->qcdRefresh('Product', $params, true);
    }

    public function hookActionObjectProductDeleteAfter(array $params): void
    {
        $this->qcdRefresh('Product', $params, false);
    }

    public function hookActionObjectCategoryAddAfter(array $params): void
    {
        $this->qcdRefresh('Category', $params, true);
    }

    public function hookActionObjectCategoryUpdateAfter(array $params): void
    {
        $this->qcdRefresh('Category', $params, true);
    }

    public function hookActionObjectCategoryDeleteAfter(array $params): void
    {
        $this->qcdRefresh('Category', $params, false);
    }

    public function hookActionObjectCMSAddAfter(array $params): void
    {
        $this->qcdRefresh('CMS', $params, true);
    }

    public function hookActionObjectCMSUpdateAfter(array $params): void
    {
        $this->qcdRefresh('CMS', $params, true);
    }

    public function hookActionObjectCMSDeleteAfter(array $params): void
    {
        $this->qcdRefresh('CMS', $params, false);
    }

    public function hookActionObjectManufacturerAddAfter(array $params): void
    {
        $this->qcdRefresh('Manufacturer', $params, true);
    }

    public function hookActionObjectManufacturerUpdateAfter(array $params): void
    {
        $this->qcdRefresh('Manufacturer', $params, true);
    }

    public function hookActionObjectManufacturerDeleteAfter(array $params): void
    {
        $this->qcdRefresh('Manufacturer', $params, false);
    }

    public function hookActionObjectSupplierAddAfter(array $params): void
    {
        $this->qcdRefresh('Supplier', $params, true);
    }

    public function hookActionObjectSupplierUpdateAfter(array $params): void
    {
        $this->qcdRefresh('Supplier', $params, true);
    }

    public function hookActionObjectSupplierDeleteAfter(array $params): void
    {
        $this->qcdRefresh('Supplier', $params, false);
    }

    /**
     * Forward a saved object to the refresher (purge + object-scoped warmup).
     * Never throws: an administrator's save must never be broken by the cache.
     *
     * @param array<string, mixed> $params
     */
    private function qcdRefresh(string $type, array $params, bool $warm): void
    {
        try {
            $id = 0;
            $object = $params['object'] ?? null;

            if (is_object($object) && isset($object->id)) {
                $id = (int) $object->id;
            } elseif (isset($params['id'])) {
                $id = (int) $params['id'];
            }

            if ($id > 0) {
                (new \QcdGone\QcdRedis\Service\ObjectCacheRefresher())->enqueue($type, $id, $warm);
            }
        } catch (\Throwable) {
            // Swallow: the refresh is best-effort and must stay invisible.
        }
    }

    /**
     * Build (once) the installer service (kept private to honour SRP).
     */
    private function getInstaller(): Installer
    {
        if (!$this->installer instanceof Installer) {
            $this->installer = new Installer($this);
        }

        return $this->installer;
    }
}
