# QCD Redis

Replace the PrestaShop cache engine with **Redis**, using only the mechanisms
natively provided by PrestaShop. No overrides. No permanent core changes. Fully
reversible on uninstall.

- **Technical name:** `qcdredis`
- **Publisher:** 410 Gone
- **Licence:** Proprietary
- **Compatibility:** PrestaShop 8.x and 9.x
- **PHP:** >= 8.2
- **Multishop & multilanguage:** yes

## How it works

PrestaShop selects its cache backend through the `ps_caching` parameter in
`app/config/parameters.php`, and instantiates it via `Cache::getInstance()`.
QCD Redis plugs into exactly this mechanism:

1. On install the module writes a tiny stub, `classes/cache/CacheRedis.php`,
   whose only content is `class CacheRedis extends \QcdGone\QcdRedis\Cache\QcdRedisCache`.
   This makes the engine discoverable by PrestaShop's own autoloader after the
   class index is regenerated with `PrestaShopAutoload::getInstance()->generateIndex()`.
2. `QcdRedisCache` (a PSR-4 class in `src/Cache/`) extends PrestaShop's abstract
   `Cache` class and implements the full contract on top of the **phpredis**
   extension.
3. The module patches only two keys in `parameters.php`
   (`ps_caching` → `CacheRedis`, `ps_cache_enable` → `true`) and clears the
   Symfony cache.

No file in the PrestaShop core is ever edited, and no override is created.

### Architecture note — Symfony vs. the cache engine

Everything the module owns is built the modern, Symfony way: the back office is
a Symfony controller with routes (`config/routes.yml`), Symfony Forms, Twig
templates and dependency-injected services (`config/services.yml`). The only
component that is deliberately *not* a container-managed service is the cache
engine itself (`QcdRedisCache` and its `RedisConnection`/`RedisConfig`
collaborators): PrestaShop instantiates the cache backend through
`Cache::getInstance()` extremely early in the request — before the Symfony
container exists — so that class must stay a plain, autonomous object. A minimal
PSR-4 autoloader (`config/autoload.php`) makes it loadable at that early stage.

## Reversibility

On uninstall the module restores the previously active engine and enable flag
(saved under `QCDREDIS_PREVIOUS_CACHE` / `QCDREDIS_PREVIOUS_CACHE_ENABLE`),
deletes the generated `CacheRedis.php` stub, regenerates the class index,
removes every `QCDREDIS_*` configuration entry and clears the Symfony cache.
**No trace remains.**

## Requirements

Installation is refused with a clear message if any of these fail:

- the PHP `redis` extension is loaded (`extension_loaded('redis')`);
- the `Redis` class exists;
- a connection to the configured server (default `127.0.0.1:6379`) succeeds;
- `app/config/parameters.php` is writable.

## Features

- **Dashboard** — Redis status, version, latency, memory used/free, key count,
  connected clients, hit/miss ratio, uptime, fragmentation, evictions.
- **Connection** — host, port, password (never displayed), database, timeout,
  TLS (forward-looking), plus a live connection test.
- **Cache** — activation, default TTL, gzip compression with an automatic size
  threshold, serializer (PHP / igbinary / JSON), key prefix.
- **Purge** — full flush, by prefix, by tags, and expired-key reporting. All
  purges are scoped to the current shop's namespace (multishop safe).
- **Warmup** — automatic URL discovery for home, categories, products and CMS
  pages, processed in batches with a progress bar.
- **Statistics** — real-time hits/misses, memory, keys, latency, heaviest keys,
  average TTL, expirations, with CSV export.
- **Diagnostics** — environment and connectivity checks plus read/write/delete
  benchmarks, colour-coded green / orange / red.

## Architecture

```
qcdredis/
├── qcdredis.php                      Main module (thin, delegates to Installer)
├── composer.json                     PSR-4 autoload + dev tooling
├── config/
│   ├── autoload.php                  PSR-4 autoloader for the early engine boot
│   ├── services.yml                  Dependency injection
│   └── routes.yml                    Admin Symfony routes
├── src/
│   ├── Cache/                        Autonomous engine (loaded very early)
│   │   ├── QcdRedisCache.php         Cache engine extending PrestaShop Cache
│   │   ├── RedisConnection.php       phpredis lifecycle + reconnect
│   │   ├── RedisConfig.php           Immutable, pure config value object
│   │   └── RedisConfigFactory.php    Builds config (Symfony + legacy boot)
│   ├── Config/                       ConfigurationProvider / ConfigurationUpdater
│   ├── Service/                      Statistics, Diagnostics, Purge, Warmup, ConnectionFactory
│   ├── Form/                         ConnectionType, CacheSettingsType (Symfony Forms)
│   ├── Controller/Admin/             QcdRedisController (Symfony, routes + JSON)
│   └── Install/                      Installer + ParametersManager
├── views/
│   ├── templates/admin/configure.html.twig   Twig UI (Bootstrap 5)
│   ├── css/qcdredis.css
│   └── js/qcdredis.js
├── sql/
└── tests/                            PHPUnit + PHPStan config
```

The whole `src/` tree uses the `QcdGone\QcdRedis` PSR-4 namespace. The
`src/Cache/` classes carry no PrestaShop dependency (beyond extending `\Cache`
for the engine), which keeps the configuration layer fully unit testable.

## Development & quality

```bash
composer install
composer phpstan     # PHPStan, level max
composer test        # PHPUnit
composer cs-fix      # PSR-12 formatting
```

PHPStan and PHPUnit are configured to run from inside a real shop
(`modules/qcdredis`) so PrestaShop's classes are available for analysis. The
`RedisConfig` unit tests run standalone (they fall back to defaults when the
framework is absent).

## Notes on payload format

Every stored value carries a 2-byte self-describing header (serializer flag +
compression flag), so changing the serializer or toggling compression never
corrupts existing reads — mismatched entries simply resolve to a cache miss and
are recomputed.
