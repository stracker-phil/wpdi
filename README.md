# WPDI - WordPress Dependency Injection

A lightweight, WordPress-native dependency injection container that follows WordPress coding standards.

## Features

- ✅ **Drop-in Ready** - No Composer required, works immediately
- ✅ **Auto-Discovery** - Zero configuration for concrete classes
- ✅ **WordPress Native** - Follows WordPress coding standards and patterns
- ✅ **Type Safe** - Full PHP type hint support with clear error messages
- ✅ **Performance Optimized** - Automatic compilation for production
- ✅ **PSR-11 Compatible** - Standard container interface

## Quick Start

### 1. Installation

```php
// Download WPDI and include in your plugin/theme
require_once __DIR__ . '/wpdi/init.php';
```

### 2. Create Your Plugin/Module

```php
<?php
/**
 * Plugin Name: My Payment Plugin
 */

class My_Payment_Plugin extends WPDI\Scope {
    protected function bootstrap(): void {
        $app = $this->get( 'Payment_Application' );
        $app->run();
    }
}

new My_Payment_Plugin( __FILE__ );
```

### 3. Write Your Classes

```php
<?php
// src/Payment_Processor.php

class Payment_Processor {
    public function __construct( 
        Payment_Validator $validator,
        Payment_Config $config 
    ) {
        $this->validator = $validator;
        $this->config = $config;
    }
    
    public function process_payment( WC_Order $order ): bool {
        if ( $this->validator->validate( $order ) ) {
            // Process payment logic
            return true;
        }
        return false;
    }
}
```

That's it! WPDI automatically discovers and wires up your classes.

## Configuration (Optional)

For interface bindings, create `wpdi-config.php`:

```php
<?php
return array(
    // Interface binding - tell WPDI which implementation to use
    'Payment_Client_Interface' => function() {
        return new PayPal_Client();
    },
);
```

**Note**: Most services don't need configuration - autowiring handles dependencies automatically. Only use `wpdi-config.php` for interface bindings. Keep this file minimal!

## Requirements

- PHP 7.4+
- WordPress 5.0+

## Documentation

- [Getting Started Guide](docs/getting-started.md)
- [Configuration](docs/configuration.md)
- [Testing](docs/testing.md)
- [WP-CLI Commands](docs/wp-cli.md)
- [API Reference](docs/api-reference.md)

## License

GPL-2.0 - Compatible with WordPress.org