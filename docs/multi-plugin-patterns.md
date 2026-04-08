# Multi-Plugin Patterns

## Within-plugin modularity

A complex plugin can organize its code into distinct modules, each with its own `src/` directory. All modules share the same container, so services can freely depend on classes from any module:

```php
class My_Plugin extends WPDI\Scope {
    protected function autowiring_paths(): array {
        return array(
            'modules/core/src',
            'modules/payments/src',
            'modules/notifications/src',
        );
    }

    protected function bootstrap( WPDI\Resolver $r ): void {
        $r->get( Core_Application::class )->run();
        $r->get( Payment_Hooks::class )->register();
        $r->get( Notification_Manager::class )->register();
    }
}
```

Each module's classes are auto-discovered and autowired. A payment service can depend on a core utility, and a notification service can depend on a payment event — all resolved automatically through constructor injection.

## Between-plugin service sharing

When Plugin A needs services from Plugin B, Plugin A adds Plugin B's source directory to its `autowiring_paths()` as an absolute path:

```php
class Plugin_A extends WPDI\Scope {
    protected function autowiring_paths(): array {
        return array(
            'src',                                        // Plugin A's own classes
            plugin_dir_path( PLUGIN_B_FILE ) . 'src',    // Plugin B's classes
        );
    }

    protected function bootstrap( WPDI\Resolver $r ): void {
        // Can resolve Plugin B's classes directly
        $gateway = $r->get( Plugin_B_Payment_Gateway::class );
        $r->get( Order_Service::class )->register( $gateway );
    }
}
```

This makes the dependency explicit at the composition root. Plugin A's container discovers Plugin B's classes alongside its own — fully type-safe, IDE-navigable, and checked at compile time (`wp di compile`).

### Requirements

**Plugin B must be installed.** Its files must exist on disk. It does not need to be active as a WordPress plugin.

**Plugin B's classes must be autoloadable.** When Plugin A's container resolves one of Plugin B's classes, PHP must be able to load it. This is handled automatically when:

- Plugin B is an active WordPress plugin (its plugin file runs and sets up autoloading)
- Plugin B is a Composer dependency of Plugin A (Composer's autoloader handles it)
- Both plugins share a common autoloader (e.g. via [MU-Plugin installation](mu-plugin-installation.md))

**Use absolute paths.** Relative paths are resolved against the plugin's own base directory. For cross-plugin paths, use `plugin_dir_path()` to get a stable absolute path. Absolute paths (starting with `/`) bypass the relative path normalization and `..` removal.

### Referencing Plugin B's path

Plugin B should define a constant pointing to its main file, so Plugin A can reference it without hardcoding paths:

```php
// plugin-b/plugin-b.php
define( 'PLUGIN_B_FILE', __FILE__ );
```

```php
// plugin-a/plugin-a.php
class Plugin_A extends WPDI\Scope {
    protected function autowiring_paths(): array {
        return array(
            'src',
            plugin_dir_path( PLUGIN_B_FILE ) . 'src',
        );
    }
}
```

If the constant is not yet defined when Plugin A boots (e.g. Plugin B loads later), guard the path:

```php
protected function autowiring_paths(): array {
    $paths = array( 'src' );

    if ( defined( 'PLUGIN_B_FILE' ) ) {
        $paths[] = plugin_dir_path( PLUGIN_B_FILE ) . 'src';
    }

    return $paths;
}
```

## Shared singletons

WPDI's singleton cache is shared across all containers on the same request. When Plugin A and Plugin B both resolve the same class, they get the same object instance. This means:

- No duplicate singletons — a service instantiated by Plugin B's container is reused by Plugin A's container
- Shared state — if a service holds state (e.g. registered hooks), both plugins see the same state
- Memory efficiency — each class is constructed at most once, regardless of how many plugins use it

This is the same singleton behavior described in the [API Reference](api-reference.md), extended across plugin boundaries.

## Boot order

When two plugins bind the same interface to different implementations, whichever plugin boots first determines the resolved instance. This is consistent with WordPress's general model where plugin load order matters.

If your plugins have conflicting interface bindings, resolve it at the architectural level:

- Use different interfaces for different purposes
- Have one plugin be the authoritative source for the binding
- Use [contextual bindings](configuration.md#contextual-bindings) to scope implementations by parameter name

## Anti-patterns

### Don't use filters to share services

```php
// Bad — no type contract, no certainty the service exists
add_filter( 'plugin_a/payment_gateway', fn() => $r->get( Gateway::class ) );
$gateway = apply_filters( 'plugin_a/payment_gateway', null );
```

There is no way to know at compile time whether the filter will return a valid object. The dependency is invisible to WPDI's inspection tools and cannot be validated by `wp di compile`.

Instead, add the source plugin's `src/` to your `autowiring_paths()` and depend on the class directly through constructor injection.

### Don't pass Resolver or Container across plugin boundaries

```php
// Bad — service locator pattern, defeats the composition root constraint
do_action( 'plugin_a/init', $resolver );
```

The `Resolver` is scoped to `bootstrap()` by design. Passing it to another plugin reintroduces the service locator pattern that WPDI is built to prevent. See [Application Flow](application-flow.md) for why the container reference is intentionally discarded after bootstrap.

### Don't duplicate class names across plugins

If Plugin A and Plugin B both define `class Payment_Service` (same fully-qualified name), PHP will fail with "Cannot redeclare class." Use unique namespaces per plugin to avoid collisions:

```php
namespace Plugin_A;
class Payment_Service { ... }

namespace Plugin_B;
class Payment_Service { ... }
```
