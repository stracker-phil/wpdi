# Getting Started

## Installation

Via Composer:

```bash
composer require stracker-phil/wpdi
```

Or, download WPDI and include it in your plugin:

```php
require_once __DIR__ . '/wpdi/src/Scope.php';
```

## Basic Setup

### 1. Create Your Plugin

```php
<?php
/**
 * Plugin Name: My Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// When installed via composer:
require_once __DIR__ . '/vendor/autoload.php';

// When downloaded into a sub-folder:
require_once __DIR__ . '/wpdi/src/Scope.php';

class My_Plugin extends WPDI\Scope {
    protected function bootstrap( WPDI\Resolver $r ): void {
        $app = $r->get( My_Application::class );
        $app->run();
    }
}

new My_Plugin( __FILE__ );
```

### 2. Create Your Application

```php
<?php
// src/My_Application.php

class My_Application {
    private My_Service $service;

    public function __construct( My_Service $service ) {
        $this->service = $service;
    }

    public function run(): void {
        add_action( 'init', array( $this, 'on_init' ) );
    }

    public function on_init(): void {
        $this->service->do_work();
    }
}
```

### 3. Create Your Services

```php
<?php
// src/My_Service.php

class My_Service {
    private My_Config $config;

    public function __construct( My_Config $config ) {
        $this->config = $config;
    }

    public function do_work(): void {
        if ( $this->config->is_enabled() ) {
            // Business logic
        }
    }
}
```

```php
<?php
// src/My_Config.php

class My_Config {
    public function is_enabled(): bool {
        return get_option( 'my_plugin_enabled', true );
    }
}
```

## How It Works

1. **Auto-Discovery** - WPDI scans `src/` for PHP classes
2. **Autowiring** - Analyzes constructor parameters via reflection
3. **Dependency Injection** - Instantiates and injects dependencies automatically

## Directory Structure

```
my-plugin/
├── my-plugin.php          # Scope class (entry point)
├── wpdi-config.php        # Optional: interface bindings, external classes
├── wpdi/                   # WPDI library
└── src/                    # Your classes (auto-discovered)
    ├── My_Application.php
    ├── My_Service.php
    └── My_Config.php
```

## File Naming (PSR-4)

Class names must match file names exactly:

```
✅ My_Service.php      → class My_Service
✅ Payment_Gateway.php → class Payment_Gateway
❌ class-my-service.php (old WP style - not discovered)
```

## Next Steps

- [Configuration](configuration.md) - Interface bindings
- [Testing](testing.md) - Unit testing patterns
- [Troubleshooting](troubleshooting.md) - Common issues
