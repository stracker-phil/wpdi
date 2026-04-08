# Application Flow

## Scope is your composition root

`Scope` is the single entry point for a WPDI-powered plugin. Its `bootstrap()` method is the **composition root** — the one place where the dependency graph is assembled and entry-point services are resolved into action.

This is not just a convention. The `Resolver` passed to `bootstrap()` intentionally exposes only `get()` and `has()`. There is no `bind()`, no `clear()`, no way to extract the underlying container. Once `bootstrap()` returns, the container reference is gone.

Services do not hold a reference to the container. They receive their dependencies via constructor injection and call methods on those dependencies — never on the container. This structurally prevents the service locator pattern from taking hold.

### Keep bootstrap() flat and intentional

Every `$r->get(...)` call inside `bootstrap()` is a declaration: "I want this service to exist and run." Keep it flat:

```php
class My_Plugin extends WPDI\Scope {
    protected function bootstrap( WPDI\Resolver $r ): void {
        $r->get( HTTP_Router::class )->register_routes();
        $r->get( Admin_Menu::class )->register();
        $r->get( Cron_Scheduler::class )->schedule();
    }
}
```

If `bootstrap()` starts growing conditionals or configuration checks, those behaviors belong in the services themselves — not in the composition root. A well-designed `bootstrap()` reads as a flat list of what runs at plugin load time, nothing more.

## Boot sequence

Calling `My_Plugin::boot( __FILE__ )` runs the following steps:

**1. Duplicate guard.** If `My_Plugin` has already booted, `boot()` returns immediately. A second call — from a mu-plugin loader and a regular plugin, for instance — is a silent no-op.

**2. Collaborator setup.** `Scope::__construct()` creates a `Container`, `Cache_Store`, `Class_Inspector`, and `Auto_Discovery` instance. These collaborators are wired together and exist only during boot.

**3. Config loading.** If `wpdi-config.php` exists in the plugin root, it is loaded and its interface bindings are captured.

**4. Cache resolution.** `Cache_Manager::get_cache()` runs one of three paths depending on cache state:

| Cache state   | What happens                                                                                                                                                             |
|---------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| No cache file | Full rebuild: scans all autowiring paths, reflects every constructor, writes `cache/wpdi-container.php`. Happens once, on the first request after install or deployment. |
| Stale entries | Incremental update: only files whose `mtime` has changed are re-reflected. New transitive dependencies are discovered automatically.                                     |
| Fresh cache   | No filesystem scanning, no reflection. Just reads the cache file.                                                                                                        |

**5. Container initialization.** `Container::load_compiled()` loads class metadata from the cache. `load_config()` loads the interface bindings.

**6. bootstrap().** Your `bootstrap()` method runs. Resolve entry-point services here and wire them to WordPress hooks. The `Resolver` is a thin wrapper — only `get()` and `has()` are available.

**7. Container released.** After `bootstrap()` returns, the container is not stored as a reachable property. The booted `Scope` instance is held internally to prevent GC; services hold references only to other services.

## Development vs production

WPDI has two distinct operational phases: build time and boot time.

### Build time

`wp di compile` performs a full, clean rebuild for production:

```shell
wp di compile
```

This discovers all classes, reflects all constructors, and writes a complete `cache/wpdi-container.php`. The result is a deployable artifact: once compiled, the cache file alone is sufficient for the container to initialize. No source scanning, no reflection, no `wpdi-config.php` required at runtime.

`compile` always overwrites an existing cache. There is no `--force` flag.

### Boot time

At runtime, `Cache_Manager` determines the behavior based on the environment:

| Environment   | Staleness checks        | Reflection             |
|---------------|-------------------------|------------------------|
| `production`  | None                    | None — cache only      |
| `development` | `mtime` per cached file | Only for changed files |
| No cache      | (triggers full rebuild) | All classes            |

The environment comes from `Scope::environment()`, which calls `wp_get_environment_type()`. Override it to force a specific mode:

```php
class My_Plugin extends WPDI\Scope {
    protected function environment(): string {
        return 'development';  // Always check for stale files
    }
}
```

> In production, pre-compile the cache during deployment (`wp di compile`) to avoid the full rebuild on the first request after install. See [WP-CLI Commands](wp-cli.md) for usage.

## Failure flow

WPDI fails loudly and early:

**Missing class.** If a dependency is declared in a constructor but the class cannot be found, `Not_Found_Exception` is thrown at resolution time.

**Circular dependency.** Detected during resolution and immediately throws `Circular_Dependency_Exception`. The cycle is not silently broken.

**Invalid config.** Values in `wpdi-config.php` must be class name strings. Closures, instances, or other types cause `wp di compile` to abort with an error message. At runtime, they throw `Container_Exception` when processed.

**No cache in production.** If `cache/wpdi-container.php` is absent on a production site, `Cache_Manager` performs a full rebuild on the first request. The container ends up fully functional, but that first request carries the cost of discovery and reflection. Pre-compile during deployment to avoid this.

## What this prevents

The constraints in this design each close a common failure mode:

| Constraint                                         | What it prevents                                                                   |
|----------------------------------------------------|------------------------------------------------------------------------------------|
| `Resolver` exposes no `bind()`                     | Services cannot register new bindings at runtime                                   |
| Container not stored as a property                 | Services cannot call `$this->container->get()` — the service locator pattern       |
| `boot()` idempotent                                | Two plugins bundling WPDI cannot create two containers for the same scope          |
| `bootstrap()` receives `Resolver`, not `Container` | Even `bootstrap()` cannot mutate the container after it is built                   |
| Cache is the authoritative definition              | What `compile` builds is exactly what runs — no divergence between CLI and runtime |
