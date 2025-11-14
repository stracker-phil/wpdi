# API Reference

Complete reference for WPDI classes and methods.

## WPDI\Container

Main dependency injection container (PSR-11 compliant).

### bind(string $abstract, ?callable $factory = null, bool $singleton = true): void

Register a service.

```php
// Auto-wire concrete class
$container->bind(Payment_Processor::class);

// Interface with factory
$container->bind(Logger_Interface::class, fn() => new WP_Logger());

// Non-singleton (new instance each time)
$container->bind(Temp_Service::class, null, false);
```

**Parameters:**

- `$abstract` - Class or interface name
- `$factory` - Optional factory function (defaults to autowiring)
- `$singleton` - Cache instance (default: true)

### get(string $id): mixed

Retrieve a service (PSR-11).

```php
$processor = $container->get(Payment_Processor::class);
```

**Throws:**

- `Not_Found_Exception` - Service not found
- `Container_Exception` - Resolution error

### has(string $id): bool

Check if container can provide a service (PSR-11).

```php
if ($container->has(Optional_Service::class)) {
    $service = $container->get(Optional_Service::class);
}
```

### load_config(array $config): void

Load service bindings from array.

```php
$container->load_config(array(
    Logger_Interface::class => fn() => new WP_Logger(),
));
```

### initialize(string $base_path): void

Initialize with auto-discovery and caching.

```php
$container = new WPDI\Container();
$container->initialize(__DIR__);
```

### get_registered(): array

Get all registered service names (for debugging).

### clear(): void

Clear all bindings and instances (for testing).

## WPDI\Scope

Base class for WordPress modules.

### bootstrap(): void (abstract)

Composition root - implement in your module.

```php
class My_Plugin extends WPDI\Scope {
    protected function bootstrap(): void {
        $app = $this->get(My_Application::class);
        $app->run();
    }
}
```

### get(string $class): mixed (protected)

Get service from container. Only available within Scope.

### has(string $class): bool (protected)

Check if service exists. Only available within Scope.

### get_base_path(): string (protected)

Get base path for auto-discovery.

## Exceptions

### WPDI_Exception

Base exception for all WPDI errors.

### Container_Exception

Container errors (PSR-11 `ContainerExceptionInterface`).

**Common causes:**

- Invalid factory function
- Circular dependencies
- Reflection errors

### Not_Found_Exception

Service not found (PSR-11 `NotFoundExceptionInterface`).

**Common causes:**

- Service not registered or auto-discoverable
- Class file not in `src/` directory

### Circular_Dependency_Exception

Circular constructor dependencies detected.

**Example:** `ServiceA -> ServiceB -> ServiceA`

## Configuration File

### wpdi-config.php

Optional configuration in module root.

**Structure:**

```php
<?php
return array(
    Interface_Name::class => fn() => new Implementation(),
);
```

**Factory signature:** `function(): object`

**Examples:**

```php
return array(
    // Interface binding
    Logger_Interface::class => fn() => new WP_Logger(),

    // Another binding
    Cache_Interface::class => fn() => new Redis_Cache(),

    // That's it - keep it minimal!
);
```

**Note:** Factories receive **no arguments** (no container access). For dependencies, use constructor injection and autowiring.

## Auto-Discovery

WPDI automatically discovers classes that are:

- ✅ In `src/` directory (recursive)
- ✅ Concrete classes (not abstract, interfaces, or traits)
- ✅ Instantiable (public or no constructor)
- ✅ Proper PHP class syntax

### File Naming (PSR-4)

```
✅ My_Service.php         (matches class name)
✅ Payment_Processor.php  (matches class name)
❌ class-my-service.php   (old WP style - not discovered)
❌ my-service.php         (doesn't match class name)
```

Files must match class names exactly (case-sensitive on most systems).

### Class Naming

Use WordPress conventions with underscores:

```php
✅ class My_Service {}         // WordPress style
✅ class Payment_Processor {}  // WordPress style
⚠️ class MyService {}          // Works but not WordPress style
```

## Performance

### Cache Files

- **Location:** `{module}/cache/wpdi-container.php`
- **When:** Automatically in production
- **Contains:** Pre-compiled class names

### Memory

- **Development:** Classes loaded on-demand via reflection
- **Production:** Pre-compiled bindings, minimal overhead
- **Singletons:** Cached per request
- **Non-singletons:** Created fresh each time

### Best Practices

```php
// ✅ Lightweight factory
Simple_Service::class => fn() => new Simple_Service(),

// ❌ Avoid expensive operations
Heavy_Service::class => function() {
    $data = expensive_api_call();  // Runs every request!
    return new Heavy_Service($data);
},

// ✅ Better - lazy computation
Heavy_Service::class => fn() => new Heavy_Service(),  // Service handles expensive ops
```
