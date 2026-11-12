# Notes: Features A (src/Features/Support*) - read 2026-11-12 against 4.x @ e03c29c8

Conventions: paths relative to /home/endl/livewire/src/Features/. "Hook" = ComponentHook subclass registered in LivewireServiceProvider::bootFeatures() (lines ~161-212).
Context from reading ComponentHook.php / ComponentHookRegistry.php:
- ComponentHook::call* wrappers use method_exists() on every invocation, and callUpdate/callCall/callRender/... allocate a closure + array each call even when the hook implements nothing (ComponentHook.php:25-78).
- ComponentHookRegistry::initializeHook (ComponentHookRegistry.php:~99-113) does `new $hook` + setComponent, then `skip()` if it exists. NONE of the 19 folders in my scope implements skip(). So every hook class below that is registered gets instantiated on every mount and every hydrate for every component, even if it implements zero instance hooks (e.g. SupportBladeAttributes, SupportEntangle, SupportConsoleCommands, SupportLegacyComputedPropertySyntax, SupportCompiledWireKeys have only static provide()).
- Hooks in my scope that are instantiated per component but have NO instance lifecycle methods at all (pure waste per component, per mount and per hydrate): SupportBladeAttributes, SupportEntangle, SupportConsoleCommands, SupportCompiledWireKeys, SupportLegacyComputedPropertySyntax. (SupportCompiledWireKeys has only static methods + provide.)
- Hook classes with instance hooks: SupportAttributes (all 9), SupportEvents (call, dehydrate), SupportAutoInjectedAssets (dehydrate), SupportCssModules (dehydrate), SupportDisablingBackButtonCache (boot).
- Unregistered: SupportChecksumErrorDebugging (see below). Not hooks: BaseAsync, BaseAuthorize, BaseComputed, BaseOn (attributes).

---------------------------------------------------------------------
## SupportAccessingParent
1. Does: nothing on PHP side. Folder contains only BrowserTest.php. The `$parent` feature lives in JS ($wire.$parent, wire:click="$parent.x"). No PHP files, no JS file named after it (JS side is in js/$wire.js / js/component.js; not read for this task beyond noting disablingForms uses `component.parent.id`).
2. Files: none (non-test).
3. Hooks: none. No skip().
4. State: none.
5. Perf: none.

## SupportAsync
1. `#[Async]` attribute on a component method: tells the frontend that a call to that method may run without blocking/queuing behind other requests. PHP side only publishes the list of async method names as a memo.
2. Files:
   - BaseAsync.php (16 lines): extends SupportAttributes\Attribute. `dehydrate($context)` -> `$context->pushMemo('async', $this->getName())`.
3. Hooks: attribute-level `dehydrate` only (invoked via SupportAttributes::dehydrate). No hook class, no skip().
4. State: writes memo `async` (array of method names) into the snapshot memo on every dehydrate (not only mount) -> grows snapshot by method names, every response.
5. Perf:
   - BaseAsync.php:12-14: pushMemo runs on every dehydrate of every request (initial + updates), so the `memo.async` array is re-emitted in every snapshot (snapshot JSON size + checksum input). UNSURE whether snapshot is smaller if only emitted on mount; JS reads it at js/request/action.js:98 (`this.component.snapshot.memo?.async`) from the latest snapshot, so the memo must be present in each snapshot unless JS keeps the first one.
   - Per-method attribute instance is created per component instance (via AttributeCollection::fromComponent, see SupportAttributes).
JS contract: js/request/action.js:98 reads `snapshot.memo.async`.

## SupportAttributes (infrastructure for ALL attributes)
1. Does: base class `Attribute` for every Livewire attribute (`#[Computed]`, `#[On]`, `#[Authorize]`, `#[Url]`, `#[Validate]`...), `AttributeCollection` reflects a component once and instantiates each attribute, and the `SupportAttributes` hook fans each lifecycle event out to each attribute instance.
2. Files:
   - Attribute.php (134): abstract. `__boot($component, AttributeLevel, $name, $subName, $subTarget)` stores context. Getters. `getValue()` = `data_get($component->all(), levelName)` (PROPERTY level only). `setValue($value, $nullable)` -> may coerce string/int to BackedEnum using `new \ReflectionProperty` (`tryingToSetStringOrIntegerToEnum` lines 83-108), then `data_set`. `storeSet/Push/Get/Find/Has` proxies to `store($this->component)`.
   - AttributeLevel.php (10): enum ROOT / PROPERTY / METHOD.
   - AttributeCollection.php (55): extends Illuminate Collection. `fromComponent($component, $subTarget=null, $propertyNamePrefix='')`: `new ReflectionObject`, collects class attributes recursively up the parent chain (`getClassAttributesRecursively`, lines 42-54), then loops `getMethods()` and `getProperties()` reading `getAttributes(Attribute::class, IS_INSTANCEOF)` and `newInstance()` + `__boot()` each. Used also by form objects / sub-targets (prefix, subTarget).
   - HandlesAttributes.php (25): Component trait. `getAttributes()` lazily `$this->attributes ??= AttributeCollection::fromComponent($this)`. `setPropertyAttribute`, `mergeOutsideAttributes` (concat into a new collection).
   - SupportAttributes.php (125): hook class. Implements boot, mount, hydrate, update, call, render, dehydrate, destroy, exception. Each calls `getLivewireAttributes()` = `$component->getAttributes()->whereInstanceOf(LivewireAttribute::class)` (new filtered Collection each call) and forwards to each attribute only if `method_exists($attribute, '<hook>')`.
3. Hooks: boot, mount, hydrate, update, call, render, dehydrate, destroy, exception (all 9). No skip(). No trigger()/on() of its own.
   - update(): filters PROPERTY-level attrs whose name equals fullPath or is a prefix (`str($fullPath)->startsWith(name.'.')`), calls `$attr->update($fullPath,$newValue)`, returns a closure running returned callbacks.
   - call(): filters METHOD-level attrs with getName() === $method, calls `$attr->call($params, $returnEarly)`, returns closure for callbacks.
   - render(): maps every attribute (all levels), calls `->render(...)` where defined, returns closure.
4. State: `Component::$attributes` (protected AttributeCollection, per instance). No static caches. No store keys of its own.
5. Perf (most important file group in my scope):
   - AttributeCollection.php:15,23,31: NO per-class cache. Every component instantiation (mount AND every update request hydrate, because the component is re-created each request) does `new ReflectionObject`, `getMethods()` (ALL methods incl. inherited from Component and traits, dozens), `getProperties()` (all), and for each a `getAttributes(...)` call; then `newInstance()` of each attribute. Cost is O(methods+properties) reflection per component per request, unconditionally (lazily triggered by first `getAttributes()`, which SupportAttributes::boot always triggers at HandleComponents boot time). Cacheable per class (the list of ReflectionAttribute arguments), only `newInstance` + `__boot` would need to be per-instance.
   - AttributeCollection.php:42-54: class-level attribute walk up the whole parent chain each time (`getParentClass` loop, `getAttributes` per class).
   - SupportAttributes.php:119-124: `getLivewireAttributes()` runs `whereInstanceOf` (a full filter pass creating a new Collection) on each of 9 hooks per request (boot, mount, hydrate, render, dehydrate, plus per update / per call / destroy / exception). Plus `method_exists()` per attribute per hook (9 x N). Also render() and dehydrate() run over ALL attributes.
   - SupportAttributes.php:37-47: update(): per property update, 2 chained `filter` passes over all attributes and `str($fullPath)->startsWith(...)` allocates a Stringable per PROPERTY attribute per updated property; O(attrs x updates).
   - SupportAttributes.php:56-72: call(): filter chain over all attributes per method call; allocates callbacks collection + closure even if no matches.
   - SupportAttributes.php:12,21: boot()/mount()/hydrate() `...$params` spread + closure per call.
   - Attribute.php:61: getValue() does `$this->component->all()` (reads all public props through reflection - see BaseUtils) just to read ONE property via data_get. Who calls it: other features (Url, Session, Validate etc.), not in my scope. UNSURE how hot.
   - Attribute.php:91-93: setValue enum coercion builds `new \ReflectionProperty` on every setValue with string/int values (not per class cached).
   - HandlesAttributes.php:23: `mergeOutsideAttributes` builds a new Collection via concat each time (used by form objects / setPropertyAttribute). Probably O(n) per property attribute added; UNSURE how often.

## SupportAuthorization
1. `#[Authorize('ability', 'argument')]` on an action method; runs a Laravel Gate check before the method executes. Arguments can be a class name, a method parameter name (container-resolved route-model-style) or a component property name.
2. Files:
   - BaseAuthorize.php (86): attribute (repeatable, TARGET_METHOD) using `AuthorizesRequests`. `call(array $parameters)` runs inside `wrap($this->component)->tap(...)`: no argument -> `$this->authorize($ability)`; else resolves args via `resolveArgument` and calls `$this->authorize($ability, $resolved)`. `resolveArgument` order: `class_exists($arg)` -> class string; else a method parameter with that name (via `new \ReflectionMethod` + first-match loop, resolved with `ImplicitlyBoundMethod::resolveMethodDependencies(app(), [component, method], $parameters)` lazily once per call) -> `$deps['named'][$arg]`; else `data_get($component, $arg)`. `parseAbilityAndArguments` overridden to handle enums / class abilities.
   - (Tests: UnitTest.php, AuthorizeParameterBindingUnitTest.php, not read.)
3. Hooks: attribute-level `call` only (invoked by SupportAttributes::call, and manually by SupportEvents::call at SupportEvents.php:45-49 for event-listener methods). No hook class, no skip().
4. State: none.
5. Perf:
   - BaseAuthorize.php:25 `wrap($this->component)->tap(...)`: allocates a Wrapped object per call (the `tap` merely invokes the closure; UNSURE whether `wrap` is needed for the exception hook only).
   - BaseAuthorize.php:56 `class_exists($arg)` per argument per call: may trigger autoload attempt for property-name args (autoloader miss) - UNSURE cost depends on autoloader (classmap optimized => cheap).
   - BaseAuthorize.php:62 `new \ReflectionMethod(...)->getParameters()` per argument per call, uncached.
   - BaseAuthorize.php:36 ImplicitlyBoundMethod::resolveMethodDependencies does reflection + container resolution; computed again when the method itself is later invoked via wrap()->method() (double resolution of dependencies for the same call: once for authorization, once for the real call). UNSURE: not read ImplicitlyBoundMethod.
   - Only paid by components that use #[Authorize]; no cost for others other than the SupportAttributes filter.

## SupportAutoInjectedAssets
1. Injects `@livewireStyles`/`@livewireScripts` into full-page HTML responses automatically when a Livewire component rendered during the request (and `livewire.inject_assets` is not false), and also injects `@assets` collected outside components.
2. Files:
   - SupportAutoInjectedAssets.php (98): static `provide()`: registers `on('flush-state')` resetting 2 static flags; registers an `app('events')->listen(RequestHandled::class, ...)` closure. `dehydrate()` sets `static::$hasRenderedAComponentThisRequest = true`. `shouldInjectLivewireAssets()`, `injectAssets($html, $head, $body)`. `getLivewireAssets()` (lines 71-75) is dead code (computes styles+scripts, returns nothing, never called).
3. Hooks: instance `dehydrate`. Static provide(). Global `flush-state` listener. Laravel event `RequestHandled`. No skip().
4. State: statics `$hasRenderedAComponentThisRequest`, `$forceAssetInjection`. No store keys.
5. Perf:
   - Every HTTP response (the RequestHandled listener fires for ALL requests in the app, not just Livewire ones): `str($handled->response->headers->get('content-type'))->contains('text/html')` then `status()` check (lines 26-27). Cheap for JSON updates (returns at line 26 for `application/json` livewire update responses).
   - For HTML 200 responses (lines 34-36): `SupportScriptsAndAssets::processNonLivewireAssets()` + `getAssets()` run on every HTML response even if no Livewire on page (UNSURE cost; not read SupportScriptsAndAssets).
   - Line 47-48: `FrontendAssets::styles()` and `::scripts()` built per injecting response (string building; UNSURE whether they hit config/route lookups each time).
   - Line 53-58: `getContent()` then `str($html)->contains('</html>')` scan over whole body, then `injectAssets`: up to 2 `test` regexes + 2 `replaceMatches` full-document case-insensitive regex scans `/<\s*\/\s*head\s*>/i`, `/<\s*\/\s*body\s*>/i` (lines 86-90), i.e. 4 passes over the whole HTML, and `replaceMatches` replaces EVERY match (not just the last/first), so cost scales with page size, not component size. Only on initial full-page HTML renders, not update requests.
   - Line 56: `$handled->response->original` preserved to avoid breaking tests (no perf).
   - `dehydrate` runs per component per request (trivial assignment).

## SupportBladeAttributes
1. Adds the `$attributes->wire('model')` macro on Blade's ComponentAttributeBag, returning a `WireDirective` value object.
2. Files:
   - SupportBladeAttributes.php (22): `provide()`: `ComponentAttributeBag::macro('wire', fn($name) => ...)` using `whereStartsWith('wire:'.$name)`, `head((array) ...)`, `head(array_keys())`, `head(array_values())`, `new WireDirective($name, $directive, $value)`.
3. Hooks: static provide only. No instance hooks. No skip() (so it is `new`-ed per component per mount+hydrate for nothing).
4. State: none.
5. Perf:
   - Macro (lines 13-20): whereStartsWith builds a new attribute bag/filter pass over all attributes per `->wire()` call at Blade render time; only used by users' templates. Minor.
   - Per-component pointless instantiation (no instance methods, no skip()).

## SupportCSP
1. PHP: nothing. Folder has BrowserTest.php, CspNavigateBrowserTest.php and test-views/csp-layout.blade.php (test fixture). CSP nonce handling is JS/utility (`getNonce()` in js/utils used e.g. by supportCssModules.js) and in FrontendAssets (not in scope).
2. Files: none (non-test). (test-views/csp-layout.blade.php is a test fixture, 8 lines: skipped.)
3-5. n/a.

## SupportChecksumErrorDebugging
1. Dev-only tool intended to log checksum snapshots to a file and diff on `checksum.fail`. It is effectively DISABLED and also NOT REGISTERED.
2. Files:
   - SupportChecksumErrorDebugging.php (82): plain class (does NOT extend ComponentHook). `boot()` returns immediately at line 13 (`return;` after an @todo), so everything below is dead code (artisan command `livewire:monitor-checksum`, `on('checksum.fail')`, `on('checksum.generate')` writing `storage/framework/cache/lw-checksum-log.json`). Also missing `use function Livewire\on;` so the dead code would fatal if it were reached. `array_diff_assoc_recursive` helper.
3. Hooks: not registered anywhere (grep of src shows no references outside its own file). Not in bootFeatures().
4. State: none (would use a JSON file).
5. Perf: none currently. NOTE for later: if ever enabled, `checksum.generate` would do File::get + json_decode + File::put per component per response. Today zero cost.

## SupportCompiledWireKeys
1. Compile-time Blade precompiler that rewrites `wire:key="..."` into a PHP statement setting `SupportCompiledWireKeys::$currentLoop['key']`, plus a runtime loop-state machine (loopStack/currentLoop) that `RenderComponent` uses to generate deterministic child component keys inside @foreach/@for/@while loops.
2. Files:
   - SupportCompiledWireKeys.php (191):
     - statics `$loopStack`, `$currentLoop{count,index,key}`.
     - `provide()`: `on('flush-state')` reset; if `config('livewire.smart_wire_keys', true)` -> `registerPrecompilers()` -> `Blade::precompiler(fn => static::compile())`.
     - `compile($contents)`: (a) `preg_replace('/<livewire:[^>]+?\/>/is', placeholder, $contents)`, (b) `preg_match_all` for `wire:key="..."`/`'...'` on the cleaned content, then for each key `compileKeyExpression()` and `str_replace($key, $prefix.$key, $contents)` (whole-document replace per key), (c) `preg_replace('/(<\?php\s+\$component->withAttributes\(\[.*?\]\);\s*\?>)/s', ...)` to add `processComponentKey($component)` after Blade component attribute setup.
     - `openLoop`, `startLoopIteration`, `startLoop` (deprecated), `endLoop`, `closeLoop`: runtime loop bookkeeping (emitted by SupportMorphAwareBladeCompilation at SupportMorphAwareBladeCompilation.php:184-186).
     - `compileKeyExpression($keyString)`: `Blade::compileEchos`, then `token_get_all` over the string, collect()->slice->map->implode, str_replace twice.
     - `processElementKey` (compat shim for old compiled views: `view()->exists` + `Blade::render` at runtime, only for stale cached views), `processComponentKey($component)`.
     - `generateKey($deterministicBladeKey, $key)`: runtime; `array_merge(loopStack, [currentLoop])`, foreach, string concat. Called from Mechanisms/RenderComponent.php:56 per child component render.
3. Hooks: static provide only (no instance hook, no skip()), global `flush-state` listener, Blade precompiler registration. Class IS in bootFeatures so it is `new`-ed for each component per mount + hydrate with no instance methods.
4. State: statics `$loopStack`, `$currentLoop` (reset on flush-state).
5. Perf:
   - COMPILE-TIME (runs only when a Blade file is (re)compiled; cached by Laravel's compiled views, so cost hits first request after deploy / view:clear, and every request in dev if views are not cached): the 3 regex passes over each full template source (SupportCompiledWireKeys.php:50,53,62). The `/s` lazy `.*?` regex at line 62 scans the whole file looking for `$component->withAttributes([` and could backtrack heavily on large templates (UNSURE; compiled output PHP is what it targets; precompilers run BEFORE Blade's own compile, so on source, which means this pattern typically matches nothing unless source already contains PHP; UNSURE whether it ever matches in precompile phase).
   - Line 58: `str_replace` over the full contents once per wire:key found = O(keys x size).
   - RUNTIME (per render): `generateKey` allocates `array_merge` of the stack every call (line 171) - small. `startLoopIteration`/`openLoop` run per loop iteration, emitted in compiled views for every morph-aware @foreach (minor; array copy `static::$loopStack[] = static::$currentLoop` per openLoop).
   - `processComponentKey`: `$component->attributes->has('wire:key')` per Blade component in the template (only for templates using withAttributes).
   - Per-component pointless instantiation (no instance hooks, no skip()).

## SupportComputed
1. `#[Computed]` attribute (with optional `persist`, `cache`, `seconds`, `key`, `tags`) and the legacy `getXxxProperty()` computed syntax. Computed values are lazily evaluated on `$this->foo` access through the `__get` trigger, memoized per request on the attribute instance, optionally stored in Laravel cache.
2. Files:
   - BaseComputed.php (175): attribute. ctor `($persist=false, $seconds=3600, $cache=false, $key=null, $tags=null)`. `call()` throws `CannotCallComputedDirectlyException`. `handleMagicGet($returnValue)`: persist -> `handlePersistedGet`, cache -> `handleCachedGet`, else `evaluateComputed()`; all memoized in `$this->requestCachedValue ??=` (note `??=` re-evaluates when the computed value is null). `handleMagicUnset()`. `handlePersistedGet/handleCachedGet`: `Cache::remember` (or `Cache::tags()->remember` if `Cache::supportsTags() && tags`), then `resolveCachedValue` -> `findIncompleteClass` recursive walk of arrays looking for `__PHP_Incomplete_Class`, re-evaluates + logs a warning if found. Keys: persisted `lw_computed.{componentId}.{name}`, cached `lw_computed.{componentName}.{name}`. `evaluateComputed()` = `invade($component)->{method}()`. `getName()` overridden to `str($value)->camel()->toString()` (called on every getName()).
   - SupportLegacyComputedPropertySyntax.php (119): hook class with ONLY static `provide()`. Registers global listeners: `on('__get', ...)` (find #[Computed] attribute first, else legacy `getXxxProperty` method), `on('__unset', ...)`, `on('flush-state')` clearing `$computedPropertyNamesCache`. Statics: `findComputedAttribute`, `getComputedProperties`, `hasComputedProperty`, `getComputedProperty` (stores in `store($target)` key `computedProperties`), `getComputedPropertyNames` (per-class static cache of legacy names, built via `Utils::getPublicMethodsDefinedBySubClass` + str filters).
   - CannotCallComputedDirectlyException.php (15): exception.
3. Hooks: no instance lifecycle hooks for the hook class; uses the event bus `__get`, `__unset`, `flush-state`. BaseComputed implements only attribute-level `call` (throws when someone invokes the computed as an action). No skip().
4. State: `BaseComputed::$requestCachedValue` (per attribute instance, per component instance); `store($target)` key `computedProperties` (legacy); static `$computedPropertyNamesCache[className]`; Laravel Cache keys.
5. Perf:
   - SupportLegacyComputedPropertySyntax.php:55-62 `findComputedAttribute`: on EVERY `__get` on a component (any inaccessible/undefined property read, e.g. every `$this->computedProp` access, and also any `__isset` -> `__get` at Component.php:104, e.g. `isset($this->foo)`, `@isset`, Blade `$foo ?? ..` on component) it does `$target->getAttributes()->whereInstanceOf(BaseComputed::class)->first(fn => getName() === $propertyName)`: a full filter pass over ALL attributes (not just computed) plus a `str()->camel()` for each BaseComputed candidate via getName() (BaseComputed.php:164-172), and `str($property)->camel()` once. This runs BEFORE the memoized `requestCachedValue` check, so every repeated access of the same computed property in a Blade template (e.g. `$this->posts` in a loop) re-pays the lookup. O(#attributes) per access, per component.
   - If no #[Computed] matches, falls through to `hasComputedProperty` (another `str()->camel()` + `getComputedPropertyNames` static-cached + `in_array`), for every unknown-property read.
   - Component::__get (Component.php:114-131) creates a closure + goes through `trigger('__get', ...)` (EventBus merges listener arrays each trigger, per CODEMAP) for every such access.
   - BaseComputed.php:35,42,50 `??=`: null results are not memoized (re-run). UNSURE whether common.
   - BaseComputed.php:79,93 `Cache::supportsTags()` called each persisted/cached get (container resolve of cache manager + method) - only for persist/cache computed.
   - BaseComputed.php:116-129 `findIncompleteClass` recursively walks the whole cached array value on every cache read (O(size of cached value)); for large cached arrays/collections-as-arrays this is a full deep traversal per request. Only arrays/Incomplete_Class checked (objects are not descended).
   - BaseComputed.php:149 persisted key uses component id: each request is a cache read (+ possible write); fine/intentional.
   - Legacy: `getComputedPropertyNames` cache is per class and cleared on flush-state (Octane/test).

## SupportConsoleCommands
1. Registers artisan commands (make:livewire, livewire:make alias, convert, form, attribute, layout, stubs, configure-s3-upload-cleanup, publish, config). Console-only; irrelevant to request perf.
2. Files:
   - SupportConsoleCommands.php (37): static `provide()` returns early `if (! app()->runningInConsole())`; otherwise `commands([...])` -> `Artisan::starting(fn => $artisan->resolveCommands(...))`.
   - Commands/MakeCommand.php (605): `make:livewire`; creates class / SFC / MFC components via Finder + stubs; handles upgrade prompts, emoji dirs, `--test/--js/--css`. Constructor resolves `app('files')`, `app('livewire.finder')`, `app('livewire.compiler')`.
   - Commands/LivewireMakeCommand.php (12): hidden alias extending MakeCommand.
   - Commands/ConvertCommand.php (275): `livewire:convert` SFC <-> MFC using SingleFileParser/MultiFileParser.
   - Commands/FormCommand.php (57), AttributeCommand.php (57): GeneratorCommands with stub lookup in base_path('stubs').
   - Commands/LayoutCommand.php (100): creates layout file from `livewire.component_layout` config, handles namespaced layouts.
   - Commands/StubsCommand.php (96): copies 15 stubs to base_path('stubs').
   - Commands/S3CleanupCommand.php (99): sets S3 lifecycle rule via invade() into Flysystem adapter.
   - Commands/PublishCommand.php (46), ConfigCommand.php (19): wrap `vendor:publish`.
   - Commands/FileManipulationCommand.php (45): base class for LayoutCommand (ensureDirectoryExists, welcome message, isFirstTimeMakingAComponent).
   - Commands/the-tao.php (21): returns an array of quotes (unused by the listed commands; UNSURE if referenced anywhere).
   - Stubs (*.stub, 15 files): livewire.stub, .inline, .view, .test, .pest, .form, .attribute, .layout, livewire-sfc, livewire-mfc-{class,view,test,js,css}. Read; templates only.
3. Hooks: static provide only. Class is in bootFeatures so it is `new`-ed per component per mount+hydrate (no instance methods, no skip()). Not request-relevant.
4. State: none.
5. Perf:
   - SupportConsoleCommands.php:12: in web requests `provide()` is a single cheap check. In console (artisan) every Laravel artisan invocation instantiates the 10 command classes via `resolveCommands`; MakeCommand/ConvertCommand constructors resolve 2-3 services (`app('livewire.finder')`, `app('livewire.compiler')`) at artisan boot (lines 31-40 / 27-34) - affects artisan startup only. UNSURE whether Finder/Compiler construction is heavy.
   - Not on the HTTP request path. Low priority.

## SupportCssModules
1. Serves per-component scoped and global CSS from a component's `styleModuleSrc()` / `globalStyleModuleSrc()` (multi-file `.css`/`.global.css`), via two registered routes, and tells the browser through effects to inject `<link>` tags.
2. Files:
   - SupportCssModules.php (105): static `provide()` registers 2 GET routes (`EndpointResolver::componentCssPath()`, `::componentGlobalCssPath()`); each decodes the name (`----`->`:`, `---`->`::`, `--`->`.`), `app('livewire')->new($component)` (instantiates the component, 404 on ComponentNotFound), checks method_exists, `file_exists`, `file_get_contents`, `filemtime`, and returns `Utils::pretendResponseIsFileFromString(...)` (caching headers). The scoped route wraps CSS as `[wire\:name="X"] { ... }` at request time. Instance `dehydrate($context)`: only when `$context->isMounting()`: if component has `styleModuleSrc` -> `filemtime(path)` -> `crc32` -> `addEffect('styleModule', $hash)`; same for `globalStyleModuleSrc`.
3. Hooks: instance `dehydrate`; static provide (routes registered at boot for every request). No skip().
4. State: none. (JS keeps `loadedStyles` Set.)
5. Perf:
   - SupportCssModules.php:16,49: two `Route::get` registrations on every request's boot (route table size + route-cache compat; UNSURE about closure routes preventing `route:cache`: closures cannot be serialized for route caching - Laravel route:cache fails on closure routes unless they are in files? UNSURE how the package handles this, other Livewire routes presumably use controllers).
   - Line 81-102 dehydrate: per MOUNT of every component: `method_exists` x2; for components that have the method, `styleModuleSrc()` (user code; likely path resolution) + `filemtime()` = a filesystem stat syscall (PHP stat cache helps within a request, but across requests it is a real stat) per mounted component instance, every page render, no caching across requests (even for N copies of the same component in a list: N x stat + 2 method calls). Components without CSS pay only 2 method_exists.
   - Routes (lines 21-33): each CSS asset request instantiates the component via the factory (`new`), reads and rewraps the file in PHP; relies on HTTP caching via pretendResponseIsFileFromString and the `?v=` hash. Not per Livewire update.
JS contract: js/features/supportCssModules.js: on `effect` hook reads `effects.styleModule` / `effects.globalStyleModule`, builds `${getModuleUrl()}/css/${encodedName}.css?v=${hash}` (name encoding: `.`->`--`, `::`->`---`, `:`->`----`), appends `<link>` once (module-level `loadedStyles` Set, with CSP nonce).

## SupportDataBinding
1. PHP: nothing. Folder only has BrowserTest.php and MergeSnapshotBrowserTest.php. (`wire:model` logic is in js/directives/wire-model.js and PHP updating is in HandleComponents::updateProperties / HandleSynths::hydratePropertyUpdate.) No files to read.
2-5: n/a.

## SupportDataLoading
1. PHP: nothing (BrowserTest.php only). JS-only feature: `data-loading` attribute applied to the origin element of an action during a request.
2. Files: none.
JS contract (js/features/supportDataLoading.js, read): `interceptMessage` -> on send sets `data-loading="true"` on `action.origin.el` (or `targetEl` if provided) for every action in the message; removes it on success/finish through `releaseAfterNavigation`. No PHP/server fields involved.
3-5: n/a (JS only; per-message loop over actions, undo closures).

## SupportDisablingBackButtonCache
1. Makes responses that contain a Livewire component send `Cache-Control: no-store`-style headers so the browser back button re-fetches the page instead of showing stale HTML with outdated snapshots.
2. Files:
   - SupportDisablingBackButtonCache.php (32): static `$disableBackButtonCache`. `provide()`: `on('flush-state')` reset; `app()->make(Http\Kernel::class)`; `hasMiddleware(...)` check; `pushMiddleware(DisableBackButtonCacheMiddleware::class)` (global middleware on the HTTP kernel). Instance `boot()` sets `static::$disableBackButtonCache = true`.
   - DisableBackButtonCacheMiddleware.php (35): `handle`: runs `$next`, then if response is a Symfony Response and the flag is set, adds 3 headers (Pragma, Expires, Cache-Control) and resets flag to false.
   - HandlesDisablingBackButtonCache.php (16): Component trait with `disableBackButtonCache()` / `enableBackButtonCache()` setting the static flag.
3. Hooks: instance `boot`. Static provide. flush-state. No skip().
4. State: static `$disableBackButtonCache` (global, not per component: any component booting in the request sets it; a component calling `enableBackButtonCache()` only works if it runs after the last boot - UNSURE ordering semantics).
5. Perf:
   - SupportDisablingBackButtonCache.php:19 `app()->make(Kernel::class)` on every request at provide time (resolve is cheap, singleton). Adds a GLOBAL middleware to every request in the app (lines 25): trivial work per request (one instanceof + static check), but note it is applied also to the Livewire update POST.
   - `boot()` runs per component per request (assignment only), plus instance creation (needed).
   - Headers added also on JSON update responses (flag is true because components boot during the update): extra ~150 bytes per response. UNSURE whether update responses are affected by the middleware (they are Illuminate JsonResponse: yes `instanceof Response`).

## SupportDisablingFormsDuringRequest
1. PHP: nothing (BrowserTest.php only). JS-only: disables form controls while a `wire:submit` request is in flight.
JS contract (js/features/supportDisablingFormsDuringRequest.js, read first ~50 lines only): on `directive.init` for `wire:submit` (inside a `setTimeout`) adds a submit listener that calls `disableForm(el)` (walks form with `Alpine.walk`, skips `wire:ignore` subtrees) and stores undo fns in a `Bag` keyed by component id; `interceptMessage` re-enables on success/finish via `releaseAfterNavigation`. No server fields.
Files: none. Perf (JS, mention only): one `setTimeout` per `wire:submit` directive init; Alpine.walk of the whole form per submit.

## SupportEntangle
1. Defines the `@entangle(...)` Blade directive that outputs `window.Livewire.find('<id>').entangle('<prop>')` (+ `.live` when a WireDirective with `live` modifier is passed).
2. Files:
   - SupportEntangle.php (18): static `provide()`: `Blade::directive('entangle', ...)` returning a heredoc PHP snippet: `(object)(expr) instanceof \Livewire\WireDirective` branch, else plain. Uses `$__livewire->getId()`.
3. Hooks: static provide only. No skip() (per-component pointless `new` on mount and hydrate).
4. State: none.
5. Perf: compile-time directive only; at render, the emitted PHP does `(object)($expression) instanceof WireDirective` plus `getId()` per @entangle usage (minor). `$expression` is duplicated multiple times in the compiled output (evaluated 2-3 times). Per-component instantiation waste only.
JS contract: js/features/supportEntangle.js exports `generateEntangleFunction(component, cleanup)` -> `(name, live=false)`: reads initial value `$wire.get(name)`, creates an Alpine interceptor + `Alpine.entangle` binding between `livewireComponent.get/set(name, value, isLive)` and the Alpine scope; logs console.error if the property is undefined; `cloneIfObject` = `JSON.parse(JSON.stringify(value))` clone on each interceptor init (not per change); `.live` property getter flips isLive. (Perf, JS: deep clone via JSON for object values.)

## SupportErrorResponses
1. PHP: nothing (BrowserTest.php only); JS handles HTTP error responses (js/features/supportErrors.js exists; not read beyond name). No files.

## SupportEvents
1. Livewire event system (`$this->dispatch()`, `#[On('event')]`, `$listeners` property): server-side dispatches are serialized into `effects.dispatches`; browser events targeted at a component are turned into a `__dispatch` method call, which this hook resolves to the listener method.
2. Files:
   - SupportEvents.php (137): hook. Implements `call($method,$params,$returnEarly)`: only acts for `$method === '__dispatch'`: validates params (`Utils::hasUnambiguousParameterKeys`), `getListenerEventNames($component)` + `in_array`, `getListenerMethodName`, verifies the method is `$`-magic or in `Utils::getPublicMethodsDefinedBySubClass($component)` (reflection, uncached), runs `#[Authorize]` attributes on the method manually (filter x3 over all attributes), then `$returnEarly(wrap($component)->$method(...$params))`. Implements `dehydrate($context)`: skips while lazy-mounting; adds `listeners` effect when mounting or lazy-hydrating; adds `dispatches` effect from `store($component)->get('dispatched')` each dehydrate. Statics: `getListenerEventNames`, `getListenerMethodName`, `getComponentListeners` (`invade($component)->getListeners()` merged with `store['listenersFromAttributes']`, then `replaceDynamicEventNamePlaceholders` with regex `/\{(.*)\}/U` and `data_get`), `getServerDispatchedEvents`.
   - HandlesEvents.php (23): Component trait. `protected $listeners = []`, `getListeners()`, `dispatch($event, ...$params)` creates `Event`, `store($this)->push('dispatched', $event)`, returns the Event (fluent `->to()/->self()`).
   - Event.php (106): value object. Extracts reserved params (`ref`, `component`, `el`, `self`, `to` legacy) from params; `serialize()` -> `['name','params', self?, component? (resolved through `app('livewire.factory')->resolveComponentName`), ref?, el?]`.
   - BaseOn.php (24): `#[On('event' | [..])]` attribute (class or method level, repeatable). `boot()` -> `storePush('listenersFromAttributes', $this->getName() ?? '$refresh', $event)` (keyed by event name, value = method or `$refresh`).
   - TestsEvents.php (87): Testable trait (assertDispatched, assertDispatchedTo, fireEvent...). Test tooling only.
   - fake-echo.js (146): Dusk fixture, fake `window.Echo` implementation. Test tooling only.
3. Hooks: instance `call`, `dehydrate` (hook class); attribute-level `boot` in BaseOn. No skip(). Uses `store($this)` keys.
4. State (store keys): `dispatched` (array of Event, pushed), `listenersFromAttributes` (map event=>method, pushed in BaseOn::boot), reads `isLazyLoadMounting`, `isLazyLoadHydrating`. No statics.
5. Perf:
   - BaseOn.php:14-22: each #[On] attribute's `boot()` pushes into the store on EVERY request's boot (not only mount), re-deriving listener maps per request; arrays rebuilt per request. Each `storePush` goes through `store($component)` -> DataStore lookup (WeakMap/ID?). Small per attribute.
   - SupportEvents.php:96-105 `getComponentListeners`: called 1x in dehydrate (only at mount / lazy hydrate) and, in a `__dispatch` request, 3x (`getListenerEventNames` line 27, `getListenerMethodName` line 33 which calls it again, ...) - each call does `invade($component)` (anonymous class + `new ReflectionClass` per invade, helpers.php:19-29), `getListeners()`, `array_merge`, and `replaceDynamicEventNamePlaceholders` (loop with `unset`+reassign of every non-numeric key and `preg_replace_callback` per key). Not cached across the 2-3 calls within the same dispatch.
   - SupportEvents.php:29 `in_array($name, $names)` linear; 38 `Utils::getPublicMethodsDefinedBySubClass` = `new ReflectionObject` + `getMethods()` + array_filter/map each `__dispatch` (uncached; CODEMAP already notes this).
   - SupportEvents.php:45-49 3 chained `filter` passes over ALL attributes for authorization per dispatched event.
   - SupportEvents.php:16 `call()` is invoked for EVERY method call of EVERY component (cheap string compare when not `__dispatch`) but still pays the hook closure/array allocation wrappers (ComponentHook::callCall) + proxyCallToHooks overhead.
   - SupportEvents.php:57-70 `dehydrate` runs on every response for every component: 2 `storeGet`s, `getServerDispatchedEvents` builds a `collect()->map->toArray` even when empty (`store->get('dispatched', [])`), allocating a Collection per component per request. Event::serialize (Event.php:100) calls `app('livewire.factory')->resolveComponentName()` per dispatch-to-component.
   - Event.php:18-42 constructor: 5 `isset` checks per dispatch (negligible).
   - JS contract: js/features/supportListeners.js: on `effect` reads `effects.listeners` (names) -> per listener name adds a `window.addEventListener(name, handler)` + `component.el.addEventListener(name, ...)`, which calls `component.$wire.call('__dispatch', name, e.detail || {})` (hence PHP `call` hook with `__dispatch`). js/features/supportDispatches.js: after each message morph / at `component.initialized` reads `effects.dispatches` ([{name, params, self?, component?, ref?, el?}]) and dispatches DOM events (triple `queueMicrotask` for initial). Listener list is only re-sent on mount (PHP dehydrate:62), so JS keeps listeners across updates.
   - JS perf note: every `effect` hook run re-registers listeners? (supportListeners.js registerListeners is called on each `effect`, but PHP only sends `listeners` at mount, so on updates `listeners=[]` -> no re-registration.) OK.

---------------------------------------------------------------------
## Cross-cutting PERF OBSERVATIONS (ranked by likely impact, my scope only)
1. AttributeCollection::fromComponent (SupportAttributes/AttributeCollection.php:11-40) does full ReflectionObject + getMethods + getProperties + getAttributes + newInstance with NO per-class cache, on every component instantiation (every update request). Triggered unconditionally by SupportAttributes::boot via HandlesAttributes::getAttributes (HandlesAttributes.php:11).
2. SupportAttributes hook fan-out: 9 lifecycle hooks each rebuild `whereInstanceOf` collection + `method_exists` per attribute (SupportAttributes.php:119-124 and each hook); update/call do extra filter chains + `str()` per attribute (lines 37-72).
3. No hook in my scope defines skip(): 5 hook classes with only static provide() (BladeAttributes, Entangle, ConsoleCommands, CompiledWireKeys, LegacyComputedPropertySyntax) are still instantiated + callBoot/callHydrate/callMount'd per component per request, plus SupportAsync/Authorize/Computed attributes ride on SupportAttributes. Also SupportCssModules (dehydrate only at mount), SupportAutoInjectedAssets (dehydrate trivially), SupportDisablingBackButtonCache (boot), SupportEvents instantiated always.
4. Computed property access path (SupportLegacyComputedPropertySyntax.php:55-62 + BaseComputed.php:164): each `$this->computed` read walks all attributes, camel-cases each candidate name, and passes through `trigger('__get')` before the memoized value is consulted.
5. SupportEvents `__dispatch`: invade() reflection x3, uncached `getPublicMethodsDefinedBySubClass`, placeholder regex per key, 3-pass attribute filter (SupportEvents.php:27-49,96-105).
6. SupportEvents::dehydrate allocates a Collection per component per response (lines 107-112) even when no events dispatched; BaseAsync emits `memo.async` in every snapshot (BaseAsync.php:14).
7. SupportAutoInjectedAssets: for HTML responses runs up to 4 whole-document regex passes (SupportAutoInjectedAssets.php:55,86-95); RequestHandled listener for all app requests; plus `processNonLivewireAssets()` for every HTML 200 response (line 34).
8. SupportCssModules::dehydrate does `filemtime()` per mounted component with CSS (SupportCssModules.php:87,98) with no cross-request cache; `Route::get` x2 at boot per request (lines 16,49).
9. BaseAuthorize with args: `new ReflectionMethod`, ImplicitlyBoundMethod reflection (duplicated by the real method call), `class_exists` autoload probes (BaseAuthorize.php:56-69).
10. Dead/unused code (zero cost, but noise): SupportChecksumErrorDebugging (unregistered, boot returns immediately), SupportAutoInjectedAssets::getLivewireAssets (unused), the-tao.php (unused? UNSURE).

## Files I could not / did not read in full
- Read in full: every non-test PHP file in all 19 folders (including all Commands/*.php and all *.stub), BaseAsync, all SupportAttributes, SupportEvents/* (incl. fake-echo.js and TestsEvents.php).
- JS counterparts: read supportEntangle.js, supportCssModules.js, supportListeners.js, supportDispatches.js, supportDataLoading.js (first 60 lines = whole functional part), supportDisablingFormsDuringRequest.js (first 50 lines only). Did NOT read supportErrors.js, supportDataCurrent.js, js/request/action.js beyond line 98 grep.
- Not read (outside scope, relied on grep/snippets): ComponentHookRegistry (read lines 85-125 only), Component.php (lines 95-145 only), ImplicitlyBoundMethod, SupportScriptsAndAssets, FrontendAssets, RenderComponent.
- Empty/test-only folders (no non-test files): SupportAccessingParent, SupportCSP (only test-views fixture), SupportDataBinding, SupportDataLoading, SupportDisablingFormsDuringRequest, SupportErrorResponses.
