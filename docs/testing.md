# Testing Guide

Learn how to unit test your WPDI-powered WordPress modules.

## Testing Strategy

WPDI makes testing easier by using constructor injection. You can easily mock dependencies and test components in isolation.

## Basic Unit Testing

### Testing a Simple Service

```php
<?php

class Payment_Processor_Test extends WP_UnitTestCase {
    
    public function test_successful_payment_processing(): void {
        // Arrange - Create mocks
        $mock_validator = $this->createMock( 'Payment_Validator' );
        $mock_config = $this->createMock( 'Payment_Config' );
        $mock_logger = $this->createMock( 'Payment_Logger' );
        
        // Configure mock behavior
        $mock_validator->method( 'validate' )
                      ->willReturn( true );
        
        $mock_config->method( 'get_api_key' )
                   ->willReturn( 'test-api-key' );
        
        // Create service with mocked dependencies
        $processor = new Payment_Processor( $mock_validator, $mock_config, $mock_logger );
        
        // Act
        $result = $processor->process_payment( 100.00, 'USD' );
        
        // Assert
        $this->assertTrue( $result );
    }
    
    public function test_invalid_payment_rejected(): void {
        // Arrange
        $mock_validator = $this->createMock( 'Payment_Validator' );
        $mock_config = $this->createMock( 'Payment_Config' );
        $mock_logger = $this->createMock( 'Payment_Logger' );
        
        // Configure validator to reject
        $mock_validator->method( 'validate' )
                      ->willReturn( false );
        
        // Expect logger to be called
        $mock_logger->expects( $this->once() )
                   ->method( 'error' )
                   ->with( $this->stringContains( 'validation failed' ) );
        
        $processor = new Payment_Processor( $mock_validator, $mock_config, $mock_logger );
        
        // Act
        $result = $processor->process_payment( -10.00, 'USD' );
        
        // Assert
        $this->assertFalse( $result );
    }
}
```

### Testing Services with WordPress Integration

```php
<?php

class Settings_Service_Test extends WP_UnitTestCase {
    
    public function setUp(): void {
        parent::setUp();
        
        // Set up WordPress options for testing
        update_option( 'my_plugin_api_key', 'test-key' );
        update_option( 'my_plugin_enabled', true );
    }
    
    public function test_loads_settings_from_wordpress(): void {
        // No mocking needed - test real WordPress integration
        $service = new Settings_Service();
        
        $this->assertEquals( 'test-key', $service->get_api_key() );
        $this->assertTrue( $service->is_enabled() );
    }
    
    public function test_handles_missing_options(): void {
        // Remove options
        delete_option( 'my_plugin_api_key' );
        delete_option( 'my_plugin_enabled' );
        
        $service = new Settings_Service();
        
        $this->assertEquals( '', $service->get_api_key() );
        $this->assertFalse( $service->is_enabled() );
    }
    
    public function tearDown(): void {
        // Clean up
        delete_option( 'my_plugin_api_key' );
        delete_option( 'my_plugin_enabled' );
        
        parent::tearDown();
    }
}
```

## Testing with Interfaces

When your services depend on interfaces, you can easily swap implementations for testing.

```php
<?php

// Production implementation
class WP_Logger implements Logger_Interface {
    public function log( string $message ): void {
        error_log( '[Plugin] ' . $message );
    }
}

// Test implementation
class Test_Logger implements Logger_Interface {
    public array $messages = array();
    
    public function log( string $message ): void {
        $this->messages[] = $message;
    }
}

class Service_With_Logger_Test extends WP_UnitTestCase {
    
    public function test_service_logs_messages(): void {
        // Use test logger instead of real one
        $test_logger = new Test_Logger();
        $service = new My_Service( $test_logger );
        
        $service->do_something();
        
        // Verify logging behavior
        $this->assertCount( 1, $test_logger->messages );
        $this->assertStringContains( 'did something', $test_logger->messages[0] );
    }
}
```

## Integration Testing

Test your entire module with WPDI container.

```php
<?php

class Plugin_Integration_Test extends WP_UnitTestCase {
    
    private WPDI\Container $container;
    
    public function setUp(): void {
        parent::setUp();
        
        // Set up container with test configuration
        $this->container = new WPDI\Container();
        
        // Load test-specific bindings
        $test_config = array(
            'Logger_Interface' => function() {
                return new Test_Logger();
            },
            'API_Client_Interface' => function() {
                return new Mock_API_Client();
            },
        );
        
        $this->container->load_config( $test_config );
        $this->container->initialize( plugin_dir_path( __FILE__ ) . '../' );
    }
    
    public function test_full_payment_flow(): void {
        // Get the main application service
        $app = $this->container->get( 'Payment_Application' );
        
        // Simulate WordPress hooks
        $app->run();
        
        // Test that hooks were registered
        $this->assertTrue( has_action( 'woocommerce_payment_complete' ) );
        
        // Test actual payment processing
        $processor = $this->container->get( 'Payment_Processor' );
        $result = $processor->handle( 123 );
        
        $this->assertTrue( $result );
    }
}
```

## Testing WordPress Hooks

Test that your services properly integrate with WordPress hooks.

```php
<?php

class Hook_Integration_Test extends WP_UnitTestCase {
    
    public function test_payment_hook_registration(): void {
        // Create service
        $processor = new Payment_Processor( 
            $this->createMock( 'Payment_Validator' ),
            $this->createMock( 'Payment_Config' ),
            $this->createMock( 'Payment_Logger' )
        );
        
        $hooks = new Payment_Hooks( $processor );
        $hooks->init();
        
        // Verify hooks were registered
        $this->assertTrue( has_action( 'woocommerce_payment_complete' ) );
        $this->assertTrue( has_filter( 'woocommerce_payment_gateways' ) );
    }
    
    public function test_hook_callbacks_work(): void {
        $mock_processor = $this->createMock( 'Payment_Processor' );
        $mock_processor->expects( $this->once() )
                      ->method( 'handle' )
                      ->with( 123 );
        
        $hooks = new Payment_Hooks( $mock_processor );
        $hooks->init();
        
        // Trigger the hook
        do_action( 'woocommerce_payment_complete', 123 );
    }
}
```

## Testing Best Practices

### ✅ Do This

```php
class Good_Test extends WP_UnitTestCase {
    
    public function test_specific_behavior(): void {
        // Arrange - Set up test data and mocks
        $mock_dependency = $this->createMock( 'Dependency' );
        $mock_dependency->method( 'get_data' )->willReturn( 'test-data' );
        
        $service = new Service_Under_Test( $mock_dependency );
        
        // Act - Call the method being tested
        $result = $service->process_data();
        
        // Assert - Verify expected behavior
        $this->assertEquals( 'processed-test-data', $result );
    }
    
    public function test_error_handling(): void {
        // Test exception scenarios
        $mock_dependency = $this->createMock( 'Dependency' );
        $mock_dependency->method( 'get_data' )
                       ->willThrowException( new Exception( 'Test error' ) );
        
        $service = new Service_Under_Test( $mock_dependency );
        
        $this->expectException( 'Service_Exception' );
        $service->process_data();
    }
}
```

### ❌ Don't Do This

```php
class Bad_Test extends WP_UnitTestCase {
    
    public function test_everything(): void {
        // ❌ Testing too much in one test
        $service = new Service_Under_Test();
        
        $this->assertTrue( $service->validate_data( array() ) );
        $this->assertFalse( $service->validate_data( null ) );
        $this->assertEquals( 'result', $service->process_data() );
        $this->assertNotEmpty( $service->get_config() );
    }
    
    public function test_with_real_dependencies(): void {
        // ❌ Using real dependencies instead of mocks
        $service = new Service_Under_Test(
            new Real_Database_Connection(),  // Slow, unreliable
            new Real_API_Client()           // Network dependent
        );
        
        $result = $service->do_something();
        $this->assertTrue( $result );
    }
}
```

## Test Organization

### Directory Structure

```
tests/
├── unit/
│   ├── test-payment-processor.php
│   ├── test-payment-validator.php
│   └── test-settings-service.php
├── integration/
│   ├── test-plugin-integration.php
│   └── test-hook-integration.php
├── mocks/
│   ├── Mock_API_Client.php
│   └── Test_Logger.php
└── bootstrap.php
```

### Test Bootstrap

```php
<?php
// tests/bootstrap.php

// Load WordPress test environment
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin() {
    require dirname( dirname( __FILE__ ) ) . '/my-plugin.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
```

## Running Tests

### PHPUnit Configuration

```xml
<!-- phpunit.xml -->
<phpunit bootstrap="tests/bootstrap.php">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

### Commands

```bash
# Run all tests
ddev composer test

# Run with coverage
ddev composer coverage
```

The test coverage report is available at: https://wpdi.ddev.site/coverage/

## Next Steps

- [API Reference](api-reference.md) - Complete method documentation
- [Troubleshooting](troubleshooting.md) - Common testing issues
