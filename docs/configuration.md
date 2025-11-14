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

### Environment-Based Configuration

```php
<?php
return array(
    'API_Client_Interface' => function() {
        $environment = wp_get_environment_type();
        
        switch ( $environment ) {
            case 'production':
                return new Live_API_Client( get_option( 'api_live_key' ) );
                
            case 'staging':
                return new Staging_API_Client( get_option( 'api_staging_key' ) );
                
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

### Complex Factory Logic

```php
<?php
return array(
    'Payment_Gateway_Factory' => function() {
        return new Payment_Gateway_Factory(
            array(
                'paypal'  => PayPal_Gateway::class,
                'stripe'  => Stripe_Gateway::class,
                'square'  => Square_Gateway::class,
            )
        );
    },
    
    'Email_Service' => function() {
        $settings = get_option( 'email_settings', array() );
        
        if ( ! empty( $settings['smtp_host'] ) ) {
            return new SMTP_Email_Service(
                $settings['smtp_host'],
                $settings['smtp_port'],
                $settings['smtp_username'],
                $settings['smtp_password']
            );
        }
        
        return new WP_Mail_Service();
    },
);
```

## Configuration Best Practices

### ✅ Do This

```php
<?php
return array(
    // WordPress services handle get_option() internally
    'My_Config' => function() {
        return new My_Config(); // Class calls get_option() in methods
    },

    // Environment-specific logic in factory
    'My_Service' => function() {
        return WP_DEBUG ? new Debug_Service() : new Production_Service();
    },

    // Interface bindings can use constructor parameters for config
    'API_Client_Interface' => function() {
        // Choose one of two implementations
        $is_live = defined('PLUGIN_LIVE_MODE') && PLUGIN_LIVE_MODE;
        return $is_live ? new Live_API_Client() : new Sandbox_API_Client();
    },
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

## Loading Configuration

WPDI automatically loads `wpdi-config.php` if it exists in your plugin/theme directory. No additional code needed!

## Next Steps

- [Testing](testing.md) - Unit testing configured services
- [API Reference](api-reference.md) - Container methods and options
