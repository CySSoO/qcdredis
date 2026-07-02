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

/**
 * Runtime contexts supported by the Redis cache gate.
 */
enum CacheContext: string
{
    case FRONT = 'front';
    case BACK = 'back';
    case CLI = 'cli';
    case CRON = 'cron';
    case INSTALL = 'install';
    case UNKNOWN = 'unknown';
}
