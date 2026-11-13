# Core performance results (fork vs upstream v4.4.8)

Measured 2026-11-12 in Docker (PHP 8.4.27, Chromium 156), interleaved A/B against the untouched `v4.4.8` worktree.
All numbers are from the *synthetic* Orders Manage fixture (`perf/fixtures/orders-manage`, built by the empire-v2 investigator from
the real page's structure; 200 rows, 801 `x-data`, ~22.8k morph comments). Re-run: `perf/ab.sh perf/php/<Test>.php` (PHP),
`perf/js/run.sh` (browser). Changes live on branch `perf/core-performance` (new feature folder `src/Features/SupportSkipUnchanged/`).

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

## Real app (empire-v2 Orders Manage, 200 rows; synthetic seed; reports in codemap/real-app-ab.md, real-app-profile.md, app-side-ab.md)

- **Unmodified mainline: fork == upstream within +-3%.** The skip never fires because cells render `uniqid()` per render (`orders-manage.blade.php:52,72`) and the table root `wire:key` contains every row id. Server time (TTFB) is flat in every arm: the update request is dominated by app queries and Blade.
- **With the first two app fixes (deterministic ids, stable root key), fork vs the same app on upstream:** sort -55%, expand-one -51%, row-set -61%, expand-all -30% (main-thread LoAF). Versus the unmodified app: sort -53%, expand-one -57%, row-set -76%, expand-all -36%. At 4x CPU throttle: sort -57%, expand-one -56%.
- **App-side only (upstream Livewire), cumulative vs unmodified:** deterministic ids -3/-10/-3 (sort/expand-one/row-set); + stable root key +3/-12/-37; + markers off -9/-28/-45; + shared Alpine expressions -8/-26/-45.
- Real page profile (upstream): initial load is native 46%, Alpine core 22%, Livewire 11%; 2,386 unique x-data expressions (1,867 embed row ids) so Alpine's expression cache never hits; sort cost is Alpine initTree on replaced rows (351 ms) while Livewire's morph is 84 ms.
- Fork bug found by the A/B and fixed in 9b60ace1: a keyed element that was a leaf, then a container, then a leaf again kept a stale signature, so closing a row was skipped and the lazy child stayed mounted (200 orphans after Collapse All). Re-verified: orphans 0, DOM fingerprints equal to upstream, same test results (F2 348/348 Feature + 18/18 browser; F4 has the same 4 OrderColumnHighlightsTest failures as U4, which pin the old markup).
- Known behaviour difference (by design): edits made by something other than Alpine/Livewire to an unchanged keyed row persist (probe: upstream removes a `data-probe` attribute on the next morph, the fork keeps it; `morph_skip_unchanged=false` restores the reset). Example seen: after hiding and showing a column, a date cell reads empty in both arms; upstream's next re-render restores the server text, the fork leaves it empty until the row's HTML changes.
- Prerequisite for the fork to help a page: deterministic per-render markup (no `uniqid()`/random ids in rows) and keyed rows. Anything that varies per render defeats the skip. Without these the fork changes nothing.
- Caveat: absolute numbers come from a PHP built-in server and a local Playwright Chromium, not Octane/CloudFront/production hardware.
