# ADR-017: No Primitive Injection

**Status:** Accepted

## Context

WPDI resolves dependencies by type. When a constructor declares `Cache_Interface $cache`, the container finds the registered implementation and injects it. The question is whether this mechanism should extend to primitive types — `string`, `int`, `bool`, `array` — allowing something like `string $base_url` to be satisfied by a named binding in `wpdi-config.php`.

## Decision

Primitive injection is not supported. The container resolves only typed class and interface dependencies. Primitives and other built-in PHP types must not flow through the DI layer directly.

## Consequences

This decision follows from five compounding problems with primitive injection:

**Error boundaries shift to runtime.** A missing typed wrapper class is detected when the container is compiled (`wp di compile`) or when the class is first resolved — early and loud. A missing primitive binding is only discovered when the specific class that needs it is instantiated, which may happen late or conditionally.

**Primitives require magic string keys.** Class names are self-describing, globally unique, and navigable. A primitive binding needs an arbitrary identifier (`'base_url'` or `'$base_url'`) — an opaque string with no type contract, no validation path, and no tooling support.

**IDE navigation is lost.** Holding Cmd and clicking a `::class` reference navigates to the class definition. A string key in a config file is a dead end — there is no "go to definition" for `'base_url'`.

**Autowiring primitives is fundamentally ambiguous.** The container cannot infer which `string` value a parameter named `$url` should receive. Every primitive binding requires explicit registration — the zero-config property is gone, and the config file grows unbounded.

**It opens the architecture to anti-patterns.** Once primitives are injectable, the config file becomes a general-purpose value store. Business logic, feature flags, and computed values follow. ADR-016 documents real-world evidence of this drift in a codebase that allowed factory closures.

### Practical implications

- Built-in types (`string`, `int`, `bool`, `array`, `callable`) in constructor signatures are unresolvable by the container. `Class_Inspector` marks them `builtin: true`; the `inspect` command renders them as red `builtin` leaf nodes to flag non-autowirable parameters.
- Runtime-dynamic values (WordPress options, API keys, database URLs) are wrapped in a typed class with a `value()` method and resolved by type. See ADR-016.
- Static (compile-time) values are expressed as class constants, not constructor parameters. See ADR-016.
- `wpdi-config.php` maps only interface names to concrete class names. No scalar values, no string identifiers. See ADR-013.
