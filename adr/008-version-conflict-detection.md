# ADR-008: Version Conflict Detection

**Status:** Accepted

## Context

Multiple WordPress plugins may bundle WPDI. If different versions load, class redeclaration errors or subtle bugs can occur.

## Decision

`src/version-check.php` runs before any class declarations. It defines `WPDI_VERSION` on first load and calls `wp_die()` with actionable guidance if a newer version tries to load after an older one. Newer-or-equal versions silently defer to the already-loaded version.

## Consequences

- Clear error message with solutions (scoper, update, mu-plugin)
- Version must be maintained in two places: `composer.json` and `src/version-check.php`
- Newer WPDI gracefully defers to already-loaded same-or-newer version
