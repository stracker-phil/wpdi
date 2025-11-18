# WPDI - WordPress Dependency Injection

Lightweight, WordPress-native dependency injection with auto-discovery and zero configuration.

## Features

- **Zero Config** - Autowires concrete classes in `src/` folder
- **Production Ready** - Cached class mapping; only modified files re-scanned during development
- **WordPress Native** - Follows WordPress coding standards
- **PSR-11 Compatible** - Standard container interface
- **Opinionated** - Limited feature-set by design

## Philosophy

> "Constraint is the feature"

In my experience, DI usually fails in WordPress projects not because they lack features, but because they have too many. It's easy for developers to misuse the container as a global registry, a configuration store, a service locator - all patterns that DI was meant to solve.

WPDI prevents these patterns by design. It does one thing well: wire your classes together automatically. Everything else—configuration, state, WordPress options—stays where it belongs.

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

## Caching

Autowiring uses reflection to analyze constructor dependencies—but only once. WPDI caches the class mapping and rebuilds it automatically when files change. In production, this means zero overhead: just a single array lookup per service.

For deployment, pre-compile the cache to avoid the initial scan:

```shell
wp di compile
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
