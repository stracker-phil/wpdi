# Getting Started with WPDI

This guide walks you through setting up WPDI in your WordPress plugin, theme, or custom module.

## Installation

### Method 1: Direct Download (Recommended)

1. Download the WPDI library
2. Extract to your plugin/theme directory
3. Include the init file:

```php
require_once __DIR__ . '/wpdi/init.php';
```

### Method 2: Composer (Optional)

```bash
composer require stracker-phil/wpdi
```

## Your First WPDI Module

### Step 1: Create the Main Class

```php
<?php
/**
 * Plugin Name: My Sample Plugin
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/wpdi/init.php';

class My_Sample_Plugin extends WPDI\Scope {
    protected function bootstrap(): void {
        // This is the composition root & main entry point.
        // Access to the DI services is only allowed in this function.
        $app = $this->get( Sample_Application::class );
        $app->run();
    }
}

// Initialize the plugin
new My_Sample_Plugin();
```

### Step 2: Create Your Application Class

```php
<?php
// src/Sample_Application.php

class Sample_Application {
    private Sample_Service $service;
    private Sample_Logger $logger;
    
    public function __construct( Sample_Service $service, Sample_Logger $logger ) {
        $this->service = $service;
        $this->logger = $logger;
    }
    
    public function run(): void {
        add_action( 'init', array( $this, 'on_init' ) );
        add_action( 'wp_footer', array( $this, 'on_footer' ) );
    }
    
    public function on_init(): void {
        $this->logger->info( 'Sample plugin initialized' );
        $this->service->do_something();
    }
    
    public function on_footer(): void {
        if ( current_user_can( 'manage_options' ) ) {
            echo '<!-- Sample Plugin Active -->';
        }
    }
}
```

### Step 3: Create Your Business Logic

```php
<?php
// src/Sample_Service.php

class Sample_Service {
    private Sample_Config $config;
    private Sample_Logger $logger;
    
    public function __construct( Sample_Config $config, Sample_Logger $logger ) {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    public function do_something(): void {
        if ( $this->config->is_enabled() ) {
            $this->logger->info( 'Sample service is doing something!' );
            // Your business logic here
        }
    }
    
    public function get_data(): array {
        return array(
            'status' => $this->config->is_enabled() ? 'active' : 'inactive',
            'version' => $this->config->get_version(),
        );
    }
}
```

```php
<?php
// src/Sample_Config.php

class Sample_Config {
    private bool $enabled;
    private string $version;
    
    public function __construct( bool $enabled = true, string $version = '1.0.0' ) {
        $this->enabled = $enabled;
        $this->version = $version;
    }
    
    public function is_enabled(): bool {
        return $this->enabled;
    }
    
    public function get_version(): string {
        return $this->version;
    }
}
```

```php
<?php
// src/Sample_Logger.php

class Sample_Logger {
    private string $prefix;
    
    public function __construct( string $prefix = '[Sample Plugin]' ) {
        $this->prefix = $prefix;
    }
    
    public function info( string $message ): void {
        if ( WP_DEBUG ) {
            error_log( $this->prefix . ' ' . $message );
        }
    }
    
    public function error( string $message ): void {
        error_log( $this->prefix . ' ERROR: ' . $message );
    }
}
```

### Step 4: Test Your Setup

1. Activate your plugin
2. Check your debug log (if `WP_DEBUG_LOG` is enabled)
3. You should see: `[Sample Plugin] Sample plugin initialized`
4. View page source and look for: `<!-- Sample Plugin Active -->`

## What Just Happened?

1. **Auto-Discovery**: WPDI automatically found all your classes in `src/`
2. **Dependency Injection**: It analyzed constructor parameters and injected dependencies
3. **Type Safety**: PHP's type system ensures correct dependencies are injected
4. **WordPress Integration**: Your code uses standard WordPress hooks and patterns

## Next Steps

- [Configuration Guide](configuration.md) - Learn about `wpdi-config.php`
- [Testing](testing.md) - Unit testing your WPDI classes
