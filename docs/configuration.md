# Configuration

WPDI works with zero configuration for concrete classes in `src/`. You only need `wpdi-config.php` for interface bindings or classes outside autowiring paths (e.g., Composer packages).

## Autowiring Paths

By default, WPDI auto-discovers classes from `src/`. Override `autowiring_paths()` to customize:

```php
class My_Plugin extends WPDI\Scope {
    protected function autowiring_paths(): array {
        return array( 'src' );  // Default
    }
}
```

### Multi-Module Structure

```php
class My_Plugin extends WPDI\Scope {
    protected function autowiring_paths(): array {
        return array(
            'modules/core/src',
            'modules/admin/src',
            'modules/api/src'
        );
    }
}
```

### Conditional Paths

```php
class My_Plugin extends WPDI\Scope {
    protected function autowiring_paths(): array {
        $paths = array( 'src' );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $paths[] = 'debug-tools/src';
        }

        return $paths;
    }
}
```

### No Auto-Discovery

```php
class My_Plugin extends WPDI\Scope {
    protected function autowiring_paths(): array {
        return array();  // Manual bindings only (wpdi-config.php)
    }
}
```

### Cross-Plugin Paths

Add another plugin's source directory as an absolute path to share its services:

```php
class My_Plugin extends WPDI\Scope {
    protected function autowiring_paths(): array {
        return array(
            'src',
            plugin_dir_path( PLUGIN_B_FILE ) . 'src',
        );
    }
}
```

Absolute paths (starting with `/`) pass through unchanged. Plugin B must be installed and its classes autoloadable. See [Multi-Plugin Patterns](multi-plugin-patterns.md) for the full guide.

## Manual Bindings (wpdi-config.php)

Use `wpdi-config.php` for interface bindings and external classes.

```php
<?php
// wpdi-config.php
return array(
    Logger_Interface::class => WP_Logger::class,
    Cache_Interface::class  => Redis_Cache::class,
);
```

Map each interface to the concrete class that should be injected. Dependencies of the concrete class are resolved automatically via autowiring.

## Interface Bindings

```php
<?php
interface Cache_Interface {
    public function get( string $key );
    public function set( string $key, $value ): bool;
}

class Redis_Cache implements Cache_Interface {
    // Implementation
}

// wpdi-config.php
return array(
    Cache_Interface::class => Redis_Cache::class,
);
```

Any service depending on `Cache_Interface` receives `Redis_Cache`.

## Contextual Bindings

When multiple services need different implementations of the same interface, use contextual bindings. Instead of a single class name, provide an array of class names keyed by the constructor parameter name (prefixed with `$`):

```php
<?php
// wpdi-config.php
return array(
    Cache_Interface::class => array(
        '$db_cache'   => Redis_Cache::class,
        '$file_cache' => File_Cache::class,
        'default'     => Redis_Cache::class,
    ),
);
```

Each parameter name in the consuming class determines which implementation is used:

```php
class Report_Service {
    public function __construct(
        Cache_Interface $db_cache,    // Gets Redis_Cache (matches '$db_cache')
        Cache_Interface $file_cache   // Gets File_Cache (matches '$file_cache')
    ) {}
}

class Notification_Service {
    public function __construct(
        Cache_Interface $cache        // Gets Redis_Cache (no match, uses default '')
    ) {}
}
```

### Rules

- **Keys must start with `$`** to make it clear they refer to variable names, e.g. `'$db_cache'`
- **Default key is `'default'`** — used when no parameter name matches
- **No default + no match = error** — if no `'default'` key is defined and the parameter name doesn't match any key, a `Container_Exception` is thrown
- **Each branch is a singleton** — instances are cached separately per branch, so `$db_cache` and `$file_cache` produce different singleton instances
- **Calling `$container->get()` directly** on a contextual interface uses the `'default'` branch (or throws if none defined)

## WordPress Options

Services are **singletons** — the concrete class is instantiated once and cached.

### Correct: Options in Methods

```php
class My_Service {
    public function get_api_key(): string {
        return get_option( 'api_key', '' );  // Fresh every call
    }
}
```

No configuration needed — autowiring handles it.

### Wrong: Options in Constructor

```php
class My_Service {
    public function __construct( string $api_key ) {}
}
```

Constructor arguments are resolved at first instantiation and cached. Option changes are never picked up.

## Conditional Bindings

`wpdi-config.php` is a static map — no runtime logic. For environment-dependent bindings, create a `ServiceProvider` class and resolve it from `bootstrap()`:

```php
class My_Plugin extends WPDI\Scope {
    protected function bootstrap( WPDI\Resolver $r ): void {
        $r->get( Service_Provider::class )->register();
    }
}
```

## Best Practices

### Do

```php
return array(
    Logger_Interface::class => WP_Logger::class,
    Cache_Interface::class  => Redis_Cache::class,
);
```

### Don't

```php
return array(
    'Bad'                   => Bad::class,    // String literals not allowed - use ::class
    Logger_Interface::class => new Bad(),     // Instances not allowed - map to class name
);
```

## Summary

- Keep `wpdi-config.php` minimal — interface bindings and external classes only
- Map interface names to concrete class names using `Interface::class => Concrete::class`
- Fetch options in methods, not constructors
- Always use `::class` as service identifier
