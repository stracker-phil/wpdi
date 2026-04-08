# ADR-015: CLI Commands Use Cache_Manager Pipeline

**Status:** Accepted

## Context

The WP-CLI commands (`list`, `inspect`, `depends`) each created their own `Auto_Discovery` instances and performed full filesystem discovery and reflection on every invocation. For a 15-class project this took 2-3 seconds per command — even when the compiled container cache already contained all the data.

An initial approach of having CLI commands read the cache file directly was rejected: it introduced shadow logic that diverged from the runtime path. When `Cache_Manager` internals change (staleness detection, cache format, rebuild triggers), CLI commands would not automatically pick up those changes.

## Decision

CLI commands use `Cache_Manager::get_cache()` — the identical pipeline that `Scope::__construct()` uses at runtime. No command creates its own `Auto_Discovery` for data collection. The framework's cache management layer is the single source of truth.

### How it works

`Command` base class gained a `load_module_cache(string $path, array $autowiring_paths)` method that:

1. Loads `wpdi-config.php` bindings (same as `Scope::__construct()`)
2. Creates `Cache_Store`, `Auto_Discovery`, and `Cache_Manager` (same collaborator graph)
3. Calls `Cache_Manager::get_cache('', $config_bindings)` — empty scope file since CLI has none
4. Stores the result in `$this->cache_data`

`load_module_cache()` always passes `'development'` as the environment to `Cache_Manager`, **regardless of `wp_get_environment_type()`**. WordPress defaults `wp_get_environment_type()` to `'production'` when `WP_ENVIRONMENT_TYPE` is not defined — which would cause `Cache_Manager` to skip staleness checks and silently return stale data. CLI commands are developer tools; they must always detect and refresh stale caches.

This means the CLI commands get the same behavior as runtime:
- **No cache exists:** full rebuild (discovery + reflection) — happens once, cache is persisted.
- **Cache is stale:** incremental mtime-based update — only modified files are re-parsed.
- **Cache is fresh:** only N `file_exists()` + `filemtime()` checks — near-instant.

### What changed

**`Command` base class:**
- `load_module_cache()` instantiates `Cache_Manager` and calls `get_cache()`.
- `get_constructor_param_map()` and `get_full_param_map()` check `$this->cache_data` first, falling back to reflection for classes not in the cache (e.g., external dependencies).
- `resolve_short_name()` reads from `$this->cache_data` — no standalone `Auto_Discovery`.

**`List_Command`:** reads `cache_data['classes']` and `cache_data['bindings']` directly. No discovery fallback.

**`Inspect_Command` and `Depends_Command`:** call `load_module_cache()` at the start. Tree building and dependent scanning read from cached data. Reflection is only used for classes outside the module's autowiring paths.

**`Compile_Command`:** always does fresh standalone discovery (does not use `Cache_Manager`). It has no `--force` flag — full regeneration is unconditional. See the amendment below.

## Consequences

- CLI commands follow the same code path as the runtime — no divergence.
- Cache format changes, staleness fixes, or new rebuild triggers in `Cache_Manager` automatically apply to CLI commands.
- First CLI run on a project without a cache triggers a full rebuild (same as the first HTTP request); subsequent runs are near-instant when files haven't changed.
- The `compile` command remains the explicit "build for production" step; the other commands create/update the cache implicitly as a side effect of `Cache_Manager`.

## Amendment: compile always regenerates; --force removed

**Status:** Amended

`Compile_Command` previously accepted a `--force` flag to overwrite an existing cache, and would otherwise warn and exit early. This was removed because the flag was always necessary in practice and was misleading:

When any WP-CLI command runs, `Scope` bootstraps the container — which runs `Cache_Manager::get_cache()`, which writes (or updates) the cache file. By the time `Compile_Command.__invoke()` executes, the cache already exists because the bootstrap already created it. A guard that exits when the cache exists would cause `wp di compile` (without `--force`) to always warn and exit, making the flag mandatory but non-obvious.

The root cause is that `Scope` bootstrap and `compile` both write to the same file. The correct fix is to make `compile` unconditionally overwrite — its purpose is to do a full, clean rebuild for production, and that semantics should not require a flag.

`--force` is removed from the synopsis, docs, and implementation. `compile` now always regenerates the full cache.
