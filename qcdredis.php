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
        $this->version = '1.0.0';
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
            $this->context->link->getAdminLink(self::ADMIN_CONTROLLER)
        );
    }

    /**
     * Expose the last installer error message for the back office.
     */
    public function getInstallerError(): string
    {
        return $this->getInstaller()->getLastError();
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
