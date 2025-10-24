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
    
    // WordPress option-based configuration
    'Sample_Config' => static function() {
        return new Sample_Config(
            get_option( 'sample_plugin_enabled', true ),
            get_option( 'sample_plugin_version', '1.0.0' )
        );
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
    
    // WordPress option-based configuration
    'Sample_Config' => static fn() => new Sample_Config(
        get_option( 'sample_plugin_enabled', true ),
        get_option( 'sample_plugin_version', '1.0.0' )
    ),
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

```php
<?php
return array(
    // Always fresh values from WordPress options
    'Plugin_Settings' => function() {
        return new Plugin_Settings(
            get_option( 'plugin_api_key', '' ),
            get_option( 'plugin_timeout', 30 ),
            get_option( 'plugin_retry_attempts', 3 ),
            get_option( 'plugin_debug_mode', false )
        );
    },
    
    // User-specific configuration
    'User_Preferences' => function() {
        $user_id = get_current_user_id();
        
        return new User_Preferences(
            get_user_meta( $user_id, 'plugin_theme', true ) ?: 'default',
            get_user_meta( $user_id, 'plugin_notifications', true ) ?: true
        );
    },
);
```

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
    // Use get_option() for fresh values
    'My_Config' => function() {
        return new My_Config( get_option( 'my_setting' ) );
    },
    
    // Environment-specific logic
    'My_Service' => function() {
        return WP_DEBUG ? new Debug_Service() : new Production_Service();
    },
    
    // Clear factory logic
    'Complex_Service' => function() {
        $dependency_a = new Dependency_A();
        $dependency_b = new Dependency_B( get_option( 'setting' ) );
        
        return new Complex_Service( $dependency_a, $dependency_b );
    },
);
```

### ❌ Don't Do This

```php
<?php
return array(
    // Don't cache option values
    'Bad_Config' => new Bad_Config( get_option( 'setting' ) ), // ❌ Cached!
    
    // Don't return scalars
    'bad_setting' => get_option( 'setting' ), // ❌ Scalar value!
    
    // Don't use magic strings
    'some_string_key' => function() { // ❌ Magic string!
        return new Service();
    },
);
```

## Loading Configuration

WPDI automatically loads `wpdi-config.php` if it exists in your plugin/theme directory. No additional code needed!

## Next Steps

- [Testing](testing.md) - Unit testing configured services
- [API Reference](api-reference.md) - Container methods and options
