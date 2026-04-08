# ADR-012: CLI Command Output Centralization in `Command` Base Class

**Status:** Accepted

## Context

The WP-CLI commands (`compile`, `list`, `inspect`, `depends`, `clear`) each needed consistent terminal output: box-drawing tables, colorized class names, typed headers, and dependency trees. Without a shared abstraction, each command duplicated output logic independently — helper methods like `get_short_class_name`, `format_type_label`, and `get_type_color` were copy-pasted across files, and the visual style differed subtly between commands (`depends` lacked a `Path:` header that `inspect` showed; namespace coloring used incompatible tokens in different places).

## Decision

All rendering logic lives in `src/Commands/Command.php`, an abstract base class that every WP-CLI command extends. Commands are responsible only for data collection; they call parent methods to produce output. Changing a rendering method in `Command` affects all commands uniformly.

### Shared rendering API

`Command` provides:

- **`table(array $items, array $fields, array $types, string $title, array $separators)`** — renders a bordered table. Column widths are computed from raw (uncolored) values using `mb_strlen()` so multi-byte UTF-8 characters (tree connectors, box chars) are measured by display width, not byte count. An optional `$title` prepends a full-width spanning row above the column headers. An optional `$separators` array lists row indices before which an extra mid-border line is emitted (used by `render_tree()` to separate depth-1 groups).

- **`render_tree(array $rows)`** — reshapes dependency-tree rows (prefix, label, type, fqcn) into a `{param, type, class}` table and delegates to `table()`. Depth-1 nodes (direct dependencies of the inspected class) are treated specially: their tree connector is stripped and replaced with a 1-space indent, and a mid-border separator is injected between each depth-1 group via `$separators`. For depth-2+ nodes the depth-1 continuation segment (first 4 display chars) is collapsed to a single space, keeping total left-margin at 2 characters from the cell border.

- **`log_class_header(string $class_name, string $type, string $base_path)`** — consistent header for commands that target a specific class: `Path: <relative-path>` followed by `(colored-type) Namespace\(colored-leaf)`.

- **`parse_format_flag(array $assoc_args)`** — reads `--format=ascii` from WP-CLI args and sets `$this->ascii = true`. Every command calls this at the start of `__invoke()`.

- **`tree_connector(bool $is_last)` / `tree_indent(bool $is_last)`** — return 4-char tree-drawing strings (`├── ` / `└── ` / `│   ` / `    `) in Unicode mode, or their ASCII equivalents (`+-- ` / `|   `) when `$this->ascii` is set. Used by `Inspect_Command::collect_tree_rows()`.

- **`get_constructor_param_map(string $class_name)`** — returns only class/interface-typed constructor parameters (used by `depends` for reverse-dependency lookups).

- **`get_full_param_map(string $class_name)`** — returns all constructor parameters including built-in types (`string`, `bool`, `array`, `callable`, etc.) and untyped params (`mixed`). Each entry is `['type' => string, 'builtin' => bool]`. Used by `inspect` to show the complete constructor signature; built-in types render as red `builtin` leaf nodes in the dependency tree.

- **`format_class_name`, `get_short_class_name`, `get_namespace`, `format_type_label`, `get_type_color`, `parse_autowiring_paths`** — shared FQCN utilities and type helpers. `get_type_color()` maps `'builtin'` to `%r` (red) to flag non-autowirable parameters.

- A shared `Class_Inspector $inspector` instance initialized in `__construct()` so commands need no constructor of their own.

### Format identifier system

`table()` accepts a `$types` map of `field => format_identifier`. Format identifiers are strings, not callables. `apply_cell_format()` dispatches on them:

| Identifier      | Behaviour |
|-----------------|-----------|
| `class_name`    | Colorizes FQCN leaf by type (reads `$item['type']`) |
| `class_fqcn`    | Like `class_name` but also detects and colors `[CIRCULAR]` in red |
| `class_binding` | Like `class_name` but reads `$item['binding_type']` — used where a row has two distinct class columns each with their own type (e.g. the `binding` column in `compile`) |
| `type_label`    | Colors a normalized type label with its type color |
| `via`           | Colors the class/short-name portion in a `via Name` or `as Name` string cyan |
| `bool`          | Colors false-like values (`no`, `false`, `0`) red |
| `param`         | Colors every `$word` token in the cell yellow |

Type values stored in rows must be pre-normalized labels (`'class'`, not `'concrete'`) before being passed to `table()`, so that `class_name` and `type_label` formats receive consistent input.

### ASCII output mode

All output-producing commands support `--format=ascii`. When set, `table()` substitutes `+-|` characters for the Unicode box-drawing set (`┌─│┬┼┴├┤└┐┘`). `tree_connector()` and `tree_indent()` similarly return `+-- ` and `|   ` instead of their Unicode equivalents. The `'param'` column in the inspect/depends tree respects ASCII mode automatically because the prefix strings are built from those helpers.

### Unicode box-drawing characters

Box-drawing characters are written as UTF-8 literals (`┌ ─ │ ┬ ┼ ┴ ├ ┤ └ ┐ ┘`) directly in source, not as hex escapes (`\xE2\x94\x80`). This is readable and unambiguous; PHP source files are already declared UTF-8 via the project's `declare(strict_types=1)` convention.

## Consequences

- Adding a new WP-CLI command requires only data collection; all formatting is inherited.
- Restyling any output element (colors, borders, column layout) requires one change in `Command`.
- The `table()` method handles multi-byte prefix strings correctly, so dependency trees with `├──` / `└──` connectors align properly regardless of tree depth.
- The `$types` format identifier registry is closed (switch statement) — adding a new format requires editing `apply_cell_format()` in `Command`, which is acceptable given the small, stable set of display patterns across these commands.
- All four output-producing commands (`list`, `inspect`, `depends`, `compile`) support `--format=ascii` for terminals or CI environments that cannot render Unicode box-drawing chars.
- The `render_tree()` depth-1 separator design means the inspect tree visually groups each top-level dependency with its subtree, separated by mid-border lines. This is intentional and should be preserved in any future tree rendering changes.
