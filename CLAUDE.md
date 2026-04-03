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
  Resolver.php           # Limited API wrapper (get/has only) for Scope::bootstrap()
  Scope.php              # Base class for plugins/themes (composition root, overridable environment())
  Auto_Discovery.php     # Scans src/ for concrete classes via tokenization
  Class_Inspector.php    # Extracts constructor dependencies via reflection
  Compiler.php           # Generates cache/wpdi-container.php ({classes, bindings} array)
  Cache_Manager.php      # Incremental cache staleness detection and updates
  version-check.php      # Multi-plugin version conflict detection
  Commands/              # WP-CLI commands: Command.php (abstract base), Cli.php (register_commands entry point), compile, list, inspect, clear, depends
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

- **Composition root**: `App::boot(__FILE__)` is the entry point — a static method on `Scope` that is idempotent (duplicate calls are no-ops) and returns `void`. The constructor is `protected`; `boot()` is the only external entry point. `bootstrap(Resolver)` is the single place services are resolved. `Scope` also provides overridable `autowiring_paths()` and `environment()` methods. See [ADR-006](adr/006-composition-root-pattern.md).
- **Zero-config autowiring**: Concrete classes in `src/` are auto-discovered and wired via reflection. Interface bindings go in `wpdi-config.php` as static class name strings (`Interface::class => Concrete::class`). Contextual bindings use `'$param_name'`-keyed arrays; the fallback key is `'default'`. See [ADR-004](adr/004-zero-config-autowiring.md), [ADR-010](adr/010-contextual-bindings.md), [ADR-013](adr/013-static-config-format.md).
- **Singleton by default**: Services are cached after first resolution. Don't pass `get_option()` to constructors — use method-level calls instead. See [ADR-009](adr/009-singleton-by-default.md).
- **Metadata caching**: Cache stores `{classes: {path, mtime, dependencies}, bindings: {interface => class}}`. Both autowired class metadata and `wpdi-config.php` interface bindings are serialized — the compiled file is a complete deployable artifact. Incremental updates in dev; pre-compile for production with `wp di compile`. See [ADR-005](adr/005-metadata-caching.md).
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

**Scope changes:**
- Constructor is `protected` — use `App::boot(__FILE__)` as the external entry point
- `boot()` is idempotent: second call for the same class is a no-op (silent return)
- `clear()` is for test teardown only — call in `tearDown()` via `TestScope::clear()`
- Test fixtures that need direct instantiation must override the constructor with `public` visibility and call `parent::__construct()`

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
- Cache format is `{classes: {class => [path, mtime, dependencies]}, bindings: {interface => class}}`; old flat-format caches are auto-migrated on load
- `Compiler::write(array $class_map, array $bindings = [])` — always pass bindings when writing
- `Cache_Manager::get_cache(string $scope_file, array $config_bindings = [])` — threads bindings through all write paths
- Update `Compiler`, `Cache_Manager`, and `Container::load_compiled()` together
- Ensure `var_export()` output is valid PHP 7.4+; config values must be class name strings (not closures)
- `Compile_Command` validates `wpdi-config.php` on load: top-level values must be strings or arrays; array values must be strings; anything else (closure, object, etc.) calls `$this->error()` and aborts

**WP-CLI command changes:**
- All commands extend `Command` (abstract base class in `src/Commands/Command.php`); commands only collect data and call parent rendering methods
- Output uses `$this->table($items, $fields, $types, $title, $separators)` — column widths use `mb_strlen()` for multi-byte safety; `$types` maps field names to string format identifiers (`'class_name'`, `'class_fqcn'`, `'class_binding'`, `'type_label'`, `'via'`, `'bool'`, `'param'`); use `'class_binding'` when a row has two distinct class columns (reads `$item['binding_type']` instead of `$item['type']`); `$title` adds a full-width spanning row above column headers; `$separators` is an array of row indices before which a mid-border line is emitted
- Type values stored in table rows must be pre-normalized labels (`'class'` not `'concrete'`) via `$this->format_type_label()` before passing to `table()`
- Adding a new format identifier requires editing `apply_cell_format()` in `Command.php`
- `render_tree()` in `Command` reshapes tree rows into a table; depth-1 nodes strip their tree connector and are preceded by a mid-border separator; depth-2+ nodes collapse the depth-1 continuation to 1 space (giving 2-space left margin total)
- Use `$this->tree_connector($is_last)` and `$this->tree_indent($is_last)` when building tree prefixes — these return ASCII or Unicode chars depending on `$this->ascii`
- Call `$this->parse_format_flag($assoc_args)` at the start of every `__invoke()` that uses `table()` or `render_tree()`; add `[--format=<format>]` to the command's `@synopsis`
- Use `$this->log_class_header($class, $type, $path)` for the consistent `Path:` + colored-class preamble shown by `inspect` and `depends`
- See [ADR-012](adr/012-cli-command-output-centralization.md) for the full design rationale

**New exceptions:**
- Extend `Container_Exception` (container-related) or `WPDI_Exception` (library-specific)
- Add to `ExceptionsTest.php`, `tests/bootstrap.php`, and `src/Scope.php`
- Test catchability at multiple hierarchy levels
