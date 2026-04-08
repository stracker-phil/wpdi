# ADR-013: Static Class Name Format for wpdi-config.php

**Status:** Accepted

## Context

`wpdi-config.php` previously mapped interfaces to factory closures:

```php
return array(
    Logger_Interface::class => fn() => new WP_Logger(),
    Cache_Interface::class  => array(
        '$db_cache' => fn() => new Redis_Cache(),
        ''          => fn() => new File_Cache(),
    ),
);
```

Two problems with this approach:

1. **Closures are not serializable.** `var_export()` cannot encode a closure, so config bindings could never be included in the compiled cache. The cache was always a partial picture — classes from autowiring, but no interface bindings.
2. **Factories ran without injection.** `fn() => new Redis_Cache()` constructs the concrete class directly, bypassing the container. If `Redis_Cache` has its own constructor dependencies, they are silently not injected.

## Decision

`wpdi-config.php` maps interface names to concrete class name strings only — no callables:

```php
return array(
    Logger_Interface::class => WP_Logger::class,
    Cache_Interface::class  => array(
        '$db_cache' => Redis_Cache::class,
        'default'   => File_Cache::class,
    ),
);
```

`Container::load_config()` wraps each string value in `fn() => $this->get($concrete)`, so the concrete class is resolved through the container (with full autowiring). `bind_contextual()` validates that values are class or interface names. The contextual default key is the literal string `'default'` (previously `''`).

Conditional bindings (e.g. environment-dependent implementation selection) belong in a service class resolved in `bootstrap()`, not in the config file.

## Consequences

- Config bindings are serializable — included in `cache/wpdi-container.php` (see ADR-005 amendment)
- Concrete class dependencies are properly injected via the container, not silently skipped
- Config file is a static, inspectable map — no hidden logic or side effects
- No escape hatch for passing scalar values or dynamic values via config; see ADR-016 for the configuration-vs-DI principle
- The compiled cache is a complete, self-contained artifact: plugins can ship it without `wpdi-config.php`
