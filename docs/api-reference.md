# API Reference

## WPDI\Container

PSR-11 compliant dependency injection container.

### bind(string $abstract, ?callable $factory = null, bool $singleton = true): void

Register a service.

```php
// Auto-wire concrete class
$container->bind( Payment_Processor::class );

// Interface with factory (direct API — prefer wpdi-config.php for interface bindings)
$container->bind( Logger_Interface::class, fn() => new WP_Logger() );

// Non-singleton
$container->bind( Temp_Service::class, null, false );
```

### get(string $id): mixed

Retrieve a service (PSR-11).

```php
$processor = $container->get( Payment_Processor::class );
```

**Throws:** `Not_Found_Exception`, `Container_Exception`

### has(string $id): bool

Check if service exists (PSR-11).

```php
if ( $container->has( Optional_Service::class ) ) {
    $service = $container->get( Optional_Service::class );
}
```

### load_compiled(array $cache): void

Load compiled cache — registers interface bindings. Concrete classes are autowired on demand when first requested via `get()`.

```php
$container = new WPDI\Container();
$container->load_compiled( $cache );
```

> **Note:** You rarely call this directly. `Scope::boot()` handles container initialization automatically, including cache management and config loading.

### bind_contextual(string $abstract, array $bindings): void

Register a contextual binding with multiple class names keyed by parameter name.

```php
$container->bind_contextual( Cache_Interface::class, array(
    '$db_cache'   => Redis_Cache::class,
    '$file_cache' => File_Cache::class,
    'default'     => Redis_Cache::class,
) );
```

**Throws:** `Container_Exception` (invalid class/interface, invalid key format, invalid class name)

### load_config(array $config): void

Load service bindings from array. Accepts both simple class name bindings and contextual binding arrays.

```php
$container->load_config( array(
    Logger_Interface::class => WP_Logger::class,
    Cache_Interface::class  => array(
        '$db_cache' => Redis_Cache::class,
        'default'   => File_Cache::class,
    ),
) );
```

### resolver(): Resolver

Get cached Resolver instance with limited API.

```php
$resolver = $container->resolver();
$service = $resolver->get( My_Service::class );
```

### get_registered(): array

Get all registered service names (debugging).

### clear(): void

Clear all bindings and instances (testing).

---

## WPDI\Scope

Base class for WordPress modules.

### boot(string $scope_file): void (static)

Entry point — creates the container and calls `bootstrap()`. Idempotent: subsequent calls for the same class are silently ignored, preventing accidental duplicate containers.

```php
class My_Plugin extends WPDI\Scope {
    protected function bootstrap( WPDI\Resolver $r ): void {
        $app = $r->get( My_Application::class );
        $app->run();
    }
}

My_Plugin::boot( __FILE__ );
```

### clear(): void (static)

Removes the stored instance for this class, allowing a fresh `boot()` call. Intended for test teardown only.

```php
protected function tearDown(): void {
    My_Plugin::clear();
}
```

### bootstrap(Resolver $resolver): void (abstract)

Composition root - implement in your module. Called automatically by `boot()`.

---

## WPDI\Resolver

Limited API wrapper for service resolution. Used by `Scope::bootstrap()`.

### get(string $id): mixed

Get service by class or interface name.

### has(string $id): bool

Check if service exists.

---

## WPDI\Commands\Cli

Standalone WP-CLI command registration.

### register_commands(): void (static)

Register all `wp di` WP-CLI commands without instantiating a `Scope` or `Container`. Safe to call multiple times and a no-op when WP-CLI is not active.

```php
WPDI\Commands\Cli::register_commands();
```

When extending `Scope`, this is called automatically.

---

## Exceptions

### WPDI_Exception

Base exception for all WPDI errors.

### Container_Exception

Container errors (PSR-11 `ContainerExceptionInterface`).

**Causes:** Invalid class name, circular dependencies, reflection errors.

### Not_Found_Exception

Service not found (PSR-11 `NotFoundExceptionInterface`).

**Causes:** Service not registered, class not in `src/`, not auto-discoverable.

### Circular_Dependency_Exception

Circular constructor dependencies detected.

**Example:** `ServiceA -> ServiceB -> ServiceA`

---

## Configuration File

### wpdi-config.php

Optional configuration in module root. Maps interface names to concrete class names. Supports both simple bindings and contextual bindings (array keyed by `$param_name`).

```php
<?php
return array(
    // Simple binding: one implementation for all consumers
    Logger_Interface::class => WP_Logger::class,

    // Contextual binding: different implementations based on parameter name
    Cache_Interface::class  => array(
        '$db_cache'   => Redis_Cache::class,
        '$file_cache' => File_Cache::class,
        'default'     => Redis_Cache::class,
    ),
);
```

---

## Auto-Discovery

WPDI discovers classes that are:

- In `src/` directory (recursive)
- Concrete (not abstract, interface, or trait)
- Instantiable (public or no constructor)
- File name matches class name (PSR-4)

```
✅ My_Service.php         → class My_Service
✅ Payment_Processor.php  → class Payment_Processor
❌ class-my-service.php   → not discovered
```

---

## Performance

### Cache

- **Location:** `{module}/cache/wpdi-container.php`
- **Contains:** Class → metadata mapping (path, mtime, dependencies)
- **Staleness:** Each file's mtime checked in non-production environments
- **Incremental:** Only modified files re-parsed, new dependencies discovered transitively

### Singletons

Services are cached per request by default. Non-singletons created fresh each time via `bind($class, null, false)`.
