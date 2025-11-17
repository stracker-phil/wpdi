# WPDI - WordPress Dependency Injection

Lightweight, WordPress-native dependency injection with auto-discovery and zero configuration.

## Features

- **Zero Config** - Autowires concrete classes automatically
- **WordPress Native** - Follows WordPress coding standards
- **PSR-11 Compatible** - Standard container interface
- **Type Safe** - Full type hint support with clear errors

## Quick Start

```php
<?php
/**
 * Plugin Name: My Plugin
 */

require_once __DIR__ . '/vendor/autoload.php';

class My_Plugin extends WPDI\Scope {
    protected function bootstrap( WPDI\Resolver $r ): void {
        $app = $r->get( My_Application::class );
        $app->run();
    }
}

new My_Plugin( __FILE__ );
```

Your classes in `src/` are auto-discovered and autowired:

```php
<?php
// src/My_Application.php

class My_Application {
    public function __construct( My_Service $service, My_Logger $logger ) {
        $this->service = $service;
        $this->logger = $logger;
    }

    public function run(): void {
        add_action( 'init', array( $this, 'on_init' ) );
    }
}
```

## Configuration (Optional)

For interface bindings, create `wpdi-config.php`:

```php
<?php
return array(
    Logger_Interface::class => fn( $r ) => new WP_Logger(),
    Cache_Interface::class  => fn( $r ) => new Redis_Cache(
        $r->get( Logger_Interface::class )
    ),
);
```

## Requirements

- PHP 7.4+
- WordPress 5.0+

## Documentation

- [Getting Started](docs/getting-started.md)
- [Configuration](docs/configuration.md)
- [Testing](docs/testing.md)
- [WP-CLI Commands](docs/wp-cli.md)
- [API Reference](docs/api-reference.md)
- [Troubleshooting](docs/troubleshooting.md)

## License

GPL-2.0
