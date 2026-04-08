# ADR-004: Zero-Configuration Autowiring

**Status:** Accepted

## Context

Most WordPress DI setups require verbose configuration. For concrete classes with typed constructor parameters, the container can resolve dependencies automatically via reflection.

## Decision

`Auto_Discovery` scans configurable paths (default `src/`) for concrete classes using PHP tokenization. The `Container` autowires them via `ReflectionClass` — no manual registration needed. Explicit interface bindings in `wpdi-config.php` override autowiring — each interface maps to a concrete class name string (see ADR-013).

## Consequences

- Plugin authors add classes to `src/` and they "just work"
- Reflection overhead mitigated by metadata caching (`Cache_Store`)
- Only concrete, instantiable classes are discovered (traits, interfaces, abstracts excluded)
- Interface bindings still require explicit configuration
