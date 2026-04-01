# ADR-006: Composition Root via Scope Class

**Status:** Amended (boot() entry point added)

## Context

Service location (calling the container from anywhere) is an anti-pattern. The container should only be accessed in one place.

Originally, plugins instantiated their scope with `new App(__FILE__)`. This produced an unused return value that triggered PhpStorm and CI warnings, and allowed accidentally creating multiple containers for the same plugin.

## Decision

`Scope` is the base class for WordPress plugins/themes. Its `protected` constructor creates a `Container`, initializes it, then calls `bootstrap(Resolver)`. The `Resolver` exposes only `get()` and `has()` — no `bind()` or `clear()`. The Container is a local variable, never stored as a property. All services receive dependencies via constructor injection.

Plugins boot via `App::boot(__FILE__)` — a `static` method on `Scope` that:

1. Guards against duplicate instantiation: a second call for the same class is a no-op.
2. Stores the instance in `private static $booted[class-name]` to prevent GC.
3. Returns `void`, eliminating unused-return-value warnings.

`Scope::clear()` is a static method that removes the stored instance for a given class. It is intended for test teardown only.

The constructor is `protected` to enforce `::boot()` as the external entry point. Test fixtures that need direct instantiation (e.g. `TestScope`) may override the constructor with `public` visibility.

## Consequences

- Service location is structurally prevented (no container reference leaks)
- `bootstrap()` is the single composition root
- Duplicate container creation for the same scope is prevented at runtime
- No IDE/CI warnings about unused return values
- Plugin authors extend `Scope`, call `App::boot(__FILE__)` from their plugin file, and override `bootstrap()` and optionally `autowiring_paths()` or `environment()`
