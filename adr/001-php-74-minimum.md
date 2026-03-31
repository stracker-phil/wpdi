# ADR-001: PHP 7.4 Minimum Version

**Status:** Accepted

## Context

WordPress plugins must support a wide range of hosting environments. Many shared hosts still run PHP 7.4, and WordPress itself supports 7.4+.

## Decision

The library targets PHP 7.4+ (`composer.json: "php": ">=7.4"`). No PHP 8.0+ exclusive features (mixed types, nullsafe operator, match expressions, union types, named arguments). DDEV is configured with `php_version: "7.4"` to enforce this during development.

## Consequences

- Broader adoption across WordPress hosting environments
- Must use docblocks instead of modern type hints (`@return mixed`, `@param string|int`)
- Token parsing in `Auto_Discovery.php` must handle both PHP 7.4 and 8.0+ tokens (`T_NAME_QUALIFIED`)
