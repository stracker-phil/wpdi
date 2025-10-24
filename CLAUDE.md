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

### Key Design Decisions

**Autowiring Strategy:**
- Default factories autowire via reflection: `new $class(...$dependencies)`
- User-defined factories in `wpdi-config.php` override autowiring
- Singletons cached in `$instances` array
- Non-singletons created fresh each time

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
- `tests/Fixtures/` - Sample classes for testing dependency injection
- `tests/ContainerTest.php` - Core DI functionality (27 tests)
- `tests/ScopeTest.php` - Module initialization (10 tests)
- `tests/AutoDiscoveryTest.php` - Class scanning (12 tests)
- `tests/CompilerTest.php` - Cache generation (14 tests)
- `tests/ExceptionsTest.php` - PSR-11 compliance (20 tests)

**Fixture Loading:**
Test fixtures are manually `require_once`'d in `setUp()` since Auto_Discovery expects loaded classes when checking with `class_exists()`.

**Legitimate Skipped Tests:**
- `test_circular_dependency_handling` - Feature not implemented (future enhancement)

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
- Extend appropriate PSR-11 interface
- Add tests to `ExceptionsTest.php`
- Verify exception hierarchy (NotFound extends Container)
- Document common causes in exception docblock
