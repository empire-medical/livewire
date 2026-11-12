# Notes: Features D (read 2026-11-12, 4.x @ e03c29c8)

Common: every class here extending ComponentHook is instantiated per component on EVERY mount and hydrate by ComponentHookRegistry (see CODEMAP). None of the hooks below defines skip(). Base ComponentHook (src/ComponentHook.php:14-80) has call*() wrappers using method_exists() per call and allocating a closure per callUpdate/callCall/callRender/callRenderIsland (:26-72) -- so each hook that is registered pays method_exists + closure alloc per event even when it has no such method.
Registered in LivewireServiceProvider::bootFeatures (lines ~164-212): WireModelingNestedComponents, ScriptsAndAssets, Teleporting, Validation, WithMethod, Transitions, Streaming, WireRef, Testing, Slots, Wireables. NOT found in the grep: SupportSingleAndMultiFileComponents (UNSURE whether registered elsewhere / dead stub).

Folders with NO non-test PHP (JS-only; PHP folder holds tests only): SupportStdClasses (UnitTest only), SupportWireBind, SupportWireConfirm, SupportWireCurrent (only a test blade view), SupportWireIgnore, SupportWireIntersect, SupportWireLoading (test png), SupportWireShow, SupportWireSort, SupportWireText. JS counterparts (not read in depth; names + sizes only): js/directives/wire-{bind,confirm,current,ignore,loading(281 lines),show,text,transition(190)}.js, js/features/supportWireSort.js(96), supportWireIntersect.js(35), supportDataLoading.js. No PHP<->JS contract exists for these (pure client side). Not read: wire-loading.js, wire-transition.js, wire-current.js, supportWireSort.js beyond line counts.

---------------------------------------------------------------
## SupportScriptsAndAssets
1. What: Blade directives @script/@endscript (per-component JS run once per component) and @assets/@endassets (page-wide, once per request/page assets). Scripts go in effects.scripts; assets are injected into HTML head on initial render or sent as effects/assets in AJAX payloads.
2. Files: SupportScriptsAndAssets.php (170). Others (asset-stylesheet.css, test.js, non-livewire-asset.js, asset-after-stylesheet.js) are test fixtures.
 - provide(): registers 4 Blade compile-time directives (script/endscript/assets/endassets) + flush-state listener. getUniqueBladeCompileTimeKey uses crc32(view path)+counter (compile-time only).
 - hydrate($memo): copies memo.scripts / memo.assets into store (forwardScriptsToDehydrateMemo / forwardAssetsToDehydrateMemo).
 - dehydrate($context): diffs store scripts/assets vs already-run keys via in_array; pushEffect('scripts'), addMemo('scripts'|'assets'); fills static $renderedAssets.
 - processNonLivewireAssets(): static; loops $nonLivewireAssets with in_array dedupe (pointless dedupe, keys are unique).
 - getAssets(): returns static::$renderedAssets.
3. Hooks: provide, hydrate, dehydrate. No skip().
4. State: store: scripts, assets, forwardScriptsToDehydrateMemo, forwardAssetsToDehydrateMemo. Statics: $alreadyRunAssetKeys, $countersByViewPath, $renderedAssets, $nonLivewireAssets (reset on flush-state).
JS contract: js/features/supportScriptsAndAssets.js reads memo.assets on component.init, effects.scripts on 'effect', payload.assets on payload.intercept.
5. Perf:
 - :128-137 hydrate and :139-169 dehydrate run on every request for every component even if it has no scripts/assets: two storeGet, one addMemo('scripts', []) and addMemo('assets', []) ALWAYS emitted (:151, :168) -> adds `"scripts":[],"assets":[]` to every snapshot memo (snapshot JSON bytes + checksum input) for every component. (Fact: addMemo unconditional.)
 - :145,:158 in_array O(n*m) over keys (small n; negligible unless many scripts).
 - Compile-time directives: no runtime cost beyond ob_start in templates that use them. @endassets (:105-125) in_array on a static list per execution.
 - $renderedAssets is a process-wide static; under Octane relies on flush-state (:65-70).

## SupportSession
1. What: #[Session] property attribute: persist a property in Laravel session across page loads. Restores in mount, writes in dehydrate.
2. Files: BaseSession.php (111) extends SupportAttributes\Attribute. (Surface attribute is src/Attributes/Session.php, not in scope.)
 - mount($params): if Session::exists(key) -> read() via PersistedValueCodec::decodeFromStorage -> setValue; forgets key on CorruptPersistedValueException/TypeError/ValueError.
 - dehydrate($context): write() every request: getValue, optionally codec->encodeForStorage (only if session.serialization==='json'), Session::put(key, value).
 - key(): crc32(componentName . propName) unless explicit key; replaceDynamicPlaceholders uses preg_replace_callback('/\{(.*)\}/U') + data_get on component.
3. Hooks (attribute-level): mount, dehydrate. No skip(). key() recomputed on every call (called up to 3x per dehydrate/mount: exists, read, write, plus Session::forget).
4. State: $this->codec (lazy app(PersistedValueCodec::class)); no store.
5. Perf:
 - :43-46/:67-82 dehydrate does Session::put on EVERY request for every #[Session] property, unconditionally (even when value unchanged and even on pure-read action). Session::put is in-memory but marks session dirty -> forces session save (driver write: file/db/redis) at end of every Livewire request. UNSURE whether the session middleware is even applied on /livewire/update (PersistentMiddleware re-applies page middleware; depends on app).
 - :94-101 key(): crc32 string concat + (if placeholders) regex per call; called 2-3x per op. Minor.
 - :72/:84-87 config('session.serialization') per write. Minor.
 - json mode: encodeForStorage does HMAC-signed envelope per write (cost lives in PersistedValueCodec, out of scope).
 - :48-51 Session::exists (existence check) then Session::get -> 2 lookups in mount.

## SupportSingleAndMultiFileComponents
1. What: Empty stub hook. provide() is a no-op. Actual logic lives in Finder/Compiler/Factory (CODEMAP).
2. Files: SupportSingleAndMultiFileComponents.php (13). 3. Hooks: provide (empty). Not found in bootFeatures grep (UNSURE). 4. none. 5. none (if it were registered it would still be instantiated per component).

## SupportSlots
1. What: Named/default slots for Livewire child components (`<livewire:foo>content</livewire:foo>`). Slots render wrapped in HTML-comment fragment markers `<!--[if FRAGMENT:name=..|type=slot|id=..|token=..|mode=morph]><![endif]-->` so JS can morph just the slot; when a parent re-renders but child is skipped (stub), the parent sends `slotFragments` effect to update slot content in the child.
2. Files:
 - SupportSlots.php (101): provide(): listener on 'mount.stub' -> $parent->withChildSlots($slots,$id). render()/renderIsland(): build SlotProxy and $view->with(['slot'=>..,'slots'=>..]). hydrate($memo): collect($memo['slots'])->partition(content isset) -> withHydratedSlots / withPlaceholderSlots. dehydrate(): effect 'slotFragments' (from getSlotsForSkippedChildRenders) and memo 'slots' (name, componentId, parentId, plus content if shouldSkipRender()).
 - HandlesSlots.php (67): trait on Component. Props $slotsForSkippedChildRenders, $slots. withChildSlots (builds Slot->toHtml immediately per slot), withSlots, withPlaceholderSlots, withHydratedSlots, getSlots.
 - Slot.php (65) / PlaceholderSlot.php (64): Htmlable value objects; toHtml() builds metadata array, crc32 token, string concat; encodeFragmentMetadata called twice (start and end marker) per toHtml. PlaceholderSlot is identical but empty content and mode=skip. Duplicate code in both classes.
 - SlotProxy.php (71): ArrayAccess/Htmlable wrapper over slots array; find() linear scan; get() falls back to `new Slot(name,'',componentId)` each call when missing.
3. Hooks: provide(mount.stub listener), hydrate, render, renderIsland, dehydrate. No skip(). Custom event consumed: 'mount.stub'.
4. State: Component props (not store): $slots (array of Slot|PlaceholderSlot), $slotsForSkippedChildRenders. memo 'slots'. effect 'slotFragments'.
JS contract: js/features/supportSlots.js reads payload.effects.slotFragments, finds fragment by name+token, morphFragment. Markers parsed by js/fragment.js.
5. Perf:
 - :20-31 render() and :33-43 renderIsland(): a new SlotProxy and `$view->with()` (2 keys) allocated for EVERY render of EVERY component, even components that never receive slots (the common case; getSlots returns []). Also :45-56 hydrate always runs collect()->partition() (creates Collection + closure + 2 sub-collections) even when memo['slots'] is absent -- :51 collect($memo['slots'] ?? []) on every hydrate of every component.
 - :58-62 dehydrate: calls two sub-methods every time; cheap when empty (getSlots, loop zero, `empty`). 
 - HandlesSlots adds 2 array properties to every Component (default init cost per `new`).
 - Slot::toHtml (:31-44, :46-64): crc32 + array spread + encodeFragmentMetadata x2 (string concat loop) per slot per call; withChildSlots (:20) calls toHtml once per slot at stub time. Slot memo with content (:90-92) means slot HTML gets stored in snapshot memo for skipped-render (lazy) components -> snapshot size grows by slot content bytes and is round-tripped + checksummed every request. 
 - SlotProxy::find (:22-31) linear; fine for small n.
 - Note bug-ish: Slot.php:40 `crc32($a . $b . $c ?? '')` precedence: `??` applies to the whole concat (concat evaluated first), so ?? is a no-op. Not perf.

## SupportStdClasses
No non-test PHP (only UnitTest.php). stdClass handling is in Mechanisms/HandleComponents/Synthesizers/StdClassSynth (out of my scope).

## SupportStreaming
1. What: $this->stream(...) pushes server-sent chunks mid-request (text/event-stream), consumed by JS to inject content into wire:stream targets.
2. Files:
 - SupportStreaming.php (68): stream() (public instance, called through hook) -> ensureStreamResponseStarted() (static; builds response()->stream + sendHeaders once) -> streamContent() json_encode + echo + ob_flush + flush.
 - HandlesStreaming.php (28): trait method stream($content,$replace,$name,$el,$ref,$to); looks up hook with ComponentHookRegistry::getHook($this, SupportStreaming::class), builds StreamManager.
 - StreamManager.php (44): fluent builder content()/to() -> calls $hook->stream().
3. Hooks: provide (flush-state listener) only. No lifecycle hook methods used besides being instantiated. No skip(). So it is a pure per-component instantiation cost + nothing else.
4. State: static $response (reset on flush-state). No store.
JS contract: js/features/supportStreaming.js; JSON `{stream:true, body:{id,type(directive|element|ref),content,mode,name,el,ref}, endStream:true}` per chunk.
5. Perf: no per-request cost unless stream() called. When called: per-chunk json_encode + flush (inherent). ComponentHookRegistry::getHook lookup per stream call (WeakMap, cheap). Hook instantiated per component though unused in 99% of cases (:8 class has no hydrate/mount etc, but still `new` + setComponent + stored in WeakMap).

## SupportTeleporting
1. What: @teleport($selector)/@endteleport Blade directives -> `<template x-teleport="...">`. 2. File: SupportTeleporting.php (20): provide() registers 2 directives. 3. Hooks: provide only; no skip. 4. none. 5. Compile time only; hook object still instantiated per component with no methods.

## SupportTesting (see detailed section below)
Files and roles:
 - SupportTesting.php (131): provide() returns unless app()->environment('testing'); registers Dusk, test macros (assertSeeLivewire/assertDontSeeLivewire on TestResponse and TestView) and parallel-testing compiler cache sync. Hook methods: hydrate() (storeSet testing.validator=null), dehydrate($context) (if errorBag non-empty storeSet testing.errors), exception($e,$stop) (stores ValidationException->validator in store).
   NOTE: provide() is gated on testing env, but the hook class is registered in bootFeatures regardless, so hydrate/dehydrate/exception RUN IN PRODUCTION too: :43-52 dehydrate calls $target->getErrorBag() every request, which when no bag is in store triggers HandlesValidation::getErrorBag (:42-50) -> app('view')->getShared() + new MessageBag + store set (so it creates the error bag for every component on every request; SupportValidation dehydrate would do the same anyway). :54-57 hydrate does a storeSet every hydrate.
 - Testable.php (425): the object returned by Livewire::test(). Uses traits MakesAssertions, TestsEvents, TestsRedirects, TestsValidation, TestsJsEvaluation, TestsFileDownloads + Macroable. Holds RequestBroker + ComponentState (lastState).
 - RequestBroker.php (56): wraps Laravel MakesHttpRequests + InteractsWithExceptionHandling; `temporarilyDisableExceptionHandlingAndMiddleware($cb)` (saves handler, withoutExceptionHandling([HttpException, AuthorizationException, ModelNotFoundException]), withoutMiddleware, HandleRequests::temporarilyPropagateExceptions, finally restores); addHeaders; call() comes from MakesHttpRequests (real in-process HTTP kernel request).
 - Render.php (30): abstract; extractComponentAndBladeView($cb) registers temp listeners on 'dehydrate' (captures component instance) and 'render' (captures Blade view) around $cb.
 - InitialRender.php (72): makeInitialRequest.
 - SubsequentRender.php (77): makeSubsequentRequest.
 - ComponentState.php (79): immutable bundle (component, response, view, html, snapshot, effects); getHtml(strip) / getSnapshotData -> recursive untupleify.
 - MakesAssertions.php (279): assertSee*, assertSet*, assertSnapshotSet*, assertCount, assertReturned, assertRenderSkipped. Pure helper.
 - ShowDuskComponent.php, DuskTestable.php (193), DuskBrowserMacros.php (703): Dusk (browser) only. DuskBrowserMacros was only skimmed (function list: ~89 closures; browser macros like assertNotVisible, waitForLivewire, etc.); contains no hot-path code. DuskTestable writes/reads Testing/_runtime_components.json (file IO per browser test). Tests/TestableLivewireCanBeInvaded.php is a test.
 - SupportValidation/TestsValidation.php (153) is the Testable trait for assertHasErrors etc. (reads store($this->target)->get('testing.validator'|'testing.errors')).

### How Livewire::test() drives a component (for benchmark harness)
LivewireManager::test($name,$params) (src/LivewireManager.php:238) -> Testable::create($name,$params,$queryParamsForTesting,$cookiesForTesting,$headersForTesting):
1. normalizeAndRegisterComponentName: array -> registers each; object (anonymous class instance) -> registers under str()->random(10); string -> `isDiscoverable` or `component($name)`.
2. new RequestBroker(app()); InitialRender::make(): picks random uri `/livewire-unit-test-endpoint/<rand20>`, REBUILDS the whole route collection (registerRouteBeforeExistingRoutes: new RouteCollection, add test route first, re-add every existing route, router->setRoutes) -- O(#routes) per Livewire::test() call and new RouteCollection (route lookup tables rebuilt). Route closure: `Blade::render('@livewire($name,$params)')` (compiles a Blade string -> temp compiled view, goes through RenderComponent -> HandleComponents::mount). Request executed via GET through full HTTP kernel (middleware disabled via withoutMiddleware, exceptions propagated) = real `$app->handle()` equivalent. Listeners capture the component instance (dehydrate event) and the Blade view (render event). `app('livewire')->flushState()` afterwards. HTML parsed with Utils::extractAttributeDataFromHtml for wire:snapshot and wire:effects (HTML-attr decode + json_decode).
3. Each ->set()/->call()/->commit()/->update(): Testable::update -> SubsequentRender::make: builds payload {components:[{snapshot: json_encode(lastState snapshot), calls, updates}]} and POSTs to app('livewire')->getUpdateUri() with header X-Livewire:true through the same RequestBroker (full kernel: route match, HandleRequests::handleUpdate, Checksum verify, hydrate, updates, calls, render, dehydrate). Response json_decode'd; snapshot string json_decode'd again; effects['html'] used or last HTML carried forward (getHtml(stripInitialData) does str betweenFirst + 2 str_replace on whole HTML). flushState() called after every request. The *instance* returned by ->instance()/->get() is the one captured via the 'dehydrate' listener (a fresh hydrated instance per request), NOT a persistent object.
4. Each test request therefore includes: kernel handling, router dispatch, with middleware disabled and exception handler swapped, plus the Livewire pipeline. setProperty -> one HTTP round trip per property (fill() with N values = N round trips; set(array) also N trips). Uploads: simulated via _startUpload/_finishUpload calls + direct FileUploadController->validateAndStore.
5. $refresh -> commit() -> update() with empty calls/updates (a pure render round trip). `$set` -> set().
Harness advice (facts only): to benchmark the update path faithfully, reuse a single Testable and call ->call()/->set()/->commit() repeatedly (each = one full /livewire/update request; no route rebuild after the initial one); to isolate pure PHP pipeline cost without kernel/router overhead, call `app(HandleComponents::class)->update($snapshot,$updates,$calls)` directly using Testable->snapshot (public via __get('snapshot')) -- UNSURE of exact HandleComponents::update signature, check src/Mechanisms/HandleComponents. Mount-only benchmark: existing src/Performance/RenderPerformanceBenchmarkTest.php (per CODEMAP). Be aware that test mode (APP_ENV=testing) means SupportTesting::provide ran (macros only) and that `config('app.debug')` profiling in HandleComponents is on if debug enabled.
Perf observations (test harness itself, matter for any benchmark built on it):
 - InitialRender.php:50-71 route collection rebuild per Livewire::test() (O(routes)); Blade::render compile per call (:25), random uri.
 - InitialRender.php:44-45 two attribute extractions + json_decode of snapshot; SubsequentRender.php:22 json_encode snapshot, :53 json decode response, :60 json_decode inner snapshot (double encoding mirrors production).
 - ComponentState.php:47-57 untupleify recursive (used by getSnapshotData -> assertSnapshotSet / getData).
 - ComponentState.php:59-75 getHtml(strip): betweenFirst + str_replace over full HTML, called by SubsequentRender.php:65 on every subsequent render with no html effect AND by every assertSee.
 - Testable.php:176-186/ :152-158 set(array)/fill() = one request per key.
 - Render.php:14-26 two event listeners registered/removed per request: EventBus listener arrays modified per request (EventBus::trigger merges arrays on every call, so extra listeners add cost to every trigger of dehydrate/render during test; also hooks-registered 'render' listener returning closure).
 - RequestBroker.php:24-43 each request swaps exception handler & middleware flags.
 - Testable.php:269 `app('request')->cookies->all()` per update.

## SupportTransitions
1. What: server-side control of View Transitions: $this->transition($type) / skipTransition() and #[Transition] method attribute. Sets effects.transition which morph.js reads (morph.js:60,99).
2. Files: SupportTransitions.php (21): dehydrate only: storeGet transitionType/transitionSkip, addEffect('transition', array_filter(...)) if set. HandlesTransitions.php (19): transition()/skipTransition() set store. BaseTransitionAttribute.php (25, TARGET_METHOD): call() sets store transitionType/transitionSkip.
3. Hooks: dehydrate (hook); call (attribute). No skip().
4. State: store transitionType, transitionSkip.
5. Perf: dehydrate does 2 storeGet per component per request (DataStore lookup via WeakMap + array). Minor. Attribute call() runs only for methods annotated.

## SupportValidation
1. What: Laravel-validator integration: validate(), validateOnly(), error bag in memo, #[Validate]/#[Rule] attributes, share $errors with Blade views during render, ValidationException handling.
2. Files:
 - SupportValidation.php (91): provide: static::$view reset + flush-state. hydrate($memo): setErrorBag(memo['errors'] ?? []) (creates MessageBag in store). render/renderIsland: viewErrorBag() (clone shared ViewErrorBag or new; put('default', bag)) then Utils::shareWithViews('errors', $errors), returns finisher closure to revert. dehydrate: getErrorBag()->toArray(), filter via Utils::hasProperty per error key, addMemo('errors', ...) (always, even if empty []). exception: on ValidationException setErrorBag + $stopPropagation().
 - HandlesValidation.php (529): Component/Form trait. Error bag in store('errorBag'). getRules() merges component rules()/prop with rulesFromOutside via array_merge_recursive each call. validate(): providedOrGlobalRules..., getDataForValidation (Utils::getPublicPropertiesDefinedOnSubclass), prepareForValidation, checkRuleMatchesProperty (collect keys->each), getModelAttributeRuleKeysToShorten, unwrapDataForValidation (app('livewire')->findSynth per object property), Validator::make, form object validators. validateOnly(): rulesForField via per-rule str()->is, str explode, ...; filterCollectionDataDownToSpecificKeys recursion; hasRuleFor/ruleWithNumbersReplacedByStars (2 regex replaces).
 - BaseValidate.php (108, repeatable attribute on property/class/all): boot() builds rules/messages/attributes and pushes to component via add*FromOutside (runs at boot of every component hydrate); update() returns closure that calls validateOnly via wrap($this->component)->tap(...) after each property update.
 - BaseRule.php (11): BC alias subclass. TestsValidation.php: testing assertions (see above).
3. Hooks: provide, hydrate, render, renderIsland, dehydrate, exception; attributes: boot, update. No skip().
4. State: store errorBag, testing.*; component props $rulesFromOutside, $messagesFromOutside, $validationAttributesFromOutside, $withValidatorCallback; static::$view (cached app('view')).
JS contract: memo.errors round-trips in snapshot; no JS feature file.
5. Perf:
 - SupportValidation.php:24-29 hydrate always creates a MessageBag per component per request (even without errors).
 - :31-42 / :44-53 render ALWAYS clones the shared ViewErrorBag (or news one), put('default'), shareWithViews + revert closure -- per component render, even with no validation used. (`Utils::shareWithViews` presumably does app('view')->share and re-share; cost in Utils, out of scope.) :80 caches app('view') in a static, good.
 - :55-67 dehydrate: getErrorBag()->toArray() + collect()->filter()->toArray() per component per request; per error key Utils::hasProperty. Always adds memo 'errors' => [] (snapshot gets `"errors":[]` for every component).
 - HandlesValidation.php:42-50 getErrorBag: when store has no bag, app('view')->getShared() + new ViewErrorBag + setErrorBag (once per component per request).
 - :107-124 getRules() is uncached and re-run many times per validate(): rulesForModel(:174-176 calls getRules twice), hasRuleFor (:188, :191), providedOrGlobalRules (:485), withFormObjectValidators (:296 per form). rules() method (user code) is therefore executed repeatedly per validate/validateOnly; array_merge_recursive(...array_map(value())) each time. With #[Validate] attributes on N properties, rulesFromOutside has N entries merged on each getRules().
 - :126-143 getMessages/getValidationAttributes similar array_merge spread per call.
 - :505-508 getDataForValidation -> Utils::getPublicPropertiesDefinedOnSubclass (reflection-based extraction of all public properties) per validate() and per validateOnly() -- i.e. per #[Validate]-triggered property update (BaseValidate::update closure :89-106 -> validateOnly), so each updated property with #[Validate] re-extracts ALL public props and rebuilds rules.
 - :350-368 validateOnly: per rule, str($field)->is($rule) (Str::is builds a regex per call), two str()->explode() Collections, join. O(#rules) with object allocation per rule.
 - :330-341 validateOnly: `$this->all()` (InteractsWithProperties, collects all props) just to check if property is a Form -- per validateOnly call. UNSURE of cost of all(); it is in Concerns.
 - :510-522 unwrapDataForValidation: collect()->map over ALL public props; for each object calls app('livewire')->findSynth (synth instantiation, see CODEMAP findByKey/findByTarget).
 - :455-472 getModelAttributeRuleKeysToShorten loops rules, `$data[$propertyName]` direct index (would notice if undefined).
 - :477 shortenModelAttributesInsideValidator: str()->snake()->replace()->is() per model rule key.
 - :198-211 ruleWithNumbersReplacedByStars: 2 regexes per call (hasRuleFor, called from where? UNSURE; grep needed) .
 - BaseValidate.php:22-83 boot() runs per #[Validate] attribute per component boot (every request): array building; trans() calls when `as`/`message` provided; head/str before(':').
 - :103 wrap($this->component)->tap(...) allocates Wrapped object per update.
 - TestsValidation: test-only.

## SupportWireModelingNestedComponents
1. What: #[Modelable] on a child property lets a parent bind wire:model to the child component (`<livewire:child wire:model="foo">`). Adds x-modelable/wire:model-on-root attributes to the child's root HTML and keeps parent value seeded into child.
2. Files:
 - SupportWireModelingNestedComponents.php (106): provide(): flush-state; listener on 'mount.stub' (child skipped during parent re-render): collect($attributes)->first(str startsWith 'wire:model') -> static::$outersByComponentId[$id]=[$outer=>data_get($parent,$outer)]. hydrate($memo): reads memo bindings/bindingsDirectives; stores; if parent stub provided outer value -> sets component->$inner. render(): returns finisher closure (html, replaceHtml): if bindings, regex-check root tag has no wire:model, Utils::insertAttributesIntoHtmlRoot adds `{directive}="$parent.outer"` and `x-modelable="$wire.inner"`. dehydrate: addMemo bindings + bindingsDirectives.
 - BaseModelable.php (54): attribute. mount($params,$parent,$attributes): finds first wire:model* attribute (str()->startsWith per attribute), storePush bindings, setValue(data_get($parent,$outer)). update(): returns closure restoring old value if storeGet('hasBeenSeeded').
3. Hooks: provide(mount.stub listener), hydrate, render, dehydrate; attribute: mount, update. No skip().
4. State: store bindings, bindings-directives, hasBeenSeeded; static $outersByComponentId.
5. Perf:
 - :22-30 'mount.stub' listener runs for EVERY child stub on every parent re-render (nested components): `collect($attributes)->first(closure with str()->startsWith)` per attribute per stub.
 - :33-35 hydrate cheap early return when no bindings.
 - :57-92 render(): always returns a closure (allocation per render) + two storeGet; closure is invoked on every render finish -> `storeGet('bindings')`; only heavy (ltrim, strpos, preg_match on opening tag, insertAttributesIntoHtmlRoot) when bound. ltrim($html) copies the whole HTML string (:77) only for bound components.
 - BaseModelable.php:16-22 per-attribute `str($key)->startsWith`. Only for modelable children.
 - :94-105 dehydrate: storeGet only.

## SupportWireRef
1. What: wire:ref on a component tag (`<livewire:x wire:ref="name">`) -> JS findRefEl lookup. Persists ref in memo and re-adds `wire:ref` attr on root element during render.
2. File: SupportWireRef.php (45): mount (params['wire:ref'] -> store), hydrate (memo ref -> store), render (finisher: if ref, Utils::insertAttributesIntoHtmlRoot), dehydrate (addMemo('ref') if storeHas).
3. Hooks: mount, hydrate, render, dehydrate. No skip().
4. State: store 'ref'. JS: js/features/supportRefs.js (8 lines; contract: root element attribute wire:ref + memo.ref).
5. Perf: render() allocates a closure per component render, invoked at render end (one storeGet). Mount/hydrate cheap. insertAttributesIntoHtmlRoot (Utils, out of scope) runs only when a ref exists. Also: because render() always returns a closure, the render finisher chain gets an extra callback per component on every render.

## SupportWireables
1. What: Registers WireableSynth so objects implementing Livewire\Wireable are (de)hydrated via toLivewire()/fromLivewire().
2. Files: SupportWireables.php (13): provide() -> app('livewire')->propertySynthesizer(WireableSynth::class). WireableSynth.php (53): key 'wrbl'; match() = is_object && instanceof Wireable; dehydrate: toLivewire() then dehydrateChild per key; hydrate: validates meta.class with is_a(..., true) (autoload + class string check) then hydrateChild per key then Class::fromLivewire; set; unwrapForValidation -> toLivewire().
3. Hooks: provide only. No skip.
4. none. 5. Perf: WireableSynth::match runs in the synth list scan for property values (position in list = unshifted to front, per CODEMAP: user and feature synths are unshifted to front, so match() is evaluated early for every object value; cheap instanceof). hydrate :39 is_a with allow_string per wireable hydrate. toLivewire() called once in dehydrate but again in unwrapForValidation (validation only).

## SupportWithMethod
1. What: lets a component define `with()` returning extra view data, merged into the Blade view at render and renderIsland.
2. File: SupportWithMethod.php (52): render($view,$data) and renderIsland($name,$view,$data) identical: method_exists($this->component,'with'); `wrap($this->component)->with()` (ImplicitlyBoundMethod container DI); is_array check; `$view->with($withData)`.
3. Hooks: render, renderIsland. No skip().
4. none.
5. Perf: :10-30/:32-52 method_exists per render per component (cheap) -- but hook instantiated per component and callRender closure allocated per render. Dead code: `$mergedData = array_merge($data,$withData)` (:25, :48) computed and never used (wasted array copy of all view data, only when with() exists). UNSURE whether `$data` can be large (it is the component's public props + computed).

---------------------------------------------------------------
## TOP perf observations (this scope)
1. Unconditional memo emission: SupportScriptsAndAssets.php:151,:168 (`scripts`,`assets` []), SupportValidation.php:61 (`errors` []) add keys to EVERY component snapshot (bytes, checksum, JSON encode/decode both directions).
2. Per-render allocations in components that don't use the feature: SupportSlots.php:20-43 (SlotProxy + view->with), SupportValidation.php:31-53 (ViewErrorBag clone + shareWithViews + revert closure), SupportWireRef/WireModeling render closures, + SupportSlots.php:51 collect()->partition() in every hydrate.
3. HandlesValidation getRules()/getMessages()/getDataForValidation uncached and called repeatedly (:107,:174-176,:188-191,:485,:505); each #[Validate] property update does validateOnly -> re-runs rules() user method, reflects ALL public props (:507), builds Collections per rule (:350-368), `all()` (:337).
4. SupportSession BaseSession.php:43-46,:81 Session::put every request per #[Session] property (forces session persistence write; no dirty check).
5. SupportTesting (production-resident part): SupportTesting.php:43-57 hydrate/dehydrate/exception hooks execute in non-test environments too (getErrorBag + storeSet per component per request); and the test harness InitialRender.php:50-71 rebuilds the full route collection and compiles Blade string per Livewire::test(), SubsequentRender is a full kernel round trip per set()/call().
Also: no hook in these folders implements skip(), so every hook (incl. empty ones: Teleporting, Streaming, SingleAndMultiFile (if registered), Wireables, Transitions) is `new`ed per component per request.
