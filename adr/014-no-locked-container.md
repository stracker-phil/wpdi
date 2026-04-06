# ADR-014: No Locked Container Mode

**Status:** Rejected

## Context

A "lock" flag was considered for the compiled cache: when a locked container file is present, the library would skip all reflection, ignore `wpdi-config.php`, and serve purely from the compiled artifact. The intent was a fully static, production-optimized mode with zero runtime overhead beyond array lookups.

## Decision

**Not implemented.** The locked container mode was rejected for the following reasons:

1. **Zero performance gain.** In production, `Cache_Manager` already skips staleness checks entirely. The only remaining work is a single `require` of the compiled PHP file and `load_config()` for bindings — both constant-time operations regardless of class count. Benchmarking a 500-class project showed no measurable difference.

2. **Additional complexity.** A lock flag introduces a new state (`locked` vs `unlocked`) that every code path must respect: CLI commands, cache rebuilds, config loading. The conditional logic and documentation burden outweigh the non-existent performance benefit.

3. **Footgun potential.** A developer who accidentally locks the container on a dev environment (or commits a locked cache without the matching source) creates a silent failure mode where code changes have no effect. Debugging this is non-obvious.

4. **Unlocked cache is self-healing.** The current design degrades gracefully: a stale or missing cache triggers a rebuild. A locked cache that becomes stale (e.g., after a deployment with new classes) would block the production site with no automatic recovery path.

## Consequences

- Production performance relies on the existing `environment()` check in `Cache_Manager` — no additional mechanism needed.
- `wp di compile` remains the recommended pre-deployment step, but forgetting it is not fatal.
- The compiled cache continues to be a complete deployable artifact (classes + bindings), just not a "locked" one.
