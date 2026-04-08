# ADR-016: Configuration Belongs in Services, Not in the DI Layer

**Status:** Accepted

## Context

As projects grow, there is recurring pressure to pass configuration values — environment URLs, feature flags, scalar settings — through the DI container. Two approaches were considered and rejected:

**Scalar bindings in `wpdi-config.php`** (e.g. `InvoicePrefix::class => 'INV-2025-'`): looks convenient but has no natural boundary. Once scalar values enter the config file it becomes the path of least resistance for any hardcoded value, making the config file a mixed bag of type bindings and data. The deeper architectural reasons why primitives cannot flow through DI are documented in ADR-017.

**Callable/factory support in `wpdi-config.php`**: already rejected in ADR-013 for serializability reasons. The deeper problem surfaced by real-world usage is that factory closures attract business logic. A factory that was intended to "just return a value" drifts into calling `apply_filters`, reading settings objects from the container, and encoding eligibility rules:

```php
// Seen in the wild — business logic smuggled into a service factory
static function ( ContainerInterface $container ): array {
    $features = apply_filters( 'plugin_rest_common_merchant_features', array() );
    $general_settings = $container->get( 'settings.data.general' );
    return array(
        FeaturesDefinition::FEATURE_A => ( $features[...]['enabled'] ?? false )
            && ! $general_settings->own_brand_only(),
        // ...
    );
},
```

This is not a DI binding — it is a service method hiding in a factory. The factory escape hatch makes this tempting and normalizes the pattern.

## Decision

**Static configuration belongs in the service, not in the DI layer.**

If a value is known at **compile time**, it is internal knowledge of the service and should be expressed as a class constant:

```php
class ApiEndpointHandler {
    protected const LIVE_URL = 'https://api-live.com';
    protected const TEST_URL = 'https://api-sandbox.com';

    /** @var Environment */
    protected $environment;

    public function __construct( Environment $environment ) {
        $this->environment = $environment;
    }

    public function url(): string {
        return $this->environment->is_live() ? static::LIVE_URL : static::TEST_URL;
    }
}
```

For testing, use mocking or override the constant in a subclass — do not extract config into a DI binding just to make it replaceable in tests:

```php
class TestableApiEndpointHandler extends ApiEndpointHandler {
    protected const LIVE_URL  = 'https://unit-test-live.com';
    protected const TEST_URL  = 'https://unit-test-sandbox.com';
}
```

**Dynamic (runtime-unknown) values use thin, typed wrapper classes.**

Values that genuinely cannot be known at compile time — WordPress options, API keys from the database, user-specific settings — should be wrapped in a typed class and resolved by the container via that type:

```php
class InvoicePrefix {
    public function value(): string { return get_option('invoice_prefix'); }
}
```

Because `InvoicePrefix` is a concrete class with no constructor parameters, the container autowires it automatically. Any service that needs it declares the dependency in its constructor — no explicit binding or `bootstrap()` call required:

```php
class InvoiceService {
    /** @var InvoicePrefix */
    private $prefix;

    public function __construct( InvoicePrefix $prefix ) {
        $this->prefix = $prefix;
    }

    public function next_number(): string {
        return $this->prefix->value() . $this->generate_sequence();
    }
}
```

Each runtime-dynamic value gets its own typed wrapper class. Naming them after the concept (not the data type) keeps consuming code readable and avoids collisions when two services need different string values.

## Consequences

- `wpdi-config.php` remains a pure, static, inspectable map of interface → concrete class names. No values, no callables.
- Business logic cannot be smuggled into DI factories because no factory mechanism exists in the config layer.
- Services own their internal configuration. Static values (URLs, limits, prefixes that never change at runtime) are constants — not constructor parameters, not bindings.
- The only values flowing through DI are **behaviors** (interface implementations) and **runtime-dynamic values** wrapped in typed classes. Scalar primitives do not flow through DI directly — see ADR-017 for why primitive injection is rejected at the architectural level.
- Testability of static values is achieved by subclassing and constant overrides, not by making constants into injected parameters.
