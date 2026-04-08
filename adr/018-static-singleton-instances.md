# ADR-018: Static Singleton Instance Cache

**Status:** Accepted

## Context

When multiple WordPress plugins use WPDI on the same site, each `Scope` subclass creates its own `Container` instance during boot. Previously, each Container maintained a private `$instances` array, meaning two plugins resolving the same concrete class would get two separate objects. This wasted memory and prevented plugins from sharing service instances.

The motivating use case is cross-plugin service sharing: Plugin A adds Plugin B's `src/` directory to its `autowiring_paths()`, gaining access to Plugin B's classes. If Plugin B also boots its own Scope, both plugins should resolve the same class to the same object.

## Decision

`Container::$instances` is a static property shared across all Container instances in the same PHP request. The first container to resolve a class caches the instance; all subsequent containers reuse it.

All other Container properties remain per-instance:

- `$bindings` — each plugin has its own `wpdi-config.php`
- `$contextual_bindings` — per-plugin contextual interface bindings
- `$class_constructors` — per-plugin compiled cache metadata
- `$resolving` — circular dependency detection per resolution chain
- `$resolver` — per-container Resolver wrapper

### First-resolver-wins semantics

When Container A resolves a class and caches it in the static pool, Container B's `get()` finds it at step 1 (singleton cache check) and returns immediately — without consulting B's own bindings. If two plugins bind the same interface to different implementations, whichever plugin boots first determines the resolved instance. This is intentional: conflicting bindings for the same interface across plugins represent an architectural conflict, and boot-order resolution makes it visible rather than silently creating divergent instances.

### Test teardown

`Container::clear_instances()` is a public static method that resets the shared pool. `Scope::clear()` chains to it, ensuring test teardown via `TestScope::clear()` fully resets singleton state between test cases.

### Absolute autowiring paths

`Scope::normalize_paths()` now supports absolute paths (detected by a leading `/`). Relative paths are still resolved against the plugin's base directory with `..` removal for traversal prevention. Absolute paths pass through unchanged, enabling `plugin_dir_path( PLUGIN_B_FILE ) . 'src'` as an autowiring path.

## Consequences

- Multiple plugins using WPDI on the same site share object identity for the same class — no duplicate singletons.
- Boot order determines resolution for conflicting interface bindings. This is consistent with WordPress's hook priority model.
- `Scope::clear()` now clears the shared singleton pool in addition to the booted instance registry.
- Cross-plugin autowiring is explicit: Plugin A declares Plugin B's `src/` in `autowiring_paths()`. No filters, no service locators, no implicit sharing.
- Plugin B only needs to be installed (files on disk) with its classes autoloadable. It does not need to be active as a WordPress plugin if autoloading is handled via Composer.
