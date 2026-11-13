# App-side route: what the real Orders Manage page gains from app changes, and what the fork adds on top

Run 2026-11-13, throwaway worktree `/home/endl/empire-v2-fork-ab` (detached at `origin/livewire-poc` 9ed0ba5d9c = mainline before PR #1001). Synthetic/seeded data only. Nothing committed or pushed; the app files below exist only in the worktree. `/home/endl/livewire` was read-only (its `dist/` and PHP source used in place).

## Headline

Percent change vs **U0** (unmodified mainline, upstream Livewire 4.4.8), medians of 5 interleaved rounds, unthrottled (LoAF = main-thread long-animation-frame time of the interaction, lower is better):

| Arm | what it is | Sort | Expand one row | Row-set change | Expand All | Initial load (LoAF / long tasks) |
|---|---|---|---|---|---|---|
| U1 | deterministic cell ids (2 lines) | -3% | **-10%** | -3% | -3% | +2% / -1% |
| U2 | U1 + stable table root key | +3% | -12% | **-37%** | -8% | -1% / +0% |
| U3 | U2 + `inject_morph_markers=false` | **-9%** | **-28%** | **-45%** | -18% | +4% / -2% |
| U4 | U3 + shared Alpine expressions | -8% | -26% | -45% | -11% | -3% / **-9%** |
| F2 | U2 on the fork | **-53%** | **-57%** | **-76%** | -36% | -2% / +1% |
| F4 | U4 on the fork | **-58%** | **-62%** | **-79%** | -45% | -1% / -9% |

At 4x CPU throttle (3 rounds; LoAF, ms): initial load U0 5289 -> U4 4699 (-11%) / F4 4775 (-10%), DCL 2749 -> 2012 (-27%); sort U0 2492 -> U4 2384 (-4%) / **F4 1073 (-57%)**; expand-one U0 3777 -> U4 3029 (-20%) / **F4 1647 (-56%)**. Sort INP 224 -> 96 (U4) / 88 (F4); expand-one INP 328 -> 296 (-10%).

**A/A noise (U0 vs U0b, 5 rounds each):** Initial load: LoAF -6.8%; Initial load: long-task ms -1.6%; Sort: LoAF (main thread) +0.7%; Sort: click to morphed +0.6%; Expand one (+lazy child): LoAF -3.9%; Expand one: click to morphed -2.7%; Row-set change: LoAF -1.0%; Row-set change: click to morphed -0.0%. Initial load is the noisiest metric (U0 itself spans 1047-1221 ms LoAF); interactions are within +-4%. Treat anything under ~5% (load: under ~8%) as noise. INP has 8 ms granularity.

**Server time did not move in any arm** (update-request TTFB: sort 1446 U0 vs 1423-1477 for all arms; expand-one 1570 vs 1540-1571). All gains are client main-thread time. (The PHP built-in server used here is ~2x slower than the earlier Pest in-process server: absolute TTFB is not representative.)

## Ranked: which single change gives the most for the least

1. **Fork (on top of U1+U2): by far the biggest, and it needs no app markup beyond U1+U2.** F2 vs U2: sort -55%, expand-one -51%, row-set -61%, expand-all -30% (LoAF). It only works once U1 (deterministic ids) and U2 (stable root) are in: on U0/U1 the fork changes nothing (see real-app-ab.md).
2. **`inject_morph_markers=false` (U3): 1 config line + a one-space fix in 1 view; -9%/-28%/-45%/-18% on top of U2**, plus 26k fewer comment nodes (30.9k -> 4.7k) and 17% less HTML (4.43 MB -> 3.69 MB). Caveat: it exposes a Livewire 4.4.8 compile bug (below) and I verified DOM correctness only on the sequences listed.
3. **Stable table root key (U2): the only change that helps row-set change much (-37%)**, but it is the largest code change (~140 diff lines in the shared `x-table`, plus `stable-rows` opt-in); it does nothing for sort/expand by itself, and under 4x throttle upstream sort got 10% *slower* with it (2731 vs 2492 ms) because reordering keyed rows costs more than replacing the table when nothing is skipped.
4. **Deterministic ids (U1): 2 lines, -10% on expand-one**, ~0 elsewhere; mainly a prerequisite for the fork.
5. **Shared Alpine expressions (U4): the most invasive app change, smallest extra gain.** Compiled functions 2386 -> 414 (-83%), initial-load long tasks -9% (unthrottled), -11% LoAF / **-27% DCL** under 4x throttle; no further gain for sort/expand/row-set beyond U3. It touches shared components and breaks 4 tests that pin the old markup (below).

## Metrics: all arms (n=5 rounds each, median [min-max], % vs U0)

| Metric (ms) | U0 | U0b | U1 | U2 | U3 | U4 | F2 | F4 |
|---|---|---|---|---|---|---|---|---|
| Initial load: LoAF | 1138 [1047-1221] base | 1061 [1048-1127] -7% | 1159 [1110-1312] +2% | 1122 [1058-1168] -1% | 1185 [1173-1194] +4% | 1108 [1076-1118] -3% | 1119 [1116-1138] -2% | 1124 [1095-1139] -1% |
| Initial load: long-task ms | 890 [879-909] base | 876 [867-890] -2% | 882 [872-904] -1% | 892 [871-931] +0% | 873 [854-891] -2% | 811 [741-818] -9% | 899 [891-911] +1% | 814 [791-829] -9% |
| Initial load: DCL | 1721 [1689-1728] base | 1699 [1669-1756] -1% | 1804 [1711-1845] +5% | 1732 [1710-1758] +1% | 1682 [1661-1695] -2% | 1662 [1630-1698] -3% | 1687 [1662-1740] -2% | 1638 [1609-1716] -5% |
| Initial load: TTFB | 1297 [1291-1310] base | 1285 [1280-1320] -1% | 1314 [1296-1360] +1% | 1311 [1298-1331] +1% | 1286 [1257-1300] -1% | 1271 [1258-1313] -2% | 1274 [1257-1308] -2% | 1256 [1224-1330] -3% |
| Sort: LoAF (main thread) | 560 [547-565] base | 564 [551-578] +1% | 545 [532-571] -3% | 577 [569-592] +3% | 512 [494-522] -9% | 514 [506-521] -8% | 260 [253-267] -53% | 236 [215-245] -58% |
| Sort: click to morphed | 1645 [1596-1681] base | 1654 [1616-1671] +1% | 1663 [1638-1672] +1% | 1680 [1646-1712] +2% | 1601 [1584-1663] -3% | 1589 [1572-1644] -3% | 1379 [1321-1398] -16% | 1318 [1275-1351] -20% |
| Sort: INP (8 ms granularity) | 48 [48-48] base | 48 [48-48] +0% | 48 [48-56] +0% | 24 [24-32] -50% | 24 [16-24] -50% | 24 [16-24] -50% | 24 [24-32] -50% | 24 [16-24] -50% |
| Select: INP | 48 [48-48] base | 56 [48-56] +17% | 56 [48-56] +17% | 56 [56-56] +17% | 48 [48-56] +0% | 48 [48-48] +0% | 56 [56-56] +17% | 48 [48-48] +0% |
| Expand one (+lazy child): LoAF | 810 [798-822] base | 778 [763-801] -4% | 730 [722-743] -10% | 714 [703-717] -12% | 585 [571-592] -28% | 601 [581-624] -26% | 352 [332-354] -57% | 310 [294-321] -62% |
| Expand one: click to morphed | 2602 [2563-2657] base | 2533 [2509-2619] -3% | 2519 [2463-2599] -3% | 2521 [2491-2529] -3% | 2330 [2300-2391] -10% | 2375 [2335-2404] -9% | 2117 [2062-2203] -19% | 2060 [1993-2102] -21% |
| Expand one: INP | 80 [72-80] base | 80 [72-80] +0% | 80 [80-80] +0% | 80 [80-80] +0% | 72 [72-80] -10% | 80 [72-80] +0% | 80 [72-80] +0% | 80 [72-80] +0% |
| Row-set change: LoAF | 843 [835-867] base | 835 [813-841] -1% | 821 [820-836] -3% | 530 [520-536] -37% | 463 [454-469] -45% | 467 [444-471] -45% | 206 [204-219] -76% | 177 [173-182] -79% |
| Row-set change: click to morphed | 2659 [2657-2692] base | 2658 [2606-2707] -0% | 2693 [2662-2698] +1% | 2374 [2290-2390] -11% | 2241 [2185-2274] -16% | 2246 [2221-2257] -16% | 1990 [1954-2322] -25% | 1924 [1872-2009] -28% |
| Sort: server TTFB of update | 1446 [1388-1497] base | 1447 [1430-1474] +0% | 1481 [1456-1497] +2% | 1457 [1402-1478] +1% | 1477 [1441-1495] +2% | 1423 [1400-1465] -2% | 1451 [1385-1469] +0% | 1444 [1396-1470] -0% |
| Expand one: server TTFB of update | 1570 [1535-1645] base | 1547 [1509-1658] -2% | 1557 [1526-1646] -1% | 1571 [1552-1598] +0% | 1540 [1522-1610] -2% | 1565 [1545-1583] -0% | 1547 [1524-1646] -1% | 1546 [1525-1600] -2% |

### Expand All (separate pass: straight after load, nothing else done to the page/DB; n=5)

| Metric (ms) | U0 | U0b | U1 | U2 | U3 | U4 | F2 | F4 |
|---|---|---|---|---|---|---|---|---|
| Expand All: LoAF | 1123 [1030-1173] base | 1136 [1095-1159] +1% | 1093 [1034-1117] -3% | 1035 [1002-1106] -8% | 924 [909-956] -18% | 1001 [923-1010] -11% | 722 [670-741] -36% | 613 [596-673] -45% |
| Expand All: click to morphed | 3789 [3737-3942] base | 3809 [3762-4010] +1% | 3778 [3473-3892] -0% | 3844 [3675-3918] +1% | 3680 [3337-3820] -3% | 3561 [3386-3940] -6% | 3450 [3335-3513] -9% | 3263 [2975-3413] -14% |
| Expand All: server TTFB(s) summed | 3116 [3070-3670] base | 3079 [3002-3600] -1% | 3132 [3064-3206] +1% | 3090 [3017-3116] -1% | 3485 [3410-3572] +12% | 3406 [2952-3532] +9% | 3028 [2976-3432] -3% | 3013 [2988-3456] -3% |

(My first Expand-All number, taken after the row-set step, was confounded: the row-set step restores the removed line just before it, so stable-root arms had one extra row to morph. This pass replaces it.)

### 4x CPU throttle (`Emulation.setCPUThrottlingRate=4`, n=3)

| Metric (ms) | U0 | U2 | U4 | F4 |
|---|---|---|---|---|
| Initial load: LoAF | 5289 [5182-5306] base | 5314 [5233-5400] +0% | 4699 [4691-4807] -11% | 4775 [4734-4844] -10% |
| Initial load: long-task ms | 4576 [4384-4652] base | 4416 [4406-4446] -3% | 4397 [4317-4416] -4% | 4392 [4351-4579] -4% |
| Initial load: DCL | 2749 [2743-2771] base | 2782 [2550-2870] +1% | 2012 [1981-2058] -27% | 1995 [1990-2008] -27% |
| Sort: LoAF | 2492 [2462-2580] base | 2731 [2613-2812] +10% | 2384 [2362-2398] -4% | 1073 [1065-1089] -57% |
| Sort: click to morphed | 3242 [3149-3255] base | 3520 [3400-3604] +9% | 3192 [3160-3216] -2% | 1958 [1898-1990] -40% |
| Sort: INP | 224 [200-232] base | 112 [104-120] -50% | 96 [96-96] -57% | 88 [88-96] -61% |
| Expand one: LoAF | 3777 [3667-3839] base | 3470 [3341-3597] -8% | 3029 [3000-3059] -20% | 1647 [1600-1653] -56% |
| Expand one: click to morphed | 5559 [5534-5646] base | 5262 [5165-5449] -5% | 4765 [4754-4787] -14% | 3393 [3291-3481] -39% |
| Expand one: INP | 328 [312-352] base | 328 [328-392] +0% | 296 [296-320] -10% | 296 [296-312] -10% |

### Page shape per arm (initial load)

| Arm | elements | comment nodes | decoded HTML bytes | compiled Alpine functions | unique after normalising ids/strings | `[x-data]` scopes |
|---|---|---|---|---|---|---|
| U0 | 12,105 | 30,946 | 4,432,635 | 2,386 | 301 | 2,376 |
| U0b | 12,105 | 30,946 | 4,432,635 | 2,386 | 301 | 2,376 |
| U1 | 12,105 | 30,946 | 4,445,790 | 2,386 | 301 | 2,376 |
| U2 | 12,106 | 30,948 | 4,444,804 | 2,386 | 301 | 2,376 |
| U3 | 12,106 | 4,661 | 3,686,354 | 2,386 | 301 | 2,376 |
| U4 | 12,106 | 4,661 | 3,834,577 | 414 | 301 | 2,376 |
| F2 | 12,106 | 30,948 | 4,444,826 | 2,386 | 301 | 2,376 |
| F4 | 12,106 | 4,661 | 3,834,598 | 414 | 301 | 2,376 |

`[x-data]` scopes are unchanged at 2,376 (~11 per row, 2,200 in the tbody); U4 reduces *compiled expressions* to 414 by making them identical across rows, while the number of unique strings after normalisation stays 301.

### Rows going through `morph.updating` (median)

| Arm | `tr` through `morph.updating`: expand-one | sort | `morph.added` on sort |
|---|---|---|---|
| U0 | 401 | 0 | 1 |
| U0b | 401 | 0 | 1 |
| U1 | 401 | 0 | 1 |
| U2 | 401 | 365 | 39 |
| U3 | 401 | 365 | 39 |
| U4 | 401 | 365 | 39 |
| F2 | 201 | 183 | 39 |
| F4 | 201 | 183 | 39 |

U0/U1 re-key the whole table on sort (1 `morph.added`, 0 `tr` morphed); U2+ morph the existing rows. The fork skips ~half of them (201 of 401 `tr` on expand-one: the 200 unchanged data rows).

## Correctness

- **Feature tests (348) + the 18 browser tests** (same lists as in real-app-ab.md): **U0, U1, U2, U3: all pass.** **U4: 344/348, 4 failures, all in `tests/Feature/Orders/OrderColumnHighlightsTest.php`** (`date cells listen for line header updated` x4): the test pins the exact old markup (`date: '06/15/2025', ground: true`, `if ($event.detail.id == 103) {...`), which U4 intentionally replaces with `$el.dataset...`. They pass on U0-U3, so the failures come from U4, not pre-existing. The 18 browser tests pass on U4.
- **U3 (markers off), DOM sequence** (`ab2/dom-check.js`, 13 steps at 200 rows, same sequence on U2 vs U3, U4 vs U4-with-markers, upstream vs fork): expand 3 rows, collapse/expand/collapse, Expand All, Collapse All, sort twice, select 3, select-all, hide then restore a column via the preferences modal (conditional header/cell blocks), rows-per-page 25 (row-set shrink), refresh. At every step the row ids/order, expansion visibility, expansion-content counts, checked counts, main-checkbox state, column counts and tr counts were **identical** between U2 (markers on) and U3 (markers off) and between U4 with and without markers; no JS errors. Comment nodes: 4,661 (off) vs 30,948 (on) at load. Limits: one page, one vendor; my whole-row HTML hash was dominated by a volatile cell and could not be used (row 0 HTML was byte-identical between arms), so the DOM claim rests on the structural fields above, not on a full HTML diff.
- **Things that rely on markers / break without them (U3):** (a) **`resources/views/livewire/view-invoices-modal.blade.php` fails to compile** with markers off (`syntax error, unexpected token "endforeach"`): it has `...@endif@endif</td>` back-to-back with no whitespace (lines 20 and 53). It is the only one of 612 Blade views that fails (compiled each with markers on/off). I patched it with a space (diff below). Minimal repro: with `livewire.inject_morph_markers=false` (and default `smart_wire_keys=true`), `@foreach($xs as $x)@if($x) a @endif@endforeach` compiles to `... <?php endif; ?>@endforeach` (the second closing directive is left uncompiled); with markers on it compiles fine. That is a Livewire 4.4.8 core bug in `SupportMorphAwareBladeCompilation` (markers-off path), not an app problem. (b) No test in the 366 above failed with markers off. (c) The table's own comment (table.blade.php:254) about `@if` markers breaking row matching is about markers *on*; I found no visual/functional difference with them off.
- **FORK FINDING (needs your attention): after a row is collapsed the fork leaves its lazy child component mounted in the DOM; upstream removes it.** Reproduced in all three fork arms I ran (F2 markers on, F4 and F4m, i.e. independent of markers and of the U3/U4 markup), never on upstream. Sequence: expand rows (lazy child loads) -> collapse: `expansionContentRows` (expansion rows still containing a `wire:id` child) after "collapse/expand/collapse" is 3 on the fork vs 2 upstream; after **Collapse All 200 vs 0**; after sort 182/174; after rows-per-page 25 it drops to 7 (children of removed rows go away). The `tr` is hidden (Alpine class), so it is invisible, but the orphan children stay alive client-side (their `#[On(...)]` listeners, snapshots) although the server no longer renders them. GUESS at the cause, from reading `js/morph.js` (eachSignableElement / the `signature` check in `updating` / `serverSignatures`): the content `div wire:key=...-content` is a *leaf* keyed element when collapsed (signable, signature S_empty seeded at load), becomes a *container* when the child is rendered (it then has a keyed descendant, so it is not stamped and its old S_empty entry is never updated or deleted), and when the row is collapsed again the incoming empty div matches the stale S_empty and is skipped, so the child is never removed. If that is right, a signature entry must be dropped when an element stops being signable.
- Not covered: tests/Browser/Seeded/* of PR #1001 (not in mainline); Reverb presence; F-arms were not put through the Feature/browser test suites (only the measurement and DOM sequences).

## Exact diffs per arm (worktree only)

Arm files are stored under `/home/endl/empire-v2-fork-ab/arms/files/<arm>/` and switched with `ab2/run-arm.sh`; each arm also sets env `LIVEWIRE_MORPH_MARKERS` (false for U3/U4/F4 only) and uses its own compiled-view directory (pre-warmed; no compile in the timed window). Bundles: A = upstream Vite production build, B = the fork `dist/` built the same way (JS is identical across U-arms: U4 needed no JS change since `x-data="OrdersLinePresence(+$el.dataset.lineId)"` calls the existing Alpine.data component).

**All arms: `config/livewire.php`** (neutral, default `true`; makes U3 "config only" selectable by env):

```diff
--- a/src/config/livewire.php
+++ b/src/config/livewire.php
@@ -204,7 +204,7 @@
     |
     */
 
-    'inject_morph_markers' => true,
+    'inject_morph_markers' => env('LIVEWIRE_MORPH_MARKERS', true),
 
     /*
     |---------------------------------------------------------------------------
```

**U0 -> U1 (deterministic cell ids):**

```diff
diff -ruN --label a --label b a b
--- a
+++ b
@@ -49,7 +49,7 @@
                             @endscope
 
                             @scope("cell_patients", $line)
-                                @php($patientsId = uniqid())
+                                @php($patientsId = 'orders-manage-patients-'.$line->id)
                                 <div x-data="{ patients: @js($line->patients->pluck('full_name_with_facility_patient_id')->join(', ')) }"
                                      class="cursor-pointer click-to-copy w-12/12 flex"
                                      :title="patients"
@@ -69,7 +69,7 @@
                             @endscope
 
                             @scope("cell_description", $line)
-                                @php($descId = uniqid())
+                                @php($descId = 'orders-manage-description-'.$line->id)
                                 <div x-data="{ desc: @js($line->description) }"
                                      x-empire-line-header:{{ $line->id }}="desc = $event.detail.description"
                                      class="cursor-pointer click-to-copy w-12/12 flex"
```

**U1 -> U2 (stable table root key; stable-rows without the row-hash / skipUnchangedRows hook):**

```diff
diff -ruN --label a --label b a b
--- a
+++ b
@@ -48,6 +48,8 @@
         public ?bool $fluent = false,
         public ?bool $sticky = true,
         public ?bool $parentGroupHasPages = null,
+        public ?bool $stableRows = false,
+        public ?bool $rowsPayload = true,
         // Slots
         public mixed $actions = null,
         public mixed $tr = null,
@@ -132,6 +134,22 @@
         return $this->rows->pluck($this->selectableKey)->all();
     }
 
+    public function getRowsByKey(): object
+    {
+        $items = is_object($this->rows) && method_exists($this->rows, 'items') ? $this->rows->items() : $this->rows;
+        $byKey = [];
+        foreach ($items as $index => $item) {
+            $byKey[data_get($item, $this->selectableKey) ?? $index] = $item;
+        }
+
+        return (object) $byKey;
+    }
+
+    public function getColumnCount(): int
+    {
+        return count($this->headers) + ($this->selectable ? 1 : 0) + ($this->expandable ? 1 : 0) + ($this->actions ? 1 : 0);
+    }
+
     public function isRowSelectable(mixed $row): bool
     {
         return $this->selectableCondition === null || (bool) ($this->selectableCondition)($row);
diff -ruN --label a --label b a b
--- a
+++ b
@@ -29,11 +29,26 @@
     The selector is `:scope > table > thead > ...` so a nested table inside an expansion
     can't match. — Added by Claude
 --}}
+@php $allIdsExpr = $stableRows ? 'this.allIds' : \Illuminate\Support\Js::from($getAllIds())->toHtml(); @endphp
 <div x-data="{
         selection: @if($selectable)$wire.entangle(@js($selectableModel))@else[]@endif,
         expanded: @if($expandable)$wire.entangle(@js($expandableModel), true)@else [] @endif,
+        @if($stableRows)
+        get pageIds() { return this.tableState('pageIds', []) },
+        get allIds() { return this.tableState('allIds', []) },
+        get rows() { return this.tableState('rows', {}) },
+        tableState(name, fallback) {
+            const el = this.$refs.tableState
+            const raw = el?.dataset[name]
+            if (raw === undefined) return fallback
+            el.__stateCache ??= {}
+            if (el.__stateCache[name]?.raw !== raw) el.__stateCache[name] = { raw, value: JSON.parse(raw) }
+            return el.__stateCache[name].value
+        },
+        @else
         pageIds: {{ json_encode($getSelectableIds()) }},
         rows: {},
+        @endif
         colspanSize: 0,
         init() {
             if (this.selection === undefined || this.expanded === undefined) {
@@ -44,10 +59,12 @@
 
             this.colspanSize = this.$el.querySelector(':scope > table > thead > tr[x-ref=headers]')?.childElementCount ?? 0
             this.$nextTick(() => { this.colspanSize = this.$refs.headers?.childElementCount ?? 0 })
+            @unless($stableRows)
             let items = @js(is_object($rows) && method_exists($rows, 'items') ? $rows->items() : $rows);
             (Array.isArray(items) ? items : Object.values(items)).forEach((item, index) => {
                 this.rows[item.{{ $selectableKey }} ?? index] = item;
             });
+            @endunless
 
             @if($selectable)
             this.handleCheckAll()
@@ -127,23 +144,29 @@
             this.allExpanded = !this.allExpanded;
             if (this.allExpanded) {
                 if(this.$wire.multipleTablesOnPage) {
-                    this.expanded = [...this.expanded, ...@js($getAllIds())];
+                    this.expanded = [...this.expanded, ...{!! $allIdsExpr !!}];
                 } else {
-                    this.expanded = @js($getAllIds());
+                    this.expanded = {!! $allIdsExpr !!};
                 }
                 return;
             } 
 
             if (this.$wire.multipleTablesOnPage) {
-                this.expanded = this.expanded.filter(id => !@js($getAllIds()).includes(id));
+                this.expanded = this.expanded.filter(id => !{!! $allIdsExpr !!}.includes(id));
                 return;
             }
             this.expanded = [];
         },
     }"
     class="overflow-x-auto"
-    wire:key="{{ $uuid }}-table-{{ implode(',', $getAllIds()) }}"
+    wire:key="{{ $uuid }}-table{{ $stableRows ? '' : '-'.implode(',', $getAllIds()) }}"
 >
+    @if($stableRows)
+        <template x-ref="tableState"
+            data-page-ids="{{ json_encode($getSelectableIds(), JSON_THROW_ON_ERROR) }}"
+            data-all-ids="{{ json_encode($getAllIds(), JSON_THROW_ON_ERROR) }}"
+            @if($rowsPayload) data-rows="{{ json_encode($getRowsByKey(), JSON_THROW_ON_ERROR) }}" @endif></template>
+    @endif
     <table
     {{
     $attributes
@@ -177,6 +200,7 @@
                             autocomplete="off"
                             class="checkbox checkbox-sm"
                             x-ref="mainCheckbox"
+                            @if($stableRows) x-init="handleCheckAll()" @endif
                             @click="toggleCheckAll($el.checked)" />
                     </th>
                 @endif
@@ -317,7 +341,7 @@
                 {{-- Beware, using "@if ($expandable)" here causes snapshot errors when line statuses change [JJB] --}}
                 {{-- wire:ignore.self prevents morph from removing Alpine's `hidden` class, which would briefly make allexpansion rows visible --}}
                 <tr wire:key="{{ $uuid }}-{{ data_get($row, $selectableKey, $k) }}--expand" wire:ignore.self class="!bg-inherit hidden" :class="{ 'hidden': !isExpanded({{ $getKeyValue($row, 'expandableKey') }}) }">
-                    <td :colspan="colspanSize" wire:key="{{ $uuid }}-{{ data_get($row, $selectableKey, $k) }}--expand-td">
+                    <td @if($stableRows) colspan="{{ $getColumnCount() }}" @else :colspan="colspanSize" @endif wire:key="{{ $uuid }}-{{ data_get($row, $selectableKey, $k) }}--expand-td">
                         @if ($expandable && isset($expansion))
                             {{ $expansion($row) }}
                         @endif
diff -ruN --label a --label b a b
--- a
+++ b
@@ -34,6 +34,7 @@
                             show-empty-text
                             class="h-px" {{-- h-px necessary for component cells to render full height [JJB] --}}
                             :sticky="false"
+                            stable-rows
                             id="orders-manage-v{{$vendorId}}-a{{$shippingAddressId}}"
                         >
                             @scope('header_actions', $row)
```

**U2 -> U3 (markers off is the env var; this is only the compile fix):**

```diff
diff -ruN --label a --label b a b
--- a
+++ b
@@ -17,7 +17,7 @@
                         {{-- lw-invoice-detail carries admin middleware, but this modal is only
                              facility-scoped, so linking unconditionally would hand facility users
                              a link that 403s. They get plain text. (Comment added by Claude.) --}}
-                        <td>@if ($invoice->bill?->bill_number)@if (auth()->user()?->isAdmin())<a href="{{ route('lw-invoice-detail', ['invoice' => $invoice->id]) }}" class="link link-hover" target="_blank" rel="noopener">{{ $invoice->bill->bill_number }}</a>@else{{ $invoice->bill->bill_number }}@endif@endif</td>
+                        <td>@if ($invoice->bill?->bill_number)@if (auth()->user()?->isAdmin())<a href="{{ route('lw-invoice-detail', ['invoice' => $invoice->id]) }}" class="link link-hover" target="_blank" rel="noopener">{{ $invoice->bill->bill_number }}</a>@else{{ $invoice->bill->bill_number }}@endif @endif</td>
                         <td>${{ number_format($invoice->total, 2) }}</td>
                         <td>{{ $invoice->invoice_date?->format('m/d/Y') }}</td>
                         <td>
@@ -50,7 +50,7 @@
     <x-modal wire:model="open" title="Invoice {{ $selectedInvoice?->bill?->bill_number }}" box-class="max-w-4xl">
         @if ($selectedInvoice)
             <div class="flex gap-6 mb-4 text-sm">
-                <span><span class="font-semibold">Invoice #:</span> @if ($selectedInvoice->bill?->bill_number)@if (auth()->user()?->isAdmin())<a href="{{ route('lw-invoice-detail', ['invoice' => $selectedInvoice->id]) }}" class="link link-hover" target="_blank" rel="noopener">{{ $selectedInvoice->bill->bill_number }}</a>@else{{ $selectedInvoice->bill->bill_number }}@endif@endif</span>
+                <span><span class="font-semibold">Invoice #:</span> @if ($selectedInvoice->bill?->bill_number)@if (auth()->user()?->isAdmin())<a href="{{ route('lw-invoice-detail', ['invoice' => $selectedInvoice->id]) }}" class="link link-hover" target="_blank" rel="noopener">{{ $selectedInvoice->bill->bill_number }}</a>@else{{ $selectedInvoice->bill->bill_number }}@endif @endif</span>
                 <span><span class="font-semibold">Date:</span> {{ $selectedInvoice->invoice_date?->format('m/d/Y') }}</span>
                 <span><span class="font-semibold">Total:</span> ${{ number_format($selectedInvoice->total, 2) }}</span>
                 @if ($selectedInvoice->bill?->status_id)
```

**U3 -> U4 (share Alpine expressions: ids and row data move to `data-*` attributes; the table part is not gated by the `share-expressions` attribute I added to the call site, it applies to every `x-table` page in the worktree, which is why other pages' tests ran against it too):**

```diff
diff -ruN --label a --label b a b
--- a
+++ b
@@ -50,6 +50,7 @@
         public ?bool $parentGroupHasPages = null,
         public ?bool $stableRows = false,
         public ?bool $rowsPayload = true,
+        public ?bool $shareExpressions = false,
         // Slots
         public mixed $actions = null,
         public mixed $tr = null,
diff -ruN --label a --label b a b
--- a
+++ b
@@ -8,9 +8,10 @@
     @endif
     @if ($lineId)
         {{-- Follows expanded-row saves so the cell doesn't keep the pre-save date. Added by Claude. --}}
-        x-data="{ date: @js($date ?? '') }"
+        x-data="{ date: $el.dataset.date }"
+        data-date="{{ $date ?? '' }}" data-line-id="{{ $lineId }}"
         {{-- The id test is redundant under x-empire-line-header but kept: OrderColumnHighlightsTest pins this expression. Added by Claude. --}}
-        x-empire-line-header:{{ $lineId }}="if ($event.detail.id == {{ $lineId }}) date = $event.detail.date_expected"
+        x-empire-line-header:{{ $lineId }}="if ($event.detail.id == $el.dataset.lineId) date = $event.detail.date_expected"
     @endif
 >
     <span @if ($lineId) x-text="typeof date !== 'undefined' ? date : ''" @endif>
diff -ruN --label a --label b a b
--- a
+++ b
@@ -8,9 +8,10 @@
     @endif
     @if ($lineId)
         {{-- Follows expanded-row saves so the cell doesn't keep the pre-save date. Added by Claude. --}}
-        x-data="{ date: @js($rawDate ?? ''), ground: @js($accountForGroundShipping && $isGroundShipping) }"
+        x-data="{ date: $el.dataset.date, ground: $el.dataset.ground === '1' }"
+        data-date="{{ $rawDate ?? '' }}" data-ground="{{ ($accountForGroundShipping && $isGroundShipping) ? '1' : '0' }}" data-line-id="{{ $lineId }}"
         {{-- The id test is redundant under x-empire-line-header but kept: OrderColumnHighlightsTest pins this expression. Added by Claude. --}}
-        x-empire-line-header:{{ $lineId }}="if ($event.detail.id == {{ $lineId }}) { date = $event.detail.date_needed; ground = @js($accountForGroundShipping) && $event.detail.is_ground_shipping }"
+        x-empire-line-header:{{ $lineId }}="if ($event.detail.id == $el.dataset.lineId) { date = $event.detail.date_needed; ground = @js($accountForGroundShipping) && $event.detail.is_ground_shipping }"
         :class="{ 'bg-warning text-black font-bold': @js($shouldHighlight) && typeof date !== 'undefined' && date !== '' }"
         :title="@js($shouldHighlight) && typeof date !== 'undefined' && date !== '' ? 'Has a Need By date.' : null"
     @endif
diff -ruN --label a --label b a b
--- a
+++ b
@@ -1,5 +1,5 @@
 @props(['id'])
-<div x-data="OrdersLinePresence(@js($id))"
+<div x-data="OrdersLinePresence(+$el.dataset.lineId)" data-line-id="{{ $id }}"
     class="join"
     x-bind:title="typeof title !== 'undefined' ? title : ''"
     @click="showExpandedByModal"
diff -ruN --label a --label b a b
--- a
+++ b
@@ -6,7 +6,8 @@
     'lineId' => null,
 ])
 <div
-    x-data="{ partNumber: @js($partNumber) }"
+    x-data="{ partNumber: $el.dataset.partNumber }"
+    data-part-number="{{ $partNumber }}"
     @if ($lineId)
         {{-- Follows expanded-row saves (line-header-updated). Added by Claude. --}}
         x-empire-line-header:{{ $lineId }}="partNumber = $event.detail.part_number"
diff -ruN --label a --label b a b
--- a
+++ b
@@ -277,7 +277,7 @@
                                 class="checkbox checkbox-sm checkbox-primary"
                                 value="{{ data_get($row, $selectableKey) }}"
                                 x-model{{ $selectableModifier() }}="selection"
-                                @click.stop="toggleCheck($el.checked, '{{ data_get($row, $selectableKey, $k) }}')"
+                                @click.stop="toggleCheck($el.checked, $el.value)"
                                 data-testid="row-select-{{ data_get($row, $selectableKey, $k) }}" />
                             @endif
                         </td>
@@ -288,9 +288,9 @@
                         <td class="w-1 pe-0">
                             <x-icon
                                 name="o-chevron-down"
-                                ::class="isExpanded({{ $getKeyValue($row, 'expandableKey') }}) || '-rotate-90 !text-current'"
+                                ::class="isExpanded(Number($el.dataset.rowKey)) || '-rotate-90 !text-current'" data-row-key="{{ $getKeyValue($row, 'expandableKey') }}"
                                 class="cursor-pointer p-2 w-8 h-8 bg-base-300 rounded-lg"
-                                @click="toggleExpand({{ $getKeyValue($row, 'expandableKey') }});"
+                                @click="toggleExpand(Number($el.dataset.rowKey));"
                                 data-testid="row-expand-{{ $getKeyValue($row, 'expandableKey') }}" />
                         </td>
                     @endif
@@ -340,7 +340,7 @@
                 <!-- EXPANSION SLOT -->
                 {{-- Beware, using "@if ($expandable)" here causes snapshot errors when line statuses change [JJB] --}}
                 {{-- wire:ignore.self prevents morph from removing Alpine's `hidden` class, which would briefly make allexpansion rows visible --}}
-                <tr wire:key="{{ $uuid }}-{{ data_get($row, $selectableKey, $k) }}--expand" wire:ignore.self class="!bg-inherit hidden" :class="{ 'hidden': !isExpanded({{ $getKeyValue($row, 'expandableKey') }}) }">
+                <tr wire:key="{{ $uuid }}-{{ data_get($row, $selectableKey, $k) }}--expand" wire:ignore.self class="!bg-inherit hidden" :class="{ 'hidden': !isExpanded(Number($el.dataset.rowKey)) }" data-row-key="{{ $getKeyValue($row, 'expandableKey') }}">
                     <td @if($stableRows) colspan="{{ $getColumnCount() }}" @else :colspan="colspanSize" @endif wire:key="{{ $uuid }}-{{ data_get($row, $selectableKey, $k) }}--expand-td">
                         @if ($expandable && isset($expansion))
                             {{ $expansion($row) }}
diff -ruN --label a --label b a b
--- a
+++ b
@@ -35,6 +35,7 @@
                             class="h-px" {{-- h-px necessary for component cells to render full height [JJB] --}}
                             :sticky="false"
                             stable-rows
+                            share-expressions
                             id="orders-manage-v{{$vendorId}}-a{{$shippingAddressId}}"
                         >
                             @scope('header_actions', $row)
@@ -51,10 +52,12 @@
 
                             @scope("cell_patients", $line)
                                 @php($patientsId = 'orders-manage-patients-'.$line->id)
-                                <div x-data="{ patients: @js($line->patients->pluck('full_name_with_facility_patient_id')->join(', ')) }"
+                                <div x-data="{ patients: $el.dataset.patients }"
+                                     data-patients="{{ $line->patients->pluck('full_name_with_facility_patient_id')->join(', ') }}"
+                                     data-copy-id="{{ $patientsId }}"
                                      class="cursor-pointer click-to-copy w-12/12 flex"
                                      :title="patients"
-                                     @click="window.Empire.copy.elementTextToClipboard('{{ $patientsId }}')"
+                                     @click="window.Empire.copy.elementTextToClipboard($el.dataset.copyId)"
                                 ><div class="min-w-0 max-w-sm overflow-hidden whitespace-nowrap text-ellipsis"
                                       id="{{ $patientsId }}"
                                       x-text="patients"
@@ -71,11 +74,13 @@
 
                             @scope("cell_description", $line)
                                 @php($descId = 'orders-manage-description-'.$line->id)
-                                <div x-data="{ desc: @js($line->description) }"
+                                <div x-data="{ desc: $el.dataset.desc }"
+                                     data-desc="{{ $line->description }}"
+                                     data-copy-id="{{ $descId }}"
                                      x-empire-line-header:{{ $line->id }}="desc = $event.detail.description"
                                      class="cursor-pointer click-to-copy w-12/12 flex"
                                      :title="desc"
-                                     @click="window.Empire.copy.elementTextToClipboard('{{ $descId }}')"
+                                     @click="window.Empire.copy.elementTextToClipboard($el.dataset.copyId)"
                                 ><div class="min-w-0 max-w-sm overflow-hidden whitespace-nowrap text-ellipsis"
                                       id="{{ $descId }}"
                                       x-text="desc"
@@ -83,28 +88,28 @@
                             @endscope
 
                             @scope("cell_quantity", $line)
-                                <span x-data="{ v: @js($line->quantity) }"
+                                <span x-data="{ v: $el.dataset.v }" data-v="{{ $line->quantity }}"
                                       x-empire-line-header:{{ $line->id }}="v = $event.detail.quantity"
                                       x-text="v"
                                 ></span>
                             @endscope
 
                             @scope("cell_cost", $line)
-                                <span x-data="{ v: @js(number_format($line->cost, 2)) }"
+                                <span x-data="{ v: $el.dataset.v }" data-v="{{ number_format($line->cost, 2) }}"
                                       x-empire-line-header:{{ $line->id }}="v = $event.detail.cost"
                                       x-text="'$' + v"
                                 ></span>
                             @endscope
 
                             @scope("cell_markup", $line)
-                                <span x-data="{ v: @js(number_format($line->markup, 3)) }"
+                                <span x-data="{ v: $el.dataset.v }" data-v="{{ number_format($line->markup, 3) }}"
                                       x-empire-line-header:{{ $line->id }}="v = $event.detail.markup"
                                       x-text="v"
                                 ></span>
                             @endscope
 
                             @scope("cell_price", $line)
-                                <span x-data="{ v: @js(number_format($line->price, 2)) }"
+                                <span x-data="{ v: $el.dataset.v }" data-v="{{ number_format($line->price, 2) }}"
                                       x-empire-line-header:{{ $line->id }}="v = $event.detail.price"
                                       x-text="'$' + v"
                                 ></span>
@@ -128,7 +133,7 @@
                             @endscope
 
                             @scope("cell_warehouse_name", $line)
-                                <span x-data="{ v: @js($line->warehouse?->name ?? '') }"
+                                <span x-data="{ v: $el.dataset.v }" data-v="{{ $line->warehouse?->name ?? '' }}"
                                       x-empire-line-header:{{ $line->id }}="v = $event.detail.warehouse_name"
                                       x-text="v"
                                 ></span>
```

F2 = U2 files on the fork (PHP via `vendor/livewire/livewire -> /home/endl/livewire`, JS via the fork bundle); F4 = U4 files on the fork; U0b = U0 repeated (noise arm).

## Method notes

- Per run: fresh PHP built-in server (4 workers, opcache on) with the arm's env; login; one warm-up navigation in a throwaway context (opcache, compiled views, DB caches), then a NEW browser context (cold browser cache) for the measured load; Playwright 1.64.1/Chromium 1248, headless, 1600x1000; vendor with 225 open lines, `perPage=200`, 200 rows rendered. Steps on the same page in order: load, sort (header click), select (checkbox), expand one row (incl. lazy child), row-set change (a line leaves Open then `Orders.refresh`; DB row restored after), then (separate pass for Expand All). Metrics via PerformanceObserver (long-animation-frame, longtask, Event Timing) installed before navigation, Livewire hooks for morph counts and "quiet" detection. Rotation per round: U0, U1, U2, U3, U4, F2, F4, U0b. Scripts: `ab2/ab.js`, `ab2/ab_ea.js`, `ab2/run-arm.sh`, `ab2/run-rounds.sh`; raw JSON in `ab2/main`, `ab2/ea`, `ab2/thr`, `ab2/dom`, `ab2/dom2`, test logs in `correctness2/`.
- Row-set-change INP is not reported (the interaction is a programmatic dispatch, not an input event). Select INP is ~48-56 ms in every arm (no change).
- Server-side TTFB comes from resource timing of the `livewire/update` requests.
- During setup I hit and fixed harness bugs (stale server from a bad `pkill` pattern made one "U4" trial actually run U0; a first DOM-check run leaked a rows-per-page preference); none of the reported numbers come from those runs.
- Worktree, schemas (`fork_ab`, `fork_ab_test`), user `fork_ab` and the worktree's files are left in place; the worktree currently holds the U4 arm's files and the last test run left the test DB wiped (re-seed with `migrate:fresh --seed` + `prep-profile.php` to measure again).

## Re-verification after fix 9b60ace1

Run 2026-11-13. Fork at `9b60ace1` ("Forget remembered signatures for keyed elements that stop being leaves"); `dist/livewire.esm.js` was rebuilt by you; I rebuilt the app's B bundle from it (`NODE_ENV=production vite build`, `build-B/assets/boot.js` 1,551,514 bytes vs 1,551,475 before: +39 bytes) and confirmed `vendor/livewire/livewire -> /home/endl/livewire` (PHP side). Same worktree, same synthetic data (DB re-seeded + vendor padded to 225 lines, `perPage=200`), upstream arms U2/U4 re-run in the same session for the comparisons. Nothing committed or pushed; `/home/endl/livewire` read-only.

### 1. Orphaned lazy children: fixed

Expansion rows still containing a child component (`expansionContentRows`), per step of the 13-step sequence (expand 3 rows, collapse/expand/collapse, Expand All, Collapse All, sort x2, select 3, select-all, hide/show a column, rows-per-page 25, refresh), 200-row page:

| Step | U2 | F2 | U4 | F4 |
|---|---|---|---|---|
| load | 0 | 0 | 0 | 0 |
| expand 3 rows | 3 | 3 | 3 | 3 |
| collapse/expand/collapse | 2 | **2** (was 3) | 2 | **2** (was 3) |
| expand all | 200 | 200 | 200 | 200 |
| collapse all | 0 | **0** (was 200) | 0 | **0** (was 200) |
| sort 1 / sort 2 / select / check-all / Qty off / Qty on | 0 | 0 | 0 | 0 |
| rows-per-page 25 / refresh | 0 | **0** (was 7) | 0 | **0** (was 7) |

Equal to upstream at every step. All other structural fields (row ids and order, expanded-row ids, checked counts, main checkbox, header columns, `tr` count) are also equal at every step, no JS errors.

**Full-DOM fingerprint (stricter than before):** per data row and per expansion row I hashed the normalised `outerHTML` (comments removed; normalised: `data-has-alpine-state`, `wire:id|snapshot|effects`, the table's `mary<hash>` uuid prefix, 13-hex uniqids, `lw-<n>-<i>` keys, whitespace) at all 13 steps, and kept the full normalised HTML at 9 of them (load, expand 3, collapse/expand/collapse, collapse all, sort 1, sort 2, Qty on, 25 rows, refresh; the other 4 are hash-only). Row-by-row comparison fork vs upstream, for both U2/F2 and U4/F4, gives identical results:

- **Equal after masking only the ticking Age cell (`h:mm:ss`, changes every second)**: all 200 data rows at load, sort 1, sort 2, Qty on, collapse all; expansion rows at load/collapse all/sort.
- **Differences that are run-to-run noise, not fork behaviour** (they also appear between two upstream runs by construction): inside the expansion rows of the 2-3 expanded rows, child-component random ids (`data-choices="...patient-select-main-<random 20>"`, `wire:key="no-results-<random>"`) and floating-ui popover coordinates (`style="...left: 501px; top: 783px"`).
- **One real remaining difference (cosmetic, 25 of 25 rows at the last two steps, in U2/F2 and U4/F4 alike):** the date-needed cell's `<span x-text="typeof ground ...">`. At load it reads "Ground" in both arms. After hiding and re-showing a column (Qty off/on) it reads "" in **both** arms (so this empty-after-column-toggle state exists upstream too). At the next re-render that touches those rows (rows-per-page 25 and the refresh), **upstream re-populates "Ground" (morph overwrites the span with the server's static text) while the fork leaves the empty span** (the row HTML is unchanged on the server so the fork skips it). So the fork does not cause the empty cell, but it no longer "heals" it. Also 19-25 rows at the refresh step differ only by this.
- Not covered: the 4 hash-only steps (expand all, select 3, check-all, Qty off) have equal hashes only for the structural fields, not for the row HTML; Age was the only masked token.

### 2. Timings after the fix (unthrottled, 3 rounds; U0 and upstream arms re-run in the same session)

LoAF ms, median, % vs U0 (pre-fix fork medians in brackets for reference; absolute values drift ~2-4% between sessions, so compare within a column set):

| Metric | U0 | U2 | U4 | F2 | F4 |
|---|---|---|---|---|---|
| Initial load LoAF | 1071 | 1060 (-1%) | 1019 (-5%) | 1071 (-0%) [1119] | 1022 (-5%) [1124] |
| Initial load long tasks | 779 | 777 | 720 (-8%) | 792 (+2%) [899] | 722 (-7%) [814] |
| Sort | 538 | 556 (+3%) | 499 (-7%) | **245 (-54%)** [260] | **222 (-59%)** [236] |
| Expand one | 725 | 656 (-9%) | 551 (-24%) | **305 (-58%)** [352] | **269 (-63%)** [310] |
| Row-set change | 761 | 506 (-34%) | 452 (-41%) | **208 (-73%)** [206] | **175 (-77%)** [177] |
| Expand All (separate pass) | - | 953 | 877 | 686 [722] | 546 [613] |
| `tr` through `morph.updating` on expand-one | 401 | 401 | 401 | 201 | 201 |

Fork vs the same-markup upstream arm: sort -56% (F2/U2) and -55% (F4/U4); expand-one -54% / -51%; row-set -59% / -61%; Expand All -28% / -38% (before the fix: -55 / -51 / -61 / -30 for F2 vs U2). **No metric moved by more than 5% against the pre-fix relative gains: the fix is cost-free within noise.** Server TTFB unchanged (sort 1391 F2 vs 1356 U2; expand 1460 vs 1492). No JS errors in any run.

### 3. Test suites on the F arms

| Arm | Feature (348) | Browser (18) |
|---|---|---|
| F2 (U2 markup, markers on, fork) | **348/348 pass** | **18/18 pass** |
| F4 (U4 markup, markers off, fork) | 344/348: the **same 4** `OrderColumnHighlightsTest` "date cells listen for line header updated" failures as U4 (they pin the old `date: '...', ground: true` / `$event.detail.id == <n>` markup) | **18/18 pass** |

### 4. Client edits and the kill switch (the check I could not do before)

Why the earlier probe showed nothing: Livewire omits the `html` effect when a component's render is byte-identical to the last one, so no morph ran at all (0 `morph.updating`); my triggers re-rendered identical HTML. This time the trigger is **expanding another row (row 10)**, which changes that row's expansion subtree, so the root HTML differs and a real morph happens while row 3 is unchanged.

Probe (`ab2/probe.js`, U2 markup = deterministic ids + stable root, 200 rows): after load, set `data-probe="1"` on row 3's `tr`, `data-probe-cell="1"` on one `td`, `data-probe-inner="1"` on the element inside that cell; install hooks (`commit`, `morph.updating` per tag, `morph.added`); click expand on row 10; wait until Livewire is quiet 2.5 s; read the attributes. 2 rounds per arm, identical results:

| Arm | `livewireScriptConfig.skipUnchanged` | `tr` attr | `td` attr | inner attr | `morph.updating` total / of which `tr` |
|---|---|---|---|---|---|
| U2 upstream | (not present: null) | **removed (reset)** | removed | removed | 9064 / 401 |
| F2 fork, default | **true** | **kept** | **kept** | **kept** | 803 / 201 |
| F2off: fork, `livewire.morph_skip_unchanged=false` | **false** | **removed (reset)** | removed | removed | 9064 / 401 |

The row node itself stays in the DOM in all three (`isConnected` true). The kill switch needed no JS rebuild: I added `'morph_skip_unchanged' => env('LIVEWIRE_MORPH_SKIP_UNCHANGED', true)` to the worktree's `config/livewire.php` (all arms; ignored by upstream) and set the env var for the F2off server; `@livewireScriptConfig` then emitted `skipUnchanged:false` and the behaviour matched upstream exactly (same 9064/401 counts). Expected behaviour confirmed: **fork keeps client edits on unchanged keyed rows, upstream resets them, and the kill switch restores the reset.**
