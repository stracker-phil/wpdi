# ADR-003: WordPress Coding Standards

**Status:** Accepted

## Context

As a WordPress-native library, the code should feel familiar to WordPress developers and pass WPCS linting.

## Decision

PHPCS enforces `WordPress` ruleset via `phpcs.xml`. File naming rule (`WordPress.Files.FileName`) is disabled (severity 0) to allow PSR-4 compatible PascalCase filenames like `Cache_Manager.php`. Classes use `Snake_Case`, methods use `snake_case`, indentation uses tabs.

All class names, function names, and constants from other namespaces must be imported via `use` / `use function` / `use const` statements. Never use fully-qualified names (FQNs) inline in implementation code.

## Consequences

- Familiar to WordPress ecosystem developers
- PSR-4 autoloading works despite WordPress naming conventions
- `ddev composer cs` / `ddev composer cs-fix` for enforcement
