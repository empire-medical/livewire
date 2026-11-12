# Notes: JS plugins, test infra, tooling, docs

(Line numbers are file:line in /home/endl/livewire/js/plugins/. Spec files were not read: links.spec.js, coordinator.spec.js.)

## 1. js/plugins

### navigate/ (Alpine plugin, default export `function(Alpine)`, index.js)
- index.js: registers `Alpine.navigate(url, {preserveScroll})`, `Alpine.navigate.disableProgressBar`, `x-navigate` directive (modifiers `.hover`, `.preserve-scroll`; wire:navigate maps to it via Livewire's directive aliasing - UNSURE where), `addInitSelector`. Fires `alpine:navigate` (cancelable), `alpine:navigating` (with onSwap), `alpine:navigated`. Also wires popstate (history.js).
- links.js: click/mousedown/mouseup/keydown handling. Navigation starts on MOUSEDOWN (prefetch), real navigate on mouseup (+rAF). `whenThisLinkIsHoveredOrFocusedFor(el, 60ms)` adds 4 listeners per link (mouseenter/leave, focus/blur) only if `.hover`. Each link always adds 3 listeners (click, mousedown, keydown) (links.js:7,28,51). Helpers: same-origin, same-page-fragment, native-handling checks, `getUriStringFromUrlObject` = pathname+search+hash (cache key).
- prefetch.js: module-level `prefetches` map (prefetch.js:6), keyed by uri; 30s TTL (`cacheDuration`, :9) started when the fetch finishes via setTimeout; entry is DELETED on first consumption (:51,:59) so it is single-use. In-flight dedupe: a click during an in-flight prefetch waits on it (:55-67), failure falls back to normal fetch. "Warning: this could cause some memory leaks" comment (:5). No cap on entries.
- fetch.js: `fetchHtml` -> `performFetch` -> `sendNavigateRequest` in js/request/index.js:667 (plain `fetch(uri, {headers:{'X-Livewire-Navigate':'1'}, signal})`, then `response.text()`; no cache headers control; returns text, status, final URL after redirect).
- navigation.js: `Navigation` state machine (fetching -> ready -> finished / cancelled); `startNavigation` cancels the active one (aborts previous). `releaseAfterNavigation`, `onNavigationStart`, `isNavigating` used by request layer to hold things until destination settled (UNSURE of consumers).
- page.js (`swapCurrentPageWithNewHtml`): replaceNoncesInHtml(html) (regex over whole html, utils) -> `new DOMParser().parseFromString` of the FULL page (:16) -> adopt body+head -> hashes old body scripts (outerHTML of every `<script>` in old body, regex strip of attrs, `simpleHash` char loop, accumulating in module array `oldBodyScriptTagHashes` which GROWS every navigation via concat, :21-23) -> replaceHtmlAttributes -> `mergeNewHead` -> clone/replace every body `<script>` (cloneScriptTag, :59) -> `document.body.replaceWith(newBody)` -> `Alpine.destroyTree(oldBody)` AFTER the swap (:39). mergeNewHead: for each head child computes `ignoreAttributes(outerHTML)` (up to 3 regex builds `new RegExp` per attr per element, :225, + replaceAll(' ','')) and does `Array.includes` against lookup array = O(n*m) string compares (:90-105). Remote scripts awaited via promises (blocking Alpine init until loaded).
- transition.js (`transitionPageSwap`): ALWAYS does a SECOND `new DOMParser().parseFromString(html)` (:9) of the full page before every swap just to look for `wire:transition.navigate` elements, so every navigation parses the HTML twice (page.js:16 and transition.js:9). Early-returns to update() when none found.
- history.js (navigate): `snapshotCache` (limit 10, :17) stores full `document.documentElement.outerHTML` strings per history entry (Snapshot{url,html}). `outerHTML` serialization of whole live DOM happens: before each navigation (`updateCurrentPageHtmlInHistoryStateForLaterBackButtonClicks` :68-75), on fragment navigation (index.js:93), on back/forward (:77-81), and `pushUrl(.., html)` stores the NEWLY fetched html string (no re-serialization). Keys `url-Math.random()`. popstate handler registered once. Non-2xx status forces full reload on back. Back button with cached snapshot re-parses snapshot via swapCurrentPageWithNewHtml AND transitionPageSwap (double parse again); scroll.js may parse a third time (snapshotHtml for fragment history, scroll.js:17).
- history/coordinator.js (shared with queryString): batches `replaceState` via microtask, `pushState` flushes first; `writeToHistory` copies state; `unwrap` = JSON.parse(JSON.stringify()) (utils.js:4) on each batch. 640k char note (:63).
- persist.js: `document.querySelectorAll('[x-persist]')` before and after swap; removes persisted nodes from live DOM (Alpine.mutateDom) and re-inserts replacing the stub; unused persists destroyTree'd. `usedPersists.includes` small.
- teleport.js / popover.js: pack/unpack teleports & open popovers across persisted elements; popover.js serializes animations (getAnimations/keyframes). Only run for persisted elements.
- scroll.js: stores scroll in `data-scroll-*` attributes on body and `[x-navigate:scroll]` elements; restore in double microtask. Parses snapshot html with DOMParser only for fragment history entries.
- bar.js: NProgress; injects `<style>` at import time (module side effect, bar.js:11); shown only if navigation > 150ms (:19-23).
- bus.js: tiny pub/sub (listen/emit) - UNSURE if used.

### End-to-end flow of wire:navigate click
1. mousedown -> `prefetchHtml` (fetch with X-Livewire-Navigate header) unless same-page fragment; `.hover` additionally prefetches after 60ms hover/focus.
2. mouseup -> `alpine:navigate` event (cancelable) -> `navigateTo`.
3. `startNavigation()` (aborts prior), progress bar timer (150ms), `fetchHtmlOrUsePrefetchedHtml` (consumes prefetch or fetches).
4. Cross-origin redirect -> native visit.
5. `alpine:navigating`; store scroll attrs into DOM (querySelectorAll); `Alpine.destroyTree(document.body)` with custom walker skipping persisted/teleport targets (index.js:338-352; comment :349-351 itself flags DOM walk as potential perf issue); `outerHTML` serialization of current page into history state/cache.
6. `Alpine.stopObservingMutations()`; `transitionPageSwap` (parse #2) -> store persisted els -> pushState with new html -> `swapCurrentPageWithNewHtml` (parse #1, head merge, script cloning, body replaceWith) -> teleports cleanup, persisted put back, scroll restore -> after remote scripts loaded: `startObservingMutations`, microtask -> `Alpine.initTree(document.body)` (skip persisted) -> autofocus -> `alpine:navigated`. Livewire components re-init via Alpine initTree/ Livewire hooks on the new body (UNSURE: lives outside plugins).
7. Back/forward: popstate -> snapshot lookup (10 entries) -> swap from stored HTML string; else fallback to normal fetch navigation with shouldPushToHistoryState false.

### PERFORMANCE OBSERVATIONS (plugins)
1. Double full-document DOMParser parse per navigation: page.js:16 + transition.js:9 (could parse once, pass doc down). Third parse in scroll.js:17 for fragment history only.
2. `document.documentElement.outerHTML` serialization of the whole live DOM on every navigation away (history.js:74, index.js:93,132) and every back; cost grows with page size; stored up to 10 full HTML strings (history.js:17) -> memory.
3. `Alpine.destroyTree(document.body)` walks the whole tree (index.js:352) THEN page.js:39 destroys the old body again (`Alpine.destroyTree(oldBody)`) - UNSURE whether second is a no-op for already-cleaned elements; potentially a double walk. Also removes persisted before destroy only via skip.
4. page.js:21-23 accumulates `oldBodyScriptTagHashes` forever (concat, never deduped/reset) and re-hashes every old script each navigation; hashing = regexes + char-by-char reduce (:211-217). Grows with navigation count.
5. mergeNewHead (page.js:85-161): per-element `new RegExp` constructions in `ignoreAttributes` (:225) for each head child, each navigation; `lookup.includes` O(n*m). Could cache/precompute.
6. `replaceNoncesInHtml(html)` runs a regex/replace over full html string before parse (page.js:14) - cost UNSURE (defined in js/utils).
7. Prefetch: single-use, 30s TTL, no max size, no cancel on mouseleave (only the 60ms delay cancels); mousedown prefetch gives only the press->release head-start; `.hover` is opt-in. Prefetched HTML kept as string (no pre-parse), so parse cost still on critical path after click. No ETag/HTTP-cache usage logic; fetch default browser cache.
8. Listener count: 3 listeners per wire:navigate link always (+4 with .hover) and listeners never explicitly removed (no cleanup callback in directive, index.js:45-81) - fine unless many links.
9. Alpine init after swap is a full `initTree(document.body)` (index.js:329) - largest likely cost for big pages.
10. `injectScriptTagAndWaitForItToFullyLoad` makes head scripts serial-ish gate: Alpine init waits for Promise.all of new remote scripts (page.js:29-31,160).
11. history/index.js `track` uses JSON.stringify comparisons per update and `unwrap` JSON round-trip (utils.js:4) on every query-string batch; `fromQueryString` re-parses whole search per has/get/set/remove. Low impact.
12. history/url.js: appears DEAD/BROKEN code (urlFromParams references `params.toString()` on object and double '?'); not imported by anything I read - UNSURE (not grepped repo-wide).

## 2. Test infrastructure

### Files
- tests/TestCase.php: extends `Orchestra\Testbench\Dusk\TestCase`. setUp clears views (`view:clear`), livewire factory, rate limiter, deletes generated app/Livewire, resources/views/livewire etc. Sets view paths to tests/views, fixed app.key, DB = sqlite `:memory:` (connection `testbench`), disk `unit-downloads` -> tests/fixtures. `DuskOptions::withoutUI()` (headless) unless `DUSK_HEADLESS_DISABLED` set. `driver()` connects RemoteWebDriver to `http://localhost:9515` (chromedriver must be running/auto-started by Dusk) with `--no-sandbox --disable-dev-shm-usage` (already container-friendly). `resolveApplication` uses env path repo root.
- tests/BrowserTestCase.php: extends TestCase; fires `browser.testCase.setUp/tearDown` triggers (Livewire hooks).
- tests/TestComponent.php: empty `<div></div>` component.
- tests/fixtures/download.txt; tests/views/* blade fixtures + components + layouts; tests/Browser/{console,screenshots,source} empty dirs (Dusk output).
- Note: unit tests ALSO extend the Dusk TestCase (so Dusk/testbench-dusk must be installed even for unit tests; but chromedriver not started for unit tests unless they browse).
- phpunit.xml.dist: 3 suites. Unit = `src/**/*UnitTest.php`; Browser = `src/**/*BrowserTest.php`; LegacyBrowser = `legacy_tests/Browser/**/*Test.php`. Env CACHE_STORE=file, SESSION_DRIVER=file. bootstrap vendor/autoload.php; cacheDirectory .phpunit.cache.
- IMPORTANT: `src/Performance/RenderPerformanceBenchmarkTest.php` ends in `BenchmarkTest.php`, so it is in NEITHER suite (not `UnitTest.php`/`BrowserTest.php`). Run it by path: `vendor/bin/phpunit src/Performance/RenderPerformanceBenchmarkTest.php` (UNSURE if phpunit accepts a path outside suites with this config; normally yes, path argument works).
- vitest.config.js: jsdom env, globals, alias `@` -> ./js. Spec files: js/evaluator, diff, directives, diffAndPatchRecursive, wire, request/request, plugins/navigate/links, plugins/history/coordinator (8 `.spec.js`). `npm run test:run`. Needs `npm ci` (jsdom 27, vitest 4.1.x); `dist/` not required by vitest (UNSURE) but CI downloads it for PHP tests.
- composer.json scripts: `setup` (composer install, npm install, `vendor/bin/dusk-updater detect --auto-update`, `npm run build`), `test`, `test:unit` (--testsuite Unit), `test:browser`, `test:browser:headed`. PHP ^8.1. Deps: laravel/framework 10-13, orchestra/testbench 8-11, testbench-dusk 8-11, sushi, mockery. minimum-stability dev.
- package.json: esbuild ^0.14.49, build = `node ./scripts/build.js` (outputs dist/livewire{,.csp,.esm,.csp.esm,.min,.csp.min}.js + manifest.json with RANDOM hash; brotli-size for min). Alpine ^3.17.5 and plugins as deps.

### Counts
- src: 54 UnitTest.php files, 63 BrowserTest.php files (find src -name ...).
- legacy_tests: Unit/ (2 entries), Browser/ (40 entries: Actions, Alpine, DataBinding, Dirty, DynamicComponentLoading, Events, Extensions, FileDownloads, GlobalLivewire, Headers, Hooks, Init, Loading, MagicActions, Morphdom, Nesting, Offline, Polling, PollingViewport, Prefetch, ProductionTest, QueryString, Redirects, Replace, ScriptTag, Stacks, StringNormalization, SupportCollections/DateTimes/Enums/Stringables, SyncHistory, TestCase.php, DuskCommand.php, views...), 44 `*Test.php` files total under legacy_tests, plus AppLayout*.php, HttpKernel.php, TestEnum.php at root.

### How to run
- Unit: `composer install` then `vendor/bin/phpunit --testsuite Unit` (or `composer test:unit`; single feature: `vendor/bin/phpunit src/Features/SupportX/UnitTest.php` or `--filter`). Requires dist/ built? CI downloads dist for unit tests, so some unit tests likely read dist (e.g. asset-serving tests; UNSURE which).
- Browser: needs Chrome + matching chromedriver (`vendor/bin/dusk-updater detect --auto-update`) listening on 9515 (Dusk testbench starts it from vendor/laravel/dusk/bin or orchestra bin - UNSURE exact path) and built `dist/`. CLAUDE.md says NEVER run full browser suite; use --filter.
- Environment observed on this host: PHP 8.4.1 (herd-lite, extensions incl. pdo_sqlite, sqlite3, dom, mbstring, intl, zip, curl, xml, libxml present), node v22.12.0, /usr/bin/google-chrome 146.0.7680.80. NO vendor/ or node_modules/ or dist/ present (nothing installed). chromedriver not found on PATH (dusk-updater downloads into vendor/laravel/dusk/bin or testbench-dusk equivalent).

### CI (.github/workflows)
- test-4.x.yml (push to 4.x) and test-prs.yml (PRs): jobs build-assets (node 22, clones alpinejs main, builds and `npm link`s it into livewire, `npm run build`, uploads dist artifact), js-tests (`npm run test:run`), unit-tests (matrix PHP 8.1-8.5 x Laravel 10-13; extensions dom,curl,libxml,mbstring,iconv,intl,zip,pdo_sqlite; `composer require laravel/framework:X --no-update --dev` + `composer update --prefer-stable`; `dusk-updater detect`; `phpunit --testsuite Unit`, env RUNNING_IN_CI=true), browser-tests (3 shards via .github/scripts/get-browser-test-shard.php greedy bin-pack of BrowserTest files by test count; retry once with --order-by=defects --stop-on-failure; shard 1 also runs `test_can_cancel_an_upload` with FORCE_RUN=1), legacy-browser-tests (`--testsuite LegacyBrowser` with retry). PR matrix reduced to PHP 8.4/8.5 x Laravel 11-13.
- draft-release.yml: workflow_dispatch; npm ci, build, `npm run test:run`, creates release branch with `git add -f dist/`, draft GH release.
- .github also has CONTRIBUTING.md, SECURITY.md, FUNDING.yml, ISSUE_TEMPLATE, DISCUSSION_TEMPLATE, PULL_REQUEST_TEMPLATE.md.

### RenderPerformanceBenchmarkTest.php (src/Performance/) - exactly what it does
- Class extends Tests\TestCase (boots full Testbench/Dusk app per test method; Dusk not browsing). 5 tests, each calls `benchmark(name, closure)`; 1000 iterations after 10 warm-ups.
- Each closure only calls `app('livewire')->mount(Class[, params])`: i.e. server-side component instantiate + mount + render + dehydrate (Livewire::mount returns html + snapshot - UNSURE of exact return, not read) in-process, in a loop. Components: Simple (static div string), WithProperties (2 props + inline blade), WithComputed (#[Computed], array_sum of 10), WithTemplate (blade with @foreach 3 items), WithHooks (boot/booted/hydrate no-ops, json_encode).
- Measures: total wall time via microtime (not hrtime), avg ms/render, renders/sec, memory delta `memory_get_usage()` end-start (not peak, subject to GC/ no gc_collect), printed via echo in tearDown summary.
- Assertion: only `total elapsed < 10s` for 1000 renders (sanity threshold, not regression detection). No results persisted (no JSON/CSV), no baseline comparison, no statistical repeat (single run, no variance, no median/p95).
- Does NOT measure: HTTP/ request lifecycle (no `/livewire/update` roundtrip, no middleware, no routing), update requests/subsequent hydrate+method call+re-render+effects (only initial mount path; the WithHooks `hydrate` hook is not even invoked since no hydration happens - UNSURE), snapshot checksum/ synthesizer hydration costs of complex types (Eloquent, Collections, Carbon...), Blade compilation cache effects (inline views compile once then are cached so mostly measures cached render; first compile in warm-up), nested components/ slots/ islands/ lazy, JS side (morph, Alpine, navigate), network, DB, large property payloads, concurrency. Memory metric is crude. Runs on in-memory sqlite but no DB used.
- Not part of any phpunit suite (see above), so CI never runs it.

## 3. Tooling

- scripts/build.js: esbuild bundler, see above; `@/` alias resolver plugin; CSP variant swaps `alpinejs` for `@alpinejs/csp`; `--watch`; defines `CDN`, `IS_CSP_BUILD`, `process.env.NODE_ENV` ('development' unless --watch(!), inverted logic, scripts/build.js ~ line 120); esbuild 0.14 API (`watch` option). Writes dist/manifest.json with a random 8-hex hash on each build (not content hash).
- scripts/bump-alpine.js: `npm view alpinejs version`, rewrites package.json alpine deps, npm install.
- scripts/setup.sh: composer install, dusk-updater detect --auto-update, npm install (non-fatal warnings). NOTE: does not run `npm run build`.
- scripts/worktree.sh / cleanup-worktree.sh: create/remove git worktree (base default origin/main) and install deps there.
- scripts/admin + scripts/laravel.php: tiny artisan-like console (`make:feature {name}`) scaffolding src/Features/SupportX from scripts/stubs/FEATURE/{FEATURE.php.stub,Test.php.stub}. laravel.php boots a minimal Illuminate app in sys_get_temp_dir()/__lwcmdcache.
- stubs/Dusk*.stub: legacy Dusk test scaffolding (Tests\Browser\[name]\Component/Test).
- vercel.json: disables Vercel git deployments (`deploymentEnabled:false`).
- .styleci.yml, .prettierrc, .editorconfig, .vscode present (not read).
- PRD-wire-model-modifiers.md (230 lines): product spec/PRD for splitting wire:model modifiers into ephemeral layer (before `.live`) vs network layer (after `.live`); breaking change for v4. Stray planning doc at repo root. (git status shows only CODEMAP.md untracked, so PRD is tracked.)
- test_unified_scripts.php (95 lines): stray ad-hoc script exercising `Livewire\V4\Compiler\SingleFileComponentCompiler` (single-file components, `<script>` extraction into `export default function run()`; writes into /tmp). Not a phpunit test. Uses `require_once 'vendor/autoload.php'` relative to cwd.
- close.bash: macOS-only osascript snippet closing all VS Code windows except frontmost; unrelated to the project (personal util), rest commented out.

## 4. Docs
- docs/ files (~90): per-feature pages (navigate, islands, lazy, morph, hydration, how-livewire-works, polling, offline, testing, security, csp, etc.), wire-*.md directive pages, attribute-*.md pages, directive-*.md blade pages, plus AGENTS.md, __outline.md, undocumented-features-todo.md, upgrade-guide-scratch-file.md, rules/ dir, best-practices.md (1 line, effectively empty).
- __nav.md sections: Getting Started (Quickstart, Installation, Upgrade); Essentials (Components, Pages, Properties, Actions, Forms, Events, Lifecycle Hooks, Nesting, Testing); Features (Alpine, Styles, Navigate, Islands, Lazy Loading, Loading States, Validation, Uploads, Pagination, URL, Computed, Redirecting, Downloads, Teleport); HTML Directives (wire:bind ... wire:text, 23); PHP Attributes (Async, Computed, Defer, Isolate, Js, Json, Layout, Lazy, Locked, Modelable, On, Reactive, Renderless, Session, Title, Transition, Url, Validate); Blade Directives (@island, @placeholder, @persist, @teleport); Advanced (Morphing, Hydration, Release Tokens, Nesting, Troubleshooting, Security, CSP, JavaScript, Synthesizers, Packages, Contribution Guide).
- Performance claims in docs:
  - navigate.md:41 / wire-navigate.md:2: wire:navigate "much faster page load times - often twice as fast"; avoids re-downloading assets; prefetch begins on mousedown (press-to-release head start; "often enough time to load half or even an entire page"); `.hover` prefetches after 60ms hover/focus, warns it increases server usage (navigate.md:73).
  - lazy.md / attribute-lazy.md (viewport-triggered) / attribute-defer.md (right after page load): keep slow components from blocking initial render; lazy requests are parallel by default, optional bundling (`bundle`) to cut server overhead and connections (lazy.md:284-348).
  - islands.md: island = partial re-render without child-component overhead; with computed props + lazy islands (`lazy` param), expensive work only on island re-render. attribute-isolate.md: requests bundled by default (fewer connections, lower server load); #[Isolate] runs parallel for slow components, costs more connections.
  - attribute-renderless.md / actions.md#skipping-re-renders: skip render for actions that don't change view. attribute-async.md: parallel/non-blocking actions for pure side effects.
  - computed-properties.md:56-65 / attribute-computed.md:44: memoized per-request only (not across requests); #[Computed(persist)] / cache options exist (UNSURE not read).
  - morph.md: morphing preserves listeners/focus/inputs; no quantified perf claims. polling.md: not read for claims beyond grep (no perf numbers).
  - No docs page gives benchmark numbers or guarantees other than "often twice as fast" for navigate.

## Couldn't read / unsure list
- Spec files (links.spec.js, coordinator.spec.js) not read per scope.
- js/utils (replaceNoncesInHtml, cloneScriptTag), js/request internals beyond sendNavigateRequest, js/directives/wire-transition not read.
- Whether any unit test needs dist/ not verified.
- Chromedriver location/startup details not verified; nothing installed locally (no vendor/node_modules).
