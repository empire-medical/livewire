# Synthetic Orders Manage fixture

Stand-in for empire-v2's Orders Manage page (vendor view). Core Livewire 4.4.8 + Alpine only, no app classes/Blade components/JS. All data is synthetic and deterministic (crc32-derived, no RNG, no real names/addresses/IDs). Verified in this repo with Testbench: `vendor/bin/phpunit perf/fixtures/orders-manage/SmokeTest.php` (passes).

## Use

```php
require_once '/home/endl/livewire/perf/fixtures/orders-manage/autoload.php';   // PSR-4 for Livewire\Performance\Fixtures\
\Livewire\Performance\Fixtures\Registrar::register(withRoute: true);           // view ns `perf-fixtures`, 2 components, GET /perf/orders-manage
Livewire::test(\Livewire\Performance\Fixtures\OrdersManage::class);
```
Keep `livewire.inject_morph_markers = true` (default). Sushi needs a writable cache dir (Testbench provides one).

## Files

| File | What |
|---|---|
| `autoload.php` | tiny PSR-4 loader (no composer.json change) |
| `Registrar.php` | registers views, components, optional route |
| `OrdersManage.php` | root component (page). Layout `perf-fixtures::layout` |
| `ExpandedRow.php` | `#[Lazy]` child, mounted per expanded id |
| `Forms/FilterForm.php` | 16 `#[Url(except: '')]` props |
| `Forms/ExpandedRowForm.php` | 47 nullable public fields |
| `Models/Fixture{Line,Address,AddressVendor,Product,Vendor,Warehouse,TrackingNumber}.php` | Sushi models |
| `Support/Seed.php` | deterministic generators |
| `views/orders-manage.blade.php`, `expanded-row.blade.php`, `layout.blade.php` | markup |
| `SmokeTest.php`, `MeasureTest.php` | Testbench tests; MeasureTest prints the numbers below |

## Shape produced (measured with `Livewire::test`, default perPage 200)

| Metric | Value |
|---|---|
| Rows / page | 200 of 240 for vendor 1 (300 lines total, 26 columns in `FixtureLine`) |
| Visible columns | 12 (actions, id, patients, part #, description, manufacturer, qty, cost, price, date needed, date expected, warehouse) + checkbox + expand = 14 `<td>` per data row |
| `<tr>` | 401 (2 per row + header) |
| `x-data` scopes | 801 (4 per row + table root): presence badge, part #, date needed, date expected |
| Custom-directive stand-ins | 3 per row, as `x-on:line-header-updated.window` listeners |
| `wire:key` | 804 collapsed page (4 per row + root + 2 header ths + filters); none on data-row `td`s |
| Morph marker comments (`<!--[if ...`) | **22,812 (~114 per row)**. My earlier prod estimate was 70-100/row; the fixture is a bit above that |
| HTML size, 0 expanded | 3.25 MB (see caveat below), of which `data-rows` JSON attr = 186 KB |
| HTML size, 10 / 200 expanded | 3.16 MB / 3.85 MB |
| Root snapshot | 70.5 KB (0 expanded), 71 KB (10), 84 KB (200 expanded; the root snapshot grows with `expanded` and child components, each child's own snapshot is separate in the HTML) |
| Lazy children mounted | `expanded` ids → 1 `<livewire:perf-fixtures.expanded-row>` each (199-200 at max, one `wire:snapshot` per child) |
| Computeds on root | 25 `#[Computed]` (plain, no persist): `tableRows` (not in snapshot, also fills the sidebar prop like the app), tableHeaders, cellDecoration, pageIds, allIds, vendor/manufacturer/warehouse/status/category/costCenter/orderMethod/facility name maps, expanded/selected lookups, rowClasses, alignmentClasses, columnWidths, sortableKeys, colspan, requiredNoteLineIds, groundShippingLineIds, pageTitle. The view reads rowClasses, alignmentClasses, cellDecoration, vendorNames/manufacturerNames/warehouseNames once or more per row (200 x several `__get` -> `findComputedAttribute`) |
| Root public props | sortBy, vendorId, shippingAddressId (`#[Url]`), selected, expanded, perPage=200, pageNumber, tablePreferencesModal, multipleTablesOnPage, defaultSortBy, toggleableTableHeaderState, `FilterForm searchFilterForm`, initialLoadComplete, type, showEmpireAccounts, `ordersManageSidebarData` (`lines` = 200 x 16-key arrays) |
| Alpine on root | `$wire.entangle('selected')`, `$wire.entangle('expanded', true)`, hidden `<template x-ref="tableState" data-page-ids data-all-ids data-rows>` with getters that `JSON.parse` the datasets (the stable-rows shape, because you asked for the data-rows template) |

Expanded row child: `#[Lazy]` with string `placeholder()`, 9 public model props (Address, 2x AddressVendor, Product, 3x Vendor, 2x Warehouse) + `Collection $trackingNumbers` + `Carbon $dateExpected` + `ExpandedRowForm` (47 fields) + the line as a `#[Computed]` (like the app). Template: 7 `wire:model` (4 `.blur`, 3 plain), 7 `wire:click`, ~18 `wire:key` sites (2 inside loops), 19 `@if`, 4 `@foreach`, ~60-70 elements. Actions: `save` (dispatches `Orders.refresh`), `cancel`, `markChanged`, `applyStandardPrice`, `addNote`, `clearNote`, `refreshTracking`. Root actions for benchmarking: `ping()` (no-op full re-render), `toggleExpanded($id)`, `expandAll()`, `collapseAll()`, `sort($column)`.

## What I guessed / differences from the app

- **HTML size is probably overstated vs prod.** 1.67 MB of the 2.9 MB `<tbody>` is pure whitespace (Blade keeps the indentation of the heavily nested markup). The app's table is indented similarly but I have no measurement of real prod HTML, so treat absolute bytes as GUESS and compare fork-vs-upstream only.
- Marker count is also from my inlined markup: per cell I have one `@if` chain for the column, plus the two `@if($link)` pairs and the outer in-list `@if`, like `table.blade.php`; real cells also get markers from nested app components (not modelled).
- Custom directive `x-empire-line-header` replaced by `x-on:line-header-updated.window` listeners (same cost class: 1 listener per use).
- 4 `wire:key` per row exactly as in the app; the app uses `wire:ignore.self` on the expansion `tr`, kept.
- Sort is `orderBy(vendor_id)->orderBy(id)` (all rows tie on vendor, like the app's id tiebreak); no real join.
- Top pagination links replaced by a plain text block; `WithPagination` is still used (page state, `paginate()`).
- Expanded child omits the app's 7 nested `<livewire:...>` tags, `wire:loading`-free like the app, and has about half the app's `@if`/element count (app: 38 `@if`, 71 `<x-...>` components, ~1,192 template lines). Form is 47 fields (app ~50+).
- Sushi models were not verified for ModelSynth rehydration on a real second request beyond `Livewire::test` (it did work in `test_expanded_rows_mount_lazy_children` and MeasureTest with 200 children); production uses MySQL, so dehydrate/hydrate DB cost differs. Each hydrate here hits Sushi's in-memory sqlite.
- The sidebar `lines` array is filled inside the `tableRows` computed (same side-effect pattern as the app), so it is rebuilt on every render.
- Not modelled: `#[On('Orders.refresh')]` listener on root, saved views, preferences modal, presence manager, sidebar child with `wire:poll.60s`, the 24 always-mounted modal children (add them if you want that cost; I can supply a generator).
