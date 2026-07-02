<?php
/**
 * QCD Redis - PHPUnit bootstrap.
 *
 * Defines the minimal PrestaShop constants required to load the classes under
 * test, then wires the module autoloader (Composer when present, PSR-4 fallback
 * otherwise). The configuration layer is pure and needs no framework.
 *
 * @author    410 Gone
 * @copyright 410 Gone
 * @license   Proprietary
 */

declare(strict_types=1);

require __DIR__ . '/phpstan-bootstrap.php';
require dirname(__DIR__) . '/config/autoload.php';
