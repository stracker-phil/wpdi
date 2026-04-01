# ADR-011: Config-Aware Resolution in `wp di depends`

**Status:** Amended (config mapping display extended — see bottom)

## Context

`wp di depends SomeClass` scans autowiring paths for classes whose constructors inject `SomeClass`
directly. However, in typical WPDI usage, a concrete class like `Randomizer` is rarely injected
directly — instead, it is bound to `RandomizerInterface` in `wpdi-config.php`, and all consumers
inject the interface. Running `wp di depends Randomizer` therefore showed no results even though
many classes effectively depend on it at runtime.

Two sub-problems were also identified:

1. **FQCN input for unloaded interfaces** — when a user passed a fully-qualified interface name
   (e.g. `'My\Plugin\Contracts\LoggerInterface'`) and that interface was not yet loaded in the
   current PHP process, the command incorrectly routed the FQCN through `resolve_short_name`,
   which compares only the trailing segment. This always failed to match, producing a misleading
   "Class or interface not found" error.

2. **Short name vs FQCN routing** — `resolve_short_name` is only meaningful for unqualified names
   (no `\`). A name that already contains a namespace separator is, by definition, already
   resolved.

## Decision

### FQCN handling

`__invoke` no longer calls `resolve_short_name` when the input contains a backslash. A name with
`\` is treated as a FQCN and passed directly to `find_dependents`. If it turns out not to exist,
`find_dependents` returns empty and the command reports "no dependents found" — a correct result.

Short names (no `\`) continue to go through `resolve_short_name` as before, and still error if
the name is ambiguous or absent.

### Config-bound interface lookup

`find_dependents` uses reflection to retrieve all interfaces the target class implements, then
intersects that list with the keys of `wpdi-config.php`. Any interface that both:
- is implemented by the target concrete class, **and**
- has an explicit entry in `wpdi-config.php` (meaning it is deliberately wired)

is treated as an additional search target. Classes that inject such an interface appear in the
results with a `via InterfaceName` annotation.

**Priority rule:** a direct injection match always wins over a via-interface match for the same
consuming class. Only one entry per consumer class is emitted.

**Config introspection constraint:** factory values in `wpdi-config.php` are opaque callables.
The command cannot determine what a factory resolves to at runtime. It only uses the *key
presence* in the config as signal that an interface is intentionally wired.

*(This constraint was partially lifted — see Amendment below.)*

```
Dependents of Randomizer (My\Plugin\Services):

MyController    class    $randomizer    My\Plugin\Controllers    via RandomizerInterface
SomeService     class    $rand          My\Plugin\Services       via RandomizerInterface
```

## Consequences

- `wp di depends ConcreteClass` surfaces all runtime consumers, whether they inject the class
  directly or via a config-bound interface
- The `via` annotation makes the indirection visible — users can see which interface is the
  actual type hint
- Interfaces that are *not* in the config are never surfaced (no false positives from standard
  PHP interfaces like `Countable` or `Traversable`)
- If a concrete class is rebound in the config (a different class is bound to the same interface
  later), the `via` results may be misleading — but this is an unusual pattern and the annotation
  still correctly identifies the interface key
- Contextual bindings (`array`-valued entries in the config) are included in the key scan, so a
  contextual interface also triggers via-lookup; which branch actually runs is not shown

## Amendment: Config Mapping Column

The original decision stated factory values are opaque. This was subsequently relaxed:
`build_config_mapping_label()` now resolves bindings and shows `as ConcreteClass` in the
`config mapping` column of the dependents table. The lookup priority is:

1. Indirect match (`via` set) → `via InterfaceName`
2. `$config[DependentClass]['$param']` → `as ConcreteClass` (per-dependent contextual)
3. `$config[TargetInterface]['$param']` → `as ConcreteClass` (interface-keyed param array)
4. `$config[TargetInterface]` as a non-array value → `as ConcreteClass` (simple global binding)
5. No binding found → `-`

Both string class names (`SomeClass::class`) and **typed closures** (`fn(): SomeClass => ...`)
are resolved. For typed closures, `ReflectionFunction::getReturnType()` is used to extract the
declared return type at definition time. Untyped closures and non-closure callables fall through
to `-`.

This means `wp di depends RandomizerInterface` correctly shows `as Randomizer` for all
consumers when the config has `RandomizerInterface::class => ['$randomizer' => fn():Randomizer => ...]`.
