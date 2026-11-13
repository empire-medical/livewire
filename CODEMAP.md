# Livewire Code Map

Navigation aid for future sessions (written 2026-11-12 against `4.x` @ e03c29c8). All source files (PHP and JS, non-test) have now been read in full by a reader, apart from the exceptions listed in section 1b. The top-level tables in this file are summaries; **the per-file detail, hook lists and file:line perf observations live in `codemap/*.md`**. Claims there marked UNSURE were not confirmed.

Line counts are approximate. Unit and browser tests sit beside code as `UnitTest.php`/`BrowserTest.php` (JS tests as `*.spec.js`), so ignore those when looking for implementation.

## 1. Request lifecycle (the perf-critical path)

**Initial page render**
`<livewire:foo />` or `@livewire` -> `Mechanisms/CompileLivewireTags/LivewireTagPrecompiler` (compile-time tag rewrite) -> `Mechanisms/RenderComponent` -> `HandleComponents::mount()` -> `snapshot()` embedded in the HTML as `wire:snapshot`/`wire:effects`.

**Subsequent update (the "round trip")**
JS `js/request/*` POSTs to `/livewire/update`
-> `Mechanisms/HandleRequests/HandleRequests` (payload guards, middleware, per-component loop)
-> `HandleComponents::update($snapshot, $updates, $calls)`:
1. `Checksum` verify
2. `fromSnapshot()` -> `hydrateProperties()` via synths
3. `updateProperties()` (apply `wire:model` writes)
4. `callMethods()`
5. `render()` (Blade view render)
6. `snapshot()` -> `dehydrateProperties()` via synths
-> JSON back -> JS `request/message.js` -> `morph.js` patches the DOM.

Cost centres to look at first:
- Synth hydrate/dehydrate per property: `Mechanisms/HandleSynths/HandleSynths`
- Checksum: `Mechanisms/HandleComponents/Checksum`
- Reflection of public properties: `Drawer/BaseUtils::reflectAndCachePropertyMetadata`
- Hook fan-out: `ComponentHookRegistry` + `EventBus` `trigger()`, which hits every `Support*` feature on every lifecycle event
- Blade render
- Snapshot JSON size
- JS morph

### Verified notes on the update path (observations, not conclusions)

These are facts read from the code, listed as leads for investigation.

- `HandleRequests::handleUpdate` processes the `components` array **serially** in a `foreach`. Each response carries `snapshot` as a JSON **string** inside the JSON envelope (double-encoded).
- Per component in `HandleComponents::update`: a `Checksum::verify` HMAC over `json_encode($snapshot)`, then hydrate, a second `Checksum::generate` HMAC on the way out, and `json_encode` of the snapshot again in `handleUpdate`. `generate()` calls `app('encrypter')->getKey()` each time.
- `Utils::getPublicPropertiesDefinedOnSubclass` is called repeatedly per request (dehydrate, `getView`, and **once per `updateProperty`**). Reflection metadata is cached per class, but `extractPropertyValuesFromInstance` builds a new `ReflectionObject` and `ReflectionProperty` objects each call. `Utils::getPublicMethodsDefinedBySubClass` is **uncached** and runs per method call.
- `hydrateProperties` builds `new \ReflectionProperty` for every hydrated property.
- `getView` + `Utils::generateBladeView` create an anonymous `Illuminate\View\Component` class and call `app('view')->make` on every render.
- Hook cost: for each component, each `Support*` hook class is **instantiated on every hydrate and every mount** (`new $hook`, `callBoot`, `callHydrate`) before `skip()` is consulted. `bootFeatures()` lists 47 hook classes, so that is up to 47 `new` + `callBoot` + `callHydrate` per component per request (before any `skip()` returns early). Not yet measured.
- `EventBus::trigger` re-merges listener arrays on each call.
- `HandleSynths::findByKey` is a linear scan and instantiates a synth per property per call. `findByTarget` caches the class by `get_debug_type`.
- `if (config('app.debug'))` microtime profiling is inlined throughout `update()` and `callMethods()` (the `profile` event).
- JS: after each response `morph.js` re-writes `wire:snapshot` (the full encoded snapshot) onto the root element.

## 1b. Detail files (`codemap/`)

| File | Covers |
|---|---|
| `codemap/php-core.md` | `Compiler`, `Finder`, `FrontendAssets`, `PersistentMiddleware`, `ExtendBlade`, `CompileLivewireTags`, `Component`, `ComponentHook`, `LivewireManager`, service provider, `Drawer`, synthesizers, `config/livewire.php` (every key and who reads it) |
| `codemap/features-a-accessing-to-events.md` | AccessingParent ... Events |
| `codemap/features-b-filedownloads-to-models.md` | FileDownloads ... Models (synths for models, forms, uploads) |
| `codemap/features-c-morphaware-to-renderless.md` | MorphAwareBladeCompilation ... RequestInteractions (nesting, reactive props, query string, navigate) |
| `codemap/features-d-scripts-to-wireables.md` | ScriptsAndAssets ... WithMethod, plus how `Livewire::test()` drives a component |
| `codemap/js-core.md` | `js/` root files and `js/request/`, the JS round trip, the build (`scripts/build.js`) |
| `codemap/js-directives-features.md` | `js/directives/*`, `js/features/*` |
| `codemap/plugins-tests-tooling.md` | `js/plugins/*`, test infrastructure and Docker needs, scripts, CI, docs, stray root files |

Final-pass reads (done directly, in full): `DuskBrowserMacros.php` (Dusk-only test helpers: assertion macros and `waitForLivewire`/`waitForNavigate*` which hook `Livewire.hook('commit'|'request'|'navigate.request')`; nothing on a production path), `supportErrors.js` (the `$wire.$errors` object: reads `snapshot.memo.errors`, client-side overrides reset when the snapshot changes), `supportDataCurrent.js`, `supportDisablingFormsDuringRequest.js`, `directives/wire-navigate.js`, and the rest of `morph.js` and `request/interactions.js`. The 8 JS spec files are plain vitest unit tests (jsdom, `@` alias to `js/`) and were skimmed for structure only. `vendor/` is now installed, but Laravel-internals claims in the detail files were written before that and are still UNSURE until checked.

### Consolidated performance leads (from the readers; each is a hypothesis until measured)

**Per-component, per-request hook overhead (PHP)**
- 47 hook classes (`bootFeatures()`) are `new`-ed per component on every mount and hydrate. None implements `skip()` except a few. `update`/`call`/`render` allocate a closure per hook even if it has no such method (`ComponentHook.php:183-265`). Five hooks only have a static `provide()` and are still instantiated: BladeAttributes, Entangle, ConsoleCommands, CompiledWireKeys, LegacyComputed.
- `AttributeCollection::fromComponent` does a full reflection and `newInstance` walk with no per-class cache, on every instantiation. `SupportAttributes` runs a `whereInstanceOf` filter per lifecycle event.
- `SupportLifecycleHooks` rebuilds a pattern list and runs `str()->is()` per action, `studly()` per property, and loops all traits about 10x per request.
- Uncached reflection: `getPublicMethodsDefinedBySubClass`; per-call `ReflectionObject` in `all()` and `extractPropertyValuesFromInstance`; `ReflectionClass` per mount in SupportFormObjects and SupportLazyLoading; per dehydrate in `IsLazy`; `hasAttribute` calling `newInstance()`; `ImplicitlyBoundMethod` building up to 4 `ReflectionClass` per typed param.
- Empty `scripts`/`assets`/`errors`/`children` memos are added to every snapshot (ScriptsAndAssets, Validation, NestingComponents).
- SupportNestingComponents reflects all public properties on every mount. SupportPagination's `skip()` calls uncached `class_uses_recursive`. SupportQueryString recomputes per instance. SupportReactiveProps does 2-4 `json_encode` per reactive prop per request.
- Validation: `getRules`/`getMessages`/`getDataForValidation` uncached, re-run per `#[Validate]` property update. `BaseSession` does `Session::put` per property per request with no dirty check.
- `SecurityPolicy` runs 14 uncached `is_a` per hydrated class. `SupportReleaseTokens` resolves the component class per checksum verify.
- `PersistentMiddleware`: when no middleware ran, each component in a batch repeats `request()->duplicate()`, a route match and `gatherRouteMiddleware`.

**Compile and render (PHP)**
- `CacheManager`/`Compiler::compile()`: about 13 stat calls and 8 md5 per call, then `require` again. No production short-circuit. (Unread: whether `Factory`'s cache sits in front of it.)
- `SupportMorphAwareBladeCompilation` is a global precompiler on every Blade compile: `substr` copy of the template per directive, regexes rebuilt each time. At runtime each marker calls `isRenderingLivewireComponent()`, including per loop iteration.
- `ExtendBlade` applies/undoes directives and precompilers via `invade()` reflection on every outermost render, even for cached views. `trigger('view:compile')` has no listeners in `src`.
- `SupportSlots` builds a `SlotProxy` and calls `view->with` per render. `SupportValidation` clones and shares a `ViewErrorBag` per render.
- Page level: `FrontendAssets` re-reads `manifest.json` per `scripts()`. `SupportAutoInjectedAssets` makes up to 4 full-HTML regex passes.

**Models, uploads, events**
- `SupportModels`: `getResolvedModel` runs `isDirty()` and a container lookup per hit; `meta['keys']` grows with collection size; `useWritePdo` forced.
- `SupportFileUploads`: `cleanupOldUploads` lists the temp dir on every `_finishUpload`; `FileUploadConfiguration` re-reads config each call.
- Three global `on('call')` listeners run on every action (Json, MagicActions, FileUploads). `SupportEvents::__dispatch` runs `invade()` reflection up to 3x plus a 3-pass attribute filter.
- `SupportTesting` hydrate/dehydrate/exception hooks also run in production.

**JS (client): TOP LEAD, confirmed in source and matching an app profile (see "App usage profile" below)**
- `lifecycle.js:65-110` registers an `Alpine.interceptInit` that, for every element carrying any `wire:` attribute (so every `tr`/`td` with `wire:key`), calls `findComponentByEl(el, false)` (`store.js:53`). That calls `walkUpwards` (`utils.js:529`) and, **at every ancestor level, `walkBackwards` over all previous siblings** looking for slot fragment markers (`store.js:59-83`). Cost per element is roughly depth x preceding siblings, so a 200-row table makes it roughly quadratic in rows. The app profile measured this as the largest single self-time (~90ms of ~128ms in the callback, for a 200-row response). The sibling scan is only needed to resolve slot ownership, so there are likely cheap fast paths (stop at the first ancestor with `__livewire`; skip the scan when no slot markers exist).
- `lifecycle.js:40-60` has a second `interceptInit`-style path that also calls `findComponentByEl` and the `matchesForLivewireDirective` scan per element.
- `wire:navigate` init (`directives/wire-navigate.js`): `Alpine.interceptInit` runs for **every element**, looping 5 attribute names with `hasAttribute`. `supportDataCurrent.js` runs `querySelectorAll` on all navigate links on every `livewire:navigated` and does `Array.from(el.attributes).some(...)` per link. `alpine:navigating` inscribes snapshot+effects on every component.
- `morph.js` `getMorphConfig`: `lookahead: false`; `trigger('morph.updating')` and `'morph.updated'` fire per element through the generic hook bus; `key()` does up to 3 attribute reads per element.
- `interactions.js`: each `model.live` action calls `getDeepChildrenWithBindings` for components with children (`componentHasBoundChildren`), and `$commit` actions are created for every bound child per message.
- Every request re-sends the full encoded snapshot per component (`request/index.js:268`, `component.js:242`).
- `JSON.parse(snapshotEncoded)` sits inside a messages x payloads loop (`request/index.js:428-451`): N bundled components parse N^2 snapshots.
- `mergeNewSnapshot` makes about 4 deep-clone passes, runs a deprecated discarded `diff()` (`component.js:71-97`). `diffAndPatchRecursive` calls `deeplyEqual` (2x `JSON.stringify`) per key and depth (`utils.js:370`). `getUpdates` walks the full canonical/ephemeral trees per send (`utils.js:223-347`). `Message.addAction` recomputes all fingerprints per add (`message.js:36`).
- Per-attribute `new RegExp` (`directives.js:5-13`); `trigger('morph.updating')` per DOM node (`morph.js:126`); repeated `querySelectorAll('[wire\\:id]')`.
- `supportDisablingFormsDuringRequest.js:13` schedules a `setTimeout` per directive init. Each `wire:loading` element registers its own global `interceptMessage` (`wire-loading.js:110`). `wire-dirty.js` stringifies all state per dirty element per change and has no cleanup. `evaluator.js:83` runs 3 uncached regex passes per reactive change. `wire-poll.js:100` re-parses attributes per tick.
- `wire:navigate` parses each page twice (`page.js:16`, `transition.js:9`), serializes the whole DOM with `outerHTML` per navigation (`history.js:74`), and `oldBodyScriptTagHashes` only grows.
- Build: non-watch builds set `NODE_ENV` to `'development'` (`build.js:96`). Impact UNSURE.

## 1c. App usage profile (reported by the session working on empire-v2 issue #992; not independently verified)

- App: livewire/livewire 4.4.8 in `composer.lock` (unknown whether it resolves this fork). About 209 component classes, 140 files in `resources/views/livewire`. Grep counts: `wire:model.live` x120, `wire:navigate` x69, `wire:dirty` x48, `#[Url]` x310, `#[Session]` x0, `wire:poll` x1, `#[Lazy]` x9, `@island` x31, `WithFileUploads` x29.
- Production (from that session's notes, unverified): Octane; CloudFront -> ALB -> Octane, all 120s timeouts; Aurora read replicas; temp uploads on S3. Unknown: debug flag, opcache, route/view caching.
- Slowest known page: Orders Manage (vendor page): one Livewire component rendering up to 200 table rows. Client side INP 2.1s, nav p95 6.9s, server <0.4s. Server-side Livewire time is led by `orders.expanded-table-row-content` (~28%, p50 164ms, p95 646ms, mostly an unspanned external FedEx call).
- Client profile of one 200-row response (original code, local): ~500ms Alpine `initTree` on the replaced subtree, ~100ms Livewire morph, ~128ms in the `interceptInit` callback (`findComponentByEl` -> `walkUpwards`, ~90ms self). With a stable key but no skip, Alpine morph's `cloneNode` re-initialises every unchanged `x-data` tree (~310ms). `isStartFragmentMarker`/`isEndFragmentMarker` ~20-30ms per response. Initial load: ~580ms `initTree`, ~850ms native parse/layout of ~2.7MB HTML (8,600 nodes, 2,200 `x-data`, ~10,200 directive attrs).
- App-level workarounds already made (empire-v2 branch `fix-orders-manage-client-cost-992`, draft PR #1001): fewer per-row Alpine scopes, opt-in stable rows with a `Livewire.hook('morph.updating')` that calls `skip()` when a per-row md5 of the server HTML is unchanged, and a sort tie-break so rows keep their positions. **Do not touch those empire-v2 files while PR #1001 is open** (`x-table`, `table.blade.php`, `boot.js` morph hooks, `lineCells.js`, `skipUnchangedRows.js`).
- Gotcha: Livewire wraps `@if` in `<!--[if BLOCK]-->` comments. Comment siblings between rows stopped Alpine morph from matching rows by `wire:key` (4 of 200 matched) until plain `@php` was used.
- Fork-level candidates they did not do: (1) `findComponentByEl` per `wire:` element (above), (2) Alpine morph `cloneNode` re-running `initTree` on unchanged `x-data` trees (the skip hook is an app-level workaround), (3) fragment-marker walking.

### 1d. Investigator's full app report: `codemap/app-usage.md` (2026-11-12; read-only survey of empire-v2, Orders Manage and its expanded row in depth, other pages metrics only)

Key facts: production is FrankenPHP Octane with about 10 workers, `opcache.validate_timestamps=0`, `view:cache` on deploy; `inject_morph_markers` is true; Livewire JS is imported from `vendor/livewire/livewire/dist/livewire.esm` by the app's Vite bundle (not `@livewireScripts`), so using the fork needs a built `dist/` (the fork never commits it; it would have to come from a tagged release build) plus a vendor swap and a Vite rebuild. 663 `#[Computed]`, 310 `#[Url]` (mostly on filter Form objects, up to 38 on one form), 91 Form objects, 270 `<livewire:>` tags, 24 modal children mounted on every Orders page, Orders Manage root snapshot carries a 16-key array per row (about 3,400 scalars at 200 rows), expanded row child is `#[Lazy]` with about 9 public Eloquent model props.

**Correction (verified against the fork source):** the report's claim that `BaseComputed::handleMagicGet` skips in-request memoisation when `persist`/`cache` is set is **not true for 4.4.8**: `BaseComputed.php:31-52` does `$this->requestCachedValue ??= ...` in all three modes. Only a computed that returns `null` is re-evaluated on each access. The app's `App\Attributes\Computed` memo is therefore redundant (it may predate 4.4.8). The real per-access cost is upstream of that: every `$this->computed` read goes `Component::__get` -> `trigger('__get')` -> `SupportLegacyComputedPropertySyntax::findComputedAttribute` (`:46-53`), which camel-cases the name, filters all attributes with `whereInstanceOf`, and calls `getName()` (another `str()->camel()`) per computed attribute on the class, **before** the memo is consulted. Blade views read `$this->x` inside row loops, so this scales with rows x reads x computeds per class.

## 2. PHP core (`src/`)

| File | Role |
|---|---|
| `Component.php` | Base class users extend. Composes all `Handles*` traits from features. |
| `ComponentHook.php` | Base class for feature hooks (`boot/mount/hydrate/update/call/render/dehydrate/destroy/exception`). |
| `ComponentHookRegistry.php` | Static. `register()` collects hook classes. `boot()` subscribes one `mount` and one `hydrate` listener **per hook class**. On each, `initializeHook()` does `new $hook` for the component, `setComponent`, optionally `skip()`, and stores it in a `WeakMap` keyed by component. The other events (`update`, `call`, `render`, `renderIsland`, `render.placeholder`, `dehydrate`, `destroy`, `exception`) each have one listener that loops over that component's hooks via `proxyCallToHooks`. `proxyCallToHooks` returns a closure per call that forwards finish-callbacks. |
| `EventBus.php` | `on/before/after/off/trigger`. `trigger()` merges the before/on/after listener arrays on every call, runs each listener, and collects non-null returns as "finishers". It returns a closure that threads a `$forward` value through them. Listeners and finishers are how features wrap lifecycle phases (the `$finish = trigger('render', ...)` pattern). |
| `LivewireServiceProvider.php` | Boot. `bootFeatures()` holds the list of all `Support*` features. Registers mechanisms and Blade directives. |
| `LivewireManager.php` / `Livewire.php` | Main service (`app('livewire')`) and its facade: mount, test, component registration, flush state. |
| `helpers.php` | Namespaced free functions: `str`, `invade`, `once`, `of`, `revert`, `wrap`, `pipe`, `trigger`, `on`, `after`, `before`, `off`, `memoize`, `store`. |
| `Concerns/InteractsWithProperties.php` | Component property helpers (`reset`, `only`, `all`, `pull`). |
| `Attribute.php` + `Attributes/*` | Thin public attribute classes. Each extends the real implementation in `Features/Support*/Base*Attribute.php`. |
| `Drawer/` | Misc static utilities. `BaseUtils` has the property reflection and caching (hot) and `isSyntheticTuple`. `Utils` has HTML attribute and response helpers. `ImplicitRouteBinding` is route-model binding for components. `Regexes` has Blade directive regexes. |
| `Factory/Factory.php` | `create($name,$id)` instantiates a component (`new $class`, then `setId`/`setName`). `resolveComponentNameAndClass` goes name -> `Finder::resolveClassComponentClassName`, else multi-file path, else single-file path (compiled by `Compiler`), else the registered missing-component resolvers. Keeps a per-process `resolvedComponentCache`. Reached via `LivewireManager::new()`. |
| `Finder/Finder.php` (522) | Locates class, single-file and multi-file components on disk, including the lightning-bolt-named files. |
| `Compiler/` | Single-file (`.blade.php` with PHP) and multi-file component compiler: `Compiler`, `CacheManager` (compiled-file cache and mtime checks), `Parser/{Single,Multi}FileParser`. |
| `ImplicitlyBoundMethod.php` | DI for component method params. |
| `Wrapped.php` | `wrap($obj)->method(...)`. Invokes through `ImplicitlyBoundMethod` (container DI). Returns the fallback if the method is missing. On a throw it fires the `exception` event so a feature can stop propagation. **Every component action call and every `render()` call goes through it.** |
| `Pipe.php` + `Transparency.php` | `pipe($x)`: a small fluent wrapper that threads a value through callables. `Transparency` is the trait that makes it behave like its target (`__toString`, `ArrayAccess`, `IteratorAggregate`). Nothing hot. |
| `WireDirective.php` | Value object returned for `$attributes->wire('model')` in Blade (`name()`, `directive()`, `value()`, `modifiers()`, `hasModifier()`, `toHtml()`). Created in `SupportBladeAttributes`. Not on the update path. |
| `Form.php`, `Wireable.php`, `WithFileUploads.php`, `WithPagination.php`, `WithoutUrlPagination.php` | Aliases for the feature classes and traits users import. |
| `Exceptions/*` | Typed exceptions. |
| `Performance/RenderPerformanceBenchmarkTest.php` | **Only existing benchmark.** 1000x mount of simple components. Mount only. |
| `Tests/` | Shared test helper components. |

### Mechanisms (`src/Mechanisms/`)

| Dir | Role |
|---|---|
| `HandleRequests/` | HTTP endpoint (`/livewire/update`). `HandleRequests` is the controller logic, with payload-size and call-count guards (`PayloadGuardsUnitTest`). `EndpointResolver` builds URL paths. `RequireLivewireHeaders` is middleware. `StreamedResponse`. |
| `HandleComponents/` | Heart of hydrate -> update -> call -> render -> dehydrate. See section 1. Also `ComponentContext` (per-component effects/memo bag), `ViewContext`, `SecurityPolicy` (class denylist), `CorruptComponentPayloadException`, and `Synthesizers/` (Array, Collection, Carbon, Enum, Float, Int, StdClass, Stringable and the `Synth` base). |
| `HandleSynths/` | `HandleSynths` holds the ordered synth list (Carbon, Collection, Stringable, Enum, StdClass, Array, Int, Float, with user and feature synths unshifted to the front). `dehydrate()` returns primitives as-is, otherwise `[data, meta]` with `meta['s']` = synth key. `hydrate()` validates `meta['class']` against `SecurityPolicy` first. `hydrateForUpdate`/`hydratePropertyUpdate` are the **trust boundary for `wire:model` updates**: synth identity comes only from the checksum-verified snapshot, never the update payload. `resolve()` uses `findByKey` (linear scan) or `findByTarget`. Already has a `typeCache` (runtime type -> synth class). `PersistedValueCodec` is used only by `#[Session]`: HMAC-signed envelope for values stored in the session. |
| `PersistentMiddleware/` | Re-applies the page's original middleware to `/livewire/update` requests. |
| `ExtendBlade/` | Hooks Blade compile and render: `ExtendedCompilerEngine`, `DeterministicBladeKeys` (deterministic `wire:key`s for loops). |
| `CompileLivewireTags/` | `<livewire:...>` tag precompiler. |
| `FrontendAssets/` | Serves and injects `livewire.js`, the `@livewireScripts` and `@livewireStyles` directives, the sourcemap, and cache headers. |
| `HandleRouting/` | Registers the `Route::livewire($uri, $component)` macro (a GET route to `LivewirePageController`) and the lazy-loading route macro. |
| `RenderComponent.php` | Backs `@livewire` / `<livewire:>` and the `Blade::component` render. |
| `DataStore.php` | Per-component-instance key/value store (what `store($this)` uses). |
| `ClearCachedFiles.php` | On console `view:clear` / `optimize:clear` success, calls `livewire.compiler->clearCompiled()` so compiled single/multi-file components are wiped too. |

## 3. Features (`src/Features/Support*`)

Each feature is a folder. It may hold a `Support*.php` hook, a `Handles*` trait for `Component`, a `Base*Attribute`, a synth, and its tests. The registry is `LivewireServiceProvider::bootFeatures()`.

**Many folders have no PHP at all** and are JS-only, with the PHP folder existing only to hold tests. These are: DataBinding, DataLoading, DisablingFormsDuringRequest, ErrorResponses, Interceptors, JsComponent, LargePayloads, MagicErrors, Octane, Polling, PreserveScroll, RequestInteractions, StdClasses, WireBind, WireConfirm, WireIgnore, WireIntersect, WireShow, WireSort, WireText, plus several whose logic is attribute-only.

Grouped by area:

- **Data in/out (synths, hydration).**
  - `SupportModels`: `ModelSynth` and `EloquentCollectionSynth`.
  - `SupportLegacyModels`: the older model binding.
  - `SupportFormObjects`: `FormObjectSynth`.
  - `SupportWireables`: `WireableSynth`.
  - `SupportFileUploads`: `FileUploadSynth`, `TemporaryUploadedFile`, and the upload and preview controllers.
  - `SupportStdClasses`.
  - `SupportComputed`: `#[Computed]`, with caching in `BaseComputed`.
  - `SupportLockedProperties`.
  - `SupportAttributes`: attribute infrastructure (`AttributeCollection`, `AttributeLevel`) that lets `#[Attr]`s hook into the lifecycle.
- **Lifecycle.** `SupportLifecycleHooks` (`mount/updating/updated/rendering...`), `SupportEvents` (`dispatch`, `#[On]`, `Event`), `SupportRenderless`, `SupportAsync`, `SupportIsolating`, `SupportLazyLoading` (`#[Lazy]`/`#[Defer]`), `SupportMagicActions` (`$refresh`, `$set`, ...), `SupportWithMethod`, `SupportAuthorization` (`#[Authorize]`), `SupportReleaseTokens` (stale-deploy protection), `SupportMultipleRootElementDetection`.
- **Rendering and Blade.** `SupportMorphAwareBladeCompilation` (injects morph markers into `@if`/`@foreach`), `SupportCompiledWireKeys`, `SupportBladeAttributes`, `SupportHtmlAttributeForwarding`, `SupportSlots`, `SupportIslands` (partial re-render regions, with their own `Compiler/`), `SupportTeleporting`, `SupportCssModules`, `SupportJsModules`, `SupportJsEvaluation` (`#[Js]`), `SupportScriptsAndAssets`, `SupportAutoInjectedAssets`, `SupportCSP`.
- **Nesting.** `SupportNestingComponents`, `SupportNestedComponentListeners`, `SupportReactiveProps`, `SupportAccessingParent`, `SupportWireModelingNestedComponents` (`#[Modelable]`), `SupportEntangle`.
- **Routing and navigation.** `SupportPageComponents` (layout/title), `SupportNavigate` (`wire:navigate`), `SupportRedirects`, `SupportQueryString` (`#[Url]`), `SupportPagination`, `SupportSession` (`#[Session]`), `SupportDisablingBackButtonCache`, `SupportLocales`, `SupportFileDownloads`, `SupportStreaming`.
- **Validation.** `SupportValidation` (`#[Validate]`, `#[Rule]`, `HandlesValidation`).
- **Single and multi-file components.** `SupportSingleAndMultiFileComponents` is an **empty stub** (class with an empty `provide()`) and is **not registered** in `bootFeatures()`. The real logic is `Factory` + `Finder` + `Compiler`.
- **Dev and test tooling.** `SupportTesting` (`Livewire::test()`, `Testable`, `RequestBroker`, `Render`, Dusk macros), `SupportConsoleCommands` (`make`, `convert` and similar), `SupportChecksumErrorDebugging` (**dead code: not registered**).
- **Wire directive folders (tests plus a little PHP).** `SupportWire*`: Current, Loading, Ref, Sort and others. The implementation is in `js/directives/` or `js/features/`.

## 4. JavaScript (`js/`), bundled by `scripts/build.js` (esbuild) into `dist/`

| File or dir | Role |
|---|---|
| `index.js` | Entry. Exports `Livewire` and `Alpine`, and imports features and directives for side-effect registration. |
| `lifecycle.js` | `start()`: boot Alpine, find components, wire everything. |
| `component.js` | `Component` class: snapshot, effects, `$wire`, child tracking, `mergeNewSnapshot`, morphing. |
| `store.js` | Global component registry (`find/first/all`). |
| `$wire.js` | The `$wire` proxy (property access, actions, `$set/$refresh/$watch`). |
| `hooks.js`, `events.js` | JS-side hook system (`on/trigger`) and event dispatch. |
| `directives.js` + `directives/` | `wire:*` directive parsing (`Directive` class) and per-directive implementations: model, loading, poll, dirty, navigate, transition, wildcard (the `wire:click` family) and others. |
| `features/` | One file per feature (`supportX.js`), mirroring the PHP `Support*` names: file uploads, islands, query string, streaming, navigate, errors, entangle and others. |
| `request/` | **Network layer.** `index.js` (723): the outstanding-action batching, `fireAction`, interceptors, error and expiry handling. `action.js`, `message.js` (per-component payload, response handling), `messageBus.js` (queuing and bundling), `request.js` (fetch), `interactions.js` (coordinates concurrent requests), `interceptor.js`, `legacy.js`. |
| `morph.js` | `morph(component, el, html)`: parses the HTML into a wrapper element, **re-sets `wire:snapshot` and `wire:effects` attributes on the new root**, re-uses existing child component DOM by `wire:id`, then runs `Alpine.morph` inside `transitionDomMutation`. `morphFragment` does the same for islands. Fires `morph`/`morphed` hooks. |
| `fragment.js` | Fragment markers. Server wraps islands and slots in IE-conditional comments, `<!--[if FRAGMENT:name\|k=v]><![endif]-->` ... `ENDFRAGMENT`. This file finds them (`closestFragment`, `findFragment`), decodes the `k=v\|k=v` metadata, and does append/prepend. PHP side: `HandlesIslands`, `Slot`, `PlaceholderSlot` (`wrapWithFragmentMarkers`). |
| `island.js` | `Island` class: request metadata, and `skipIslandContents()` used during morph. Honours `render` modes bypass/skip/once, and mode append/prepend. |
| `diff*.spec.js` | Snapshot diff tests. |
| `evaluator.js` | Wraps `Alpine.evaluateRaw` for server-sent `$js` effects and directive expressions, collecting Alpine scope keys up to the component root. Errors are reported, not thrown. |
| `debounce.js`, `utils.js`, `utils/modal.js` | Helpers. `utils.js` includes dump splitting and CSRF. |
| `plugins/navigate/`, `plugins/history/` | Bundled SPA navigation and history plugins (the `wire:navigate` engine). |

JS tests: `npm run test:run` (Vitest, `js/**/*.spec.js`).

## 5. Tests, config and tooling

- `tests/`: `TestCase.php` (Testbench base), `BrowserTestCase.php` (Dusk base), `TestComponent.php`, `fixtures/`, `views/`.
- `legacy_tests/`: v2/v3 carry-over unit and browser tests (`LegacyTests\` namespace).
- `phpunit.xml.dist`: `Unit` and `Browser` testsuites. Feature tests are discovered inside `src/`.
- `config/livewire.php`: published config.
- `stubs/`: Dusk stubs.
- `scripts/`: `build.js` (esbuild), `setup.sh`, `worktree.sh` and `cleanup-worktree.sh`, `bump-alpine.js`, `laravel.php`, `admin/`, `stubs/`.
- `docs/`: Markdown docs (nav in `docs/__nav.md`).
- Root strays: `PRD-wire-model-modifiers.md` (a feature PRD), `test_unified_scripts.php`, `close.bash`. These look unrelated to the library.
- `.claude/rules/`: project conventions (`adding-features.md` for the checklist, `writing-docs.md`, `reviewing-prs.md`, `general.md`).

## 6. Test and benchmark environment (from `codemap/plugins-tests-tooling.md`)

- Suites: Unit = `src/**/*UnitTest.php`; Browser = `src/**/*BrowserTest.php`; LegacyBrowser = `legacy_tests/Browser/**/*Test.php`. `src/Performance/RenderPerformanceBenchmarkTest.php` is in none of them: run it by path. It only loops `mount()` 1000x, with no update request or JS, and asserts total < 10s.
- Unit tests extend the Orchestra Testbench Dusk `TestCase` (testbench-dusk needed even for unit). DB is in-memory sqlite.
- Dockerfile needs PHP >= 8.1 with dom, curl, libxml, mbstring, iconv, intl, zip, pdo_sqlite, plus composer and Node 22. Browser tests also need Chrome and `vendor/bin/dusk-updater detect --auto-update`. CI runs `npm run build` before `phpunit --testsuite Unit`; whether any unit test needs `dist/` is UNSURE.

## 7. Docker (set up and verified 2026-11-12)

Files: `docker/Dockerfile` (targets `base`, `browser`), `docker/docker-compose.yml` (services `app`, `browser`), `docker/browser-entrypoint.sh`, `docker/README.md` (commands). Container runs as uid/gid 1000 so `vendor/`, `node_modules/`, `dist/` and `.phpunit.cache` on the host are yours (all gitignored).

Verified results in the container (PHP 8.4.27, Chromium 156):
- `composer install` OK. `npm ci` OK. `npm run build` OK (`dist/` created, untracked).
- Unit: 1535 tests, 4325 assertions, 0 failures, 13 skipped, 3 incomplete, about 19s.
- vitest: 111 passed, 1 skipped.
- Existing benchmark runs: about 0.4ms per mount (about 2,300-2,600 mounts/s); mount only.
- Dusk: `--filter SupportWireText` passes (4 tests, about 3s) through the `browser` service.
- Gotchas found: `DUSK_DRIVER_URL` must be set for unit tests or every class errors on chromedriver; the `gd` extension is required for upload tests.

## 8. Baseline and fork decision (2026-11-12)

- Remote `upstream` = https://github.com/livewire/livewire.git (tags fetched). The app resolves **upstream** `livewire/livewire` 4.4.8 (per `composer.lock`), not this fork.
- Fork `4.x` HEAD (e03c29c8) has **byte-identical `src/`, `js/`, `composer.json` and `package.json` to tag `v4.4.8`**. It differs only by docs (release-tokens) and the `dist/` build that the tag commits (we never commit `dist/`). So the baseline for A/B comparison is simply `v4.4.8`, and the app is running the same code we are reading.
- Decision criteria (from the user): keep and point the app at the fork **only if** core-level changes give significant performance gains; "you're building the app wrong" findings don't count. A fork doesn't have to track upstream releases. Gains that are small and isolated could instead be upstream PRs or composer patches.
- Excluded from consideration: app-level patches (e.g. the empire-v2 PR #1001 skip-unchanged-rows hook). Its profile data is still used as evidence of framework behaviour.
- Pairing: the user will pair this session with a new application agent to map real-world usage. Fixtures derived from it must be synthetic or scrubbed (medical-supply data).

## 9. Changes made in the fork (branch `perf/core-performance`; numbers in `perf/RESULTS.md`)

Changes versus v4.4.8: `src/Features/SupportComputed/SupportLegacyComputedPropertySyntax.php` (computed attribute map), `src/helpers.php` + `src/EventBus.php` (cached bus, cheaper trigger), `src/ComponentHookRegistry.php` (per-event dispatch), `js/morph.js` + `js/lifecycle.js` (skip unchanged keyed elements), `config/livewire.php` + `src/Mechanisms/FrontendAssets/FrontendAssets.php` (`morph_skip_unchanged` switch), new `src/Features/SupportSkipUnchanged/`. Benchmarks: `perf/php/*` (+`perf/ab.sh`), `perf/js/*` (+`perf/js/run.sh`), fixture `perf/fixtures/orders-manage/`; profilers: `profile` docker service (xdebug + excimer), `perf/tools/*`. Local-only dirs: `.perf/` (baseline worktree at `.perf/baseline`), `perf/results/`.
Also on the branch: `js/component.js` (no unused whole-tree diff), `js/request/index.js` (snapshots parsed once per response), `perf/php/SweepBenchTest.php` (form/model/validate/children/events scenarios), `perf/js` scenarios `expandall` and `lazyall`, `perf/ADOPTING.md` + `perf/release.sh` (cut a `-pN` release with `dist/` for the app).
Lessons: xdebug exaggerates code made of many tiny calls (use the Excimer sampling profile, `perf/php/SampleProfileTest.php`); unit tests missed a hook regression that the browser tests caught, so always run the browser tests for morph/hook changes.

## 10. Environment notes

- Host has `php` 8.4.1 (herd-lite) and `docker`. `vendor/`, `node_modules/` and `dist/` are now installed (via Docker). Use the Docker setup in section 7 for all test and benchmark runs.
- Remote is the fork `empire-medical/livewire`. `origin/4.x` is the main branch.
