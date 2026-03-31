# ADR-009: Singleton Instances by Default

**Status:** Accepted

## Context

In WordPress request lifecycle, most services are stateless processors that should be instantiated once. Creating new instances per `get()` call wastes memory and breaks shared state expectations.

## Decision

All resolved services are cached as singletons in the Container's `$instances` array. The factory runs once; subsequent `get()` calls return the cached instance. Non-singleton behavior requires explicit factory override.

## Consequences

- Performance: each service constructed at most once per request
- WordPress options/config must not be passed to constructors (cached forever) — use method-level `get_option()` calls instead
- Stateful services that need fresh instances require custom factory configuration
