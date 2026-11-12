# Notes: js/directives/* and js/features/* (read in full; also read js/directives.js, js/lifecycle.js, js/hooks.js, js/evaluator.js, parts of component.js/store.js/request/index.js/plugins/history for context)

All paths relative to /home/endl/livewire/js unless absolute. No files were unreadable. Spec files ignored.

## 0. Cross-cutting machinery (needed to read the per-file notes)

### How directives get dispatched (js/lifecycle.js + js/directives.js)
- Alpine.interceptInit callback #1 in lifecycle.js:65-123 runs for EVERY element Alpine initializes (also non-wire elements on the page). First step (l.69): `Array.from(el.attributes).some(a => matchesForLivewireDirective(a.name))`. `matchesForLivewireDirective` (directives.js:5-7) does `attributeName.match(new RegExp('wire:'))` -> allocates a new RegExp per attribute per call. Unanchored: matches "wire:" anywhere in the name (e.g. `x-on:foo-wire:bar`), UNSURE if that matters.
- For elements that have a wire attribute: `Array.from(el.getAttributeNames()).filter(matches...).map(extractDirective)` (l.85-87) -> second `new RegExp('wire:')` per attribute in the filter, then `extractDirective` (directives.js:9-13) does a THIRD `new RegExp('wire:')` + `.replace` + `.split('.')` and `new Directive(...)` which calls `el.getAttribute` (l.91). So per wire attribute at init: 2-3 RegExp allocations + 1 Directive + 1 getAttribute. Directive parsing of the expression (`parseOutMethodsAndParams`, directives.js:110-146, char-by-char scan + `Alpine.evaluate(document, 'argumentsToArray(...)')` for params) is LAZY: only runs when `.method/.methods/.params` getters are touched (wire-loading getTargets via wire:target `.methods`; supportWireIntersect/others do not use them). Each getter call re-parses (no memo): `method` and `params` getters each call parseOutMethodsAndParams again.
- Then per element with wire attrs: `trigger('directive.global.init', ...)` once per directive (listeners: current, transition = 2), then if the element is inside a component: `trigger('element.init')` and `trigger('directive.init', ...)` once per directive.
- `directive.init` listener count (hooks.js `on` pushes to an array; `trigger` loops ALL callbacks, hooks.js:47-61): one per `directive(name,...)` call (confirm, dirty, ignore, init, loading, model, offline, poll, replace = 9; directives.js:15-28 each listener only does `directive.value === name`), plus wire-wildcard.js:8, supportWireSort.js:7, supportDisablingFormsDuringRequest.js:13 => 12 listeners. So every wire directive on every element invokes 12 closures (each allocating nothing big except #12 which schedules a setTimeout - see below). `directive.global.init` has 2 listeners.
- Each `cleanup` passed to directives is `Alpine.onAttributeRemoved(el, directive.raw, cb)` (closure allocated per directive per trigger, even when unused) (lifecycle.js:59,90,101,118).
- Alpine.onAttributesAdded (lifecycle.js:39-63) runs for attributes added after init; same regex filtering.
- Additional `Alpine.interceptInit` callbacks that each run for EVERY element Alpine inits (not only wire elements): wire-navigate.js:29 (up to 5 `hasAttribute`), wire-text.js:4, wire-cloak.js:3 (`hasAttribute`), wire-bind.js:4, wire-show.js:4, supportWireIntersect.js:6, supportWireSort.js:16 (each loops `el.attributes` with `startsWith`), plus lifecycle's. That is 8 interceptInit callbacks; 5 of them iterate the full attribute list with startsWith => ~5 passes over each element's attributes plus lifecycle's `some`+regex. Cost per Alpine-initialized element (wire or not), once per init (also re-run for every element newly morphed in, since Alpine's mutation observer inits them).
- Alpine.addInitSelector: wire-current.js:4 (`[wire\:current]`) and wire-navigate.js:25-27 (5 selectors). These add to Alpine's selector list used by initTree/`findClosest`/root-matching; UNSURE exactly how Alpine uses them (likely joined into querySelectorAll at start and in `Alpine.initTree` roots).
- Global message pipeline: `interceptMessage` registrations (global callbacks, each wrapped in `new MessageInterceptor(message, cb)` per message, see request/interceptor.js:118-127, which allocates ~10 default closures per interceptor per message): static ones = supportDisablingFormsDuringRequest, supportIslands, supportJsModules, supportJsEvaluation, supportStreaming, supportSlots, supportPreserveScroll, supportDispatches, supportMorphDom, supportDataLoading (10 from features) + request/index.js:110 + request/legacy.js:58 = 12, PLUS one per `wire:loading` element (wire-loading.js:110). Message creation cost therefore scales with (#loading elements across the WHOLE page, not just that component), since the filter `component !== message.component` (wire-loading.js:111) is inside the callback.
- `effect` hook (component.js:152-162 `processEffects`) fires at init (store.js:20) AND on every response (request/index.js:475). Listeners on `effect`: supportListeners, supportScriptsAndAssets, supportQueryString, supportLaravelEcho, supportRedirects, supportCssModules, supportJsModules = 7 hooks per response per component, each doing `hasOwnProperty` checks (cheap). Server only sends `listeners`, `url`, `styleModule`, `globalStyleModule`, `scriptModule` effects when mounting (verified: SupportEvents.php:62-65 `isMounting()||isLazyLoadHydrating`; BaseUrl.php:31 `if (! $context->mounting) return`; SupportCssModules.php:81; SupportJsModules.php:66-88), so re-registration on later responses does not happen. `scripts` effect: server only pushes scripts not already run (SupportScriptsAndAssets.php:130-151, memo 'scripts' = already-run keys) with JS dedupe too.
- `Directive` is NOT a heavy class on its own; the cost is per-attribute regex + getAttribute at init and the lazy `methods` parse.
- evaluator.js (central to wire:text/show/bind/init/poll/click/intersect/sort): every call of `evaluateActionExpression`/`evaluateReactiveExpression` runs `contextualizeExpression` (evaluator.js:83-119): `getAlpineScopeKeys` walks parent chain up to the component root (l.3-28, `Object.keys` of each _x_dataStack[0] + `keys.includes`), `SKIP.push(...)`, then THREE regex `replace` passes over the expression string, and for non-reactive (action) calls `getActionTargetOffset` (2 more regexes). NOT memoized per (el, expression). For `wire:text`/`wire:show`/`wire:bind` the evaluator is inside the Alpine reactive getter, so it re-runs fully on EVERY reactive re-evaluation (each time a dependency changes), not just on init. (Alpine.evaluateRaw may cache the compiled function by expression string; UNSURE.)

## 1. js/directives/

### directives/index.js
Import order: wire-transition, wire-wildcard, wire-navigate, wire-confirm, wire-current, wire-offline, wire-loading, wire-replace, wire-ignore, wire-cloak, wire-dirty, wire-model, wire-init, wire-poll, wire-show, wire-text, wire-bind. Order matters for `on('directive.init')` listener order (wildcard registered early, 2nd) and for `customDirectiveNames` check inside wildcard (wire-wildcard.js:10 consults `customDirectiveHasBeenRegistered` at runtime, so import order doesn't actually matter there). wire-sort lives in features/supportWireSort, wire-intersect in features/supportWireIntersect, wire-stream/wire-ref/wire-island have no JS directive file (handled by querySelector/attribute reads elsewhere). Note: `supportDisablingFormsDuringRequest` is imported from features/index.js.

### directives/shared.js
Role: `toggleBooleanStateDirective(el, directive, isTruthy, cachedDisplay)` shared by wire:loading, wire:dirty, wire:offline. Uses `directive.modifiers.includes(...)` (array scans), `directive.expression.split(' ').filter(String)` for class (re-split on every toggle, shared.js:6), `getComputedStyle(el).display` only when no class/attr modifier and `cachedDisplay` is null/undefined (shared.js:20-22; this is a style recalc/layout-forcing read; called by wire:loading each time loading toggles since wire-loading passes no cachedDisplay: wire-loading.js:13,34). No PHP pair. No listeners/observers.
Perf: shared.js:20-22 forced style computation per toggle for plain `wire:loading` (no .class/.attr), once per request start and once per end (restoreLoadingState -> toggle(false) -> computes display again at end). Display list array allocated/filtered per call (l.24-25).

### directives/wire-bind.js
Role: `wire:bind:x` -> `x-bind:x` with a Livewire-contextualized expression. PHP pair: none. Mechanism: `Alpine.interceptInit`. Per element: loop over attributes (`startsWith('wire:bind:')`), `Alpine.bind(el, {...})` with a getter that calls `evaluateReactiveExpression` -> Alpine effect per bound attribute. No cleanup registered (Alpine tears its own bind effects down on element destroy, fine).
Perf: wire-bind.js:4-19 attribute scan on every initialized element; evaluator regex cost re-run on every reactive change (see evaluator note). Note `startsWith('wire:bind:')` vs wire-show/text use `startsWith('wire:show')` (would also match `wire:showfoo`; not a perf issue).

### directives/wire-cloak.js
Role: removes `wire:cloak` attribute on init (CSS `[wire\:cloak]{display:none}` hides until then). Pair: none (CSS from FrontendAssets). `Alpine.interceptInit` + `Alpine.mutateDom`. Per element: one `hasAttribute`. Perf: wire-cloak.js:3-6 hasAttribute on every element; mutateDom wraps removal so Alpine's observer ignores it (good).

### directives/wire-confirm.js
Role: stores `el.__livewire_confirm` closure on element; wire-wildcard consults it at click time. Pair: none (SupportWireConfirm? JS only). `directive()` => one of the 12 directive.init listeners. No listeners/timers. `message.split('|')` at click time. Per element: one closure. No cleanup (property dies with element). Not hot.

### directives/wire-current.js
Role: sets classes + `data-current` on links matching the current URL. Pair: SupportWireCurrent test folder only. `globalDirective('current')` (directive.global.init), `Alpine.addInitSelector('[wire\:current]')`, document `livewire:navigated` listener (l.8) iterating module Map `onPageChanges`.
Per element: `new URL(href, location.href)` (l.31), `expression.split(' ').filter(String)` (l.33), `refreshCurrent(new URL(location.href))` (l.45), Map entry. Cleanup via `cleanup(() => onPageChanges.delete(el))` (l.49) - relies on Alpine.onAttributeRemoved firing at element removal (UNSURE it fires on whole-element removal; Alpine's destroyTree normally runs `_x_attributeCleanups` so probably yes).
Per navigation: for each current element: `new URL(window.location.href)` is made ONCE per `forEach` item? No: `onPageChanges.forEach(i => i(new URL(window.location.href)))` (l.9) allocates a new URL per element. `pathMatches` (l.52-73): two regex `replace(/\/+$/)`, two `split('/')` per call; then `classList.add(...classes)` + setAttribute each navigation even if unchanged (l.37-38).
Early returns (l.21-27) happen BEFORE the Map registration so ignored elements cost nothing later.

### directives/wire-dirty.js
Role: show/hide/class toggles when component state differs from last-synced snapshot. Pair: none (SupportWireDirty? JS only). Hooks: `on('commit')` (l.8) -> `succeed` -> `setTimeout(...)` -> refresh all dirty states of that component. Mechanisms: bare `Alpine.effect` (l.31; NOTE `Alpine` is used but NOT imported in this file - relies on global `window.Alpine`; UNSURE it is always defined, it works in practice because Livewire sets window.Alpine).
Per element: `dirtyTargets(el)` -> `getDirectives(el)` = parses ALL wire:* attributes on the element with fresh RegExp (l.68), `initialDisplay` read (style), WeakBag push (l.29), one Alpine.effect.
Perf:
- wire-dirty.js:31-41 the effect calls `checkDirty` (l.44-65). With NO targets (plain `wire:dirty` without wire:target/wire:model on the element): `JSON.stringify(component.canonical) !== JSON.stringify(component.reactive)` (l.48) -> two full-state stringifies, and because stringify reads every property of the reactive proxy, the effect subscribes to the ENTIRE component state: it re-runs on every change to any property (every keystroke on a non-live wire:model). Cost O(state size) x (#dirty elements) per change. With targets: per target `dataGet` + 2 stringifies (l.53-54), no early exit once dirty found (l.56-58 keeps looping).
- NO cleanup: the Alpine.effect (global, not el-scoped) is never released, and the `refreshDirtyState` closure is never removed from the WeakBag (l.29; `cleanup` not destructured/used). After morphs remove/re-add dirty elements, effects + callbacks for detached elements keep running and holding `el`. On every successful commit, `refreshDirtyStatesByComponent.each(component, i => i(false))` (l.11) calls toggleBooleanStateDirective for ALL ever-registered dirty els of the component incl. detached ones (setTimeout per commit per component).
- `on('commit')` handler itself runs per commit for every component regardless of whether any dirty element exists (l.8-14 allocates succeed callback + a setTimeout per commit per component).
- wire-model.js:107 also uses checkDirty (blur path).

### directives/wire-ignore.js
Role: flags `el.__livewire_ignore`, `__livewire_ignore_self`, `__livewire_ignore_children`; read by morph.js. One of 12 directive.init listeners. No listeners. Trivial. (morph.js reads these per node during morph: not in my scope.)

### directives/wire-init.js
Role: run action expression (default `$refresh`) at directive init. Pair: none. `setNextActionOrigin` + `evaluateActionExpression` (contextualize regexes once). No cleanup, runs each time directive.init fires for that element (including re-init after morph replaced the element). Not hot, but each wire:init triggers a request.

### directives/wire-loading.js  (extra care)
Role: toggles element display/class/attr while a request (or file upload) affecting its targets is in flight. Pair: PHP folder SupportWireLoading? (tests only; JS-only). Mechanisms: `interceptMessage` (global, per element!), `listen(window, 'livewire-upload-*')` x3 per element, `onNavigationStart` per in-flight message, setTimeout for `.delay`.
Per element at init (l.8-62):
- `getTargets(el)` (l.246-277): `getDirectives(el)` parses every wire attr (fresh RegExp each, directives.js:76), if wire:target present: `directive.methods` -> `parseOutMethodsAndParams` char-scan + `Alpine.evaluate(document, 'argumentsToArray(..)')` for param'd targets + `quickHash(JSON.stringify(params))` (btoa(encodeURIComponent)). If no wire:target: ALL other wire directives become targets by name via `.filter(includes list).map(expression.split('(')[0])` (l.267-273). NOTE this makes an un-targeted `wire:loading` element with no sibling directive have `targets=[]` = "any action on component", but one with e.g. `wire:click`/`wire:model` on same element gets that as target.
- `applyDelay` allocs closures; `restoreLoadingState` closure; two cleanup groups.
- `whenTargetsArePartOfRequest` registers a global `interceptMessage` callback (l.110): for N loading elements on a page, every message of every component runs N callbacks and allocates N MessageInterceptor objects (interceptor.js:118-127) before `component !== message.component` returns (l.111). Cost O(N_loading_total) per message instead of O(N_in_that_component).
- `whenTargetsArePartOfFileUpload` adds 3 `window` event listeners PER loading element (l.193-209) - for all elements, even ones that can never relate to uploads; every upload event (incl. `livewire-upload-progress`? no, only start/finish/error) calls `eventMismatch` for each loading element on the page across all components (l.184-191, includes `targets.map(i => i.target).includes(property)` allocating an array per event per element).
- Cleanup: both groups are cleaned in `cleanup(...)` (l.58-61) (via Alpine.onAttributeRemoved; UNSURE whether it fires when the whole element is removed by morph, though it should via destroyTree).
Per message for matching component: `closestIsland(el)` (l.117) per loading element per message when no targets - walks DOM via fragment.js (supportIslands.js:71) (not read in depth; UNSURE cost) then `message.hasActionForIsland/Component`. `onSend`: `containsTargets(payload, targets)` (l.218-244): for param'd targets `JSON.stringify(methodParams)` + btoa/encodeURIComponent per call per target (l.225), for plain targets `Object.keys(updates).some(...)` with `property.split('.')` per key, `calls.map(i=>i.method)` allocation per target per element (l.242). Per element, per message. `onSuccess` registers `onNavigationStart` listener (l.157) (set add/remove, cheap), `onEffect`, `onFinish` closures per message per matching element.
`toggleBooleanStateDirective` forced `getComputedStyle` (see shared.js) at start and end.
Leak check: `abortDelay`'s `started` flag uses a single `timeout` var: overlapping starts (activeLoadingCount>0 only starts once) fine. If the element is removed mid-request, `finishLoading` still touches detached el (harmless).

### directives/wire-model.js  (extra care)
Role: two-way binding via Alpine `x-model` over `$wire`, with `.live/.blur/.change/.enter/.debounce/.throttle/.lazy/.deep/.self/.number...` modifiers. Pair: SupportDataBinding (tests only; JS) plus PHP update path `updateProperties`. Mechanisms: `directive('model')` (directive.init), `Alpine.bind(el, {x-model..., @blur, @change, @keydown.enter})`, `findComponentByEl`, `setNextActionOrigin/Metadata`, `component.$wire.$commit()`.
Per element at init (l.9-142):
- `findComponentByEl(el)` (l.12) walks up the DOM (store.js, not read in detail; UNSURE of cost) even though `component` is already supplied by the hook (comment says slot support).
- `componentIsMissingProperty` (l.190-204): two regex `match`es + `Object.keys(component.canonical).includes(base)` per element (O(#props) allocation per wire:model element; for `$parent` recursion uses `findComponentByEl(component.el.parentElement,false)`).
- modifier array manipulations: `filter`, `indexOf`, `slice`, many `includes` (l.16-70), closures `update`, `debouncedUpdate`.
- debounce/throttle closures per element (l.88-94); `isRealtimeInput` (`toUpperCase` x2 per call).
- `getModifierTail(ephemeralModifiers)` (l.144-171) more array filters/splices.
- `Alpine.bind(el, bindings)` -> creates Alpine x-model effect (+ input/change listeners by Alpine, x-model's own `_x_forceModelUpdate`), plus optional @blur/@change/@keydown.enter listeners. Note `bindings['x-model' + tail]` returns a fresh `{get,set}` object each evaluation.
- Cleanup is passed only to `handleFileUpload` (l.28); for normal models rely on Alpine.bind's own teardown.
Per keystroke/change (hot path): x-model setter -> `dataSet(component.$wire, expression, value)` (l.131) via $wire proxy -> reactive write triggers every Alpine effect depending on that property (wire:dirty effects re-stringifying state, wire:text, wire:show, entangle, queryString effect). With `.live`: `debouncedUpdate()` -> `update()` -> `setNextActionOrigin` + `$commit()`. The `x-model` getter `dataGet(component.$wire, expression)` is evaluated through the $wire proxy on every reactive re-evaluation.
Dead code: `isDirty()` (l.182-188) unused. `parseModifierDuration` returns a string (`duration` from split) not a number (l.246-247) - passed as ms to setTimeout, works via coercion.
Blur path: `queueMicrotask` + `checkDirty(target)` whole-state JSON.stringify x2 per blur (l.103-107) when `.blur.live`/`.live.blur`.
Perf: wire-model.js:22 + 190-204 per-element property existence check; wire-model.js:12 per-element ancestor walk; each model element holds 2-4 closures plus Alpine effect and listener(s).

### directives/wire-navigate.js
Role: wire:navigate -> `x-navigate` (Alpine navigate plugin from plugins/navigate); exports selectors used by supportDataCurrent. Pair: SupportNavigate (PHP mostly disabling back-button cache; tests). Mechanisms: 5x `Alpine.addInitSelector`, `Alpine.interceptInit(skipDuringClone(...))`, `document 'alpine:navigating'` listener (l.41).
Per element: loop `Object.entries(attributeMap)` allocating an entries array per element on every Alpine-initialized element (l.32 - `Object.entries(attributeMap)` allocated per call, up to 5 `hasAttribute`) - this runs for all elements on the page, not only wire:navigate ones (wire-navigate.js:29-39).
On `alpine:navigating` (before every navigation): `Livewire.all().forEach(c => c.inscribeSnapshotAndEffectsOnElement())` - re-serialises each component's snapshot to its element attribute (JSON string writes) for ALL components on the page on each navigation (l.44-46). Uses global `Livewire` (no import).

### directives/wire-offline.js
Role: toggle on window offline/online. Pair: none. Two module-level `window` listeners (l.7-8) with Sets of handlers; per element add 2 handlers + cleanup (l.17-20). Cheap. Cleanup present.

### directives/wire-poll.js  (extra care)
Role: periodic action. Pair: SupportPolling (tests only). Mechanisms: `directive('poll')`, shared `setInterval` per distinct ms value (`clocks` object keyed by ms, l.59-81: one real timer per period, callbacks in a Set), module-level `window offline/online` + `document visibilitychange` listeners (l.85-94).
Per element: `extractDurationFrom(modifiers, 2000)` (l.127-142): 3 regex `match` per modifier x3 passes (note `/([0-9]+)s/` also matches e.g. "2s" but `/([0-9]+)m/` matches "500ms"; ms checked first), closures. `poll()` creates 3 arrays and 6 `pauseWhile/throttleWhile/stopWhen` registrations (closures) per element.
Per tick, per poll element (default 2s): evaluates stop conditions (`el.isConnected`), then pause conditions in order: `theDirectiveHasVisible` (modifiers.includes) + optional `getBoundingClientRect` (forces layout, l.112-121, only with `.visible`), **`theDirectiveIsOffTheElement(el)` => `getDirectives(el).has('poll')` (l.100-102) which parses ALL wire attributes on the element with RegExp allocations and `extractDirective` for each, every tick** (not memoized; could be `el.hasAttribute`-like), `livewireIsOffline()`, `sessionIsExpired()` (request module). Throttle: `Math.random() < .95` skip when tab hidden without `.keep-alive` (still calls once per tick per element; no real throttle of the timer itself).
Then `callback()` -> `setNextActionOrigin`, `setNextActionMetadata`, `evaluateActionExpression(el, '$refresh' or expr)` -> contextualize regexes + Alpine.evaluateRaw each tick (l.29).
Leaks: NO `cleanup()` used. The only teardown is lazy: `stopWhen(theElementIsDisconnected)` checked on next tick (l.19,40). If the `wire:poll` attribute is removed but element stays, poll keeps ticking paused (l.16) forever (until element removed). If `directive.init` is re-triggered for the same element/attribute (UNSURE when), a second poller would be added without clearing the first. The `clock` interval is cleaned when callbacks Set empties (l.76-79).
`clocks` is an array (`let clocks = []`) used as a map by ms key (sparse array with numeric ms index; `clocks[2000]` makes a sparse array - UNSURE perf, V8 treats large sparse index as dictionary mode; harmless).
Unused param `component`. `triggerComponentRequest` targetEl null disables data-loading.

### directives/wire-replace.js
Role: sets `el.__livewire_replace(_self)` flags read by morph.js. Trivial; one directive.init listener.

### directives/wire-show.js / wire-text.js
Same pattern as wire-bind (`x-show`, `x-text` + modifierString from `name.split('wire:show')[1]`) with `evaluateReactiveExpression`. Pair: SupportWireShow/SupportWireText tests (JS-only). Interceptor callback runs on every element init with full attribute scan. One Alpine effect per directive; evaluator regexes re-run on every reactive trigger (evaluator.js:68). `value.trim()` per init.

### directives/wire-transition.js
Role: view-transition helper library + no-op `globalDirective('transition')` (registers a `directive.global.init` listener that does nothing but is invoked per directive, l.8-10). Pair: SupportTransition? (#[Transition] attribute? UNSURE which PHP feature). Exports used by morph.js: `transitionDomMutation(fromEl,toEl,cb,opts)`.
Per morph (called from morph.js): l.118 `fromEl.querySelector('[wire\:transition]')` and `toEl.querySelector(...)` - two full-subtree selector scans per morph even if the page has no transitions (early exit only when found). If found/supported: `document.querySelector('dialog:modal')`, `querySelectorAll(':popover-open')` and `popover.querySelectorAll('*')` + `checkVisibility()` (l.135-139), `setTransitionNames` x2 (`root.querySelectorAll`), creates `<style>` element appended to head per transition, `MutationObserver` on documentElement with `attributes: true, attributeFilter:['open'], subtree:true` + capture `beforetoggle` listener per transition (l.84-110), both removed in `finished.finally`. Note l.94-99: observer callback runs `document.querySelector('dialog:modal')` per mutation until disconnected. `await transition.updateCallbackDone`.
`cleanup` calls `clearTransitionNames(fromEl)` which does another `querySelectorAll` (l.45).

### directives/wire-wildcard.js
Role: every other `wire:event` (click, submit, keydown.enter, etc.) -> Alpine `x-on:event` bound to action evaluation. Pair: JS-only; PHP receives calls. Mechanism: `on('directive.init')` (l.8), `Alpine.bind(el, {[attribute](e){...}})`, `callAndClearComponentDebounces` (debounce.js). 
Per directive at init: `includes` over a 13-item array (l.9) + `customDirectiveHasBeenRegistered` (Set) + `rawName.replace('wire:','x-on:')` + up to 4 more `includes`+`replace` for modifiers (async/renderless/prepend/append, l.20-37) + Alpine.bind (one event listener per element/event; x-on parse by Alpine). `cleanup(cleanupBinding)` registered (l.91).
Per event: `callAndClearComponentDebounces`, `setNextActionOrigin`, for submit `el.querySelector('button[type="submit"], input[type="submit"]')` when `e.submitter` missing (l.48), `evaluateActionExpression` (3 contextualize regex passes + Alpine.evaluateRaw) (l.74). Note wildcard excludes via hardcoded list that does NOT include 'confirm','replace','offline','navigate','transition','current','cloak','show','text','bind','intersect','stream','ref','island','poll'? - those are filtered by `customDirectiveHasBeenRegistered` only for `directive()`-registered names (confirm, dirty, ignore, init, loading, model, offline, poll, replace, current, transition). Names like `wire:show`, `wire:text`, `wire:bind:*`, `wire:intersect`, `wire:cloak`, `wire:navigate`, `wire:ref`, `wire:stream`, `wire:key`(in list), `wire:island`, `wire:sort:*` (list has 'sort' only as `value`; `wire:sort:item` has value 'sort:item'? directive.value comes from `name.replace('wire:','').split('.')[0]` so value = 'sort:item' / 'bind:foo'/'show'/'text'/'intersect'/'navigate'/'cloak'/'ref'/'stream'/'island' - these are NOT in the exclusion list nor registered via directive()) therefore fall through and get an `x-on:show` / `x-on:text` / `x-on:navigate` / `x-on:bind:foo` / `x-on:intersect` Alpine event-listener binding in addition to their real handler. UNSURE if x-on:xxx with these names is inert (it adds a real addEventListener for events named 'show', 'text', 'intersect', 'bind:foo', 'navigate', 'cloak', 'ref', 'stream', 'island', 'sort:item'). Fact: no filter for them in wire-wildcard.js:9-10. Verify when hunting (cost: an extra Alpine.bind + listener + attribute string manipulation per such element at init; plus the attribute `x-on:` is evaluated lazily).

## 2. js/features/

### features/index.js
Order-sensitive: supportListeners, supportScriptsAndAssets, supportJsEvaluation, supportIslands, supportMorphDom, supportDispatches (this order dictates the global `interceptMessage` onMorph/onMorphed ordering: islands morph before main morph? Listed islands (onMorph) then morphDom (onMorph) then dispatches (onMorphed)). Not imported here: supportErrors, supportRefs, supportWatch, supportEntangle? (entangle IS imported l.22), supportRefs/Errors/Watch are imported by $wire.js/component.js (UNSURE exact importers). supportNavigate/DataCurrent/WireSort etc. all imported.

### supportListeners.js  (pair: PHP SupportEvents)
Hook: `on('effect')`. Per component with `listeners` effect (mount only): for each listener name: one `window.addEventListener(name)` + one `component.el.addEventListener(name)`. window handler cleanup registered via `component.addCleanup`; el listener not removed (dies with el). Perf: N components listening to the same event => N window listeners; each dispatch iterates all (plus `e.target instanceof Element` + `setNextActionOrigin` + `$wire.call('__dispatch')`). Each response still runs the hook (hasOwnProperty + `[]`+ `forEach` on empty) - negligible.
Inconsistency (not perf): receivedBy.push(component) at l.20 vs push(component.id) at l.47.

### supportScriptsAndAssets.js  (pair: SupportScriptsAndAssets; extra care)
Hooks: `on('payload.intercept')` (async; per response, destructures `assets`), `on('component.init')` (registers memo assets into `executedAssets`), `on('effect')` -> `evaluateScripts`. State: `executedScripts` WeakMap(component->array of keys), `executedAssets` Set.
Per response: `payload.intercept` returns immediately without assets. With assets: sequential `await` per key; `addAssetsToHeadTagOfPage` -> `replaceNoncesInHtml` + `new DOMParser().parseFromString` + `document.adoptNode(head)` + per child append; `script.src` awaited with onload/onerror (rejection swallowed by try/catch at l.115-121). `executedAssets.add(key)` BEFORE load (l.94-97) so a failed load is never retried.
Per script effect (component mount or first time key): `extractScriptTagContent` regex `/<script\b[^>]*>([\s\S]*?)<\/script>/gm` (l.85), `includes('await')`, string-wrapped IIFE, `Alpine.evaluateRaw` through `evaluateExpression` -> `new Function`-style compile per component instance (the wrapped string includes no per-instance data, so Alpine's expression cache may hit; UNSURE). `onlyIfScriptHasntBeenRunAlreadyForThisComponent` array `includes`, `executedScripts.set` redundant (l.81). No listeners/observers created by this file itself. Scripts that register listeners (user code) depend on `$wire` cleanup; not managed here.

### supportJsEvaluation.js  (pair: SupportJsEvaluation `#[Js]`/`$this->js()`)
`Alpine.magic('js')`; `on('component.initialized')` and a global `interceptMessage` onSuccess->onMorphed `evaluateJsEffects(component, effects)`. Per response: reads `effects.js`/`effects.xjs`; for each js method `overrideMethod(component, method, () => evaluateExpression(el, body))`; each xjs `evaluateExpression(el, expression, {scope: component.getJsActions(), params})` (compile per call; `getJsActions()` builds an object per xjs item - UNSURE cost). Per-message interceptor allocation (global, see 0).

### supportIslands.js  (pair: SupportIslands + Island/Fragment PHP)
Hooks: `interceptAction` (global; runs for EVERY action with an origin), `interceptMessage` (onStream, onSuccess->onMorph). Per action: `Array.from(el.attributes).find(startsWith('wire:island'))` (l.13) allocating an array of attributes per action; else `closestIsland(origin.el)` (l.35, l.71-78) -> `closestFragment` in fragment.js (walks previous siblings/ancestors reading comment nodes; not fully read; UNSURE cost; runs on every action even for components with no islands unless closestFragment short-circuits - check `component.islands`/memo).
Per response: `renderIsland` -> `extractFragmentMetadataFromHtml` twice (l.81 and l.91, duplicate parse of same HTML) + `findFragment(component.el, ...)` full scan of the component's DOM for markers per island fragment + `morphFragment`. Island streaming (`onStream`) same path.

### supportMorphDom.js  (pair: none/HandleComponents effects.html)
Global `interceptMessage` -> onSuccess -> onMorph: `morph(component, el, html)` when effects.html present. Per response per message. Only the dispatch glue; morph.js is outside scope.

### supportDispatches.js  (pair: SupportEvents server dispatches)
Global interceptMessage onSuccess->onMorphed -> `dispatchEvents`. `on('component.initialized')`: if component has effects.dispatches, triple nested `queueMicrotask`. `getDispatches` returns `effects.dispatches || []`. Cheap.

### supportDisablingFormsDuringRequest.js  (pair: none; JS-only; `wire:submit`)
Hooks: `on('directive.init')` (l.13), global `interceptMessage` (per message; `Bag.each`, `Bag.remove`), `releaseAfterNavigation` (adds `onNavigationStart` listener per message on success).
**Perf: l.13 wraps the WHOLE body in `setTimeout(() => {...})`: a macrotask timer is scheduled for EVERY directive on EVERY element that triggers `directive.init` (wire:click, wire:model, wire:key, wire:loading, wire:target, wire:id, wire:snapshot... all of them), and only inside the timeout is `directive.value !== 'submit'` checked (l.14).** So N directives => N timers per initial page load and per morph-added element, each holding closure over el/directive/component.
For submit: `el.addEventListener('submit', ...)` (not removed; no use of `cleanup`), `disableForm(el)` does `Alpine.walk(formEl, ...)` over the form subtree at each submit with `formEl.contains(el)` per node (l.44-54; contains is O(depth)), `hasAttribute('wire:ignore')`, `tagName.toLowerCase()` x2, `inputTypesThatDontSupportReadonly` array allocated per node (l.63-66). `Bag` keyed by component id: `cleanups.each` per message even for components without forms.

### supportFileDownloads.js  (pair: SupportFileDownloads)
`on('commit')` succeed handler per commit per component: hasOwnProperty check; on download: `atob` of base64 content then slice loop building `Uint8Array`s (l.36-57, per-char `charCodeAt` loop; blocks main thread for big files; whole file passes through the JSON response as base64). Creates hidden `<a>` appended to body and never removed (l.20-28) (leak of one element per download). `revokeObjectURL` after setTimeout 0.

### supportFileUploads.js  (pair: SupportFileUploads; extra care)
Used by wire-model.js for `input[type=file]` (`handleFileUpload`) and by `$wire.upload*` APIs.
Per component (lazy, first upload use): `getUploadManager` -> `UploadManager` with 2 MessageBags and `registerListeners()` (5 `$wire.$on` subscriptions, l.88-107; never unsubscribed - UNSURE if `$on` listeners are cleaned with component).
Per file input element: closures (start/finish/error/cancel/progress), 3 element listeners (`change`, `click`, `livewire-upload-cancel`), `component.$wire.$watch(property, ...)` -> Alpine.watch (per file input, with cleanup `unwatch()`), cleanup removes the 3 listeners (l.72-78) (good). Note: wire-model.js returns right after handleFileUpload, so file inputs get none of the normal bindings.
Per upload: `FormData`, one `XMLHttpRequest` (not fetch) with `upload progress` listener doing `Math.floor` and `e.detail = {}` assignment; `$wire.call('_startUpload')` round trip, then XHR to signed URL, then `_finishUpload` round trip => at least 2 Livewire requests + 1 XHR per upload batch (per `uploadBag` queue item; queued uploads are serialized per property name). `uploadBag.first(name)` lookups. `progress` handler dispatches CustomEvents on the element on every XHR progress event (not throttled; `livewire-upload-progress`).
Dead/buggy: MessageBag.call/has reference `this.listeners` which is never defined (l.300-308; would throw if called); `setUploadLoading`/`unsetUploadLoading` are empty stubs (l.311-317). Per-event `window` listeners from wire:loading for `livewire-upload-*` (see wire-loading).
Per Alpine.watch on file input: runs a callback on any change of the property.

### supportQueryString.js  (pair: SupportQueryString #[Url]; extra care)
Hook `on('effect')` (per response; early return unless `effects.url`; sent only on mount). Per tracked property per component at mount: `normalizeQueryStringEntry`, `dataGet(component.ephemeral, name)`, `track(...)` from plugins/history: `historyCoordinator.getUrl()` (`new URL(location.href)`), `has()` (parses the query string via fromQueryString: split/regex/dotted-key assembling), possible `set()` (alwaysShow) and `replace(url,name,{value})` which is batched (coordinator.js batch) to one `history.replaceState` per tick (good).
- `use: 'replace'` (default): `Alpine.effect(() => replace(dataGet(component.reactive, name)))` (l.25-27) -> runs on every reactive change of that property (e.g. each keystroke in non-live model) -> `update()` -> `getUrl()` (new URL) + `remove/set` (fromQueryString/toQueryString on whole search string + JSON.stringify comparisons `hasReturnedToInitialValue`) -> batched replaceState. Cleanup via `Alpine.release` (l.29).
- `use: 'push'`: adds a global `on('commit')` listener per tracked property per component (l.33-52) - EVERY commit for ANY component invokes it before `component !== commitComponent` check; it also captures `JSON.stringify(canonical[name])` before and after each of the component's commits (l.36,40-41), plus `pop(...)` registers a `window 'popstate'` listener per property (history/index.js pop) with cleanup. In the popstate handler: `document.querySelectorAll('input')` + `el._x_forceModelUpdate` over ALL inputs on the page (l.60-62; comment calls it "the absolute worst thing").
- `on('commit')` handlers are never `off`'d via hooks cleanup other than `forgetCommitHandler()` in `cleanup` (l.77-80) - present.
`onPopState` lock/async logic fine.

### supportLaravelEcho.js  (pair: none/SupportEvents echo listeners)
`on('request')`: adds `X-Socket-ID` header per request if `window.Echo`. `on('effect')`: for echo listeners only (mount). `event.split(/(echo:|echo-)|:|,/)`; `window.Echo[channel_type](channel).listen(...)`; cleanup via `component.addCleanup`. `Echo.join(...)`/`Echo.leave(channel)` inside cleanup leaves channel for all components sharing it (correctness, UNSURE). The 'notification' branch `stopListening` removes all notification listeners on that channel. Cheap per response.

### supportStreaming.js  (pair: SupportStreaming)
Global `interceptMessage` -> `onStream`. Per streamed chunk: `findComponent(id)`, `component.el.querySelector('[wire\:stream.replace="name"]')` then `querySelector('[wire\:stream="name"]')` (two subtree scans per chunk for directive type; selectors built per chunk), `insertAdjacentHTML('beforeend')` or `innerHTML` assignment (innerHTML on each chunk if replace). `findComponent` may throw if component missing (UNSURE). No cleanup concerns.

### supportRedirects.js  (pair: SupportRedirects)
`on('effect')` per response: `hasOwnProperty` and `request.invokeOnRedirect`. Cheap.

### supportNavigate.js  (pair: SupportNavigate)
Three document listeners forwarding `alpine:*` events to `livewire:*` (new CustomEvent per event); `livewire:initialized` listener hides progress bar via `document.querySelector('[data-no-progress-bar]')` once. `Alpine` used as global (not imported). `shouldRedirectUsingNavigateOr` used by supportRedirects.

### supportEntangle.js  (pair: SupportEntangle `$wire.entangle`)
`generateEntangleFunction(component, cleanup)` returns `entangle(name, live)`. Per `$wire.entangle()` call (typically per `x-data` init): `$wire.get(name)`, `Alpine.interceptor`, `Alpine.entangle` (2 Alpine effects) + cleanup; `cloneIfObject` deep clone via `JSON.parse(JSON.stringify())` (l.55-59; note `typeof null === 'object'` -> JSON roundtrip of null fine). Indentation oddity only.

### supportSlots.js  (pair: SupportSlots)
Global `interceptMessage` onSuccess->onMorph: reads `slotFragments`, `renderSlot` -> `extractFragmentMetadataFromHtml`, `findComponent`, `findFragment(targetComponent.el)` (DOM marker scan), `morphFragment`. Per response (empty array normally).

### supportDataLoading.js  (pair: none; `data-loading` attribute)
Global `interceptMessage` per message (onSend per action: `setAttribute('data-loading','true')`; undo array; `releaseAfterNavigation` registers `onNavigationStart` per message). Per message per action DOM write; per message 2-3 allocated closures. Writes happen on every message including polls? (poll sets `targetEl: null` to skip, wire-poll.js:24).

### supportDataCurrent.js  (pair: SupportWireCurrent `data-current` on wire:navigate links)
`livewire:navigated` and `DOMContentLoaded` listeners -> `document.querySelectorAll(wireNavigateSelector)` (comma list of 5 attribute selectors), per link: `Array.from(el.attributes).some(startsWith('wire:current'))`, `getAttribute('href')`, `new URL(href, location.href)`, `pathMatches` (regex + splits), set/remove attribute. Runs once per navigation, O(#navigate links on page). Note: `options = {exact: true}` while the comment says partial matching (l.33 mismatch; correctness only).

### supportPreserveScroll.js  (pair: none)
Global interceptMessage onSuccess per message; per action origin with `preserve-scroll` modifier: `onSync`/`onMorph` closures reading `document.body.scrollHeight` (forces layout) before and after morph, `window.scrollTo`. Only for those messages (actions loop for all messages cheap).

### supportWireIntersect.js  (pair: none; `wire:intersect`)
`Alpine.interceptInit` for every element: attribute scan with `startsWith('wire:intersect')`; per directive: `extractDirective` (regex), `Alpine.bind(el, {x-intersect...})` => Alpine intersect plugin creates an IntersectionObserver per element (Alpine plugin behavior; UNSURE whether observer is shared, Alpine.intersect creates one per element binding by default). On intersect: `findComponentByEl`, `addActionContext`, `evaluateActionExpression`. No explicit cleanup (Alpine tears down).

### supportWireSort.js  (pair: none; `wire:sort`)
Two mechanisms: `on('directive.init')` (l.7; one of the 12 listeners; `startsWith('wire:sort:item')` per directive) and `Alpine.interceptInit` for every element (l.16-84) scanning attributes; the first `startsWith('wire:sort:item')` check then `wire:sort:group-id` / `wire:sort:group` / `wire:sort` chain. Note l.25-27 `return` inside the loop on `wire:sort:group` exits the attribute scan early. Per directive: `extractDirective`, `Alpine.bind` x-sort; the item binder `bindSortItem`. On sort event: `Array.from(el.children).filter(hasAttribute...)` + `findIndex` (l.63-66; O(children) per drop), evaluate action. Double-init guard via `el._x_sort_key` (l.11) - fires directive.init path AND interceptInit path for the same element (both attempt bindSortItem; guarded only by `_x_sort_key !== undefined`, which is set by Alpine later - UNSURE whether guard works during init ordering).

### supportCssModules.js  (pair: SupportCssModules)
`on('effect')`; mount-only effects. Per mount with style module: string replaces on `component.name`, `getModuleUrl()`, Set check, `<link rel=stylesheet>` injection (nonce) once per path. Not per response.

### supportJsModules.js  (pair: SupportJsModules; extra care)
Hook `on('effect')` (per response; early returns when no `childScriptModules`/`scriptModule`), global `interceptMessage` with FIVE callbacks per message (onSuccess+onSync+onMorphed, onCancel, onFailure, onError, onFinish) for every message of every component (l.127-140): `processingMessages.set` (WeakMap) per success, `releaseMorphGate` x several (WeakMap lookups).
Per component with `scriptModule` effect (mount / lazy hydrate): `Alpine.reactive({loading,afterLoaded})`, dynamic `import(path)` (browser module cache; URL has `?v=hash`), `Promise.all([modulePromise, morphGateFor(...)])`, then `module.run.call($wire, $wire, $wire.js)`; `Alpine.deferInit(component.el, ready)` suspends the component's Alpine subtree until script runs (l.465,471-473). Imports `childScriptModules` eagerly (`import()` per child, per response that includes the effect, l.413-417; duplicates are cheap module-cache hits).
Cost: per-mounted-component network (one JS module request per distinct component class, cached) and Alpine init deferral latency (tree initialization waits for module + morph gate). `pendingComponentAssets` WeakMap delete in finally. `lifecycle.js:82` `treeIsSuspendedFor` consulted per component init.
Cleanup: none needed beyond WeakMaps.

### supportErrors.js  (pair: SupportValidation `$wire.$errors`)
`getErrorsObject(component)`: `Alpine.reactive({clientErrors})` per component lazily, plus `__lastErrorsSnapshot`. Methods call `messages()` repeatedly (`has`, `first`, `all`, `count` each re-run `Object.keys/values.flat()`), `has()` calls `first(key)` -> `get(key)` -> `messages()` each; `all()` allocates via `flat()`. No timers/listeners. `component.snapshot` identity check per `messages()` call (reactive dependency tracking through `component.snapshot` read).

### supportRefs.js  (pair: none; `wire:ref`)
`findRefEl`: `component.el.querySelector('[wire\:ref="name"]')` per call (per `$wire.$refs.x` access or per stream chunk with ref); not memoized. Selector built from unescaped name (injection of quotes UNSURE).

### supportWatch.js  (pair: none; `$wire.$watch`)
`generateWatchFunction(component, cleanup)`: `Alpine.watch(() => dataGet(component.reactive, path), cb)` with cleanup registered via `cleanup`/`component.addCleanup` (returns an unwatch that removes the cleanup). Per `$watch` call one Alpine effect. Good cleanup.

## 3. PERFORMANCE OBSERVATIONS (ranked by estimated impact, with file:line)

A. Per directive per element at init (page load + every morph-added element):
 1. features/supportDisablingFormsDuringRequest.js:13 schedules a `setTimeout` for EVERY `directive.init` (all wire directives, not just wire:submit); the `directive.value !== 'submit'` check happens inside the timer (l.14). N wire attributes -> N timers + closures.
 2. directives.js:5-13 + lifecycle.js:42,55,69,86,111-114: `new RegExp('wire:')` allocated per attribute up to 3x (match in `some`, match in `filter`, `replace` in extractDirective); `getDirectives` (wire-loading.js:247, wire-dirty.js:68, wire-poll.js:101) repeats it for all attributes of an element.
 3. 12 `directive.init` listeners + 2 `directive.global.init` listeners looped by hooks.js:47-61 for every directive; each also allocates `cleanup` closure via Alpine.onAttributeRemoved wrapper (lifecycle.js:59/90/101/118).
 4. 8 `Alpine.interceptInit` callbacks run for EVERY Alpine element including non-wire elements: wire-bind.js:4, wire-show.js:4, wire-text.js:4, wire-cloak.js:3, wire-navigate.js:29 (also allocates `Object.entries(attributeMap)` per element l.32), supportWireIntersect.js:6, supportWireSort.js:16, lifecycle.js:65. ~5 full attribute scans with `startsWith` per element.
 5. wire-model.js:12 (`findComponentByEl` per element though component is supplied), wire-model.js:22/190-204 (`Object.keys(component.canonical)` + 2 regex per wire:model element), wire-model.js:141 Alpine.bind per element with closures.
 6. wire-wildcard.js:8-37 many `includes`/`replace` per directive, plus names not excluded (show/text/bind/intersect/navigate/ref/stream/island/cloak) fall into wildcard binding (UNSURE whether harmful).

B. Per request/response (global, scales with page size, not component):
 7. Each `wire:loading` element registers its own global `interceptMessage` (wire-loading.js:110) => per message, `new MessageInterceptor` x (12 static + N_loading) (request/interceptor.js:118-127); plus 3 window listeners per loading element for uploads (wire-loading.js:193-209) executed on any component's upload event.
 8. wire-loading.js:117 `closestIsland(el)` per matching loading element per message without targets; wire-loading.js:225,242 `JSON.stringify`/btoa/`map` per element per message in `containsTargets`.
 9. shared.js:20-22 forced `getComputedStyle` at loading start AND end (wire-loading.js:13,34, restore at end) for plain wire:loading.
 10. supportJsModules.js:127-140 five-callback interceptor per message; supportQueryString.js:33 per-property global `commit` listener (push mode) running for every commit of every component; wire-dirty.js:8-14 `on('commit')` + `setTimeout` per commit per component whether or not dirty elements exist.
 11. supportIslands.js:13 `Array.from(el.attributes).find` per action; closestIsland per action (fragment.js, UNSURE cost).
 12. 7 `effect` hooks per response per component (all early-return unless an effect key exists).

C. Per tick / per event:
 13. wire-poll.js:100-102: `getDirectives(el).has('poll')` evaluated every tick for every poll element (parses all attrs with fresh RegExps); wire-poll.js:29 re-contextualizes expression (3 regex passes) every tick; getBoundingClientRect (l.113) when `.visible`.
 14. wire-dirty.js:48 `JSON.stringify(component.canonical)` + `JSON.stringify(component.reactive)` per dirty element per reactive change; the effect subscribes to whole state (no-target case); no cleanup (wire-dirty.js:16-42) so detached elements' effects and WeakBag callbacks accumulate and run on every commit (l.11).
 15. evaluator.js:83-119 `contextualizeExpression` (parent walk + 3 regex replace passes) uncached; for wire:text/show/bind it is inside the reactive getter => runs on every dependency change (wire-text.js:15, wire-show.js:15, wire-bind.js:15).
 16. supportQueryString.js:25-27: replace-mode `Alpine.effect` per tracked property rebuilds URL/query string (new URL, fromQueryString/toQueryString) on each reactive change; replaceState itself is batched (history/coordinator.js:8-17). popstate handler (l.60) iterates all `input` elements on the page.
 17. supportDisablingFormsDuringRequest.js:44-54 Alpine.walk of whole form with `formEl.contains` per node on each submit.
 18. supportStreaming.js:17-29 two querySelector scans of component subtree per streamed chunk; supportRefs.js:3 querySelector per ref access.

D. Per morph:
 19. wire-transition.js:118 two `querySelector('[wire\:transition]')` subtree scans (from and to) on every morph, before any early exit.
 20. supportIslands.js:81/91 duplicate `extractFragmentMetadataFromHtml` per island; `findFragment(component.el)` full marker scan per island/slot fragment (supportSlots.js:27).
 21. New elements added by morph re-run A.1-A.6 (all init-time costs) because Alpine's mutation observer calls interceptInit for them.

E. Navigation:
 22. wire-navigate.js:44-46 `inscribeSnapshotAndEffectsOnElement` for ALL components on each `alpine:navigating`; supportDataCurrent.js:20-42 + wire-current.js:9 (`new URL` per element) per navigation.

F. Leaks / missing cleanup:
 - wire-dirty.js: no cleanup for Alpine.effect and WeakBag entry (see 14).
 - wire-poll.js: no cleanup; relies on `isConnected` at next tick; attribute removal leaves a permanently paused poller (l.16,19).
 - supportDisablingFormsDuringRequest.js:18: submit listener never removed (el discarded with DOM, minor).
 - supportFileDownloads.js:20-28: hidden anchor appended to body, never removed.
 - supportFileUploads.js:89-107: 5 `$wire.$on` subscriptions never unsubscribed (UNSURE if tied to component lifetime).
 - supportListeners.js:38: el-level listener not removed (dies with element) while window listener is cleaned.
 - wire-current.js:49 / wire-loading.js:58 / wire-offline.js:17 rely on Alpine.onAttributeRemoved being called at element removal (UNSURE).
 - supportScriptsAndAssets.js:94-97 executedAssets marks before load (failed assets never retried).

G. Dead/odd code (cheap to note): wire-model.js:182-188 `isDirty` unused; supportFileUploads.js:300-308 MessageBag.call/has use undefined `this.listeners`; supportFileUploads.js:311-317 stubs; wire-transition.js:8-10 no-op directive still registers a global listener; wire-dirty.js, supportNavigate.js, wire-navigate.js use global `Alpine`/`Livewire` without import.
