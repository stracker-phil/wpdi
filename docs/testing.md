# Testing

WPDI makes testing easy through constructor injection - mock dependencies and test in isolation.

## Unit Testing

```php
class Payment_Processor_Test extends WP_UnitTestCase {

    public function test_processes_valid_payment(): void {
        $validator = $this->createMock( Payment_Validator::class );
        $validator->method( 'validate' )->willReturn( true );

        $processor = new Payment_Processor( $validator );
        $result = $processor->process_payment( 100.00 );

        $this->assertTrue( $result );
    }

    public function test_rejects_invalid_payment(): void {
        $validator = $this->createMock( Payment_Validator::class );
        $validator->method( 'validate' )->willReturn( false );

        $processor = new Payment_Processor( $validator );

        $this->assertFalse( $processor->process_payment( -10.00 ) );
    }
}
```

## Testing with Interfaces

Create test implementations:

```php
class Test_Logger implements Logger_Interface {
    public array $messages = array();

    public function log( string $message ): void {
        $this->messages[] = $message;
    }
}

class Service_Test extends WP_UnitTestCase {

    public function test_logs_messages(): void {
        $logger = new Test_Logger();
        $service = new My_Service( $logger );

        $service->do_something();

        $this->assertCount( 1, $logger->messages );
    }
}
```

## Integration Testing

Test with the full container:

```php
class Plugin_Integration_Test extends WP_UnitTestCase {

    private WPDI\Container $container;

    public function setUp(): void {
        parent::setUp();

        $this->container = new WPDI\Container();

        // Override with test implementations
        $this->container->load_config( array(
            Logger_Interface::class => fn( $r ) => new Test_Logger(),
            API_Client::class       => fn( $r ) => new Mock_API_Client(),
        ) );

        // Create a temporary scope file for testing
        $scope_file = plugin_dir_path( __FILE__ ) . 'test-scope.php';
        file_put_contents( $scope_file, '<?php' );

        $this->container->initialize( $scope_file );

        unlink( $scope_file );
    }

    public function test_full_workflow(): void {
        $app = $this->container->get( Payment_Application::class );
        $app->run();

        $this->assertTrue( has_action( 'woocommerce_payment_complete' ) );
    }
}
```

## Testing WordPress Hooks

```php
class Hooks_Test extends WP_UnitTestCase {

    public function test_registers_hooks(): void {
        $processor = $this->createMock( Payment_Processor::class );
        $hooks = new Payment_Hooks( $processor );

        $hooks->init();

        $this->assertTrue( has_action( 'woocommerce_payment_complete' ) );
    }

    public function test_hook_triggers_callback(): void {
        $processor = $this->createMock( Payment_Processor::class );
        $processor->expects( $this->once() )
                  ->method( 'handle' )
                  ->with( 123 );

        $hooks = new Payment_Hooks( $processor );
        $hooks->init();

        do_action( 'woocommerce_payment_complete', 123 );
    }
}
```

## Test Structure

```
tests/
├── unit/                  # Fast, isolated tests
├── integration/           # Full container tests
├── mocks/                 # Test doubles
└── bootstrap.php
```

## Running Tests

```bash
# Run all tests
ddev composer test

# Run specific test
ddev exec vendor/bin/phpunit tests/unit/Payment_Test.php
```
