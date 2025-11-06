# API Reference

Complete reference for WPDI classes and methods.

## WPDI\Container

The main dependency injection container implementing PSR-11.

### Methods

#### bind( string $abstract, callable $factory = null, bool $singleton = true ): void

Register a service with the container.

**Parameters:**

- `$abstract` - Class or interface name (must exist)
- `$factory` - Optional factory function (defaults to autowiring)
- `$singleton` - Whether to cache the instance (default: true)

**Examples:**

```php
// Auto-wire concrete class
$container->bind( 'Payment_Processor' );

// Interface with factory
$container->bind( 'Logger_Interface', function() {
    return new WP_Logger();
} );

// Non-singleton (new instance each time)
$container->bind( 'Temp_Service', null, false );
```

#### get( string $id ): mixed

Retrieve a service from the container (PSR-11).

**Parameters:**

- `$id` - Class or interface name

**Returns:** Service instance

**Throws:**

- `WPDI\Exceptions\Not_Found_Exception` - Service not found
- `WPDI\Exceptions\Container_Exception` - Resolution error

**Examples:**

```php
$processor = $container->get( 'Payment_Processor' );
$logger = $container->get( 'Logger_Interface' );
```

#### has( string $id ): bool

Check if container can provide a service (PSR-11).

**Parameters:**

- `$id` - Class or interface name

**Returns:** `true` if service available, `false` otherwise

**Examples:**

```php
if ( $container->has( 'Optional_Service' ) ) {
    $service = $container->get( 'Optional_Service' );
}
```

#### load_config( array $config ): void

Load service bindings from configuration array.

**Parameters:**

- `$config` - Array of service bindings

**Examples:**

```php
$config = array(
    'Logger_Interface' => function() { return new WP_Logger(); }
);
$container->load_config( $config );
```

#### initialize( string $base_path ): void

Initialize container with auto-discovery and caching.

**Parameters:**

- `$base_path` - Path to module directory (contains `src/` and optional `wpdi-config.php`)

**Examples:**

```php
$container = new WPDI\Container();
$container->initialize( __DIR__ );
```

#### get_registered(): array

Get list of all registered service names (for debugging).

**Returns:** Array of service names

#### clear(): void

Clear all bindings and cached instances (for testing).

## WPDI\Scope

Base class for WordPress modules using WPDI.

### Methods

#### __construct()

Initializes container and calls `bootstrap()`.

#### bootstrap(): void (abstract)

Composition root method - implement this in your module.

**Examples:**

```php
class My_Plugin extends WPDI\Scope {
    protected function bootstrap(): void {
        $app = $this->get( 'My_Application' );
        $app->run();
    }
}
```

#### get( string $class ): mixed (protected)

Get service from container. Only available within the Scope class.

**Parameters:**

- `$class` - Class or interface name

**Returns:** Service instance

#### has( string $class ): bool (protected)

Check if service exists. Only available within the Scope class.

**Parameters:**

- `$class` - Class or interface name

**Returns:** `true` if service available

#### get_base_path(): string (protected)

Get the base path for auto-discovery (directory containing the Scope class).

**Returns:** Directory path

## Exception Classes

### WPDI\Exceptions\Container_Exception

Thrown when container encounters an error (implements PSR-11 `ContainerExceptionInterface`).

**Common causes:**

- Invalid factory function
- Circular dependencies
- Invalid class/interface name
- Reflection errors

### WPDI\Exceptions\Not_Found_Exception

Thrown when requested service cannot be found (implements PSR-11 `NotFoundExceptionInterface`).

**Common causes:**

- Service not registered and not auto-discoverable
- Typo in class name
- Class file not in `src/` directory

## Configuration File Structure

### wpdi-config.php

Optional configuration file in your module root directory.

**Structure:**

```php
<?php
return array(
    'Service_Name' => factory_function,
    'Interface_Name' => factory_function,
    // ... more bindings
);
```

**Factory Function Signature:**

```php
function(): object {
    // Return service instance
    return new Service();
}
```

**Examples:**

```php
<?php
return array(
    // Simple factory
    'Logger_Interface' => function() {
        return new WP_Logger();
    },
    
    // Factory with dependencies
    'Complex_Service' => function() {
        $dependency = new Dependency();
        return new Complex_Service( $dependency );
    },
    
    // WordPress integration
    'Settings_Service' => function() {
        return new Settings_Service(
            get_option( 'my_setting', 'default' )
        );
    },
);
```

## Auto-Discovery Rules

### Discovered Classes

WPDI automatically discovers classes that are:

- ✅ In `src/` directory (recursive)
- ✅ Concrete classes (not abstract, not interfaces)
- ✅ Instantiable (public constructor or no constructor)
- ✅ Proper PHP class syntax

### File Naming

WPDI uses PSR-4 file naming conventions:

- ✅ `My_Service.php` - Correct (matches class name)
- ✅ `Payment_Processor.php` - Correct (matches class name)
- ❌ `class-my-service.php` - Not discovered (old WordPress style)
- ❌ `my-service.php` - Not discovered

Files must be named exactly as the class name they contain (case-sensitive on most systems).

### Class Naming

Class names should use WordPress conventions with underscores:

- ✅ `My_Service` - Correct (WordPress style with underscores)
- ✅ `Payment_Processor` - Correct (WordPress style with underscores)
- ⚠️ `MyService` - Works but not WordPress style (PascalCase without underscores)

## Performance Considerations

### Cache Files

WPDI generates cache files for production:

- **Location:** `{module}/cache/wpdi-container.php`
- **When:** Automatically in production environment
- **Contains:** Pre-compiled service definitions

### Memory Usage

- **Development:** Classes loaded on-demand via reflection
- **Production:** Pre-compiled bindings, minimal memory overhead
- **Container:** Singletons cached, non-singletons created fresh

### Best Practices

```php
// ✅ Good - lightweight factory
'Simple_Service' => function() {
    return new Simple_Service();
}

// ❌ Avoid - heavy computation in factory
'Heavy_Service' => function() {
    $expensive_data = expensive_computation(); // Runs on every request
    return new Heavy_Service( $expensive_data );
}

// ✅ Better - lazy computation
'Heavy_Service' => function() {
    return new Heavy_Service(); // Let the service handle expensive ops
}
```