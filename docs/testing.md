# Testing

WPDI makes testing easier through constructor injection - easily mock dependencies and test in isolation.

## Basic Unit Test

```php
class Payment_Processor_Test extends WP_UnitTestCase {

    public function test_processes_valid_payment(): void {
        // Create mocks
        $validator = $this->createMock(Payment_Validator::class);
        $validator->method('validate')->willReturn(true);

        $config = $this->createMock(Payment_Config::class);
        $config->method('get_api_key')->willReturn('test-key');

        // Inject mocks
        $processor = new Payment_Processor($validator, $config);

        // Test
        $result = $processor->process_payment(100.00, 'USD');

        $this->assertTrue($result);
    }

    public function test_rejects_invalid_payment(): void {
        $validator = $this->createMock(Payment_Validator::class);
        $validator->method('validate')->willReturn(false);

        $processor = new Payment_Processor($validator, new Payment_Config());

        $this->assertFalse($processor->process_payment(-10.00, 'USD'));
    }
}
```

## Testing with WordPress Options

```php
class Settings_Service_Test extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        update_option('my_plugin_api_key', 'test-key');
    }

    public function test_loads_settings(): void {
        $service = new Settings_Service();

        $this->assertEquals('test-key', $service->get_api_key());
    }

    public function tearDown(): void {
        delete_option('my_plugin_api_key');
        parent::tearDown();
    }
}
```

## Testing with Interfaces

Create test implementations to verify behavior:

```php
// Test implementation
class Test_Logger implements Logger_Interface {
    public array $messages = array();

    public function log(string $message): void {
        $this->messages[] = $message;
    }
}

class Service_Test extends WP_UnitTestCase {

    public function test_logs_messages(): void {
        $logger = new Test_Logger();
        $service = new My_Service($logger);

        $service->do_something();

        $this->assertCount(1, $logger->messages);
        $this->assertStringContainsString('did something', $logger->messages[0]);
    }
}
```

## Integration Testing

Test the full container:

```php
class Plugin_Integration_Test extends WP_UnitTestCase {

    private WPDI\Container $container;

    public function setUp(): void {
        parent::setUp();

        // Set up test container
        $this->container = new WPDI\Container();

        // Override with test implementations
        $this->container->load_config(array(
            Logger_Interface::class => fn() => new Test_Logger(),
            API_Client_Interface::class => fn() => new Mock_API_Client(),
        ));

        $this->container->initialize(plugin_dir_path(__FILE__) . '../');
    }

    public function test_full_workflow(): void {
        $app = $this->container->get(Payment_Application::class);
        $app->run();

        $this->assertTrue(has_action('woocommerce_payment_complete'));
    }
}
```

## Testing WordPress Hooks

```php
class Hooks_Test extends WP_UnitTestCase {

    public function test_registers_hooks(): void {
        $processor = $this->createMock(Payment_Processor::class);
        $hooks = new Payment_Hooks($processor);

        $hooks->init();

        $this->assertTrue(has_action('woocommerce_payment_complete'));
    }

    public function test_hook_triggers_callback(): void {
        $processor = $this->createMock(Payment_Processor::class);
        $processor->expects($this->once())
                  ->method('handle')
                  ->with(123);

        $hooks = new Payment_Hooks($processor);
        $hooks->init();

        do_action('woocommerce_payment_complete', 123);
    }
}
```

## Best Practices

### ✅ Do

- Mock dependencies for unit tests
- Test one behavior per test method
- Use descriptive test names
- Clean up in `tearDown()`
- Test edge cases and error handling

### ❌ Don't

- Test multiple behaviors in one test
- Use real dependencies (database, API calls)
- Skip assertions
- Leave test data behind

## Test Structure

```
tests/
├── unit/                  # Fast, isolated tests
│   ├── test-payment-processor.php
│   └── test-settings-service.php
├── integration/           # Full container tests
│   └── test-plugin-integration.php
├── mocks/                 # Test doubles
│   ├── Mock_API_Client.php
│   └── Test_Logger.php
└── bootstrap.php
```

## Running Tests

```bash
# Run all tests
ddev composer test

# Run with coverage
ddev composer coverage
```

View coverage: `https://wpdi.ddev.site/coverage/`
