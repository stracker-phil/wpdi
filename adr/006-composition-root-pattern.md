# ADR-006: Composition Root via Scope Class

**Status:** Accepted

## Context

Service location (calling the container from anywhere) is an anti-pattern. The container should only be accessed in one place.

## Decision

`Scope` is the base class for WordPress plugins/themes. Its constructor creates a `Container`, initializes it, then calls `bootstrap(Resolver)`. The `Resolver` exposes only `get()` and `has()` — no `bind()` or `clear()`. The Container is a local variable, never stored as a property. All services receive dependencies via constructor injection.

## Consequences

- Service location is structurally prevented (no container reference leaks)
- `bootstrap()` is the single composition root
- Plugin authors extend `Scope` and override `bootstrap()` and optionally `autowiring_paths()`
