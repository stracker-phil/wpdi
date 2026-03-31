# ADR-007: DDEV for Local Development

**Status:** Accepted

## Context

The library must be tested against PHP 7.4 specifically. A containerized environment ensures consistent PHP version and extensions across developer machines.

## Decision

DDEV configured with PHP 7.4, Apache, MariaDB 10.11, Xdebug enabled. All dev commands run via `ddev exec` or `ddev composer`. Config at `.ddev/config.yaml`.

## Consequences

- Guaranteed PHP 7.4 test environment regardless of host PHP version
- All commands prefixed with `ddev` (e.g., `ddev composer test`)
- Requires Docker and DDEV installed locally
