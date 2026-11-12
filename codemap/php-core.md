# Notes: PHP core (non-Features) — read in full, 4.x @ e03c29c8

Scope read completely: src/Compiler/{Compiler,CacheManager,Parser/{Parser,SingleFileParser,MultiFileParser}}, Finder/Finder, Mechanisms/{FrontendAssets,PersistentMiddleware,ExtendBlade/*,CompileLivewireTags/*,RenderComponent,HandleRouting/LivewirePageController}, Component, ComponentHook, LivewireManager, LivewireServiceProvider, Concerns/InteractsWithProperties, Drawer/{BaseUtils,Utils,ImplicitRouteBinding,Regexes}, ImplicitlyBoundMethod, HandleComponents/{Synthesizers/*,ComponentContext,ViewContext,SecurityPolicy}, config/livewire.php, Attributes/* (20 files), Attribute.php.
Also read (outside scope, for context): ComponentHookRegistry.php (first 80 lines), Mechanisms/Mechanism.php, Features/SupportAutoInjectedAssets/SupportAutoInjectedAssets.php, helpers.php `invade`.
NOT read: nothing in scope skipped. (vendor/ is not installed, so Laravel internals — BladeCompiler::isExpired, CompilerEngine — are from memory => UNSURE where flagged.)

NOTE: the map's file list says `Drawer/Utils.php` has "HTML attribute and response helpers"; it also holds generateBladeView, applyMiddleware, shareWithViews. BaseUtils holds reflection helpers.

---------------------------------------------------------------------
## 1. Compiler (single/multi-file components)

### src/Compiler/Compiler.php (106 lines)
Role: orchestrates compile of an SFC/MFC path into cache files. Singleton `livewire.compiler` (LivewireServiceProvider:43), reached via Factory (resolve name -> path -> `compile($path)`).
- `compile($path)` L16-30: `file_exists($path)` ; `hasBeenCompiled()` (2 x file_exists); if compiled -> `isExpired()` (see CacheManager) ; then `getClassName()` which does `require $classPath` and returns `$instance::class`.
- `compilePath($path)` L32-86: picks `SingleFileParser` if `is_file($path)` else `MultiFileParser`; writes placeholder/script/style/globalstyle/view files then class file last (class file = "compiled" sentinel).
- `prepareViewForCompilation` L98: runs registered callbacks over view source (called by parsers; callbacks registered via `Livewire::prepareViewsForCompilationUsing`; the Islands feature registers one, per map).
Callers: Factory::resolveComponentNameAndClass (per map), ConvertCommand, ClearCachedFiles via clearCompiled.

### src/Compiler/CacheManager.php (248 lines)
Role: paths + mtime checks + atomic writes in `{config('view.compiled')}/livewire/{classes,views,scripts,styles,placeholders}`.
- `hasBeenCompiled` L120: `file_exists(class) && file_exists(view)`.
- `isExpired` L128: `getLatestFileModificationTimeOfSource` + `...OfCompiledFiles`.
  - Source mtime L137-144: file -> `filemtime`; dir (MFC) -> `glob($dir.'/*')` + `array_map('filemtime')` (glob + N stats).
  - Compiled mtime L146-162: computes 5 paths (each does md5 via getHash), `file_exists` x5 + `filemtime` per existing file; takes min.
- `getHash` L171: `substr(md5(Str::after($sourcePath, base_path())), 0, 8)`. Called once per getXPath call; `base_path()` call + md5 + Str::after each time, NOT memoised. In `compile()` flow getHash is invoked: hasBeenCompiled (2), isExpired (5), getClassName (1) = ~8 md5s per compile() call.
- `getClassName` L164: `require $this->getClassPath()` EVERY call (not require_once). The class file is `return new class(...) extends Component {...};` so each `require` of an anonymous-class file returns a new instance; PHP re-declares nothing (anonymous class declared once per file path via opcode cache; with opcache the class entry is reused; UNSURE on exact behaviour when required repeatedly - PHP allows because anon class name is based on file+offset). `$instance::class` is used just to get the name; an instance (constructor run) is built and discarded each resolve.
- writes use `File::replace` (atomic) + `ensureCacheDirectoryExists` (makeDirectory + file_exists(.gitignore)) on every write (compile-time only).
- `prepareGeneratedFileForCompilation` L321-338: `touch($path, mtime-1)` and deletes Blade compiled file via `app('blade.compiler')->getCompiledPath($path)`. Compile-time only. Trick: generated view is made 1s older than its Blade-compiled file so Blade's `isExpired` is false.
- `invalidateOpCache($sourcePath)` L298 invalidates opcache for **$sourcePath** (the source SFC), not the generated class path (L220-222 passes source path). UNSURE if bug; relevant for dev only.
Callers: Compiler only (+ClearCachedFiles via clearCompiledFiles).

PERF (Compiler/CacheManager)
- CacheManager.php:128-162 + Compiler.php:22-24: **every `compile()` call does stat syscalls even in production**: file_exists(path) + 2 file_exists + (filemtime source) + 5 file_exists + up to 5 filemtime on compiled files = ~13-14 stat calls, plus ~8 md5+base_path computations (L171). No "production: skip mtime" switch, no per-process memo. How often compile() is called per request depends on Factory (per map: Factory keeps `resolvedComponentCache` per process — whether it also caches the compiled class name must be checked in Factory.php (not in my scope); UNSURE whether compile() is hit per component mount/hydrate or once per process).
- CacheManager.php:140 for MFC: `glob()` + N `filemtime` on every expiry check.
- CacheManager.php:166 `require` (not require_once) of generated class file each `getClassName()`; instantiates the anonymous class (constructor) just to read ::class. Cost: opcache hit + object creation each time. UNSURE on cost.
- Parsers (Parser.php, SingleFileParser.php, MultiFileParser.php) are compile-time only (cold path): many preg_* with `/s` and lazy `.*?` over whole file contents (e.g. SingleFileParser:48,65-74,99,124,143; Parser.php:9,23,(skip-comments PCRE (*SKIP)(*FAIL)); `getViewPortion` strips PHP block 3 times (once per extractScript/Style/GlobalStyle). Not relevant to steady state; only relevant for cold start / `view:clear` / deploys / first request after deploy in prod. Note: no compile-time lock; concurrency handled by `File::replace` atomic writes.

### Parser/Parser.php (235 lines) — shared base
Static `extractPlaceholderPortion(&$contents)` (islands protected via replacement tokens, regex `@placeholder(.*?)@endplaceholder`), `extractUseStatements`, `stripTrailingPhpTag`, `ensureAnonymousClassHasReturn` (adds `return ` before first `new`), `ensureAnonymousClassHasTrailingSemicolon`, and inject{View,Placeholder,Script,Style,GlobalStyle}Method which find the last `};` via `preg_match_all('/}(\s*);/')` and splice generated method (`view()` returns `app('view')->file('<abs path>', $data)`; `placeholder()`, `scriptModuleSrc()`, `styleModuleSrc()`, `globalStyleModuleSrc()`).
=> Generated SFC class `view()` does `app('view')->file(path)` — this is what `Component::hasProvidedView()` (method_exists 'view') uses.

### Parser/SingleFileParser.php (419 lines)
`parse()` file_get_contents + prepareViewForCompilation + extract script/style/global-style (column-0 tags only) / class (`<?php ... ?>` first block) / placeholder. `generateClassContents`, `generateViewContents` (prepends `use` statements from class portion into view as a `<?php use ...; ?>` block), `generateScriptContents` (hoists `import` lines, wraps in `export function run($wire, $js){...}`), style generators, plus `*ForMultiFile` variants for the convert command, indentation helpers. Cold path only.

### Parser/MultiFileParser.php (238 lines)
`parse()` reads `name.php`, `name.blade.php`, optional `.js/.css/.global.css` (emoji stripped from folder name via preg_replace). `generateContentsForSingleFile` for convert command. Cold path only.

---------------------------------------------------------------------
## 2. Finder

### src/Finder/Finder.php (522 lines)
Role: name <-> class/view resolution. Singleton `livewire.finder` (LivewireServiceProvider:35; registers location `config('livewire.class_namespace')`). State: classLocations, viewLocations, classNamespaces, viewNamespaces, classComponents (explicit name->class), viewComponents (name->path). All plain arrays, **no result caching anywhere in Finder** (Factory has the resolved cache).
Key methods:
- `addComponent/addLocation/addNamespace` L25-69 (boot-time; bootConfig adds `component_locations` + `component_namespaces`).
- `normalizeName($nameOrClass)` L76-115: if class: `is_subclass_of($class, Component::class)` (autoload + class hierarchy check), `array_search($class, $this->classComponents)` (linear), `generateHashName` (crc32), else `generateNameFromClass` (kebab/collect/str chains, loops over all namespaces+locations); if string: `preg_replace` of ⚡ with /u flag + str_replace.
- `resolveClassComponentClassName($name)` L127-157: namespaced -> `generateClassFromName` + class_exists; explicit map hit; else `generateClassFromName($name, classLocations)` (collect/str/studly per call, up to 3 `class_exists` per location -> autoloader hits for non-existent classes) then `class_exists`.
- `resolveSingleFileComponentPath($name)` L159-222: for each view location builds **12 candidate paths** (3 emoji variants x 3 layouts + 3 plain layouts) and for each does `is_dir` + `file_exists` + (if exists) `hasValidSingleFileComponentSource` = `file_get_contents` + a recursive PCRE on the whole file (L393-410).
- `resolveMultiFileComponentPath($name)` L224-297: 12 candidate dirs per location; each does `basename`, `str_contains`, `preg_replace` (/u) and `is_dir`, then 2 `file_exists`.
- `generateClassFromName` L299-317, `generateNameFromClass` L319-371 (heavy collect/str/kebab per call), creation helpers L418-521 (make command; read config `make_command.emoji`, `class_path`, `view_path`).
Callers: Factory::resolveComponentNameAndClass / exists (per map), LivewireManager add*, SupportSingleAndMultiFileComponents, commands, `normalizeName` called on mount/snapshot paths (name normalisation) — verify callers in Factory/HandleComponents (outside this scope).

PERF (Finder)
- Finder.php:152 / 311-313: unresolved name => up to 3 `class_exists` autoload attempts per class location per lookup. Classname-miss lookups for SFC components (common v4 case: `pages::foo`, `foo`) pay this on every uncached resolve.
- Finder.php:197-217: SFC lookup does up to 12 x locations (2 default + namespace) `is_dir`/`file_exists` syscalls; on a hit, `file_get_contents` + backtracking PCRE (Finder.php:409) on the whole file **every resolve** (used only to tell SFC from a plain Blade view). Worst-case for a not-found name: 12 x 2 locations x ~2 stats = ~48 stat calls.
- Finder.php:279-293 MFC lookup: similar 12-dir probing + regex per candidate.
- No in-process memo inside Finder. Production caching depends entirely on Factory's `resolvedComponentCache` (per map, per-process) — I did NOT read Factory.php; confirm key is the raw name and that `exists()` / `normalizeName` paths also hit it.
- Finder.php:84 `is_subclass_of(...)` + L89 `array_search` + L103 `generateNameFromClass` (collect->map->implode kebab per path segment, per namespace, uncached) is invoked whenever a name is derived from a class (e.g. HandleComponents when snapshotting a class component -> `name`)? UNSURE who calls per request; check Factory/HandleComponents callers of `normalizeName`.

---------------------------------------------------------------------
## 3. Mechanisms

### src/Mechanisms/Mechanism.php (base)
`register()` binds instance into container (`app()->instance(static::class,$this)`), `boot()` no-op. Provider calls `app($mechanism)->register()` then `->boot()` for 11 mechanisms (LivewireServiceProvider:73-95, 148-159).

### FrontendAssets/FrontendAssets.php (316 lines)
Role: script/style endpoints, `@livewireScripts/@livewireScriptConfig/@livewireStyles` Blade directives (compile to `{!! FrontendAssets::scripts(...) !!}` etc.), manifest-based cache busting, published-asset detection.
Boot: registers route for JS (`EndpointResolver::scriptPath(minified: !debug)`), two sourcemap routes, three Blade directives, `publishes` dist -> public/vendor/livewire, `flush-state` listener resetting `hasRenderedScripts/Styles`.
Key methods:
- `styles($options)` L108: once-per-request guard (`hasRenderedStyles`), builds CSS heredoc, `config('livewire.navigate.progress_bar_color')`, runs `minify()` = `preg_replace('~(\v|\t|\s{2,})~m','',$html)` on a constant ~2KB string each call (L305-308). No caching of the minified string across requests (static var could hold it; only differs by nonce/colour).
- `scripts($options)` L163 -> `js()` L181: builds URL: `url(route->uri)`, config asset_url, `rtrim`, `str()->when()->start()`, **reads and json_decodes `dist/manifest.json` on every call** (L204-205: `file_exists` + `file_get_contents` + `json_decode`), `csrf_token()` if session store bound, `usePublishedAssetsIfAvailable` (L259) does `file_exists(public_path('vendor/livewire/manifest.json'))` and, if present, **a second file_get_contents + json_decode** each call, `url()` x2-3, `Utils::stringifyHtmlAttributes` (collect pipeline) of script tag attributes.
- `scriptConfig()` L238: json_encode of a small array.
- `returnJavaScriptAsFile` L81 / `maps` / `cspMaps`: `Utils::pretendResponseIsFile` -> `filemtime` + If-Modified-Since check -> 304 or `response()->file` with 1-year cache headers. These are static-asset endpoints, only hit when the browser lacks the asset cached; `Cache-Control: public, max-age=31536000` + `Expires`.
- `isCspSafe()` -> `config('livewire.csp_safe')` read per call.
Callers: Blade directives; SupportAutoInjectedAssets (RequestHandled listener).

Injection mechanics (SupportAutoInjectedAssets.php, outside scope but answers the question): listener on `RequestHandled` for **every** HTTP response in the app (L24). Early exits: content-type not text/html (L26 — so JSON `/livewire/update` responses exit at once), status != 200, then `SupportScriptsAndAssets::processNonLivewireAssets()` and `getAssets()` run BEFORE the `shouldInjectLivewireAssets` check; returns when both head/body strings empty (L51). Only when a component rendered on the page (or forced): `getContent()`, `str($html)->contains('</html>')`, then `injectAssets` (L82-97): `$html->test('/<\s*\/\s*head\s*>/i')` and `test('/<\s*\/\s*body\s*>/i')` and then **two `replaceMatches` regexes over the whole HTML** (scan full document for `</head>` and `</body>`; unanchored; case-insensitive, `\s*`). So 4 regex scans of the full response (2 tests + 2 replaces) plus `contains` plus extra copy via Stringable. `static::getLivewireAssets` (L71) is dead code (computes then discards).
Also `FrontendAssets::styles()/scripts()` are executed to build strings: scripts() hits manifest json read (above).

PERF (FrontendAssets)
- FrontendAssets.php:204-205 and :268: manifest.json file read + json_decode **per page render** (x2 if assets are published). Per full-page request only (not per update request), but trivially memoisable.
- FrontendAssets.php:307 minify regex on a constant string each page.
- SupportAutoInjectedAssets.php:86-95: up to 4 full-document regex passes per HTML response that contains a Livewire component (not on update requests).
- SupportAutoInjectedAssets.php:24-60: listener adds a `RequestHandled` hook for all responses; cheap early exit for non-HTML.
- None of this runs on `/livewire/update` (JSON) except `flush-state` reset closure.

### PersistentMiddleware/PersistentMiddleware.php (216 lines)
Role: re-run the *original page's* persistent middleware (auth, authorize, SubstituteBindings, etc. - static list L12-21) on update requests.
Hooks (boot L34-57):
- `on('dehydrate')` (every component, every response incl. initial render): `extractPathAndMethodFromRequest()` -> `app(HandleRequests::class)->isLivewireRoute()` and either stored path/method or `request()->path()`/`request()->method()`; then `addMemo('path')`, `addMemo('method')` into snapshot memo (so each snapshot carries path+method strings).
- `on('snapshot-verified')` (fired per component snapshot in update, after checksum): returns early if not the livewire route; `extractPathAndMethodFromSnapshot`; `applyPersistentMiddleware()`.
- `on('flush-state')` clears path/method/lastMiddlewareRoute/resolvedRouteModels.
`applyPersistentMiddleware` L416-452: per snapshot: builds `routeKey`; if equal to `lastMiddlewareRoute` return early. Otherwise `makeFakeRequest()` (`clone request()->server`, `str_replace` on REQUEST_URI, `request()->duplicate(server: ...)` = full Symfony Request duplication), `getApplicablePersistentMiddleware`: `app('router')->getRoutes()->match($request)` (**route matching against the whole route collection**, throws/catches NotFoundHttpException), `setContainer`, `gatherRouteMiddleware($route)` (resolves middleware aliases/groups), `filterMiddlewareByPersistentMiddleware` (collect; for each route middleware, `contains` loop with `Str::before` — O(m x p)). If any matches: `Utils::applyMiddleware` -> new `Pipeline` through them with a dummy response. Then iterates route params collecting resolved Models into `resolvedRouteModels` (used by ModelSynth to avoid re-query; `getResolvedRouteModel`).
Callers: events; `LivewireManager::add/set/getPersistentMiddleware`; ModelSynth (Features).

PERF (PersistentMiddleware)
- PersistentMiddleware.php:416-452 per component in a batch: `lastMiddlewareRoute` is only set AFTER the middleware ran (L439), and the empty-middleware early return at L435 happens BEFORE setting it => when the route has no persistent middleware (or no route match), **every component in the batch repeats makeFakeRequest + route match + gatherRouteMiddleware** (full-route-table match per component). Only when middleware exists is it deduped by consecutive route key. (Fact from code; effect size UNSURE.)
- `request()->duplicate()` + `Router::getRoutes()->match()` cost scales with route count (Laravel route cache makes match use compiled route regexes; UNSURE).
- Middleware actually executed (Authenticate, Authorize, SubstituteBindings, etc.) may run DB queries (auth user lookup, model binding) — SubstituteBindings result models are then cached for ModelSynth.
- dehydrate hook adds two memo strings to every snapshot (bytes in payload: path + method repeated per component).

### ExtendBlade/ExtendBlade.php (147 lines)
Role: wires Livewire into Blade compile/render.
`boot()` L38-87:
- `Blade::directive('this', ...)`.
- `on('render', ...)` L42-60: per component render: pushes the component on a static stack, calls `livewireifyBladeCompiler()`, `renderCounter++`; returns finisher that pops stack, `renderCounter--`, undoes at 0.
- `app()->make('view.engine.resolver')->register('blade', fn => new ExtendedCompilerEngine(app('blade.compiler')))` — replaces the **global** blade engine (all views, not only Livewire).
- `app()->singleton(DeterministicBladeKeys::class)` and re-bound on `flush-state` (new instance => counters reset).
- `$compiler->precompiler(fn($value) => app(DeterministicBladeKeys)->hookIntoCompile($compiler,$value))` — global Blade precompiler (runs on every Blade *compilation*, not render). `hookIntoCompile` -> `crc32($compiler->getPath() ?? $viewContent)`.
`livewireifyBladeCompiler()` L99-146: only when `renderCounter === 0` (outermost component render): `getCustomDirectives()`, `invade(app('blade.compiler'))` x many (each `invade()` = `new anonymous class + new ReflectionClass`, helpers.php:19-29) to read `precompilers`, loops registered livewire-only directives (set into `customDirectives` via reflection) and precompilers (`array_search` of closures in precompiler list, `array_unshift`), building `$removals` closures; undone at end of outermost render by popping removals (each does more `invade`). So **per top-level component render**: several ReflectionClass creations + array copying of Blade compiler's directive/precompiler arrays, twice (apply + undo). The registered "livewire-only" directives/precompilers are those added by `Livewire::directive/precompiler` (e.g. morph-marker precompilers per map: SupportMorphAwareBladeCompilation, SupportIslands, SupportCompiledWireKeys...). They only affect *compile*, but are installed/removed on every render even when view is cached.
Callers: `on('render')` from HandleComponents render pipeline; LivewireManager::directive/precompiler.

### ExtendBlade/ExtendedCompilerEngine.php (76 lines)
`get($path,$data)` L8-17: if not rendering a Livewire component -> parent. Else `trigger('view:compile', $component, $path)` then parent::get (Laravel CompilerEngine::get checks `isExpired` -> compile if needed -> `evaluatePath`).
`evaluatePath` L19-44: for Livewire renders: `ob_start`, `Closure::bind(fn => extract($__data); include $__path, $component, $component)()` (view runs with `$this` = component), ob_get_clean + ltrim. try/catch -> `handleViewException` which bypasses wrapping for AuthorizationException/NotFound/HttpException/`BypassViewHandler` users (`class_uses_recursive` + `array_flip`, only on exceptions).
PERF notes:
- ExtendedCompilerEngine.php:14: `trigger('view:compile', ...)` fires on EVERY view `get()` during a Livewire render (the component view and every nested @include/component view). grep shows **no listener registered for 'view:compile' in non-test src** (only this trigger), so it's an EventBus trigger with empty listener arrays per view (EventBus re-merges listener arrays per trigger per map). UNSURE if tests/third-party use it.
- Stat calls per render: `parent::get()` -> Laravel `CompilerEngine::get` -> `$this->compiler->isExpired($path)` => `filemtime` of source and `file_exists`/`filemtime` of compiled file (BladeCompiler::isExpired). Laravel versions >=10.x keep a per-engine-instance `compiledOrNotExpired` array so the check happens once per path per process/engine instance (UNSURE - vendor not installed, verify in vendor/laravel/framework `CompilerEngine`). In Octane/queue workers that array persists; in PHP-FPM it's per request so each distinct view = 1 stat set per request.
- `Closure::bind` + `extract` per view evaluation (inherent).
- Blade engine replacement applies to all views app-wide, but its Livewire branch is a single static `empty()` check otherwise.

### ExtendBlade/DeterministicBladeKeys.php (48 lines)
Role: key generator `lw-{crc32(viewPath)}-{n}` used by compiled `@livewire` output (RenderComponent) to give nested components stable keys. `hookIntoCompile` is invoked at **Blade compile time** as a precompiler (sets `currentPathHash`). `generate()` called at compile time from `RenderComponent::livewire()` to bake the key string into compiled PHP (so no runtime cost per render besides the string literal). Counter per path hash reset on flush-state. Singleton rebound on flush-state (`app()->singleton` re-registers).
PERF: negligible at render; compile only.
Edge: because the key is generated at compile time, if a view compiles once and cached, counters are only incremented during compile.

### CompileLivewireTags/CompileLivewireTags.php (13 lines)
`boot()` registers `new LivewireTagPrecompiler` as a **global** Blade precompiler (not livewire-only).

### CompileLivewireTags/LivewireTagPrecompiler.php (199 lines)
Extends Laravel `ComponentTagCompiler`. `__invoke($value)` runs 5 passes on the whole Blade source: slot opening, slot closing, opening tags, closing tags, self-closing tags — each `preg_replace_callback` using the big `/x` regexes in Drawer/Regexes.php (with recursive subpatterns `(?-1)`, nested alternations). Emits `@livewire(...)` directives (self-closing) or ob_start/ob_get_clean capture code for tags with slots.
PERF: compile-time only (cold). But it runs over **every Blade file's source** compiled in the app (not only Livewire views) because it's a global precompiler; each of the 5 passes scans the whole template (the regexes start with a literal `<` so the PCRE start-optimisation helps; `\s*livewire[-\:]` follows). No quick `str_contains($value,'<livewire')` pre-check exists (UNSURE whether a pre-check would be a net win; start-char optimisation probably already makes it cheap). Only paid on view compile (cache miss), not per render.
Edge: `compileOpeningTags` and `compileSelfClosingTags` duplicate the attribute-handling logic.

### RenderComponent.php (73 lines)
Registers `@livewire` Blade directive (`Blade::directive('livewire', ...)`). `livewire($expression)` is **compile-time**: two `preg_replace_callback` to extract `key(...)` and `$__slots ?? []` out of the directive expression; generates the PHP block that at render: defines closure `$__split` (to split name/params), calls `app('livewire')->mount($__name, $__params, $__key, $__componentSlots)`, echoes the HTML, unsets vars; `$__key ??= SupportCompiledWireKeys::generateKey('<deterministic key>', $__key)`.
Per-render cost per nested component occurrence: closure alloc, `app('livewire')` container lookup, `mount()`. Nothing else.

### HandleRouting/LivewirePageController.php (17 lines)
`__invoke`: `request()->route()->action['livewire_component']`; `app('livewire')->new($component)` (Factory create) unless already an object; `app()->call([$instance,'__invoke'])` (container method-injection reflection; the `__invoke` is provided by SupportPageComponents' `Component` trait HandlesPageComponents, per map). Full-page route entry point only.

---------------------------------------------------------------------
## 4. Core classes

### Component.php (169 lines)
Base class. Traits (16): Macroable (aliased __call), AuthorizesRequests, InteractsWithProperties, HandlesEvents, HandlesIslands, HandlesRedirects, HandlesTransitions, HandlesStreaming, HandlesAttributes, HandlesValidation, HandlesFormObjects, HandlesJsEvaluation, HandlesReleaseTokens, HandlesPageComponents, HandlesDisablingBackButtonCache, HandlesSlots, HandlesHtmlAttributeForwarding, HandlesRenderless. (= 18 `use` lines incl. Macroable and AuthorizesRequests; HandlesValidation etc. contribute many methods.)
Per-instance state declared: `protected $__id`, `protected $__name` here; traits may declare more properties (not read: Handles* traits live in Features; check their property declarations for per-instance cost — they're protected so excluded from public property reflection, but they ARE instantiated for every component).
Methods: id/setId/getId/setName/getName, `forceRender/skipMount/skipHydrate` (store($this)->set), `hasProvidedView()` = `method_exists($this,'view')`, `getProvidedView()`, magic `__isset/__get/__unset/__call`.
- `__get` L114-131 (only triggered for **undefined/inaccessible** property reads, e.g. `$this->computedProp`, and in Blade `$computed`): creates closure, `trigger('__get', ...)`, calls `$finish($value)`; throws PropertyNotFoundException (+ `$this->getName()`) if nothing handled. Every `#[Computed]` read from a view/method that is not yet cached goes through `trigger()` -> EventBus (listeners per feature: computed, validation errors, etc.). `__isset` calls `__get` (and exception-as-control-flow on miss) - `isset($this->x)` on non-defined property = full trigger + exception construct (L101-112).
- `__call` L138-161: closure + `trigger('__call', ...)` for every call to an undefined method (e.g. macros, `$this->getFoo()` helper-forwarding, legacy computed) before macro check.
PERF:
- Component.php:114-131, 138-161: magic access path allocates closure + EventBus trigger each time; computed/`$errors`-like reads hit it each time (cache is inside BaseComputed per map; trigger cost remains).
- Component.php:101-112 `__isset` -> `__get` -> exception on miss. Blade `isset($var)`/`@isset` over component properties compile to `isset($this->...)`? UNSURE.
- Component.php:91-93 `method_exists($this,'view')` each call (cheap).
- Trait count (18) only adds to class method table; per-instance cost is object creation of the property defaults from all traits (check Handles* for declared properties).

### ComponentHook.php (132 lines)
Base for feature hooks. `setComponent`; dispatch shims:
- `callBoot/callMount/callHydrate/callDehydrate/callDestroy/callException(...$params)`: `if (method_exists($this,'x')) $this->x(...$params)` — one `method_exists` + variadic spread per hook per event.
- `callUpdate/callCall/callRender/callRenderIsland/callRenderPlaceholder`: **always allocate `$callbacks=[]` and return a new closure** `function(...$params) use ($callbacks){ foreach...is_callable...}` even when the hook defines no such method (closure alloc + `method_exists`), and the closure is later invoked with the finish params, iterating an empty array. ComponentHookRegistry::proxyCallToHooks collects these closures per hook per event (per map).
- Helper accessors: `getProperties()` -> `$this->component->all()` -> `Utils::getPublicPropertiesDefinedOnSubclass` (reflection read each call); `getProperty($name)` = `data_get(getProperties(), name)` — **each call re-extracts ALL public properties via reflection**. `storeSet/Push/Get/Find/Has` -> `store($this->component)` (DataStore lookup keyed by component, per map).
PERF:
- ComponentHook.php:183-265: with 47 hooks, `update/call/render/renderIsland/render.placeholder` events allocate 47 closures + 47 empty arrays per event even if only a handful hooks implement the method (the registry may filter before — verify ComponentHookRegistry::proxyCallToHooks, which I only partially read). Mount/hydrate: `new $hook` per hook per component (registry) + 2 `method_exists` (`callBoot`,`callMount`/`callHydrate`) + `skip()` check.
- ComponentHook.php:272-275 `getProperty` calls full `all()` reflection each time — used by hooks such as SupportQueryString/Validation? (check Features for `$this->getProperty(` callers).

### ComponentHookRegistry.php (outside scope; first 80 lines read)
Confirmed: `boot()` registers a separate `mount` and `hydrate` listener **per hook class** (47 + 47 closures on EventBus); each does `initializeHook` (new hook + setComponent + WeakMap store) then `callBoot` + `callMount`/`callHydrate`. Other events share a single listener each. `getHook($component,$hook)` is a linear `instanceof` scan over that component's hooks.

### LivewireManager.php (333 lines)
Facade-backing service `app('livewire')`. Mostly pass-through: component registration -> Finder; `new()` -> Factory; `mount/snapshot/fromSnapshot/update/updateProperty` -> `app(HandleComponents::class)->...` (container singleton resolve each call — cheap); `current()` -> last of HandleComponents::$componentStack; testing helpers (`test`, `visit`, `actingAs`, `withQueryParams`...), `isRunningServerless` (reads `$_ENV['SERVER_SOFTWARE']`), persistent middleware passthrough, `flushState()` -> `trigger('flush-state')`.
- `isCspSafe()` L329: `config('livewire.csp_safe')` read per call.
- `originalPath()/originalMethod()` L307-327: on Livewire requests `json_decode(request('components.0.snapshot'), true)` — **decodes the whole first snapshot every call, uncached**; `originalUrl()` calls `originalPath()`. Who calls them per request (e.g. SupportQueryString, redirects, pagination, navigate) — not verified, UNSURE; each call = json_decode of a potentially large snapshot string + `data_get`.
- `provide($callback)` L28: binds closure to provider (used for publishes).

### LivewireServiceProvider.php (219 lines)
`register()`: LivewireManager singleton + alias 'livewire'; singletons `livewire.finder` (adds config class_namespace location), `livewire.compiler` (CacheManager at `config('view.compiled')/livewire`), `livewire.factory`; `mergeConfigFrom(config/livewire.php)`; `app(EventBus::class)->boot()`; `registerMechanisms` (calls `->register()` on 11).
`boot()`: `bootConfig()` (back-compat shims: sets `livewire.component_locations`, `component_layout` (<- `layout`), `component_placeholder` (<- `lazy_placeholder`), `make_command`; registers each location with Finder, and **`blade.compiler->anonymousComponentPath($location)` + `view->addLocation($location)` if dir exists** — so `resources/views/components` and `resources/views/livewire` are added to the global view finder paths, and `pages`/`layouts` namespaces to view namespaces — meaning plain `view('foo')` lookups also probe these dirs); `bootMechanisms` (AboutCommand + `->boot()` 11 mechanisms); `bootFeatures()` array of **47** feature classes -> `app('livewire')->componentHook($class)` -> `ComponentHookRegistry::register` (calls `provide()` if exists, `in_array` dedupe) -> then `ComponentHookRegistry::boot()`.
Mechanism order (L73-88): PersistentMiddleware, HandleSynths, HandleComponents, HandleRequests, HandleRouting, FrontendAssets, ExtendBlade, CompileLivewireTags, ClearCachedFiles, RenderComponent, DataStore.
Feature order (L163-215): order determines hook execution order (last three "priority" items LifecycleHooks, LegacyModels, Wireables; per comment).
PERF (boot, paid every PHP-FPM request unless Octane): boot instantiates 11 mechanisms + registers 47 features + ~94 EventBus listeners for mount/hydrate + ~10 more; `registerConfig`/`mergeConfigFrom` requires config file each boot (config cache normally makes this cheap; `mergeConfigFrom` skips if config cached); `bootConfig` runs `config()->set` x4, `is_dir()` per location/namespace (4 stats), and `app('view')->addLocation`. `config/livewire.php` calls `resource_path()`/`app_path()` at include time. No lazy boot of rarely-used features (console commands/testing feature booted always via SupportTesting/SupportConsoleCommands `provide()` — verify).
- `ExtendBlade::boot` calls `app('blade.compiler')` at provider boot => forces BladeCompiler + view engine resolver instantiation every request (even for non-Livewire routes) and registers global precompilers (LivewireTagPrecompiler + DeterministicBladeKeys hook).

### Concerns/InteractsWithProperties.php (160 lines)
Trait on Component. `hasProperty` (property_exists), `getPropertyValue` (`$this->{name}` then `data_get`), `fill`, `reset`, `resetExcept`, `pull`, `only`, `except`, `all()` = `Utils::getPublicPropertiesDefinedOnSubclass($this)`.
PERF: `all()` L375-378 is the central public-property reader: per call `new ReflectionObject` + `getProperty()` + `isInitialized` + `getValue` for each public property (metadata cached per class in BaseUtils; reflection objects are not). `fill()` L249 calls `all()` once (fine). `reset()` L278: `new static` fresh instance (runs constructor; may be heavy) once per reset call + `new ReflectionProperty` per property (L297, L312). `resetExcept/pull/only` call `all()`/`hasProperty` per property.

---------------------------------------------------------------------
## 5. Drawer

### Drawer/BaseUtils.php (206 lines)
- `$reflectionCache` static, per class: public non-static default props (excluding those declared on Component/Volt Component) -> [name,type]. `flushReflectionCache` (tests).
- `getPublicPropertiesDefinedOnSubclass($target)` L28-39: cached metadata + `extractPropertyValuesFromInstance`: `new ReflectionObject($target)` **per call**, then `getProperty($name)` per property (creates a ReflectionProperty each; PHP internally does a lookup in the properties table), `isInitialized`, `getValue`. So metadata cached, but object/ReflectionProperty creation is per call per property.
- `reflectAndCachePropertyMetadata` L41-62: builds metadata with collect pipeline (once per class); note `getType()->getName()` only for named types (union types => null type).
- `getPublicProperties($target, $filter)` L84-106: **uncached** full reflection + collect pipeline (used by legacy callers, maybe Form objects — verify).
- `getPublicMethodsDefinedBySubClass` L108-121: **uncached** `new ReflectionObject->getMethods()` (every method incl. inherited from Component + all traits), `array_filter` with `getDeclaringClass()->getName()` x2 per method, then `array_map getName`. Used per method call by the call validation path (map says per method call).
- `hasAttribute($target,$property,$attrClass)` L123-133: `getProperty` (new ReflectionObject) + `getAttributes()` + **`newInstance()` for every attribute** each call — instantiates attribute objects at runtime, no cache. Who calls: check SupportAttributes/Locked etc. UNSURE.
- `getProperty`, `propertyIsTyped`, `propertyIsTypedAndUninitialized`, `propertyTypeMatchesValue` + `typeMatchesValue` (match on type name): each starts with `(new ReflectionObject($target))->getProperty(...)` — **new ReflectionObject per call, per property** (called e.g. from updateProperty paths).
- `isSyntheticTuple`, `isAPrimitive`.
Callers: HandleComponents (dehydrate/hydrate/update), HandleSynths?, Features (Locked, Validation...), ImplicitRouteBinding, ComponentHook, Component trait.

### Drawer/Utils.php (215 lines, extends BaseUtils)
- `insertAttributesIntoHtmlRoot($html,$attrs)` L19-39: `stringifyHtmlAttributes` + `preg_match('/(?:\n\s*|^\s*)<([a-zA-Z0-9\-]+)/', $html, ..., PREG_OFFSET_CAPTURE)` over the rendered HTML (finds first tag; the leading `\n\s*|^\s*` alternation is tried at every position in the string until the first match, usually found at start) + `substr_replace` (copies the entire HTML string). Called per component render (injects wire:id/snapshot/effects into root) — per map.
- `stringifyHtmlAttributes` L41-49: `collect->mapWithKeys->map->implode` with `htmlspecialchars` and `json_encode` for non-scalars (`escapeStringForHtml` L251-258) — **the snapshot is JSON-encoded then HTML-escaped via htmlspecialchars**.
- `pretendResponseIsFile*`/`cachedFileResponse`/`matchesCache`/`httpDate`: 304/immutable cache helpers (filemtime per hit).
- `hasUnambiguousParameterKeys`, `containsDots`, `dotSegments`, `beforeFirstDot` (explode + head), `afterFirstDot` (str()->after), `hasProperty` (property_exists on first segment).
- `shareWithViews` L355-368: shares var with view factory, returns revert closure (uses `invade(app('view'))->shared` on revert).
- `generateBladeView` L370-394: anonymous `Illuminate\View\Component` subclass created and `->resolveView()` then `app('view')->make(...)` — for string templates (render() returning a string); `resolveView` for a string compiles/caches the inline template in a "view.compiled/..." file keyed by sha1 (Illuminate Component::createBladeViewFromString writes file if not exists: `file_exists` + possible write; UNSURE exact — Laravel behaviour from memory).
- `applyMiddleware` L396-410: new Pipeline.
- `extractAttributeDataFromHtml`: str betweenFirst + `json_decode` (tests/render helpers).
PERF:
- Utils.php:222-239: per render, one regexp + full-string copy of the HTML.
- Utils.php:376: a new anonymous class declaration per call when `$subject` is a string (anonymous class = one class entry per call site, cheap re-instantiation but still `new` + Component base ctor) — only for string templates.
- Utils.php:391 `app('view')->make` per render.

### Drawer/Regexes.php (197 lines)
Static strings only: `livewireOpeningTag`, `livewireOpeningTagOrSelfClosingTag`, `livewireSelfClosingTag`, `livewireClosingTag`, `slotOpeningTag`, `slotClosingTag`, `bladeDirective`, `specificBladeDirective()`. Used by LivewireTagPrecompiler (+ morph-aware compilation / islands precompilers per map). Compile-time only; heavy PCRE with recursive groups `(?-1)`, `(?3)` — subject to backtracking on pathological inputs (not a per-request cost).

### Drawer/ImplicitRouteBinding.php (192 lines)
Role: route-model binding for full-page components. `resolveAllParameters($route,$component)` L28: `resolveRouteParametersInOrder` + `resolveMountParameters` + `resolveComponentProps`. Uses `ReflectionMethod($component,'mount')` up to 3 times, `getPublicPropertyTypes` (calls `Utils::getPublicPropertiesDefinedOnSubclass` and `new \ReflectionProperty` per property, twice: L44 and L111), `Reflector::getParameterClassName`, `$route->uses(...)`, `router->substituteImplicitBindings`, `resolveMethodDependencies`.
Callers: SupportPageComponents (full-page mount path), per map. NOT on the update path, only initial full-page GET of a routed component.
PERF: reflection duplicated (getPublicPropertyTypes x2, ReflectionMethod x3, `new ReflectionClass` per enum check L180). Only per page-route request.

### ImplicitlyBoundMethod.php (175 lines)
Extends Laravel `BoundMethod`. Used via `Wrapped` for every action call and `render()` (per map). Reimplements `getMethodDependencies` so that route-model-style implicit binding (UrlRoutable/Enum params) works for action parameters: for each parameter: `isResolvedByAttribute` (`$parameter->getAttributes(ContextualAttribute::class, IS_INSTANCEOF)` each call), name binding substitution, implicit binding substitution, `addDependencyForCallParameter` (container make for class-typed params).
PERF:
- ImplicitlyBoundMethod.php:218 `static::getCallReflector($callback)->getParameters()` — new ReflectionMethod + parameter reflection objects per call, uncached (Laravel's BoundMethod does the same).
- Per parameter: `getAttributes()` (L249), and for class-typed params `new ReflectionClass(...)` twice (`implementsInterface` L360, `isEnum` L365) — called from `getClassForDependencyInjection` AND `getClassForImplicitBinding` => up to 4 ReflectionClass constructions per typed param per call.
- `render()` goes through Wrapped->ImplicitlyBoundMethod, so each render pays `ReflectionMethod(render)->getParameters()` (usually zero params => cheap).

---------------------------------------------------------------------
## 6. HandleComponents helpers & Synthesizers

### Synthesizers/Synth.php (65 lines) base
`__construct(ComponentContext $context, $path)`; `getKey()` static (property_exists check each call; `static::$key`); abstract static `match($target)`; `matchByType($type)` default false; `get(&$target,$key)`; `__call` throws for unimplemented hydrate/dehydrate/set/unset/call etc. Instances are created per use by HandleSynths (map: instantiated per property per call for findByKey).
### ArraySynth ($key 'arr')
`match`: is_array. `dehydrate($target,$dehydrateChild)`: foreach recurse via callback; returns `[$target, []]`. `hydrate`: guard non-array; foreach recurse. `set/unset`. Cost O(n) per array element with a closure call per element; each child goes through HandleSynths->dehydrate (type test, synth match loop). Every array property is traversed element by element on every response (dehydrate) and request (hydrate); primitives return as-is quickly (per map).
### ArrayShapedSynth (interface marker) — "hydrate requires array wire value".
### CarbonSynth ('cbn')
`match` loops over 5 classes with `instanceof`; `dehydrate`: `format(ATOM)` + `array_search(get_class($target), static::$types)` (type key; linear over 5); `hydrate`: `new static::$types[$type]($value)` — **parses a date string every hydrate** (Carbon parse is relatively expensive vs scalars). `hydrateFromType` for wire:model updates. `matchByType` `is_subclass_of`.
### CollectionSynth ('clctn', extends ArraySynth)
`dehydrate`: `$target->all()` copy, recurse; meta `class`. `hydrate`: `is_a($meta['class'], Collection::class, true)` (autoload-aware check each hydrate), recurse, `new $class($value)`. `get(&$target,$key)` L213-228: builds a reader closure + `Closure::bind` per access to get a reference into `items`.
### EnumSynth ('enm')
`match`: `is_object && is_subclass_of($target,'BackedEnum')` (string class name lookups); dehydrate `->value` + `get_class`; hydrate: `is_a($class, BackedEnum::class, true)` + `$class::from`. `matchByType` + `hydrateFromType`.
### FloatSynth ('float') / IntSynth ('int')
Never match by value (`match` false); only `matchByType('float'|'int')` + `hydrateFromType` to turn '' into null / numeric strings into typed values on wire:model updates. No dehydrate/hydrate (Synth::__call throws if called by key).
### StdClassSynth ('std')
dehydrate: `(array)` cast, recurse via iteration (note iterates `$target` while writing `$data`), hydrate builds new stdClass with recursion.
### StringableSynth ('str')
dehydrate `__toString`; hydrate `str($value)` helper.
PERF (synths):
- Tiny classes; cost is dominated by HandleSynths dispatch (linear `match()` scan over ~8+ synths per non-primitive value — Carbon's `match` does up to 5 instanceofs; Enum `is_subclass_of`).
- CarbonSynth.php:172 construct per hydrate; CollectionSynth.php:202 `is_a(..., true)` per hydrate; ArraySynth/CollectionSynth recurse via closure for every element (each element -> HandleSynths->dehydrate/hydrate incl. `isAPrimitive` checks, possible `Utils::isSyntheticTuple`).
- `Synth::getKey()` uses `property_exists(static::class,'key')` per call (L14) — called by HandleSynths when building `meta['s']` (per map).
- No `ModelSynth`/`FormObjectSynth` here (live in Features).

### ComponentContext.php (60 lines)
`#[AllowDynamicProperties]` bag: `$effects`, `$memo`, `$component`, `$mounting`; `addEffect/pushEffect/addMemo/pushMemo`. One instance per component per snapshot/dehydrate. Cheap. `AllowDynamicProperties` attribute -> dynamic props allowed (used by features to stash state, per map).
### ViewContext.php (35 lines)
Holds `slots,pushes,prepends,sections` copied out of/in to the Blade `Factory` via `invade($__env)` (new ReflectionClass anonymous wrapper per extract/merge). Used by HandleComponents around render (map: per render, copies Blade section state so child component renders don't leak). PERF: 2 `invade()` (ReflectionClass + anon class) per render for extract and 2 for merge; copies 4 arrays by value (COW).
### SecurityPolicy.php (69 lines)
Static denylist of 14 FQCNs (`$deniedClasses`) + `validateClass($class)`: `foreach ... is_a($class, $denied, true)` — **14 `is_a` calls with autoload=true per validated class, uncached** (no per-class memo). Called by HandleSynths::hydrate when `meta['class']` present (per map) -> every hydrated model/collection/enum/wireable/form object meta class per request pays 14 `is_a`. `denyClasses` adds at runtime; `getDeniedClasses` for tests.

---------------------------------------------------------------------
## 7. Attributes

### src/Attribute.php (10 lines)
`abstract class Livewire\Attribute extends Features\SupportAttributes\Attribute` (empty). Base so that attribute classes get `$this->component`, `getName()` etc. (implementation in Features/SupportAttributes).
### src/Attributes/*.php (20 files, 11-13 lines each)
Empty shells: `class X extends Features\SupportX\BaseX {}` with PHP `#[\Attribute(...)]` target flags. List with targets:
Async(default), Authorize(method, repeatable), Computed(default), Defer(default), Isolate(default), Js(default), Json(method), Layout(default), Lazy(default), Locked(default), Modelable(default), On(class|method, repeatable), Reactive(default), Renderless(default), Rule(all, repeatable), Session(property), Title(default), Transition(method), Url(default), Validate(all, repeatable).
Cost: none by themselves; runtime cost is reflection `getAttributes()` + `newInstance()` in Features/SupportAttributes (AttributeCollection) per component — NOT in my scope; verify whether that scan is cached per class. Autoloading: each attribute class is instantiated only if used by a component.
Note: `Attributes/Rule` and `Attributes/Validate` are `TARGET_ALL` so apply to class/method/property.

---------------------------------------------------------------------
## 8. config/livewire.php — every key, default, readers

| Key | Default | Read by (file:line) |
|---|---|---|
| component_locations | [resource_path('views/components'), resource_path('views/livewire')] | LivewireServiceProvider.php:103,129 (Finder::addLocation, Blade anonymousComponentPath, view addLocation) |
| component_namespaces | ['layouts'=>resource_path('views/layouts'), 'pages'=>resource_path('views/pages')] | LivewireServiceProvider.php:138 |
| component_layout | 'layouts::app' | LivewireServiceProvider.php:111 (sets, with fallback to legacy `layout`); Features/SupportPageComponents/PageComponentConfig.php:20; SupportConsoleCommands/Commands/LayoutCommand.php:20 |
| component_placeholder | null | LivewireServiceProvider.php:116 (fallback to legacy `lazy_placeholder`); Features/SupportLazyLoading/SupportLazyLoading.php:210 |
| make_command.type | 'sfc' | MakeCommand.php:64 (and bootConfig fallback L121 default 'class' only if key absent) |
| make_command.emoji | true | Finder.php:443,475; MakeCommand.php:302; ConvertCommand.php:256 |
| make_command.with.js/css/test | false each | MakeCommand.php:138,206,277,281,285 |
| class_namespace | 'App\\Livewire' | LivewireServiceProvider.php:38 (Finder location), MakeCommand.php:319, FileManipulationCommand.php:23 |
| class_path | app_path('Livewire') | Finder.php:507 |
| view_path | resource_path('views/livewire') | Finder.php:508; MakeCommand.php:320; HandleComponents.php:360 (per map: inline view path lookup, per render path?) |
| temporary_file_upload.disk | env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK') (null) | SupportFileUploads/FileUploadConfiguration.php:36,46,53 |
| temporary_file_upload.rules | null (=> ['required','file','max:12288']) | FileUploadConfiguration.php:114 |
| temporary_file_upload.directory | null (=> 'livewire-tmp') | FileUploadConfiguration.php:65 |
| temporary_file_upload.middleware | null (=> 'throttle:60,1') | FileUploadConfiguration.php:104 |
| temporary_file_upload.preview_mimes | png,gif,bmp,svg,wav,mp4,mov,avi,wmv,mp3,m4a,jpg,jpeg,mpga,webp,wma | SupportFileUploads/TemporaryUploadedFile.php:170 |
| temporary_file_upload.max_upload_time | 5 | FileUploadConfiguration.php:125 |
| temporary_file_upload.cleanup | true | FileUploadConfiguration.php:109 |
| render_on_redirect | false | SupportRedirects/HandlesRedirects.php:15 |
| legacy_model_binding | false | SupportLegacyModels/SupportLegacyModels.php:19 |
| inject_assets | true | SupportAutoInjectedAssets.php:65 |
| navigate.show_progress_bar | true | FrontendAssets.php:223,244 |
| navigate.progress_bar_color | '#2299dd' | FrontendAssets.php:117 |
| inject_morph_markers | true | SupportMorphAwareBladeCompilation.php:19,23 |
| smart_wire_keys | true | SupportMorphAwareBladeCompilation.php:20,24; SupportCompiledWireKeys.php:30 |
| pagination_theme | 'tailwind' | SupportPagination.php:149,158 |
| release_token | 'a' | SupportReleaseTokens/ReleaseToken.php:30 (`app('config')->get`) |
| csp_safe | false | LivewireManager.php:331 (`isCspSafe()`, used by FrontendAssets L83,96,209,271 and CSP feature) |
| payload.max_size | 1024*1024 | HandleRequests/HandleRequests.php:164 |
| payload.max_nesting_depth | 10 | HandleComponents.php:444 |
| payload.max_calls | 50 | HandleComponents.php:542 |
| payload.max_components | 200 | HandleRequests.php:191 |

Keys read by code but NOT in the published config file: `asset_url` (FrontendAssets.php:187,281), legacy `layout` and `lazy_placeholder` fallbacks (ServiceProvider L111,116), `app.debug` (FrontendAssets 25,85,169,273; HandleComponents profiling; per map).
Config reads are not cached beyond Laravel's config repository (array dot-lookups via Arr::get; cheap but per call). Notable per-request/per-component reads: `app.debug` + `payload.max_nesting_depth` (per updateProperty?) + `payload.max_calls`, `release_token`, `inject_morph_markers/smart_wire_keys` (static-cached in SupportMorphAwareBladeCompilation L19-24; per map), `view_path`.

---------------------------------------------------------------------
## 9. PERFORMANCE OBSERVATIONS — consolidated, ranked by suspected per-request weight

(Fact = read in code. "UNSURE" = needs measurement or vendor check.)

A. Per update request, per component
1. ComponentHook.php:183-265 + ComponentHookRegistry (partial read): up to 47 `new` hook objects per component per hydrate (and per mount), 94 mount/hydrate EventBus listener invocations, each doing `method_exists` + variadic spread; update/call/render/renderIsland/placeholder events allocate a closure + empty array per hook even if the hook lacks the method.
2. PersistentMiddleware.php:416-452: no dedupe when route has no persistent middleware => `request()->duplicate()` + full route table match + `gatherRouteMiddleware` per component in the batch. When middleware exists, dedup only for consecutive same-route snapshots. Also pipeline runs auth/binding middleware (possible DB hits).
3. BaseUtils.php:64-82 / Concerns:375-378: `new ReflectionObject` + per-property `getProperty()` on every `all()`/`getPublicPropertiesDefinedOnSubclass` call (metadata cached, reflection objects not). `ComponentHook::getProperty` (L272-275) calls it each time.
4. BaseUtils.php:108-121: `getPublicMethodsDefinedBySubClass` uncached full `getMethods()` of the component (includes all methods of 18 traits + Component) per call.
5. BaseUtils.php:123-143, 158-166: `hasAttribute` (newInstance per attribute), `getProperty`, `propertyIs*`, `propertyTypeMatchesValue` all start a new ReflectionObject each call (per property per update).
6. SecurityPolicy.php:516-523: 14 `is_a(..., true)` per hydrated class meta, uncached.
7. ImplicitlyBoundMethod.php:218-249,347-366: per action call + per render: new ReflectionMethod + `getParameters()`, `getAttributes()` per param, up to 4 `new ReflectionClass` per typed param.
8. ExtendBlade.php:99-146 + helpers `invade` (ReflectionClass + anon class each): per outermost render apply+undo of directives/precompilers on the global BladeCompiler (through reflection) even when the view is cached; ViewContext.php:452-470 `invade($__env)` x2-4 per render.
9. ExtendedCompilerEngine.php:14: `trigger('view:compile')` per view get during Livewire render with zero registered listeners (non-test src).
10. Component.php:114-161: `__get`/`__call` allocate closure + EventBus trigger per magic access (computed reads, etc.); `__isset` => `__get` + exception on miss.
11. Utils.php:219-239 `insertAttributesIntoHtmlRoot`: regexp + full HTML copy per render; Utils.php:376-391 anonymous class + `view()->make` per string-template render.
12. Synth path: linear `match()` scans (Carbon 5 instanceofs, Enum is_subclass_of), `CarbonSynth::hydrate` date parse, `CollectionSynth::hydrate` is_a autoload per hydrate; arrays are recursed element-wise with a closure per element.

B. Component resolution (per mount / per unknown-name resolve)
13. CacheManager.php:120-162 + Compiler.php:22-29: every `compile()` = ~13 stat calls + ~8 md5/base_path computations, + `require` of class file (not require_once) + instantiating anonymous class. No production short-circuit. How often `compile()` is invoked per request depends on Factory caching (Factory not read in this task).
14. Finder.php:159-297,393-410: 12-candidate probing per location (stat calls) + `file_get_contents` + backtracking PCRE on a hit; 3 `class_exists` per class location; no memoisation inside Finder.

C. Per page (full HTML) request
15. FrontendAssets.php:204-205,268: manifest.json read+json_decode on each `scripts()` call (x2 if published assets), minify regex L307, `url()` calls, route lookup.
16. SupportAutoInjectedAssets.php:86-95: up to 4 full-document regex passes (2 `test`, 2 `replaceMatches`) + `str()` copies on every HTML response containing a component. The RequestHandled listener also runs `processNonLivewireAssets()`/`getAssets()` for every HTML 200 response in the app before checking whether anything needs injecting.
17. LivewireServiceProvider boot: 11 mechanisms + 47 features + ~94 listeners + `blade.compiler`/view engine resolver instantiation + `bootConfig` (4 `config()->set`, `is_dir` x4, `view->addLocation`, `anonymousComponentPath`) every PHP-FPM request, even on non-Livewire routes.

D. Compile-time / cold only (not steady state)
18. LivewireTagPrecompiler (5 passes with recursive-subpattern regexes) runs on every Blade file the app compiles (global precompiler); DeterministicBladeKeys precompiler hook also global (crc32 of path/contents per compile). SingleFileParser/Parser: many `/s` lazy regexes, repeated `getViewPortion` stripping x3. Matters for first request after deploy/`view:clear`.
19. CacheManager::prepareGeneratedFileForCompilation: touch + delete of Blade compiled file on each (re)compile (compile-time).

E. Misc
20. LivewireManager.php:307-327 `originalPath/originalMethod` re-`json_decode` the first snapshot on every call (callers not verified).
21. `LivewireManager::isCspSafe` reads config per call; `Synth::getKey` `property_exists` per call.

Open questions for later passes (not answered here):
- Factory.php: does `resolvedComponentCache` also avoid calling `Compiler::compile()` (stat calls) on subsequent mounts/hydrates in the same process?
- Laravel's `CompilerEngine`/`BladeCompiler::isExpired` per-render stat behaviour (vendor not installed).
- ComponentHookRegistry::proxyCallToHooks — whether it filters hooks lacking the method before allocating closures.
- Who calls `LivewireManager::originalPath()` and `ComponentHook::getProperty()` on the hot path.
