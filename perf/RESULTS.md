# Core performance results (fork vs upstream v4.4.8)

Measured 2026-11-12 in Docker (PHP 8.4.27, Chromium 156), interleaved A/B against the untouched `v4.4.8` worktree.
All numbers are from the *synthetic* Orders Manage fixture (`perf/fixtures/orders-manage`, built by the empire-v2 investigator from
the real page's structure; 200 rows, 801 `x-data`, ~22.8k morph comments). Re-run: `perf/ab.sh perf/php/<Test>.php` (PHP),
`perf/js/run.sh` (browser). Patch: `perf/patches/0001-core-performance.patch` (+ new `src/Features/SupportSkipUnchanged/`).

## Server (PHP, one update round trip; lower is better)

| Benchmark | Baseline | Fork | Change |
|---|---|---|---|
| Fixture root update, 200 rows (`ping`) | 160 ms | 80 ms | **-50%** |
| Fixture root property update | 160 ms | 80 ms | -51% |
| Fixture initial mount, 200 rows | 165 ms | 86 ms | -48% |
| Computed-heavy table, 200 rows | 75 ms | 14 ms | -81% |
| One `$this->computed` read | 8.1 us | 0.75 us | -91% |
| Child component action (1 model-heavy row) | 1.08 ms | 1.04 ms | -4% |
| Counter component (fixed per-component cost) | 0.48 ms | 0.44 ms | -8.5% |
| 30 child updates in one request | 33.0 ms | 32.2 ms | -2.5% |

Changes: (1) `SupportLegacyComputedPropertySyntax::findComputedAttribute` builds a name->attribute map once per component
(WeakMap keyed by its AttributeCollection) instead of filtering and camel-casing every attribute on every `$this->x` read;
(2) `trigger()`/`on()`/... resolve the EventBus once per container, and `EventBus::trigger` avoids array merges and shares a
pass-through closure; (3) `ComponentHookRegistry` only dispatches an event to hooks that implement it (every hook class is still
instantiated: `SupportStreaming` has no lifecycle methods and is used through `getHook()`).
Not worth more effort: the fixed per-component floor (~0.45 ms) and child updates are dominated by Blade/Eloquent.

## Client (browser main thread; lower is better; n=21 per side)

| Scenario | Baseline | Fork | Change |
|---|---|---|---|
| `ping` (identical re-render), morph done | 359 ms | 115 ms | **-68%** |
| `select` (3 checkboxes), morph done | 403 ms | 131 ms | -68% |
| `expand10` (10 lazy children), morph done | 412 ms | 137 ms | -67% |
| `sort` (all 200 rows move), morph done | 571 ms | 313 ms | -45% |
| Initial load, main-thread task time | 1108 ms | 816 ms | -26% |
| Initial load, long-task time | 839 ms | 545 ms | -35% |

Final DOM is identical to baseline in all five states, ignoring Alpine's `data-has-alpine-state` marker (Alpine's morph adds it to
`x-data` elements; it is only read while cloning).
Change: `js/morph.js` skips keyed elements (no keyed descendants) whose server HTML is byte-identical to what was last applied.
Signatures are taken from the pristine incoming DOM (Alpine's morph mutates it) and, for the initial page, before Alpine starts
(`seedServerSignatures()` in `lifecycle.js`). Kill switch: `config('livewire.morph_skip_unchanged', true)`.
Behaviour change to know about: edits made to an unchanged keyed subtree by something other than Alpine/Livewire are no longer reset.

## Other measurements (not shipped)
- `inject_morph_markers=false` (an app config option) cuts client time another ~16-22% on this fixture (22.8k comment nodes).
- `findComponentByEl` is only 3-8% of an interaction here (the app profile suggested more; DOM shape differs).
- About half of each interaction is native (HTML parse, DOM), not JS.

## Verification
Unit: 1535 tests pass. Browser: 511 tests pass across 45 feature groups (morph, islands, slots, ignore, nesting, transitions,
sort, entangle, lazy, teleport, loading, current, polling, data binding, query string, navigate, events, validation, streaming, ...).
New: `src/Features/SupportSkipUnchanged/{UnitTest,BrowserTest}.php`.
A first draft that skipped instantiating hooks with no lifecycle methods broke `SupportStreaming` (found by the browser tests, reverted).
