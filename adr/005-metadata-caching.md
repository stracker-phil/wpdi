# ADR-005: Metadata Caching Strategy

**Status:** Accepted

## Context

Closures cannot be serialized with `var_export()`, so caching factory functions directly is impossible. The cache must support incremental staleness detection without full filesystem scans.

## Decision

The `Compiler` caches class metadata (path, mtime, dependencies) as a plain PHP array in `cache/wpdi-container.php`. On load, `Container::load_compiled()` recreates autowiring factories from metadata. `Cache_Manager` performs incremental updates: only modified files are re-parsed, deleted files removed, new dependencies discovered transitively.

## Consequences

- Cache file is a simple `var_export()` array — no serialization issues
- Production: zero filesystem overhead after initial compile (`wp di compile`)
- Development: per-file mtime checks, not full directory scans
