# ADR-005: Metadata Caching Strategy

**Status:** Amended (see below)

## Context

Closures cannot be serialized with `var_export()`, so caching factory functions directly is impossible. The cache must support incremental staleness detection without full filesystem scans.

## Decision

`Cache_Store` persists class metadata (path, mtime, dependencies) as a plain PHP array in `cache/wpdi-container.php`. On load, `Container::load_compiled()` recreates autowiring factories from metadata. `Cache_Manager` performs incremental updates: only modified files are re-parsed, deleted files removed, new dependencies discovered transitively.

## Amendment: Config Bindings Included in Cache

Now that `wpdi-config.php` uses static class name strings (not closures — see ADR-013), the config bindings are also serializable. The cache format was extended to include a `bindings` section alongside the existing `classes` section:

```php
return array(
    'classes'  => array(
        ConcreteA::class => array('path' => ..., 'mtime' => ..., 'dependencies' => array()),
    ),
    'bindings' => array(
        InterfaceA::class => ConcreteA::class,
    ),
);
```

`Cache_Manager::get_cache()` (renamed from `get_class_map()`) accepts config bindings and writes them into every cache update. `Container::load_compiled()` loads both sections. Old flat-format caches are auto-migrated on first load.

The compiled cache is now a complete deployable artifact — a plugin can ship `cache/wpdi-container.php` without `wpdi-config.php` and the container initializes fully from cache alone.

## Amendment: Cached Constructor Descriptors

The `classes` section now includes a `constructor` key per class — a full snapshot of each constructor parameter (name, type, builtin flag, nullable, default value). This allows `Container::autowire()` to resolve dependencies from cached data without any `ReflectionClass` or `ReflectionParameter` calls at runtime.

```php
'classes' => array(
    ConcreteA::class => array(
        'path'         => ...,
        'mtime'        => ...,
        'dependencies' => array( DepB::class ),
        'constructor'  => array(
            array( 'name' => 'dep', 'type' => 'DepB', 'builtin' => false,
                   'nullable' => false, 'has_default' => false, 'default' => null ),
        ),
    ),
),
```

`constructor` is `null` when the class has no constructor. If a default value cannot be serialized (e.g. PHP 8.1 `new Foo()` in parameter defaults), `constructor` is omitted and the container falls back to reflection for that class. The `dependencies` key is kept alongside `constructor` because `Cache_Manager` and CLI commands read it directly.

## Consequences

- Cache file is a simple `var_export()` array — no serialization issues
- Production: zero filesystem overhead and zero reflection after initial compile (`wp di compile`)
- Development: per-file mtime checks, not full directory scans
- Environment type is injected into `Cache_Manager` via `Scope::environment()` — no hard dependency on `wp_get_environment_type()`
- Cache is the single source of truth for the full container definition in production
- Reflection is only used as a fallback for classes not in the cache or with non-serializable defaults
