<?php
/**
 * QCD Redis.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

namespace QcdGone\QcdRedis\Config;

use PrestaShop\PrestaShop\Adapter\Configuration;
use QcdGone\QcdRedis\Cache\RedisConfigFactory;
use QcdGone\QcdRedis\Context\ContextResolver;

/**
 * Persists module settings through PrestaShop's Configuration service.
 *
 * The Redis password is only written when a new value is supplied, so an empty
 * or masked submission never clears an existing password.
 */
final class ConfigurationUpdater
{
    private Configuration $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * Persist connection settings.
     *
     * @param array{host:string,port:int,password:?string,db:int,timeout:float,tls:bool} $data
     */
    public function saveConnection(array $data): void
    {
        $this->configuration->set(RedisConfigFactory::KEY_HOST, trim($data['host']));
        $this->configuration->set(RedisConfigFactory::KEY_PORT, $data['port']);
        $this->configuration->set(RedisConfigFactory::KEY_DB, $data['db']);
        $this->configuration->set(RedisConfigFactory::KEY_TIMEOUT, $data['timeout']);
        $this->configuration->set(RedisConfigFactory::KEY_TLS, (int) $data['tls']);

        if (isset($data['password']) && $data['password'] !== '') {
            $this->configuration->set(RedisConfigFactory::KEY_PASSWORD, $data['password']);
        }
    }

    /**
     * Persist cache behaviour settings.
     *
     * @param array{enabled:bool,front:bool,front_ajax:bool,back:bool,cli:bool,cron:bool,ttl:int,prefix:string,compression:bool,compression_auto:bool,compression_threshold:int,serializer:string} $data
     */
    public function saveCache(array $data): void
    {
        $this->configuration->set(RedisConfigFactory::KEY_ENABLED, (int) $data['enabled']);
        $this->configuration->set(ContextResolver::KEY_FRONT, (int) $data['front']);
        $this->configuration->set(ContextResolver::KEY_FRONT_AJAX, (int) $data['front_ajax']);
        $this->configuration->set(ContextResolver::KEY_BACK, (int) $data['back']);
        $this->configuration->set(ContextResolver::KEY_CLI, (int) $data['cli']);
        $this->configuration->set(ContextResolver::KEY_CRON, (int) $data['cron']);
        $this->configuration->set(RedisConfigFactory::KEY_TTL, max(0, $data['ttl']));
        $this->configuration->set(RedisConfigFactory::KEY_PREFIX, trim($data['prefix']) ?: 'ps_');
        $this->configuration->set(RedisConfigFactory::KEY_COMPRESSION, (int) $data['compression']);
        $this->configuration->set(RedisConfigFactory::KEY_COMPRESSION_AUTO, (int) $data['compression_auto']);
        $this->configuration->set(RedisConfigFactory::KEY_COMPRESSION_THRESHOLD, max(0, $data['compression_threshold']));
        $this->configuration->set(RedisConfigFactory::KEY_SERIALIZER, $data['serializer']);
    }
}
