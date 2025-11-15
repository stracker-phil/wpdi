# API Reference

## WPDI\Container

PSR-11 compliant dependency injection container.

### bind(string $abstract, ?callable $factory = null, bool $singleton = true): void

Register a service.

```php
// Auto-wire concrete class
$container->bind( Payment_Processor::class );

// Interface with factory
$container->bind( Logger_Interface::class, fn( $r ) => new WP_Logger() );

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

### initialize(string $scope_file): void

Initialize with auto-discovery and caching.

```php
$container = new WPDI\Container();
$container->initialize( __FILE__ );
```

### load_config(array $config): void

Load service bindings from array.

```php
$container->load_config( array(
    Logger_Interface::class => fn( $r ) => new WP_Logger(),
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

### bootstrap(Resolver $resolver): void (abstract)

Composition root - implement in your module.

```php
class My_Plugin extends WPDI\Scope {
    protected function bootstrap( WPDI\Resolver $r ): void {
        $app = $r->get( My_Application::class );
        $app->run();
    }
}

new My_Plugin( __FILE__ );
```

---

## WPDI\Resolver

Limited API wrapper for service resolution. Used by factory functions and `Scope::bootstrap()`.

### get(string $id): mixed

Get service by class or interface name.

### has(string $id): bool

Check if service exists.

---

## Exceptions

### WPDI_Exception

Base exception for all WPDI errors.

### Container_Exception

Container errors (PSR-11 `ContainerExceptionInterface`).

**Causes:** Invalid factory, circular dependencies, reflection errors.

### Not_Found_Exception

Service not found (PSR-11 `NotFoundExceptionInterface`).

**Causes:** Service not registered, class not in `src/`, not auto-discoverable.

### Circular_Dependency_Exception

Circular constructor dependencies detected.

**Example:** `ServiceA -> ServiceB -> ServiceA`

---

## Configuration File

### wpdi-config.php

Optional configuration in module root. Factories receive a `Resolver` for dependency resolution.

```php
<?php
return array(
    Logger_Interface::class => fn( $r ) => new WP_Logger(),
    Cache_Interface::class  => fn( $r ) => new Redis_Cache(
        $r->get( Logger_Interface::class )
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
