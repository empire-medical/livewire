# Real-app A/B: upstream Livewire 4.4.8 vs fork (perf/core-performance @ c3a5df89) on the real Orders Manage page

Run 2026-11-12. Seeded/synthetic data only (the app's own `migrate:fresh --seed` World; no production access). No customer data appears in this file.

## Headline

1. **On the unmodified mainline Orders Manage page the fork is indistinguishable from upstream** (B vs A vs A/A within +-3% on every metric, 5 interleaved rounds). The fork's JS skip never fires, and the PHP-side gains are not visible in server time.
2. **Why the skip never fires: the app emits a per-render random id in every row.** `resources/views/livewire/orders-manage.blade.php:52` and `:72` do `@php($patientsId = uniqid())` / `@php($descId = uniqid())` and print them into `@click="...elementTextToClipboard('<id>')"` and the element ids. Two consecutive renders of the same unchanged table differ in **50 of 50** data rows (measured), so the signature (byte-equality of server HTML) never matches. Same pattern in `orders-search.blade.php` (:224, :252) and `orders-review.blade.php` (:106, :137), plus `components/copyable-text-column.blade.php:1`.
3. **With only those two `uniqid()` calls made deterministic (applied identically to A and B), the fork helps where rows survive a re-render:** expand-one-row main-thread (long-animation-frame) time **-50%** (702 -> 352 ms median), expand-all **-30%** (1102 -> 772 ms), time-to-settle -21% / -11%. `morph.updating` on `tr` elements drops 401 -> 201 per expand (all 200 data rows skipped, expansion rows still morphed).
4. **No gain for sort and row-set change on this page in either arm**, because on mainline the table root `wire:key` contains every row id (`resources/views/components/table.blade.php:~155`, `wire:key="{{ $uuid }}-table-{{ implode(',', $getAllIds()) }}"`), so any order/row-set change replaces the whole table root (1 `morph.added`, 0 `tr` morphed): nothing to skip. (This is what PR #1001's `stable-rows` removes.)
5. **Server time unchanged within noise.** TTFB of the `livewire/update` request: sort 661 vs 656 ms, expand-one 793 vs 802 ms, initial page TTFB 1049 vs 1052 ms (A vs B). The request is dominated by the app (queries, Blade for 200 rows x 14 cols, snapshot dehydrate). The fork's computed/EventBus/hook changes (-50% on the synthetic fixture) are a small slice here (GUESS: single-digit ms of ~650; I did not profile PHP).
6. **Correctness: no failures on B.** 348/348 Feature tests and 18/18 browser tests pass on the fork, both on unmodified mainline and with skip actually engaged (deterministic ids). One gap: see "What I could not verify".

## Setup (everything in the throwaway worktree)

- Worktree: `/home/endl/empire-v2-fork-ab` (detached HEAD at `origin/livewire-poc` 9ed0ba5d9c; the app's mainline, before PR #1001: no `skipUnchangedRows.js`, no `lineCells.js`, no stable-rows). Not touched: the main working tree, its branch, #1001, any remote. Nothing committed or pushed. `git status` in the worktree is clean apart from untracked helper files (listed below).
- **Switch A/B**: `vendor/livewire/livewire` is a symlink: A -> `/home/endl/empire-v2-fork-ab/livewire-upstream-4.4.8` (the vendored upstream package moved aside, v4.4.8 from composer.lock), B -> `/home/endl/livewire` (branch perf/core-performance, its `dist/` used in place, read-only). `ab-switch.sh A|B` also swaps `public/build` with a pre-built bundle per side.
- **Bundles**: `NODE_ENV=production npx vite build` once per side (build-A / build-B; the B bundle contains `seedServerSignatures`, the A bundle does not). The app imports `vendor/livewire/livewire/dist/livewire.esm` (resources/js/boot.js:3), so the fork's `dist/` is what got bundled. `livewireScriptConfig.skipUnchanged` was `true` on B (page-level check).
- **Runtime**: host PHP 8.4.1 (herd-lite), shared MySQL on 127.0.0.1:33900, dedicated schema `fork_ab_test` (user fork_ab, created with the repo's `.docker-shared/bootstrap-db.sh`), no `.env` (env vars in `ab-env.sh`: APP_KEY throwaway, CACHE/SESSION=array, QUEUE=sync, BROADCAST=log, SCOUT=collection, SLACK_NOTIFICATIONS_ENABLED=false, vendor-id vars from .env.example). `APP_ENV=local php artisan migrate:fresh --seed` (stock path, snapshot not saved: no mysqldump on host).
- **Browser**: Pest browser plugin (pestphp/pest-plugin-browser) + Playwright 1.64.1 with its bundled Chromium 1248 (`PLAYWRIGHT_BROWSERS_PATH=0`), headless. The app server is the plugin's in-process server (not Octane/FrankenPHP, no opcache tuning), so absolute server times are slower than prod. Same page both sides: vendor with 225 open lines, `perPage=200` via UserPreference (same as the repo's existing `presencePerPage` helper), admin user, 200 rows rendered, 14 columns default.
- **Not used**: Reverb/presence (harness `usePresenceBroadcasting` not called). Presence websocket joins are not in these numbers (they exist in prod; equal on both sides).
- **Test file**: `src/tests/Browser/Seeded/Ordering/ForkAbPerfSeededBrowserTest.php` (new, uses the copied `PresenceHarness` trait from PR #1001 branch for `presenceVendorWithOpenLines`/`presencePerPage` only; no #1001 app code). Driver: `run-ab.sh <rounds> <outdir>`; per round it runs A, B, then A2 (= upstream again, the A/A noise arm). Each run is a fresh PHP process + fresh browser, DB rolled back by the seeded group's DatabaseTransactions, so every run sees identical data. Summaries: `main-ab/summary.txt`, `det-ab/summary.txt` (`summarize.py`).
- **What is measured per run** (in-page, PerformanceObserver; INP-style = max Event Timing duration of the interaction): initial load (long tasks, long-animation-frame (LoAF) total, DCL, TTFB, DOM nodes, comment nodes); then on the same page in sequence: sort (header click), select (one checkbox), expand-one (click, wait until Livewire is quiet 2.5 s, includes the lazy child load), row-set change (a line leaves the Open list + `Orders.refresh`), expand-all (cap 60 s, quiet 4 s). "Main-thread time" = sum of LoAF durations in the window (long tasks reported too). Server = `livewire/update` resource timing responseStart-requestStart.

## Page shape (identical on both sides)

200 rows, DOM 12,104 elements, 30,946 comment nodes (morph markers, ~154/row), decoded HTML 4.42 MB (148 KB over the wire, compressed), 212 lazy children after expand-all.

## Results: A = upstream, A2 = upstream again (noise), B = fork. n=5 each, median [min-max], ms

### Arm 1: unmodified mainline (`uniqid()` present)

| Metric | A | A2 | B |
|---|---|---|---|
| Initial load: long-task ms | 864 [771-971] | 862 [845-1001] | 867 [856-976] |
| Initial load: LoAF ms | 1151 [1105-1246] | 1154 [1134-1228] | 1142 [1132-1170] |
| Initial load: DCL | 1515 [1502-1709] | 1509 [1460-1555] | 1507 [1468-1522] |
| Initial TTFB | 1049 | 1054 | 1052 |
| Sort: click -> morphed | 1208 [1192-1224] | 1225 [1196-1244] | 1209 [1204-1231] |
| Sort: LoAF ms / server TTFB | 567 / 661 | 570 / 661 | 569 / 656 |
| Sort INP | 56 | 48 | 48 |
| Select INP | 48 | 48 | 48 |
| Expand one: LoAF / settle / TTFB | 769 / 1811 / 793 | 774 / 1804 / 799 | 807 / 1858 / 802 |
| Expand one INP | 72 | 72 | 80 |
| Row-set change: click -> morphed / LoAF | 1430 / 807 | 1440 / 796 | 1453 / 812 |
| Expand all: LoAF / settle / TTFB | 1133 / 3085 / 1448 | 1159 / 3024 / 1430 | 1213 / 3033 / 1398 |
| Expand all INP | 120 | 120 | 120 |

A/A noise: A vs A2 differ by <= 3% on every metric (largest: row-set LoAF 807 vs 796, expand-all LoAF 1133 vs 1159). **B is within that noise on every row**; the largest B-vs-A deltas (expand-one LoAF +5%, expand-all LoAF +7%) are slightly in the wrong direction and inside the min-max spread. Per-step `tr` morph counts (`morph.updating`): A 401, B 401 on expand-one: no row skipped.

### Arm 2: same worktree with only the two `uniqid()` calls replaced by `'orders-manage-patients-'.$line->id` / `'orders-manage-description-'.$line->id` (blade lines 52, 72), applied to A, A2 and B alike

| Metric | A | A2 | B | B vs A |
|---|---|---|---|---|
| Initial load: long-task / LoAF | 846 / 1134 | 864 / 1131 | 851 / 1121 | ~0 |
| Sort: click -> morphed / LoAF | 1205 / 549 | 1186 / 547 | 1192 / 548 | ~0 |
| Select INP | 48 | 48 | 48 | 0 |
| **Expand one: LoAF** | 702 [680-714] | 756 [693-898] | **352 [335-353]** | **-50%** |
| **Expand one: settle** | 1724 | 1742 | **1356** | -21% |
| Expand one: TTFB | 786 | 786 | 774 | -2% |
| Expand one INP | 72 | 88 | 72 | 0 |
| Row-set change: click -> morphed / LoAF | 1396 / 759 | 1372 / 755 | 1442 / 778 | +3% (noise) |
| **Expand all: LoAF** | 1102 [967-1188] | 1149 | **772 [732-796]** | **-30%** |
| Expand all: settle | 2914 | 2917 | 2581 | -11% |
| Expand all INP | 120 | 120 | 120 | 0 |

`tr` morph count on expand-one: A 401, A2 401, B **201** (the 200 unchanged data rows skipped, the 200 expansion rows and the header still morph because they contain keyed descendants (`td`, `div`)). With deterministic ids, two consecutive table renders are byte-identical (verified: `htmlSame: true`, 0 differing rows).

Notes on Arm 2: INP did not move because the interaction itself (the click handler and the first frame) is small; the cost is in the post-response morph/Alpine work, which is what the LoAF sum captures. Sort and row-set change did not improve because the table root is re-keyed (see headline 4).

## Surprises / regressions to tell you about

- **No regression found.** No JS errors on any run (`window.__pestBrowser.jsErrors` empty in all 30 measured runs), final row counts equal, 212 children after expand-all on both sides, expand-all settled on every run.
- **Skip is defeated by app markup, not by the fork.** The only change needed in the app for the fork to engage on this page is removing per-render randomness from cell markup (the `uniqid()` calls). Anything else that varies per render would also defeat it (I only checked rows of Orders Manage; I did not diff other pages).
- **Livewire already drops the morph when the whole component render is byte-identical** (`effects.html` omitted by Livewire's own html-hash check). So an unchanged re-render costs nothing on either side; the fork only matters when something else on the page changed, as in expand-one.
- **Test-harness leak (not the fork):** pest-browser leaves `playwright run-server` node processes running after runs (there were 9 when I looked, several older than my session). A foreground `pest ... | tail` therefore never returns because the leaked server holds the pipe open; redirecting output to a file avoids it. I did not kill any of them.

## Correctness runs (on B; A re-run only needed on failures, and there were none)

Config for all: `php vendor/bin/pest --exclude-group=seeded <files>`, DB `fork_ab_test`, bundle of the side under test.
- **Feature, 348 tests / 721 assertions, all pass** (3 min): tests/Feature/Livewire/{OrdersManagePageCountJoinTest, OrdersManagePatientNPlusOneTest, OrdersReviewPageCountJoinTest, OrdersSearchPresenceTest, OrdersSearchSortingTest, OrdersSearchPaginationTest, OrdersSearchDeferredRowsTest, OrdersSearchTablePreferencesPersistTest}, tests/Feature/Orders/{ExpandedRowGateTest, ExpansionWrapperHasNoXCloakTest, OrderColumnHighlightsTest, SelectionCheckboxRestoreTest, OrdersReviewTest, QuickbarRenderlessTest, DuplicateModalsRegressionTest}, tests/Feature/ExpandedTableRowContentTest, tests/Feature/Accounting/{InvoiceResultsTableSelectionKeyTest, ResultsTableColumnWidthNormalizationTest}, tests/Feature/OptimisticLocking/ExpandedRowSelfConflictTest. (An earlier invocation listed three paths that do not exist; corrected and rerun.)
- **Browser, 18 tests / 126 assertions, all pass, twice**: tests/Browser/Ordering/{OrdersManageBrowserTest, OrdersLineHeaderUpdatedBrowserTest, OrdersReviewBrowserTest, OrderHistoryBrowserTest, OrderingPagesBrowserTest} on (a) unmodified mainline and (b) the deterministic-id arm (skip engaged: 201 vs 401 `tr` morphs). OrdersLineHeaderUpdatedBrowserTest is the one that pins the app's Alpine-listener cells that follow expanded-row saves, so it passing with skip engaged is the most relevant behavioural check.
- Not run: tests/Browser/Seeded/* of PR #1001 (not in mainline) and the Reverb presence tests (no Reverb).

## What I could not verify

- **Reset-of-client-edits and the kill switch.** I tried to demonstrate directly that a DOM edit made outside Alpine/Livewire to an unchanged keyed row survives a re-render on B but not on A, and that `livewire.morph_skip_unchanged=false` restores the reset. My probe never produced a morph (0 `morph.updating`, in all three configs) because the render it triggered was identical or the chosen field change was not visible under default column preferences, so it showed nothing either way; I abandoned it rather than report a false result. What is established instead: (1) `skipUnchanged` was `true` on the page with the default config and the fork's skip engaged (201 vs 401 `tr` morphs) in Arm 2; (2) the `data-no-skip-unchanged`/`livewireScriptConfig.skipUnchanged` flag is read from `config('livewire.morph_skip_unchanged')` (the app's config/livewire.php has no such key, default applies); (3) the app tests above pass with the skip engaged. The kill switch itself (set false -> same morph counts as A) was not demonstrated. A cheap follow-up: re-run Arm 2 with `config(['livewire.morph_skip_unchanged' => false])` and check `tr` morph count returns to 401.
- Which app features rely on morph resetting client-side DOM edits to keyed table rows: I did not audit. In this table, the only non-Alpine client-side row edits I know of are the `click-to-copy` helpers (no DOM edits) and Alpine `x-data` state, which Alpine preserves anyway.
- Real production behaviour (Octane/FrankenPHP, CloudFront, MySQL latency, presence websockets) is not represented.

## Files created in the worktree (all untracked; nothing in the repo is modified)

`/home/endl/empire-v2-fork-ab/`: `ab-env.sh`, `ab-switch.sh`, `run-ab.sh`, `run-tests.sh`, `run-editreset.sh`, `summarize.py`, `build-A/`, `build-B/`, `livewire-upstream-4.4.8/`, `main-ab/`, `det-ab/` (per-run JSON + logs + summary.txt), `correctness/` (test logs), `diag/` (diagnostics incl. the row-HTML diff that found `uniqid()`); inside `src/`: `vendor/` (composer install --no-scripts), `node_modules/` (copy), `storage/oauth-*.key` (copied from the main checkout, untracked), `tests/Browser/Support/PresenceHarness.php` (copied), `tests/Browser/Seeded/Ordering/{ForkAbPerfSeededBrowserTest, ForkAbDiagSeededBrowserTest, ForkAbEditResetSeededBrowserTest}.php`. The two tracked files I edited temporarily (`config/livewire.php` for the kill-switch env var, and `orders-manage.blade.php` lines 52/72 for Arm 2) are reverted (`git checkout`).
- Reproduce: `cd /home/endl/empire-v2-fork-ab && ./run-ab.sh 5 out 1` (Arm 1); for Arm 2 first apply `sed -i "52s/uniqid()/'orders-manage-patients-'.\$line->id/; 72s/uniqid()/'orders-manage-description-'.\$line->id/" src/resources/views/livewire/orders-manage.blade.php`.
- Database `fork_ab` / `fork_ab_test` and user `fork_ab` exist on the shared MySQL. Cleanup when done: `git worktree remove --force /home/endl/empire-v2-fork-ab` (vendor/node_modules are host-owned, so no root cleanup needed), then drop the two schemas and the user via `docker exec imperium-shared-mysql mysql -uroot ...`. I have not removed any of it; the user should decide.
