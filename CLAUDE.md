# CLAUDE.md

Lightweight, WordPress-native dependency injection container with PSR-11 compatibility, zero-config autowiring, and production-optimized caching. Target: WordPress plugins/themes that want clean DI without heavyweight frameworks.

## Commands

```bash
# All commands run inside DDEV (PHP 7.4 environment)
ddev composer test                  # Run all tests
ddev composer cs                    # Check WordPress coding standards
ddev composer cs-fix                # Auto-fix coding standards
ddev composer install               # Install dependencies
ddev composer coverage              # Generate HTML coverage report

# Targeted testing
ddev exec vendor/bin/phpunit tests/ContainerTest.php
ddev exec vendor/bin/phpunit --filter test_autowires_class_with_dependency
ddev exec vendor/bin/phpunit --testdox
```

## Project Structure

```
src/
  Container.php          # PSR-11 DI container, autowiring via reflection
  Resolver.php           # Limited API wrapper (get/has only) for Scope and factories
  Scope.php              # Base class for plugins/themes (composition root, overridable environment())
  Auto_Discovery.php     # Scans src/ for concrete classes via tokenization
  Class_Inspector.php    # Extracts constructor dependencies via reflection
  Compiler.php           # Generates cache/wpdi-container.php (metadata array)
  Cache_Manager.php      # Incremental cache staleness detection and updates
  version-check.php      # Multi-plugin version conflict detection
  Commands/              # WP-CLI: compile, list, inspect, clear + Cli::register_commands() entry point
  Exceptions/            # WPDI_Exception > Container_Exception > Not_Found / Circular_Dependency
tests/
  bootstrap.php          # Mocks WordPress functions (wp_mkdir_p, esc_html, etc.)
  Fixtures/              # Sample classes (circular deps, nullable params, etc.)
  Commands/              # WP-CLI command tests
  *Test.php              # PHPUnit 9.6 tests (one per src class)
docs/                    # User-facing documentation
adr/                     # Architectural Decision Records
```

## Key Patterns

- **Composition root**: `Scope::bootstrap(Resolver)` is the only place that resolves services. `Scope` also provides overridable `autowiring_paths()` and `environment()` methods. See [ADR-006](adr/006-composition-root-pattern.md).
- **Zero-config autowiring**: Concrete classes in `src/` are auto-discovered and wired via reflection. Interface bindings go in `wpdi-config.php`. Contextual bindings use `'$param_name'`-keyed arrays for multiple implementations of the same interface. See [ADR-004](adr/004-zero-config-autowiring.md), [ADR-010](adr/010-contextual-bindings.md).
- **Singleton by default**: Services are cached after first resolution. Don't pass `get_option()` to constructors — use method-level calls instead. See [ADR-009](adr/009-singleton-by-default.md).
- **Metadata caching**: Cache stores `{path, mtime, dependencies}` per class, not closures. Incremental updates in dev; pre-compile for production with `wp di compile`. See [ADR-005](adr/005-metadata-caching.md).
- **Version conflict detection**: `version-check.php` prevents older WPDI from silently breaking when multiple plugins bundle it. See [ADR-008](adr/008-version-conflict-detection.md).
- **Exception hierarchy**: `WPDI_Exception` > `Container_Exception` (PSR-11) > `Not_Found_Exception` / `Circular_Dependency_Exception`

## Conventions

- **PHP 7.4+ only** — no `mixed` types, nullsafe `?->`, `match`, union types, or named arguments. See [ADR-001](adr/001-php-74-minimum.md).
- **WordPress coding standards** enforced by PHPCS. Tabs for indentation, `Snake_Case` classes, `snake_case` methods. `WordPress.Files.FileName` disabled for PSR-4 compatibility. Always use `use` imports — never inline FQNs in implementation code. See [ADR-003](adr/003-wordpress-coding-standards.md).
- **PSR-4 autoloading**: `WPDI\` maps to `src/`, `WPDI\Tests\` maps to `tests/`. File names match class names (`Cache_Manager.php`).
- **100% test coverage** target. Test behavior, not implementation. Fixtures loaded manually in `setUp()`.
- **Version maintained in two places**: `composer.json` and `src/version-check.php` — both must match.

## Code Modification Rules

**Any source file:**
- Verify PHP 7.4 compat — run `ddev composer test`
- No defensive checks that type hints make impossible (dead code)

**Container changes:**
- Update `ContainerTest.php` with corresponding tests
- Maintain `$resolving` cleanup in try-finally for circular dependency detection
- Ensure `clear()` resets all state including `$resolving`, `$resolver`, and `$contextual_bindings`
- Test both autowiring and explicit binding paths

**Auto_Discovery changes:**
- Handle `T_NAME_QUALIFIED` token (PHP 8+) alongside `T_STRING` / `T_NS_SEPARATOR`
- Test with nested directories in `tests/Fixtures/`
- Verify only concrete, instantiable classes are discovered

**Compiler changes:**
- Only cache metadata (`path`, `mtime`, `dependencies`) — never closures
- Update both `Compiler` and `Container::initialize()` together
- Ensure `var_export()` output is valid PHP 7.4+

**New exceptions:**
- Extend `Container_Exception` (container-related) or `WPDI_Exception` (library-specific)
- Add to `ExceptionsTest.php`, `tests/bootstrap.php`, and `src/Scope.php`
- Test catchability at multiple hierarchy levels
