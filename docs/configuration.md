# Configuration

WPDI works with zero configuration for concrete classes. You only need `wpdi-config.php` for interface bindings.

## Basic Usage

```php
<?php
// wpdi-config.php
return array(
    Logger_Interface::class => fn( $r ) => new WP_Logger(),
    Cache_Interface::class  => fn( $r ) => new Redis_Cache(
        $r->get( Logger_Interface::class )
    ),
);
```

Factories receive a `Resolver` (`$r`) with `get()` and `has()` methods for resolving dependencies.

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
    Cache_Interface::class => fn( $r ) => new Redis_Cache(),
);
```

Any service depending on `Cache_Interface` receives `Redis_Cache`.

## WordPress Options

Services are **singletons** - factories run once, instances are cached.

### Wrong: Options in Constructor

```php
// wpdi-config.php
return array(
    My_Service::class => fn( $r ) => new My_Service(
        get_option( 'api_key', '' )  // Cached forever!
    ),
);
```

Option changes are ignored after first instantiation.

### Correct: Options in Methods

```php
class My_Service {
    public function get_api_key(): string {
        return get_option( 'api_key', '' );  // Fresh every call
    }
}
```

No configuration needed - autowiring handles it.

## Conditional Bindings

Use constants or environment checks (safe during bootstrap):

```php
return array(
    Email_Interface::class => fn( $r ) => defined( 'SMTP_ENABLED' )
        ? $r->get( SMTP_Mailer::class )
        : $r->get( WP_Mailer::class ),
);
```

**Avoid business logic** in factories - WordPress may not be fully initialized.

## Best Practices

### Do

```php
return array(
    Logger_Interface::class => fn( $r ) => new WP_Logger(),
    Cache_Interface::class  => fn( $r ) => new Redis_Cache(),
);
```

### Don't

```php
return array(
    'Bad' => new Bad(),                              // Not lazy
    Bad::class => fn( $r ) => new Bad(
        get_option( 'x' )                            // Options cached
    ),
    'bad' => fn( $r ) => get_option( 'x' ),          // Scalars not allowed
    'My_Logger' => fn( $r ) => new Bad(),            // Use ::class
);
```

## Summary

- Keep `wpdi-config.php` minimal - interface bindings only
- Factories receive `Resolver $r` for dependency resolution
- Fetch options in methods, not constructors
- Always use `::class` as service identifier
