# Configuration Guide

WPDI works with zero configuration for concrete classes, but you'll need to configure interfaces and WordPress-specific services.

## The wpdi-config.php File

Create a `wpdi-config.php` file in your plugin/theme root directory:

**Traditional syntax** (verbose)

```php
<?php
/**
 * WPDI Configuration
 *
 * This file defines interface bindings and WordPress-specific factories.
 * Concrete classes are auto-discovered and don't need configuration.
 */

return array(
    // Interface bindings
    'Logger_Interface' => static function() {
        return new WP_Logger();
    },

    // WordPress services (use get_option() internally for fresh values)
    'Sample_Config' => static function() {
        return new Sample_Config();
    },
);
```

**Modern arrow-function syntax** (supported in php 7.4)

```php
<?php
/**
 * WPDI Configuration
 *
 * This file defines interface bindings and WordPress-specific factories.
 * Concrete classes are auto-discovered and don't need configuration.
 */

return array(
    // Interface bindings
    'Logger_Interface' => static fn() => new WP_Logger(),

    // WordPress services (use get_option() internally for fresh values)
    'Sample_Config' => static fn() => new Sample_Config(),
);
```

## Design Philosophy: Keep Configuration Minimal

WPDI's configuration is **intentionally limited** to encourage clean architecture. Factory functions in `wpdi-config.php` receive **no arguments** - they cannot access the container or resolve dependencies.

### Why This Limitation Exists

**Prevents Service Locator Anti-Pattern:**
If factories had container access, developers would be tempted to resolve dependencies manually:

```php
// ❌ BAD: Service Locator anti-pattern (not possible with WPDI)
'Payment_Service' => function( $container ) {
    $gateway = $container->get( 'Payment_Gateway' );
    $logger = $container->get( 'Logger' );
    return new Payment_Service( $gateway, $logger );
}
```

**Encourages Constructor Injection:**
Instead, WPDI forces you to use proper dependency injection via constructors:

```php
// ✅ GOOD: Constructor injection (no config needed!)
class Payment_Service {
    public function __construct(
        Payment_Gateway $gateway,
        Logger $logger
    ) {
        $this->gateway = $gateway;
        $this->logger = $logger;
    }
}
```

WPDI automatically discovers `Payment_Service` and autowires its dependencies - **no configuration needed!**

### What Belongs in wpdi-config.php

The configuration file should **only contain interface bindings** - telling WPDI which concrete implementation to use for an interface.

**Keep it minimal** - if a class can be autowired, don't configure it! The vast majority of your services should use autowiring with zero configuration.

Ideally, treat any other conditional logic (environment-based selection, feature flags, etc.) as **business logic** and handle it in a ServiceProvider class, not on the DI level.

## Configuration Patterns

### Interface Bindings

When you have interfaces, you must tell WPDI which implementation to use:

```php
<?php
// Interface definition
interface Cache_Interface {
    public function get( string $key ): mixed;
    public function set( string $key, mixed $value, int $ttl = 3600 ): bool;
}

// Implementations
class WP_Object_Cache implements Cache_Interface {
    public function get( string $key ): mixed {
        return wp_cache_get( $key );
    }
    
    public function set( string $key, mixed $value, int $ttl = 3600 ): bool {
        return wp_cache_set( $key, $value, '', $ttl );
    }
}

class File_Cache implements Cache_Interface {
    // File-based cache implementation
}
```

```php
<?php
// wpdi-config.php
return array(
    'Cache_Interface' => function() {
        // Choose implementation based on environment
        if ( function_exists( 'wp_cache_get' ) && wp_using_ext_object_cache() ) {
            return new WP_Object_Cache();
        }
        
        return new File_Cache();
    },
);
```

### Environment-Based Selection (Reference Only)

**Note**: While WPDI supports this pattern, environment-based selection is business logic and should typically be handled in a ServiceProvider class rather than in the DI configuration. This example is shown for reference.

```php
<?php
return array(
    'API_Client_Interface' => function() {
        $environment = wp_get_environment_type();

        switch ( $environment ) {
            case 'production':
                return new Live_API_Client();

            case 'staging':
                return new Staging_API_Client();

            default:
                return new Mock_API_Client();
        }
    },
);
```

### WordPress Option Integration

**IMPORTANT**: Services are cached as singletons. This means the factory function runs **once**, and the same instance is returned for all future requests.

#### ❌ **NOT RECOMMENDED**: Resolve options during service creation

In most cases, this is too early to use `get_option()`, as option values should be resolved when they are actually needed by a method, and not during class construction.

```php
<?php
return array(
    // ❌ BAD: Options are cached at instantiation!
    'Plugin_Settings' => function() {
        return new Plugin_Settings(
            get_option( 'plugin_api_key', '' ),      // Called once, cached for the request
            get_option( 'plugin_timeout', 30 ),      // Option changes ignored
            get_option( 'plugin_debug_mode', false ) // Stale values!
        );
    },
);
```

**Why this is wrong**:
1. Factory runs once when first requested
2. `get_option()` executes and returns current values (e.g., `api_key = "abc123"`)
3. Instance is created and **cached as singleton**
4. Admin changes option to `"xyz789"`
5. The `Plugin_Settings` service keeps using the **original value** `"abc123"` until reloading the page again
6. Other plugins might not have the chance to add hooks for the option value

#### ✅ **RECOMMENDED**: WordPress Coding Standard Pattern

```php
<?php
// wpdi-config.php - just instantiate the service
return array(
    'Plugin_Settings' => function() {
        return new Plugin_Settings(); // No parameters!
    },
);

// Plugin_Settings.php - class handles option access internally
class Plugin_Settings {
    public function get_api_key(): string {
        return get_option( 'plugin_api_key', '' ); // Resolve option value when it's needed
    }

    public function get_timeout(): int {
        return (int) get_option( 'plugin_timeout', 30 );
    }

    public function is_debug_mode(): bool {
        return (bool) get_option( 'plugin_debug_mode', false );
    }
}
```

**Why this is correct**:
- Service instance is cached (good for performance)
- Each method call gets fresh option value via `get_option()`
- Option changes are reflected immediately
- Follows WordPress coding standards (classes encapsulate their option dependencies)
- Other plugins can use hooks to modify or observe the option access

### Conditional Implementation Selection (Reference Only)

**Note**: While WPDI supports this pattern, conditional selection based on options or feature flags is business logic and should typically be handled in a ServiceProvider class rather than in the DI configuration. These examples are shown for reference.

```php
<?php
return array(
    // Choose implementation based on WordPress options
    'Email_Service_Interface' => function() {
        $settings = get_option( 'email_settings', array() );

        // Select which class to instantiate (option values fetched inside the class)
        if ( ! empty( $settings['smtp_enabled'] ) ) {
            return new SMTP_Email_Service(); // Fetches SMTP settings internally
        }

        return new WP_Mail_Service();
    },

    // Choose implementation based on feature flags
    'Payment_Gateway_Interface' => function() {
        $gateway = get_option( 'active_payment_gateway', 'stripe' );

        switch ( $gateway ) {
            case 'paypal':
                return new PayPal_Gateway();
            case 'square':
                return new Square_Gateway();
            default:
                return new Stripe_Gateway();
        }
    },
);
```

**Important**: If you use `get_option()` in factories, use it only to **choose which class** to instantiate, not to pass configuration values. The selected class should fetch its own configuration internally.

## Configuration Best Practices

### ✅ Do This

```php
<?php
return array(
    // Interface binding - simple implementation selection
    'Cache_Interface' => function() {
        return new Redis_Cache();
    },

    // Interface binding - another implementation
    'Logger_Interface' => function() {
        return new WP_Logger();
    },

    // That's it! Keep wpdi-config.php minimal.
    // Concrete classes with dependencies? Use autowiring - no config needed!
);
```

### ❌ Don't Do This

```php
<?php
return array(
    // Don't instantiate outside factory function
    'Bad_Config' => new Bad_Config(), // ❌ Runs immediately, not lazy!

    // Don't pass options to WordPress services (they should handle internally)
    'Bad_Settings' => function() {
        return new Bad_Settings( get_option('setting') ); // ❌ Cached at instantiation!
    },

    // Don't manually create dependencies (use autowiring!)
    'Bad_Service' => function() {
        $dependency = new Some_Dependency(); // ❌ Bypasses container, not a singleton!
        return new Bad_Service( $dependency );
    },

    // Don't return scalars
    'bad_setting' => function() {
        return get_option( 'setting' ); // ❌ DI is for objects, not values!
    },

    // Don't use magic strings as keys
    'some_string_key' => function() { // ❌ Use class names!
        return new Service();
    },
);
```

**Remember**: If you need to inject dependencies into a service, use constructor injection and let autowiring handle it. Don't manually create dependencies in factories!

## Loading Configuration

WPDI automatically loads `wpdi-config.php` if it exists in your plugin/theme directory. No additional code needed!

## Next Steps

- [Testing](testing.md) - Unit testing configured services
- [API Reference](api-reference.md) - Container methods and options
