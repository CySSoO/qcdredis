<?php
/**
 * QCD Redis.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

namespace QcdGone\QcdRedis\Context;

use QcdGone\QcdRedis\Cache\RedisConfigFactory;

/**
 * Resolves the current runtime context and decides whether Redis may be used.
 *
 * This service is intentionally framework-light because CacheRedis is built
 * before the Symfony container exists. It never creates a Redis connection and
 * only reads the context activation flags needed for the current decision.
 */
final class ContextResolver
{
    public const KEY_FRONT = 'QCDREDIS_CONTEXT_FRONT';
    public const KEY_FRONT_AJAX = 'QCDREDIS_CONTEXT_FRONT_AJAX';
    public const KEY_BACK = 'QCDREDIS_CONTEXT_BACK';
    public const KEY_CLI = 'QCDREDIS_CONTEXT_CLI';
    public const KEY_CRON = 'QCDREDIS_CONTEXT_CRON';

    /** @var array<string, bool> */
    private const DEFAULTS = [
        self::KEY_FRONT => true,
        self::KEY_FRONT_AJAX => true,
        self::KEY_BACK => false,
        self::KEY_CLI => false,
        self::KEY_CRON => false,
    ];

    /**
     * Resolve the current cache context.
     */
    public function resolve(): CacheContext
    {
        if ($this->isCli()) {
            return CacheContext::CLI;
        }

        if ($this->isInstallOrUpgrade()) {
            return CacheContext::INSTALL;
        }

        if ($this->isCron()) {
            return CacheContext::CRON;
        }

        if ($this->isBackOffice()) {
            return CacheContext::BACK;
        }

        if ($this->isFrontOffice()) {
            return CacheContext::FRONT;
        }

        return CacheContext::UNKNOWN;
    }

    /**
     * Whether Redis is allowed for the current request.
     */
    public function isRedisAllowed(): bool
    {
        return $this->isRedisAllowedFor($this->resolve());
    }

    /**
     * Whether Redis is allowed for a specific context.
     */
    public function isRedisAllowedFor(CacheContext $context): bool
    {
        return match ($context) {
            CacheContext::FRONT => $this->isAjax()
                ? $this->readFlag(self::KEY_FRONT_AJAX)
                : $this->readFlag(self::KEY_FRONT),
            CacheContext::BACK => $this->readFlag(self::KEY_BACK),
            CacheContext::CLI => $this->readFlag(self::KEY_CLI),
            CacheContext::CRON => $this->readFlag(self::KEY_CRON),
            CacheContext::INSTALL,
            CacheContext::UNKNOWN => false,
        };
    }

    /**
     * @return string[]
     */
    public static function configurationKeys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public static function getDefault(string $key): ?bool
    {
        return self::DEFAULTS[$key] ?? null;
    }

    private function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || defined('STDIN');
    }

    private function isInstallOrUpgrade(): bool
    {
        if (defined('_PS_INSTALLATION_IN_PROGRESS_') || defined('PS_INSTALLATION_IN_PROGRESS')) {
            return true;
        }

        $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $requestUri = strtolower(str_replace('\\', '/', (string) ($_SERVER['REQUEST_URI'] ?? '')));
        $self = strtolower(str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? '')));

        foreach ([$script, $requestUri, $self] as $value) {
            if ($value === '') {
                continue;
            }

            if (str_contains($value, '/install') || str_contains($value, '/upgrade')) {
                return true;
            }
        }

        return false;
    }

    private function isCron(): bool
    {
        $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
        $requestUri = strtolower(str_replace('\\', '/', (string) ($_SERVER['REQUEST_URI'] ?? '')));
        $query = strtolower((string) ($_SERVER['QUERY_STRING'] ?? ''));

        foreach ([$script, $requestUri, $query] as $value) {
            if ($value !== '' && str_contains($value, 'cron')) {
                return true;
            }
        }

        return false;
    }

    private function isBackOffice(): bool
    {
        if (defined('_PS_ADMIN_DIR_')) {
            return true;
        }

        if (class_exists('Context', false)) {
            try {
                $controller = \Context::getContext()->controller ?? null;

                if (is_object($controller) && isset($controller->controller_type)) {
                    return $controller->controller_type === 'admin';
                }
            } catch (\Throwable) {
                return false;
            }
        }

        $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $self = strtolower(str_replace('\\', '/', (string) ($_SERVER['PHP_SELF'] ?? '')));

        return preg_match('#/admin[^/]*/#', $script) === 1
            || preg_match('#/admin[^/]*/#', $self) === 1;
    }

    private function isFrontOffice(): bool
    {
        if (class_exists('Context', false)) {
            try {
                $controller = \Context::getContext()->controller ?? null;

                if (is_object($controller) && isset($controller->controller_type)) {
                    return $controller->controller_type === 'front';
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return PHP_SAPI !== 'cli';
    }

    private function isAjax(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        if (isset($_SERVER['HTTP_ACCEPT'])
            && str_contains(strtolower((string) $_SERVER['HTTP_ACCEPT']), 'application/json')) {
            return true;
        }

        return isset($_REQUEST['ajax']) && (string) $_REQUEST['ajax'] !== '0';
    }

    private function readFlag(string $key): bool
    {
        $default = (bool) self::DEFAULTS[$key];

        if (class_exists('Configuration', false)) {
            try {
                $value = \Configuration::get($key);

                if ($value !== false && $value !== null && $value !== '') {
                    return $this->toBool($value);
                }
            } catch (\Throwable) {
                return $default;
            }
        }

        $value = RedisConfigFactory::readLegacyValue($key, $default);

        return $value === null || $value === '' ? $default : $this->toBool($value);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
