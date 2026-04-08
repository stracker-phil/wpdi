# ADR-010: Contextual Interface Bindings

**Status:** Accepted

## Context

A single interface binding in `wpdi-config.php` maps one interface to one implementation. When multiple consumers need different implementations of the same interface, there was no solution short of creating wrapper interfaces or manual factory logic.

Symfony solves this with "named autowiring" — binding a constructor parameter name to a service globally, regardless of type hint. This implicit, type-agnostic convention was considered but rejected: any parameter named `$fooService` anywhere in the codebase silently receives a bound service, with no type-level contract and no explicit config entry pointing to it.

WPDI takes the same core idea — param name as a discriminator — but scopes it to a specific interface binding and requires explicit configuration. The type hint remains the primary routing key; the param name is only consulted as a tiebreaker when the interface has multiple implementations.

## Decision

`wpdi-config.php` accepts an array of class names keyed by `$parameter_name` (prefixed with `$`) as an alternative to a single class name. The literal key `'default'` serves as the fallback. Each branch is cached as a separate singleton (keyed by `interface::$param_name`). Missing match without a default throws `Container_Exception`.

```php
return array(
    Cache_Interface::class => array(
        '$db_cache'   => Redis_Cache::class,
        '$file_cache' => File_Cache::class,
        'default'     => Redis_Cache::class,
    ),
);
```

The container detects contextual bindings in `resolve_parameter()` before falling through to regular `get()`. Direct `$container->get()` calls on a contextual interface use the `'default'` branch.

## Consequences

- Multiple implementations of one interface without wrapper interfaces or naming magic
- Explicit `$`-prefixed keys make it clear the routing is based on variable names
- Each branch is a true singleton — no duplicate instances for the same branch
- Static class name values (no callables) make the config fully serializable and inspectable
- Contextual bindings are cached in the `bindings` section of `cache/wpdi-container.php` alongside class metadata (see ADR-005 amendment)
