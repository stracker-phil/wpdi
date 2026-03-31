# ADR-010: Contextual Interface Bindings

**Status:** Accepted

## Context

A single interface binding in `wpdi-config.php` maps one interface to one implementation. When multiple consumers need different implementations of the same interface, there was no solution short of creating wrapper interfaces or manual factory logic.

Symfony solves this with "named autowiring" — matching the constructor parameter name to an implementation automatically. This approach was considered but rejected for WPDI because the implicit naming convention adds magic that is hard to grasp for WordPress developers.

## Decision

`wpdi-config.php` accepts an array of factories keyed by `$parameter_name` (prefixed with `$`) as an alternative to a single factory closure. An empty string key (`''`) serves as the default fallback. Each branch is cached as a separate singleton (keyed by `interface::$param_name`). Missing match without a default throws `Container_Exception`.

```php
return array(
    Cache_Interface::class => array(
        '$db_cache'   => fn( $r ) => new Redis_Cache(),
        '$file_cache' => fn( $r ) => new File_Cache(),
        ''            => fn( $r ) => new Redis_Cache(),
    ),
);
```

The container detects contextual bindings in `resolve_parameter()` before falling through to regular `get()`. Direct `$container->get()` calls on a contextual interface use the `''` default.

## Consequences

- Multiple implementations of one interface without wrapper interfaces or naming magic
- Explicit `$`-prefixed keys make it clear the routing is based on variable names
- Each branch is a true singleton — no duplicate instances for the same branch
- Simple bindings (single closure) remain unchanged — fully backwards compatible
- `Compiler` and `Cache_Manager` require no changes (contextual bindings are user-defined factories, never cached as metadata)
