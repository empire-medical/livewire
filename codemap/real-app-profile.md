# Where main-thread time goes on the real Orders Manage page (unmodified mainline, upstream Livewire 4.4.8, arm A)

Run 2026-11-12/13. Read-only profiling pass in the throwaway worktree `/home/endl/empire-v2-fork-ab` (detached at `origin/livewire-poc` 9ed0ba5d9c, vendor/livewire/livewire = upstream 4.4.8). Seeded/synthetic data only; nothing from production; no real customer data appears below.

## How this was captured

- **Profiler**: Chromium 1248 (Playwright 1.64.1), CDP `Profiler.setSamplingInterval(100us)`, headless, a SEPARATE pass from the timing runs (profiler overhead inflates JS time; treat absolute ms as relative, not as the A/B numbers in real-app-ab.md). Script: `profile/profile.js`; analysis: `profile/analyze.js` (cpuprofile -> per-function self time, inclusive time, categories). Raw `.cpuprofile` files (open in Chrome DevTools): `/home/endl/empire-v2-fork-ab/profile/A/A-{load,sort,expand}-{1,2,3}.cpuprofile`.
- **App server for this pass**: PHP built-in server (`serve-profile.sh`, 4 workers, `APP_ENV=local`, file sessions) rather than the Pest in-process server, because the Pest plugin does not expose CDP. Same DB/schema `fork_ab_test`, vendor 346 padded to 225 open lines, `perPage=200`, admin user, real login through the form. 3 runs per step, each in a fresh browser context; numbers below are the mean of the 3 (per-function min/max kept in the JSON).
- **Bundle**: arm A (upstream) built UNMINIFIED (`vite build --minify false`, `build-A-unmin/`), so function names are real. Positions are mapped bundle -> `dist/livewire.esm.js` (the Vite map) -> original files through `livewire.esm.js.map` (the second hop gives `js/*.js` Livewire sources and `node_modules/alpinejs`, `@alpinejs/morph`, `@alpinejs/anchor` Alpine sources, as they were when Livewire's dist was built; line numbers are in those original files).
- **Steps**: (1) initial load = Profiler start before `goto`, stop 3 s after 200 rows are present; (2) sort = click a sortable header (whole table root is replaced on mainline, see real-app-ab.md), until Livewire is quiet 2.5 s; (3) expand-one = click the expand icon of row 6 incl. the lazy child load, until quiet 2.5 s. The profile window contains idle time (waiting for the server and the quiet period): `busy` below = window minus `(idle)`.
- **Page**: 200 rows, DOM 12,105 elements. `[x-data]` count = **2376** (not ~800: 2200 of them are inside `<tbody>`, i.e. about 11 per row on this mainline markup; see below).

## Initial load

Profile window 4353 ms, of which busy (not idle) 1634 ms (means of 3 runs, profiler on).

### Self time by category (additive; sums to the busy time)

| Category | self ms | % of busy |
|---|---|---|
| native: (program) = parse/style/layout/paint/other | 747 | 46% |
| (a) Alpine core (initTree, directives, evaluator, reactivity) | 361 | 22% |
| (c) Livewire code | 173 | 11% |
| native: builtins (JSON.parse, innerHTML, DOM, regexp ...) | 141 | 9% |
| (a2) Alpine plugin @alpinejs/anchor | 117 | 7% |
| native: GC | 64 | 4% |
| (e) app JS / Alpine plugins | 6 | 0% |
| (g) third-party libs | 5 | 0% |
| (f) other: boot.js(unmapped) | 2 | 0% |
| (f) other: http://127.0.0.1:8099 | 2 | 0% |

### Inclusive time of key entry points (self+callees, outermost occurrence; NOT additive: they nest)

| Entry point | ms |
|---|---|
| Alpine initTree (outermost) | 730 |
| Alpine evaluate/evaluator (outermost; x-data/x-bind/x-on expression compile+run) | 186 |
| Alpine morph plugin: any function in @alpinejs/morph (outermost) | 0 |
| Livewire js/morph.js `morph` (outermost) | 0 |
| Livewire any function in livewire code (outermost) | 755 |
| Livewire mergeNewSnapshot | 7 |
| Livewire findComponentByEl | 71 |
| Livewire interceptInit / initialize/ component init | 7 |
| Livewire getUpdates | 1 |
| Livewire processEffects | 3 |
| Livewire trigger() under morph `updating` callback (= time in morph.updating listeners, incl. app hooks) | 0 |
| Livewire trigger() under morph `updated`/`added`/`removed` callbacks | 0 |
| app JS (resources/js) inclusive (outermost) | 50 |

### Top 30 by self time (mean ms per run; min-max over 3 runs)

| # | ms (min-max) | function | original source:line | category |
|---|---|---|---|---|
| 1 | 746.7 (720.6-768.9) | `(program)` | (native) | native: (program) = |
| 2 | 105.2 (100.8-108.8) | `isOverflowElement` | @alpinejs/anchor/dist/module.cjs.js:595 | (a2) Alpine plugin |
| 3 | 71.5 (66.4-80.5) | `construct` | (native):7 | native: builtins (JSON.parse, |
| 4 | 64.0 (61.1-67.2) | `(garbage collector)` | (native) | native: GC |
| 5 | 62.6 (60.1-64.7) | `(anonymous)` | alpinejs/dist/module.cjs.js:3145 | (a) Alpine core |
| 6 | 31.0 (27.9-33.0) | `walkBackwards` | js/utils.js:545 | (c) Livewire code |
| 7 | 18.9 (17.7-20.2) | `isEndFragmentMarker` | js/fragment.js:75 | (c) Livewire code |
| 8 | 17.6 (15.5-19.0) | `recurse` | alpinejs/dist/module.cjs.js:3076 | (a) Alpine core |
| 9 | 15.9 (14.3-17.0) | `injectMagics` | alpinejs/dist/module.cjs.js:3143 | (a) Alpine core |
| 10 | 14.9 (13.9-15.6) | `(anonymous)` | js/features/supportWireSort.js:16 | (c) Livewire code |
| 11 | 14.8 (7.7-19.1) | `flushHandlers` | alpinejs/dist/module.cjs.js:3387 | (a) Alpine core |
| 12 | 14.0 (12.0-15.5) | `(anonymous)` | js/features/supportWireIntersect.js:6 | (c) Livewire code |
| 13 | 13.3 (12.7-13.8) | `(anonymous)` | alpinejs/dist/module.cjs.js:4524 | (a) Alpine core |
| 14 | 13.0 (12.0-13.8) | `(anonymous)` | js/lifecycle.js:66 | (c) Livewire code |
| 15 | 13.0 (10.7-16.6) | `injectDataProviders` | alpinejs/dist/module.cjs.js:4523 | (a) Alpine core |
| 16 | 12.4 (9.5-14.2) | `isStartFragmentMarker` | js/fragment.js:71 | (c) Livewire code |
| 17 | 12.1 (10.0-13.4) | `directives` | alpinejs/dist/module.cjs.js:3354 | (a) Alpine core |
| 18 | 11.0 (8.7-13.5) | `(anonymous)` | (native):1 | native: builtins (JSON.parse, |
| 19 | 9.0 (8.5-9.7) | `closestDataStack` | alpinejs/dist/module.cjs.js:3004 | (a) Alpine core |
| 20 | 8.4 (6.5-10.2) | `querySelector` | (native) | native: builtins (JSON.parse, |
| 21 | 8.3 (7.7-9.5) | `(anonymous)` | alpinejs/dist/module.cjs.js:3438 | (a) Alpine core |
| 22 | 7.9 (7.1-8.6) | `walk` | alpinejs/dist/module.cjs.js:3497 | (a) Alpine core |
| 23 | 7.8 (6.8-8.9) | `(anonymous)` | alpinejs/dist/module.cjs.js:3035 | (a) Alpine core |
| 24 | 7.7 (7.3-8.3) | `(anonymous)` | alpinejs/dist/module.cjs.js:3517 | (a) Alpine core |
| 25 | 7.2 (6.5-7.8) | `(anonymous)` | alpinejs/dist/module.cjs.js:5272 | (a) Alpine core |
| 26 | 6.7 (6.3-7.1) | `(anonymous)` | alpinejs/dist/module.cjs.js:3263 | (a) Alpine core |
| 27 | 6.6 (6.1-7.0) | `getTrueOffsetParent` | @alpinejs/anchor/dist/module.cjs.js:1009 | (a2) Alpine plugin |
| 28 | 6.1 (5.9-6.4) | `querySelectorAll` | (native) | native: builtins (JSON.parse, |
| 29 | 5.8 (4.5-7.4) | `(anonymous)` | js/directives/wire-navigate.js:30 | (c) Livewire code |
| 30 | 5.7 (5.1-6.0) | `alpineAttributeRegex` | alpinejs/dist/module.cjs.js:3454 | (a) Alpine core |

Native self time breakdown (top builtins): `(program)` 747 ms, `construct` 71 ms, `(garbage collector)` 64 ms, `(anonymous)` 11 ms, `querySelector` 8 ms, `querySelectorAll` 6 ms, `setTimeout` 5 ms, `queueMicrotask` 4 ms

## Sort click

Profile window 4972 ms, of which busy (not idle) 896 ms (means of 3 runs, profiler on).

### Self time by category (additive; sums to the busy time)

| Category | self ms | % of busy |
|---|---|---|
| (a) Alpine core (initTree, directives, evaluator, reactivity) | 225 | 25% |
| native: (program) = parse/style/layout/paint/other | 224 | 25% |
| (c) Livewire code | 170 | 19% |
| native: builtins (JSON.parse, innerHTML, DOM, regexp ...) | 116 | 13% |
| native: GC | 41 | 5% |
| (b) Alpine morph plugin | 9 | 1% |
| (e) app JS / Alpine plugins | 2 | 0% |

### Inclusive time of key entry points (self+callees, outermost occurrence; NOT additive: they nest)

| Entry point | ms |
|---|---|
| Alpine initTree (outermost) | 350 |
| Alpine evaluate/evaluator (outermost; x-data/x-bind/x-on expression compile+run) | 83 |
| Alpine morph plugin: any function in @alpinejs/morph (outermost) | 44 |
| Livewire js/morph.js `morph` (outermost) | 87 |
| Livewire any function in livewire code (outermost) | 228 |
| Livewire mergeNewSnapshot | 6 |
| Livewire findComponentByEl | 63 |
| Livewire interceptInit / initialize/ component init | 0 |
| Livewire getUpdates | 1 |
| Livewire processEffects | 0 |
| Livewire trigger() under morph `updating` callback (= time in morph.updating listeners, incl. app hooks) | 0 |
| Livewire trigger() under morph `updated`/`added`/`removed` callbacks | 0 |
| app JS (resources/js) inclusive (outermost) | 17 |

### Top 30 by self time (mean ms per run; min-max over 3 runs)

| # | ms (min-max) | function | original source:line | category |
|---|---|---|---|---|
| 1 | 223.5 (217.0-229.3) | `(program)` | (native) | native: (program) = |
| 2 | 41.3 (38.5-42.9) | `(anonymous)` | alpinejs/dist/module.cjs.js:3145 | (a) Alpine core |
| 3 | 40.8 (39.3-43.3) | `(garbage collector)` | (native) | native: GC |
| 4 | 39.0 (36.4-40.4) | `morph2` | js/morph.js:7 | (c) Livewire code |
| 5 | 29.5 (28.7-30.9) | `walkBackwards` | js/utils.js:545 | (c) Livewire code |
| 6 | 17.2 (16.3-18.0) | `isEndFragmentMarker` | js/fragment.js:75 | (c) Livewire code |
| 7 | 15.8 (13.4-18.3) | `construct` | (native):7 | native: builtins (JSON.parse, |
| 8 | 13.0 (12.4-13.3) | `cloneNode` | (native) | native: builtins (JSON.parse, |
| 9 | 12.3 (10.8-15.0) | `recurse` | alpinejs/dist/module.cjs.js:3076 | (a) Alpine core |
| 10 | 12.2 (12.1-12.4) | `querySelector` | (native) | native: builtins (JSON.parse, |
| 11 | 12.1 (11.1-12.6) | `isStartFragmentMarker` | js/fragment.js:71 | (c) Livewire code |
| 12 | 11.6 (9.8-12.7) | `(anonymous)` | js/features/supportWireIntersect.js:6 | (c) Livewire code |
| 13 | 10.0 (8.5-11.5) | `(anonymous)` | alpinejs/dist/module.cjs.js:4524 | (a) Alpine core |
| 14 | 9.1 (8.2-9.5) | `remove` | (native) | native: builtins (JSON.parse, |
| 15 | 9.0 (8.4-9.5) | `(anonymous)` | js/features/supportWireSort.js:16 | (c) Livewire code |
| 16 | 8.9 (7.4-9.9) | `directives` | alpinejs/dist/module.cjs.js:3354 | (a) Alpine core |
| 17 | 8.8 (8.8-9.0) | `injectDataProviders` | alpinejs/dist/module.cjs.js:4523 | (a) Alpine core |
| 18 | 8.6 (6.6-10.0) | `(anonymous)` | js/lifecycle.js:66 | (c) Livewire code |
| 19 | 8.4 (7.7-8.9) | `(anonymous)` | (native):1 | native: builtins (JSON.parse, |
| 20 | 8.0 (7.5-8.2) | `elementsFromPoint` | (native) | native: builtins (JSON.parse, |
| 21 | 7.5 (6.8-8.2) | `closestDataStack` | alpinejs/dist/module.cjs.js:3004 | (a) Alpine core |
| 22 | 7.4 (7.2-7.6) | `walk` | alpinejs/dist/module.cjs.js:3497 | (a) Alpine core |
| 23 | 6.8 (6.4-7.3) | `querySelectorAll` | (native) | native: builtins (JSON.parse, |
| 24 | 6.6 (5.7-7.4) | `injectMagics` | alpinejs/dist/module.cjs.js:3143 | (a) Alpine core |
| 25 | 5.2 (4.9-5.6) | `sendRequest` | js/request/index.js:536 | (c) Livewire code |
| 26 | 5.2 (4.8-5.6) | `query` | (native):5816 | native: builtins (JSON.parse, |
| 27 | 5.1 (4.5-5.9) | `(anonymous)` | alpinejs/dist/module.cjs.js:4877 | (a) Alpine core |
| 28 | 4.7 (4.2-4.9) | `(anonymous)` | alpinejs/dist/module.cjs.js:5272 | (a) Alpine core |
| 29 | 4.6 (4.3-5.3) | `(anonymous)` | alpinejs/dist/module.cjs.js:3438 | (a) Alpine core |
| 30 | 4.3 (3.4-4.8) | `(anonymous)` | alpinejs/dist/module.cjs.js:3035 | (a) Alpine core |

Native self time breakdown (top builtins): `(program)` 224 ms, `(garbage collector)` 41 ms, `construct` 16 ms, `cloneNode` 13 ms, `querySelector` 12 ms, `remove` 9 ms, `(anonymous)` 8 ms, `elementsFromPoint` 8 ms

## Expand one row (incl. lazy child)

Profile window 5480 ms, of which busy (not idle) 3649 ms (means of 3 runs, profiler on).

### Self time by category (additive; sums to the busy time)

| Category | self ms | % of busy |
|---|---|---|
| native: (program) = parse/style/layout/paint/other | 2575 | 71% |
| (a) Alpine core (initTree, directives, evaluator, reactivity) | 226 | 6% |
| (b) Alpine morph plugin | 172 | 5% |
| (c) Livewire code | 168 | 5% |
| (a2) Alpine plugin @alpinejs/anchor | 152 | 4% |
| native: builtins (JSON.parse, innerHTML, DOM, regexp ...) | 144 | 4% |
| native: GC | 37 | 1% |
| (g) third-party libs | 7 | 0% |
| (e) app JS / Alpine plugins | 5 | 0% |

### Inclusive time of key entry points (self+callees, outermost occurrence; NOT additive: they nest)

| Entry point | ms |
|---|---|
| Alpine initTree (outermost) | 428 |
| Alpine evaluate/evaluator (outermost; x-data/x-bind/x-on expression compile+run) | 78 |
| Alpine morph plugin: any function in @alpinejs/morph (outermost) | 471 |
| Livewire js/morph.js `morph` (outermost) | 526 |
| Livewire any function in livewire code (outermost) | 574 |
| Livewire mergeNewSnapshot | 8 |
| Livewire findComponentByEl | 3 |
| Livewire interceptInit / initialize/ component init | 1 |
| Livewire getUpdates | 2 |
| Livewire processEffects | 1 |
| Livewire trigger() under morph `updating` callback (= time in morph.updating listeners, incl. app hooks) | 3 |
| Livewire trigger() under morph `updated`/`added`/`removed` callbacks | 5 |
| app JS (resources/js) inclusive (outermost) | 23 |

### Top 30 by self time (mean ms per run; min-max over 3 runs)

| # | ms (min-max) | function | original source:line | category |
|---|---|---|---|---|
| 1 | 2574.8 (2526.0-2671.6) | `(program)` | (native) | native: (program) = |
| 2 | 99.7 (94.8-103.4) | `isOverflowElement` | @alpinejs/anchor/dist/module.cjs.js:595 | (a2) Alpine plugin |
| 3 | 49.5 (45.4-52.6) | `morph2` | js/morph.js:7 | (c) Livewire code |
| 4 | 36.7 (26.1-48.5) | `(garbage collector)` | (native) | native: GC |
| 5 | 31.3 (23.4-37.5) | `getBoundingClientRect` | (native) | native: builtins (JSON.parse, |
| 6 | 30.5 (28.7-32.2) | `context.patchAttributes` | @alpinejs/morph/dist/module.cjs.js:121 | (b) Alpine morph |
| 7 | 28.3 (26.6-29.1) | `(anonymous)` | alpinejs/dist/module.cjs.js:3145 | (a) Alpine core |
| 8 | 28.1 (23.7-32.5) | `findClosest` | alpinejs/dist/module.cjs.js:3729 | (a) Alpine core |
| 9 | 21.3 (14.3-25.0) | `directives` | alpinejs/dist/module.cjs.js:3354 | (a) Alpine core |
| 10 | 20.3 (18.8-21.5) | `context.patchChildren` | @alpinejs/morph/dist/module.cjs.js:150 | (b) Alpine morph |
| 11 | 19.5 (16.8-21.7) | `(anonymous)` | js/features/supportWireIntersect.js:6 | (c) Livewire code |
| 12 | 19.5 (18.4-20.5) | `getNextSibling` | @alpinejs/morph/dist/module.cjs.js:375 | (b) Alpine morph |
| 13 | 15.6 (14.8-16.7) | `construct` | (native):7 | native: builtins (JSON.parse, |
| 14 | 15.2 (14.2-16.6) | `context.differentElementNamesTypesOrKeys` | @alpinejs/morph/dist/module.cjs.js:102 | (b) Alpine morph |
| 15 | 15.0 (13.2-16.5) | `(anonymous)` | js/lifecycle.js:108 | (c) Livewire code |
| 16 | 14.9 (14.2-15.9) | `(anonymous)` | js/features/supportWireSort.js:16 | (c) Livewire code |
| 17 | 13.0 (12.5-13.5) | `context.keyToMap` | @alpinejs/morph/dist/module.cjs.js:294 | (b) Alpine morph |
| 18 | 12.0 (10.3-15.3) | `(anonymous)` | alpinejs/dist/module.cjs.js:4877 | (a) Alpine core |
| 19 | 11.9 (10.6-12.6) | `shouldSkipChildren` | @alpinejs/morph/dist/module.cjs.js:324 | (b) Alpine morph |
| 20 | 11.4 (10.9-11.8) | `isIf` | @alpinejs/morph/dist/module.cjs.js:188 | (b) Alpine morph |
| 21 | 10.4 (9.5-11.7) | `isContainingBlock` | @alpinejs/anchor/dist/module.cjs.js:607 | (a2) Alpine plugin |
| 22 | 10.2 (9.2-11.5) | `getTrueOffsetParent` | @alpinejs/anchor/dist/module.cjs.js:1009 | (a2) Alpine plugin |
| 23 | 10.1 (9.4-10.8) | `newSetAttribute` | @alpinejs/morph/dist/module.cjs.js:390 | (b) Alpine morph |
| 24 | 9.7 (8.5-10.6) | `hasAttribute` | (native) | native: builtins (JSON.parse, |
| 25 | 9.1 (6.7-10.5) | `(anonymous)` | @alpinejs/anchor/dist/module.cjs.js:610 | (a2) Alpine plugin |
| 26 | 9.0 (7.4-10.1) | `context.patch` | @alpinejs/morph/dist/module.cjs.js:74 | (b) Alpine morph |
| 27 | 8.5 (7.2-9.6) | `(anonymous)` | (native):1 | native: builtins (JSON.parse, |
| 28 | 8.4 (7.3-8.9) | `elementsFromPoint` | (native) | native: builtins (JSON.parse, |
| 29 | 7.9 (7.3-8.5) | `key` | js/morph.js:196 | (c) Livewire code |
| 30 | 7.9 (7.5-8.6) | `querySelectorAll` | (native) | native: builtins (JSON.parse, |

Native self time breakdown (top builtins): `(program)` 2575 ms, `(garbage collector)` 37 ms, `getBoundingClientRect` 31 ms, `construct` 16 ms, `hasAttribute` 10 ms, `(anonymous)` 8 ms, `elementsFromPoint` 8 ms, `querySelectorAll` 8 ms

## Expression compilation on initial load (Alpine `AsyncFunction` constructions)

Counted by wrapping the `AsyncFunction` constructor before the bundle loads (no profiler; `profile/fnstats.js`, same on all 3 runs):

- **2386** function constructions during initial load vs **2376** `[x-data]` scopes in the final DOM (2200 of them inside `<tbody>`: ~11 per row on this mainline markup: 4x `{ v: '...' }`, `{ v: <n> }`, `OrdersLinePresence(<id>)`, `{ patients }`, `{ partNumber }`, `{ desc }`, `{ date, ground }`, `{ date }`).
- **2386 unique raw source strings out of 2386 (every construction is unique), but only 298 unique after replacing digit runs and quoted strings with placeholders.** 1867 (78%) of the compiled sources contain a 4+ digit run (row/line ids). So Alpine's expression cache (keyed on the exact string) gets **zero hits** on this page, and ~87% of the compiles would be cache hits if ids and strings were bound as data instead of embedded in the expression text.
- Top normalised patterns (count): `window.Empire.copy.elementTextToClipboard('s')` x401; `toggleCheck($el.checked, 's')` x200; `isExpanded(#) // 's'` x200; `toggleExpand(#);` x200; `OrdersLinePresence(#)` x200; `(async()=>{ if ($event.detail.id == #) { date = $event.detail.date_needed; ground = true && $event.detail.is_g` x200; `(async()=>{ if ($event.detail.id == #) date = $event.detail.date_expected })()` x200; `{ 's': !isExpanded(#) }` x200; `{ v: 's' }` x82; `shippingMethods.includes(#)` x28.
- Per row (200 rows): 2 `elementTextToClipboard('<uniqid>')` click handlers, `toggleCheck`, `isExpanded(<id>) || '...'`, `toggleExpand(<id>)`, `OrdersLinePresence(<id>)`, the two `x-empire-line-header` listeners (compiled as `(async()=>{ if ($event.detail.id == <id>) ... })()`), `{ '...': !isExpanded(<id>) }` = 10 id-bearing expressions per row. Expression-bearing attributes in the final DOM: `x-text` 2339, `@click` 889, `:class` 678, `x-show` 160, `x-init` 35, `x-model` 32.
- Initial-load inclusive time: Alpine `initTree` ~730 ms (profiler on), of which `evaluate`/evaluator (compile + first run of expressions) ~186 ms.

## `morph.updating` calls per element tag (hook counts, 1 run; identical in all 3)

| Step | total `morph.updating` | `morph.updated` | `morph.added` | per tag (top) |
|---|---|---|---|---|
| Sort | 434 | 407 | 1 | DIV 185, OPTION 61, LABEL 31, FIELDSET 24, SPAN 23, INPUT 20, DIALOG 18, BUTTON 14 |
| Expand one | 8665 | 8638 | 402 | TD 3400, SPAN 1824, DIV 1587, path 611, svg 411, TR 401, INPUT 221, OPTION 61 |

- **Sort**: only 434 elements go through `morph.updating` and **0 are `tr`/`td`**: the table root is re-keyed by the row ids, so Livewire/Alpine morph adds the whole new table as ONE `morph.added` (1) and the cost is Alpine `initTree` on the ~12k new nodes (351 ms inclusive) rather than morph diffing (Livewire `morph` inclusive 84 ms).
- **Expand one**: 8,665 elements go through `morph.updating`, including **401 `tr`, 3,400 `td`, 1,824 `span`, 1,587 `div`, 411 `svg`, 611 `path`**, and 402 `morph.added` (the lazy child and expansion content). Nothing is skipped on mainline (random `uniqid()` markup, see real-app-ab.md).
- **Time inside `morph.updating` listeners**: the inclusive time of `trigger()` called from morph's `updating` callback is ~4 ms (expand) / 0 (sort) per run out of ~535 ms inclusive in Livewire `morph` (expand). Listener bodies are cheap; the cost is the per-element walk itself (Alpine `patch`/`patchChildren`/`patchAttributes`, Livewire `morph2`, `walkBackwards`, `isEndFragmentMarker`).

## Native split from CDP `Performance.getMetrics` (profiler OFF, mean of 3; seconds as ms)

| Step | ScriptDuration | LayoutDuration | RecalcStyleDuration | TaskDuration | layouts / style recalcs |
|---|---|---|---|---|---|
| Initial load (to +3 s after rows) | 637 | 123 | 237 | 1494 | 12 / 18 |
| Sort | 391 | 39 | 63 | 720 | 1 / 5 |
| Expand one | 111 | 57 | 136 | 3416 | 14 / 208 |

Long-animation-frame cross-check (profiler off, 3 runs): sort LoAF 578/554/570 ms (long tasks 590/582/597 ms); expand LoAF 826/826/783 ms (long tasks 881/883/836 ms). These agree with the Pest-harness numbers in real-app-ab.md (sort ~570, expand ~770-800).

## Things worth knowing before you read the tables

1. **`(program)` is large and is mostly NOT Livewire/Alpine JS.** It is browser-native work outside JS (GUESS, not broken down by the sampler: HTML parse of the 4.4 MB response, style recalc, layout, paint, DOM mutation). On initial load JS (`ScriptDuration`) is ~640 ms of ~1,500 ms task time; layout ~120 ms, style ~240 ms.
2. **Forced style recalculation inside Alpine anchor's `autoUpdate`.** The #1 JS function on initial load is `isOverflowElement` (@alpinejs/anchor -> floating-ui, ~100 ms self). Caller chain: `x-anchor` directive effect -> `autoUpdate` -> `getOverflowAncestors` -> `getNearestOverflowAncestor` -> `isOverflowElement` (it calls `getComputedStyle`, which flushes pending style for the ~12k-node DOM). There is exactly **1** `x-anchor*` element on the page (a `div x-anchor.bottom-start`, not in the table); GUESS: the cost scales with DOM size, not with the number of anchors. (Appears again in expand: 104 ms.)
3. **Continuous main-thread burn after expanding a row (app/CSS, not Livewire core).** With nothing happening for 3 s, main-thread TaskDuration is 0 ms after initial load but **~2,300-2,370 ms per 3 s after expanding one row** (0 script, 0 layout, 180 style recalcs = every frame). Cause found: the expanded child contains `.lazy-placeholder.skeleton` placeholders (nested `#[Lazy]` children, e.g. the Billing Notes block, `h-[60px]`) that sit outside the viewport so their intersection-triggered load never fires, and the daisyUI `skeleton` CSS animation keeps running (`document.getAnimations()` = 2 running `skeleton`). Disabling all CSS animations drops the idle burn to ~920 ms per 3 s (the remaining ~0.9 s/3 s I did not attribute). It also inflates the `(program)` self time in the expand profile (2.7 s of 5.5 s window), so for the expand step trust LoAF (~0.8 s) and the JS rows, not `(program)`. One expanded row's HTML is ~300 KB (`innerHTML.length` 300,763 after the nested lazy children resolved) with 7 child Livewire components.
4. Profiler overhead: JS self times are inflated relative to the un-profiled A/B runs (e.g. sort LoAF 570 ms un-profiled; JS-inclusive under the profiler is higher). Use proportions, not absolute ms.
5. `[x-data]` count is 2,376 (not ~800): the page also contains ~24 modal/child components and ~11 scopes per row on the mainline markup.
6. Source mapping: `file:line` are in the original `livewire/livewire@4.4.8` `js/` sources and in `node_modules/alpinejs|@alpinejs/*` as bundled into Livewire's dist (line numbers as in those original files). A few anonymous functions map to the call site, not the definition.

## Files

`/home/endl/empire-v2-fork-ab/profile/`: `profile.js` (capture), `analyze.js` (analysis), `fnstats.js` (expression population), `idle.js`/`idle2.js` (idle burn), `A/*.cpuprofile` (9 raw profiles), `A/A-summary.json`, `A-out.json` (full analysis incl. min-max), `Anp*/` (metrics-only runs), `fnstats.json`. App server for this pass: `serve-profile.sh` (127.0.0.1:8099; currently running, owned by this session: stop with `pkill -f '[p]hp -d memory_limit=1G -S'`). Worktree, schemas and user left in place.
