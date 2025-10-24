# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**WPDI** is a lightweight, WordPress-native dependency injection container library. It provides PSR-11 compatible dependency injection with WordPress coding standards, zero configuration autowiring, and production-optimized caching.

**Target Use Case**: WordPress plugins and themes that want clean dependency injection without heavyweight frameworks.

**PHP Version**: Requires PHP 7.4+ (as specified in `composer.json`). Code must not use PHP 8.0+ exclusive features.

## Development Commands

### Testing
```bash
# Run all tests
ddev composer test
# or
ddev exec vendor/bin/phpunit

# Run specific test file
ddev exec vendor/bin/phpunit tests/ContainerTest.php

# Run with test names (testdox format)
ddev exec vendor/bin/phpunit --testdox

# Run specific test method
ddev exec vendor/bin/phpunit --filter test_autowires_class_with_dependency
```

### Code Standards
```bash
# Check WordPress coding standards
ddev composer cs

# Auto-fix coding standards
ddev composer cs-fix
```

### Dependencies
```bash
# Install dependencies (including dev)
ddev composer install

# Update dependencies
ddev composer update
```

## Architecture

### Core Components

**1. Container (`src/class-container.php`)**
- PSR-11 dependency injection container
- Autowiring via PHP reflection
- Singleton caching by default
- Config loading from `wpdi-config.php`
- Circular dependency detection with helpful error messages

**2. Scope (`src/class-scope.php`)**
- Base class for WordPress modules (plugins/themes)
- Composition root pattern - only place with container access
- Calls `bootstrap()` where user resolves root service

**3. Auto_Discovery (`src/class-auto-discovery.php`)**
- Scans `src/` directory for concrete classes
- Tokenizes PHP files to extract namespaces and class names
- PHP 8+ compatibility: handles `T_NAME_QUALIFIED` token
- Filters to only instantiable, concrete classes

**4. Compiler (`src/class-compiler.php`)**
- Caches **discovered class names** (not factory closures)
- Creates `cache/wpdi-container.php` with simple array export
- Production optimization: skips auto-discovery on cached systems

**5. Exception Hierarchy (`src/exceptions/`)**
```
WPDI_Exception (base for all library exceptions)
└── Container_Exception (PSR-11 ContainerExceptionInterface)
    ├── Not_Found_Exception (PSR-11 NotFoundExceptionInterface)
    └── Circular_Dependency_Exception (circular dependency detection)
```
- `WPDI_Exception`: Base exception - catch this to handle any WPDI error
- `Container_Exception`: PSR-11 compliant container errors
- `Not_Found_Exception`: Thrown when service not found
- `Circular_Dependency_Exception`: Thrown when circular dependencies detected

### Key Design Decisions

**Autowiring Strategy:**
- Default factories autowire via reflection: `new $class(...$dependencies)`
- User-defined factories in `wpdi-config.php` override autowiring
- Singletons cached in `$instances` array
- Non-singletons created fresh each time

**Circular Dependency Detection:**
The Container detects circular constructor dependencies and throws a `Circular_Dependency_Exception` with a clear message:
```php
// Example: ServiceA depends on ServiceB, which depends on ServiceA
Circular_Dependency_Exception: Circular dependency detected: ServiceA -> ServiceB -> ServiceA
```
- Implementation: Tracks resolving classes in `$resolving` array during autowiring
- Uses try-finally to ensure cleanup even when exceptions occur
- Circular dependencies indicate design flaws - refactor to extract shared logic or use events
- Can be caught specifically, or via `Container_Exception`, `WPDI_Exception`, or PSR-11 `ContainerExceptionInterface`

**Cache Design:**
```php
// Cache contains ONLY class names, not closures
return array(
    'My_Service',
    'My_Repository',
    'My_Controller'
);
```
This avoids Closure serialization (impossible with `var_export()`). On cache load, autowiring factories are recreated via reflection.

**Composition Root:**
The `Scope::bootstrap()` method is the **only** place that accesses the container. Services receive dependencies via constructor injection, never via container access.

**Exception Handling:**
Users can catch exceptions at multiple levels depending on their needs:
```php
// Catch specific exception type
try {
    $service = $container->get(MyService::class);
} catch (Circular_Dependency_Exception $e) {
    // Handle circular dependency specifically
}

// Catch all container exceptions (PSR-11 compliant)
try {
    $service = $container->get(MyService::class);
} catch (ContainerExceptionInterface $e) {
    // Handle any container error
}

// Catch all WPDI exceptions
try {
    $service = $container->get(MyService::class);
} catch (WPDI_Exception $e) {
    // Handle any WPDI library error
}
```

### WordPress Integration

**File Naming Convention:**
- Classes: `class-my-service.php` (WordPress style)
- Tests: `MyServiceTest.php` (PSR-4 style)

**init.php Entry Point:**
- Loads core classes manually (no autoloading)
- Checks for `ABSPATH` to prevent direct access
- Conditionally loads WP-CLI commands if available

**WordPress Function Mocking (tests/bootstrap.php):**
Tests mock WordPress functions like `wp_get_environment_type()`, `wp_mkdir_p()`, `current_time()` to run without WordPress installation.

## Testing Philosophy

**Test Structure:**
- `tests/Fixtures/` - Sample classes for testing dependency injection (includes CircularA/CircularB for circular dependency testing)
- `tests/ContainerTest.php` - Core DI functionality (27 tests including circular dependency detection)
- `tests/ScopeTest.php` - Module initialization (10 tests)
- `tests/AutoDiscoveryTest.php` - Class scanning (12 tests)
- `tests/CompilerTest.php` - Cache generation (14 tests)
- `tests/ExceptionsTest.php` - PSR-11 compliance and exception hierarchy (28 tests)
- **Total: 90 tests, 219 assertions**

**Fixture Loading:**
Test fixtures are manually `require_once`'d in `setUp()` since Auto_Discovery expects loaded classes when checking with `class_exists()`.

## Common Pitfalls

**1. PHP 7.4 Compatibility (CRITICAL)**
The library **must** support PHP 7.4+. Do NOT use PHP 8.0+ features:

❌ **Forbidden Syntax:**
- `mixed` return type (use `@return mixed` docblock instead)
- Nullsafe operator `?->` (use ternary: `$obj ? $obj->method() : 'default'`)
- `match` expressions (use `switch` or `if/elseif`)
- Union types like `string|int` (use `@param string|int` docblock)
- Named arguments

✅ **Allowed:**
- Nullable types: `?string`, `?callable`
- Return type declarations: `:void`, `:bool`, `:string`, `:array`, `:object`
- Property type hints (PHP 7.4+)

**2. Auto_Discovery PHP 8 Token Compatibility**
PHP 8 introduced `T_NAME_QUALIFIED` token for namespaces like `Foo\Bar`. When parsing namespaces, check for this token in addition to `T_STRING` and `T_NS_SEPARATOR`:
```php
if ( T_STRING === $token_type || T_NS_SEPARATOR === $token_type ||
    ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $token_type ) ) {
    $namespace .= $tokens[ $index ][1];
}
```

**3. Compiler Cache Format**
The Compiler caches **class names**, not **bindings**. Never try to serialize Closures with `var_export()` - it will fail. The cache is loaded via `Container::load_compiled()` which rebinds classes with autowiring.

**4. Container Type Safety**
`Container::bind()` and `Container::get()` only accept valid class/interface names. They validate with `class_exists()` and `interface_exists()` and throw exceptions for invalid strings. This prevents magic string bugs.

**5. Dead Code from Type Hints**
Don't write defensive checks that can never be false due to type hints. Example:
```php
// ❌ BAD - is_callable() check is dead code with ?callable type hint
public function bind( string $abstract, ?callable $factory = null ) {
    if ( ! is_callable( $factory ) ) {  // This can NEVER be true
        throw new Exception();
    }
}
```
PHP's type system validates callable before function execution. Such checks create untestable code paths.

**6. Test Bootstrap WordPress Functions**
Always mock WordPress functions in `tests/bootstrap.php`. The bootstrap must be runnable without WordPress core. Suppress expected warnings in tests with `@` operator when testing failure scenarios (e.g., write permissions).

**7. Circular Dependencies Are Design Flaws**
The Container detects and rejects circular constructor dependencies with helpful error messages. If you encounter a circular dependency exception:
- **Refactor to extract shared logic** into a third service both classes can depend on
- **Use WordPress hooks** for event-driven communication instead of direct dependencies
- **Redesign class responsibilities** - tight circular coupling indicates poor separation of concerns

Example refactoring:
```php
// ❌ BAD - Circular dependency
class ServiceA {
    public function __construct(ServiceB $b) {}
}
class ServiceB {
    public function __construct(ServiceA $a) {}
}

// ✅ GOOD - Extract shared logic
class SharedLogic {}
class ServiceA {
    public function __construct(SharedLogic $shared) {}
}
class ServiceB {
    public function __construct(SharedLogic $shared) {}
}
```

## Code Modification Guidelines

**When modifying any source file:**
- **Always verify PHP 7.4 compatibility** - run `ddev composer test` in PHP 7.4 environment
- No `mixed` type hints - use docblocks instead
- No nullsafe operator `?->` - use ternary operator
- No PHP 8+ features without fallback/polyfill

**When modifying Container:**
- Update `ContainerTest.php` with corresponding tests
- Ensure PSR-11 compliance maintained
- Test both autowiring and explicit binding paths
- Avoid defensive checks that type hints make impossible
- Maintain `$resolving` array cleanup in try-finally blocks for circular dependency detection
- Ensure `clear()` method resets all state including `$resolving`

**When modifying Auto_Discovery:**
- Test with nested directories in `tests/Fixtures/`
- Handle both PHP 7.4 and PHP 8+ token types (`T_NAME_QUALIFIED`)
- Ensure filters work (traits, interfaces, abstract classes excluded)
- Verify discovered classes are actually loadable

**When modifying Compiler:**
- Remember: only cache class names, never Closures
- Update both `Compiler` and `Container::initialize()` together
- Test cache file can be `require`'d and returns simple array
- Ensure `var_export()` output is valid PHP 7.4+ syntax

**When adding new exceptions:**
- Extend from appropriate base class in the hierarchy:
  - Container-related: extend `Container_Exception`
  - Library-specific: extend `WPDI_Exception`
- Add tests to `ExceptionsTest.php` verifying the exception hierarchy
- Update `tests/bootstrap.php` and `init.php` to load new exception file
- Document common causes and solutions in exception docblock
- Test exception can be caught at multiple levels (specific type, base class, interface)
