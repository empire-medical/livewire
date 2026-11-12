# Notes: src/Features batch B (read completely, non-test files)

Paths relative to /home/endl/livewire/src/Features. Conventions: "hook" = ComponentHook lifecycle method. None of the 20 folders defines `skip()` (only SupportPagination does, outside my scope), so every hook class here is instantiated + booted + hydrated for EVERY component on EVERY mount/hydrate (per CODEMAP ComponentHookRegistry). Hook classes that only use `provide()` (static) still have a `new` per component if they are registered in bootFeatures (UNSURE: registry may skip classes with no instance hooks; CODEMAP says it does `new $hook` for all).

Folders with NO non-test PHP (only BrowserTest.php): SupportInterceptors, SupportJsComponent, SupportLargePayloads, SupportMagicErrors. (JS-only features; PHP side nothing to read.)
Files not read: binary fixtures only (browser_test_image*.png/.jpg in SupportFileUploads), test files, fixtures/.

---------------------------------------------------------------------
## SupportFileDownloads
1. Lets a component action return a `StreamedResponse`/`BinaryFileResponse` (or `Responsable` producing one); the file is captured in memory, base64-encoded, sent as a `download` effect, and JS turns it into a Blob + hidden `<a>` click.
2. Files
- SupportFileDownloads.php: hook. `call()` returns finisher closure: converts Responsable via `toResponse(request())` (:15-17), bails if not file response (:19), parses filename from Content-Disposition (:23), `ob_start` + `sendContent()` into memory (:27, captureOutput :54), `base64_encode` (:31), `storeSet('download', ...)` (:33). `dehydrate()` copies store -> `addEffect('download')` (:41-46). `getFilenameFromContentDispositionHeader` 3 preg_match in order (:63+).
- TestsFileDownloads.php: test trait (assertFileDownloaded etc.). Not runtime.
3. Hooks: call (returns finisher), dehydrate. No skip().
4. State: store key `download` {name, content(base64), contentType}.
5. Perf
- SupportFileDownloads.php:15-17,19: runs on every action return of every component: `instanceof Responsable` + 2 instanceof. Cheap, but the finisher closure is created per call() on every component.
- :27-31 entire file read into memory (output buffer) then base64 (+33% size) then embedded in JSON snapshot response (json_encode of large string, then JS atob + slicing loop). Memory ~3x file size. Only on actual downloads.
- JS supportFileDownloads.js: `on('commit')` + `succeed` runs for every commit; base64toBlob builds per-512-byte Array + Uint8Array (js/features/supportFileDownloads.js:30-47), slow for big files.

---------------------------------------------------------------------
## SupportFileUploads
1. Temporary file uploads: signed-URL upload endpoint (local) or presigned S3 PUT; `TemporaryUploadedFile` wraps a file in the temp disk; FileUploadSynth serialises these as signed `livewire-file:` strings inside the snapshot.
2. Files
- SupportFileUploads.php: `provide()` only (:13-39): in unit tests swaps GenerateSignedUploadUrlFacade; registers `FileUploadSynth` via `propertySynthesizer`; `on('call')` listener that throws MissingFileUploadsTraitException if `_startUpload` called without trait (:26-32); registers 2 routes (upload POST, preview GET).
- FileUploadSynth.php: key `fil`. `match()`: `$target instanceof UploadedFile`. `dehydrate()` -> `dehydratePropertyFromWithFileUploads` (recursive). `hydrate()` -> `TemporaryUploadedFile::unserializeFromLivewireRequest` if `canUnserialize`.
- TemporaryUploadedFile.php (366 lines): extends UploadedFile. ctor calls `Storage::disk`, `tmpfile()`, `stream_get_meta_data`. `metaFileData()` reads `<path>.json` from disk (cached per instance). `getSize/getMimeType/hashName` have test-mode branches (`app()->runningUnitTests()`). `detectMimeTypeFromContents()` reads first 64KB (+ID3 tag) via `readStream` and finfo. `temporaryUrl()`, `isPreviewable()` (config read + in_array), `storeAs`, `delete`, `exists`, `get`, `dimensions()` (copies whole stream to tmpfile). Signing: `generateToken` = hmac sha256 with `app('encrypter')->getKey()` truncated to 8 chars (:285-288); `signPath`, `extractPathFromSignedPath` (hash_equals). `canUnserialize` (:310) recursive with `collect()->contains`. `serializeForLivewireResponse` / `serializeMultipleForLivewireResponse` (json_encode).
- FileUploadConfiguration.php: static config accessors: `storage()`, `disk()`, `diskConfig()`, `isUsingS3()`, `isUsingGCS()`, `path()`, `directory()`, `rules()`, `maxUploadTime()`, `middleware()`, `shouldCleanupOldUploads()`, `storeTemporaryFile()` (writes `.json` meta + file).
- GenerateSignedUploadUrl.php: `forLocal()` (temporarySignedRoute relative), `forS3()` (uses `invade()` x3 to reach flysystem adapter/client/bucket, builds presigned putObject), `signedRoute()`.
- FileUploadController.php: `handle()` verifies signature, validates `files.*` with config rules, stores each, returns signed paths. Middleware built per request via `middleware()`.
- FilePreviewController.php: signature check then `Utils::pretendPreviewResponseIsPreviewFile`.
- WithFileUploads.php: trait on component: `_startUpload` (#[Renderless]), `_finishUpload`, `_uploadErrored`, `_removeUpload`, `cleanupOldUploads`.
- S3DoesntSupportMultipleFileUploads.php, FileNotPreviewableException.php, MissingFileUploadsTraitException.php: exceptions.
3. Hooks: SupportFileUploads defines no instance hooks (provide only) but registers a global `on('call')` listener (fires on EVERY method call of every component, cheap string compare :27). Synth: FileUploadSynth.
4. State: static none. Store none. Temp files + `.json` meta on the disk. Test only: Facade swap.
5. PERF
- FileUploadSynth.php:51-53 `match()`: `instanceof UploadedFile` runs for every property value on dehydrate (as part of synth `findByTarget`; CODEMAP says target class is cached by get_debug_type, so run once per distinct type). Synth is unshifted to the FRONT of synth list, so its match runs before built-in synths for any non-primitive (UNSURE on order across the 4-5 feature synths).
- FileUploadSynth.php:101-105 `hydrate()`/`canUnserialize` only runs for properties tagged `fil`, so only file props. OK.
- BUT `dehydratePropertyFromWithFileUploads` (:59-99) is recursive over arrays and Wireables; only reached for UploadedFile targets. Contains `array_values($value)[0]` called up to 3 times (:69, :80), copies array each time (O(n) per call, O(n) loop). Per upload property only.
- TemporaryUploadedFile.php:19-27 ctor: `Storage::disk()` (container resolve/disk cache), `tmpfile()` created on every instantiation (an fd + temp file on local FS) and never explicitly closed (kept alive by PHP resource until GC). Created for every file in every request where the property is hydrated (once per `unserialize`, each file).
- :257-270 `metaFileData()`: one storage `get` (disk IO; remote = network call on non-S3 remote disks) per TemporaryUploadedFile instance, cached in instance only. Called by `getClientOriginalName()` (:132) and in tests for size/mime/hash. Each request rebuilds the object so the meta is re-read each request.
- :48-62 `getSize()` -> `$this->storage->size()` uncached IO each call. `getMimeType()` cached in `detectedMimeType`, but reads up to 64KB (plus up to 8MB for ID3) from storage each new instance.
- :310-323 `canUnserialize()` array branch: `collect($subject)->contains` recursion on every element.  :313 `str($subject)->startsWith` allocates Stringable per call.
- :285-288 `generateToken`: `app('encrypter')->getKey()` + hmac per file path on each serialize and unserialize (two files => two hmacs, in dehydrate AND hydrate every request for every file).
- :170 `isPreviewable` reads `config()` + `guessExtension()` (finfo mime guess on file content => `getMimeType()` => stream read) each time `temporaryUrl()` is called, which is typically in Blade for each preview, every render.
- :137-142 `dimensions()` copies whole stream to a temp file before getimagesize.
- WithFileUploads.php:441-443,540-556 `cleanupOldUploads()` runs inside EVERY `_finishUpload`: `$storage->allFiles(dir)` lists the entire livewire-tmp directory recursively, then for each file `exists()` + `lastModified()` (2 IO calls/file) and `now()->subDay()->timestamp` recomputed inside the loop (:551). O(N files) IO per upload completion. On S3 skipped (:542); on remote non-S3 disks this is N network calls.
- WithFileUploads.php:446-454 signed path verify per file (hmac). :457-459 `createFromLivewire` per file.  :460 `collect($file)->map->getFilename()` (higher-order proxy).
- :481 `app('livewire')->updateProperty` -> goes through the normal update pipeline (dehydrate of the uploaded file(s) afterwards).
- :518-530 `_removeUpload` `array_filter` O(n) per removal; `updateProperty` rewrites whole array.
- FileUploadConfiguration.php: every accessor calls `config()` and, in `path()`, `normalizeRelativePath` (new WhitespacePathNormalizer per call :166) x2 (directory + path) plus `isUsingS3()` -> `config()` x3 + `strtolower` (:150-155,:174-185). `path()` is called many times per file (getFilename path ctor :23, storage ops). No caching: UNCACHED config lookups + object allocation per call. `storage()` calls `static::disk()` and `Storage::disk()` each call (:116-134). `app()->runningUnitTests()` (container call) is evaluated in disk() (:138) and in many TemporaryUploadedFile methods.
- FileUploadConfiguration.php:234-249 `storeTemporaryFile`: 2 writes per upload (meta json + file), `str()->random(40)`.
- GenerateSignedUploadUrl.php:261-266 / FileUploadController middleware: `URL::temporarySignedRoute` HMAC per `_startUpload`. `forS3` uses reflection via `invade` x3 (:275-281) per upload start.
- SupportFileUploads.php:26 global `on('call')` listener: extra listener cost per method call for all components (small).
- Whole-file base64/stream: `storeAs` (:199-212) streams from temp disk to target; fine.

---------------------------------------------------------------------
## SupportFormObjects
1. Lets a component hold `Form` objects (public typed props). Forms are auto-instantiated in `boot()`, dehydrated by FormObjectSynth as nested arrays with `class` meta, and support their own `updating*/updated*` hooks and validation with prefixed errors.
2. Files
- Form.php: base class (Arrayable) using HandlesValidation + InteractsWithProperties. `validate`/`validateOnly` wrap parent and re-prefix error bag with property name using `invade($e->validator)`. `reset()` builds a fresh instance (`new static`) and a `ReflectionProperty` per property (:100-124). `toArray()` -> `Utils::getPublicProperties($this)` (:128).
- SupportFormObjects.php: hook. `provide()` registers synth. `boot()` -> `initializeFormObjects()`: `new ReflectionClass($this->component)->getProperties()` for EVERY property of the component on each boot (:203). For each public, uninitialized property with a named Form-subclass type: instantiates, `FormObjectSynth::bootFormObject`, `setValue`. `update()` (:155-199): per-property-update, builds `str()` object chain to compute updating/updated method names and calls `callFormHook` (method_exists + `wrap()`).
- FormObjectSynth.php: key `form`, implements ArrayShapedSynth. `dehydrate` (:257-266): `$target->toArray()` then `$dehydrateChild` for each key. `hydrate` (:268-294): class check via `is_a($meta['class'], Form::class, true)` (autoload), `data_get($this->context->component, $this->path)` to reuse existing form (:279), else `new $meta['class']`, `bootFormObject` -> `AttributeCollection::fromComponent($component, $form, $path.'.')` + `mergeOutsideAttributes`. `set()` (:296) uses `Utils::propertyIsTyped`, `Utils::getProperty`. `hydrateFormProperties` per key `Utils::propertyIsTypedAndUninitialized` (:319).
- HandlesFormObjects.php: `getFormObjects()` loops `$this->all()` (all public props!) filtering Form.
3. Hooks: boot, update (returns finisher). No hydrate/dehydrate on the hook (the synth does it). No skip().
4. State: none in store. Static none. The Form holds a ref to component (`protected Component $component`).
5. PERF
- SupportFormObjects.php:203 `boot()` -> `new ReflectionClass` + `getProperties()` + per-property `isPublic()`, `isInitialized()` for every component on every boot (every mount AND hydrate) even for components without any Form: UNCACHED reflection (class-level metadata not cached per class). `boot()` is on the per-component hook path for every component in the app. Ordering: `$property->isInitialized` on all public props.
- SupportFormObjects.php:155-199 `update()`: for every `wire:model` update on ANY property (not only forms): hook runs `$this->getProperty($formName)` and returns early only if not a Form (:157-161). Early out is cheap. For forms: ~8 `str()` objects, 6 `callFormHook` -> `method_exists` (uncached) each.
- FormObjectSynth.php:257-266 `dehydrate`: `toArray()` -> `Utils::getPublicProperties` = reflection per form per dehydrate (`ReflectionObject` + props, UNSURE if cached; CODEMAP says Utils reflect cache only for class metadata, with `extractPropertyValuesFromInstance` creating new Reflection objects each call). Then closure call per child.
- FormObjectSynth.php:279 `data_get($this->context->component, $this->path)` on every form hydrate (path walk via `Arr`/property access).
- FormObjectSynth.php:271 `is_a(..., true)` autoload check per hydrate.
- FormObjectSynth.php:305-309 `bootFormObject` -> `AttributeCollection::fromComponent` (reads attributes via reflection on the form class; UNSURE cost, in SupportAttributes, not read here) per form per hydrate; plus `wrap($form)->boot()` each time (ImplicitlyBoundMethod DI per call).
- FormObjectSynth.php:298 `set()`: `Utils::propertyIsTyped` + `Utils::getProperty` (new ReflectionProperty) per null set.
- FormObjectSynth.php:319 per-key `propertyIsTypedAndUninitialized` (reflection) only when child is null.
- Form.php:100-124 `reset()`: `new static` + `ReflectionProperty` per property.
- HandlesFormObjects.php:339 `getFormObjects()` calls `$this->all()` (reflective read of all properties) each call. UNSURE who calls it (likely SupportValidation).
- Form.php:37-38 / :49-53: `invade()` (ReflectionObject) used on validation failure only.

---------------------------------------------------------------------
## SupportHtmlAttributeForwarding
1. Lets HTML attributes written on `<livewire:foo class=".." />` flow to the component view as `$attributes` (ComponentAttributeBag); non-scalar attrs become view variables. Persisted across requests via memo `attributes`.
2. Files
- SupportHtmlAttributeForwarding.php: hook: `render`, `renderIsland`, `renderPlaceholder` -> `forwardAttributesToView($view)` (:43-50): `getHtmlAttributes()`, `$view->with(getNonHtmlAttributes())`, `$view->with(['attributes' => new ComponentAttributeBag(...)])`. `hydrate($memo)` -> `withHtmlAttributes`. `dehydrate` -> `addMemo('attributes')`.
- HandlesHtmlAttributeForwarding.php: trait; `$htmlAttributes` array; `getHtmlAttributes()` array_filter w/ closure; `getNonHtmlAttributes()` = array_diff_key(all, getHtmlAttributes()) => calls getHtmlAttributes() again.
3. Hooks: render, renderIsland, renderPlaceholder, hydrate, dehydrate. No skip().
4. State: component property `$htmlAttributes` (not Livewire public); memo `attributes`.
5. PERF
- Runs on EVERY render and island render for EVERY component, even with no attributes: `getHtmlAttributes()` called 2x in forwardAttributesToView (:45,:47 via getNonHtmlAttributes:30 -> 3 array_filter passes total), `new ComponentAttributeBag` always, 2x `$view->with()`. Note: always sets `attributes` view var, overriding any other (UNSURE intent). Cost is small per call but unconditional (no early return when `htmlAttributes` empty).
- dehydrate (:36) calls getHtmlAttributes again (a 4th filter pass per request).
- hydrate: `$memo['attributes']` is stored in the snapshot memo and is part of the checksummed/JSON payload for each request.

---------------------------------------------------------------------
## SupportInterceptors, SupportJsComponent, SupportLargePayloads, SupportMagicErrors
Only BrowserTest.php exists. No PHP runtime code. (JS side: js/request/interceptor.js etc., out of my scope.)

---------------------------------------------------------------------
## SupportIslands
1. `@island ... @endisland` regions inside a Blade view are compiled into separate cached Blade files; during updates a call carrying `metadata.island` re-renders only that island (as fragment markers + `islandFragments` effect) instead of the whole component. Supports lazy/defer/skip/always, append/prepend/morph modes, streaming.
2. Files
- SupportIslands.php: hook. `provide()` (:13-22): registers Blade string precompiler on `app('blade.compiler')->prepareStringsForCompilationUsing` (:35-42, shortcut `str_contains($content,'@endisland')` per compiled view), registers `@island` directive, and `before('dehydrate')` listener that calls `renderImplicitIslandMorphs()` on every component dehydrate (:19-21). Instance hooks: `call` (:45-79), `dehydrate` (:81-90), `hydrate` (:92-103).
- HandlesIslands.php (trait on Component): state `$islands, $islandsHaveMounted, $islandIsTopLevelRender, $renderedIslandFragments, $implicitIslandMorphs`. `renderIslandDirective()` (called from compiled Blade), `renderSkippedIsland`, `triggerImplicitIslandRender`, `renderImplicitIslandMorphs`, `renderIsland`, `streamIsland`, `renderIslandView` (:202-248), `regenerateIslandCacheFiles`, `regenerateIncludedIslandCacheFiles`, `wrapWithFragmentMarkers`, `encodeFragmentMetadata`, `injectLazyDirective` (regex), `mountIfNeedsMounting`.
- Compiler/IslandCompiler.php: compile-time only (runs when a Blade view is compiled). Strips Blade comments, uses a custom anonymous BladeCompiler subclass (new per `process()`, :220-271) with only `@island/@endisland` directives, nested-island resolution via recursive-paren regex per nesting level (:64-84), writes each island body to `<livewire cache dir>/islands/<token>.blade.php` (`File::ensureDirectoryExists` + `file_put_contents` :116-121), `prepareGeneratedFileForCompilation`.
3. Hooks: hydrate, call (returns finisher; has `returnEarly` for `__lazyLoadIsland`), dehydrate, plus global `before('dehydrate')` listener. `renderIsland` hook trigger (`renderIsland` event) is fired from HandlesIslands:231. No skip().
4. State: component props (listed above), memo `islands` (list of {name, token}), effect `islandFragments`. Static: none. File cache `islands/*.blade.php`.
5. PERF
- SupportIslands.php:19-21 `before('dehydrate')` listener executes for every component of the app on every dehydrate: calls `renderImplicitIslandMorphs()` (loop over empty array) then dehydrate() hook calls it again (:83). Trivial.
- SupportIslands.php:85 `addMemo('islands', getIslands())` per component each dehydrate (adds to snapshot only if non-empty? NO: always adds the memo key, even `[]`). Memo `islands: []` goes into every snapshot -> larger JSON + checksummed (UNSURE if addMemo drops empties, did not read ComponentContext).
- SupportIslands.php:92-103 `hydrate` runs per component per request; sets islands from memo.
- SupportIslands.php:45-79 `call()`: returns early cheaply if no `metadata.island`. With island: `array_filter` over islands (:64).
- HandlesIslands.php:140-168 `renderIsland` loops all islands comparing names (O(#islands)) and, if multiple islands share a name, renders each (note: no break after match). `renderIslandView` per island: `Utils::getPublicPropertiesDefinedOnSubclass($this)` (:218, reflection per island render), `app('view')->file($path)`, `app(ExtendBlade::class)`, `Utils::shareWithViews`, `trigger('renderIsland')` fan-out to all hooks (including SupportHtmlAttributeForwarding etc.) per island render.
- HandlesIslands.php:204-212 `file_exists($path)` (stat) on each island render, plus fallback: `regenerateIslandCacheFiles` (calls `render()`/`view()` of component and recompiles) and `regenerateIncludedIslandCacheFiles` (:277-300) which `File::allFiles` over ALL view paths and hints directories computing a hash for every `.blade.php` file until found: very expensive but only on cache miss (e.g. fresh deploy/cleared cache between page render and update).
- IslandCompiler.php:33-88 compile-time only (per Blade compile, only when content contains `@endisland`). Regex with recursive subpattern per nesting level, `file_put_contents` for each island on every compile. `app('livewire.compiler')->cacheManager` resolves repeatedly. At runtime, `getCachedPathFromToken` (:181) calls `app('livewire.compiler')` per island render and per call (container resolve).
- SupportIslands.php:35-41 precompiler closure runs for every Blade string compiled (any view) once per compile (not per request once cached): `str_contains` check only.
- HandlesIslands.php:336 `injectLazyDirective` regex per lazy island mount only.
- HandlesIslands.php:304-306 encodeFragmentMetadata called twice per wrap with same metadata (string building x2) per island render and for every skipped island on each full render (renderSkippedIsland, :100).
- On every non-mounting full render, islands without `always` render only a skipped marker (cheap), good.

---------------------------------------------------------------------
## SupportIsolating
1. `#[Isolate]` class attribute marks component so JS sends its updates in its own request (memo `isolate`).
2. Files: BaseIsolate.php (empty attribute, TARGET_CLASS); SupportIsolating.php (hook).
3. Hooks: dehydrate only. No skip().
4. State: memo `isolate` (only if true).
5. PERF
- SupportIsolating.php:16-21 `shouldIsolate()`: `getAttributes()->filter(is_subclass_of(...))->count()` runs on every dehydrate of every component: builds a new filtered collection, `is_subclass_of` over ALL attributes of the component (class + property + method level attributes) per component per request. Not cached per class. Cost grows with number of attributes (every #[Computed], #[Locked], #[On] method etc.). Could be considered `O(components x attributes)` per request.

---------------------------------------------------------------------
## SupportJsEvaluation (`$this->js()` and `#[Js]`)
1. `$this->js('expr')` pushes JS expressions to run on the client after the response (`xjs` effect). `#[Js]` method attribute: the method's returned string is sent as a `js` effect keyed by method name so JS can call `$js.name()`.
2. Files: HandlesJsEvaluation.php (trait `js()` -> `store($this)->push('js', ...)`); SupportJsEvaluation.php (hook dehydrate -> `xjs` effect); BaseJs.php (attribute, `dehydrate($context)` calls `$this->component->$name()` and `pushEffect('js', ..., $name)`). TestsJsEvaluation.php test trait.
3. Hooks: SupportJsEvaluation.dehydrate; BaseJs::dehydrate (attribute-level). No skip().
4. State: store key `js` (list of {expression, params}).
5. PERF
- BaseJs.php:10-17: every `#[Js]` method is INVOKED on every dehydrate (every request, each component) and its string is pushed as an effect each time -> repeated work and repeated payload bytes on every response even though static (and `$this->component->$name()` is a direct call, not via `wrap`; will run user code each time).
- SupportJsEvaluation.php:11 `storeHas('js')` one DataStore lookup per component dehydrate. Cheap.
- JS side js/features/supportJsEvaluation.js:26-37 runs `xjs.forEach` evaluate (Alpine evaluateRaw) per expression.

---------------------------------------------------------------------
## SupportJsModules
1. Serves a component's colocated JS module (SFC/MFC `scriptModuleSrc()`) via a route and tells the client its content hash with a `scriptModule` effect; adds `<link rel=modulepreload>` for full-page renders; announces child modules mounted during an update via `childScriptModules`.
2. Files: SupportJsModules.php only. `provide()`: flush-state reset + `Route::get(EndpointResolver::componentJsPath())` which maps the URL name back to component name (3 `str_replace`), `app('livewire')->new($component)` (instantiates component!), `file_exists`, `file_get_contents`, `filemtime`, `Utils::pretendResponseIsFileFromString`. `dehydrate($context)` (:53-107). `registerModulePreloadAsset`, `flushModulesMountedThisRequest`, static `moduleUrl()`.
3. Hooks: dehydrate only. No skip().
4. State: static `$modulesMountedThisRequest` (name=>hash); store keys read: `isLazyLoadMounting`, `isLazyLoadHydrating` (set by SupportLazyLoading). Writes `SupportScriptsAndAssets::$renderedAssets` static.
5. PERF
- SupportJsModules.php:59,68-69 dehydrate on every component: 2 store lookups + `$context->isMounting()`; on update (non-mount) calls `flushModulesMountedThisRequest` (empty check) and returns. Cheap for updates.
- :81-84 on every MOUNT of a component with a script module: `method_exists`, `scriptModuleSrc()` (UNSURE what it costs, defined in compiler-generated class), `filemtime($path)` (stat syscall; PHP stat cache helps within request), `crc32`. Repeated for the same component class on each instance mounted (e.g. a loop of 100 same components => 100 `filemtime` + `scriptModuleSrc()`; no per-class cache). :119-123 `registerModulePreloadAsset` repeats `scriptModuleSrc()` + `file_exists` + `filemtime` again (so up to 2x stats + 2x scriptModuleSrc per mount on initial page).
- :143 `url(app('livewire')->getUriPrefix())` per preload.
- Route handler (:24-50): `app('livewire')->new($component)` constructs a whole component (hooks boot) just to ask for the source path, then reads the file each request (no ETag / 304 handled here; UNSURE if `pretendResponseIsFileFromString` sets cache headers; not read).
- Preload assets registered in static `SupportScriptsAndAssets::$renderedAssets` keyed by URL (dedupes).
- JS: js/features/supportJsModules.js (168 lines) not analysed in depth.

---------------------------------------------------------------------
## SupportJson (`#[Json]` methods)
1. `#[Json]` on a method makes it callable from JS as a JSON-returning action: runs the method immediately, skips render, returns the value early, and reports validation/HTTP errors via `returnsMeta` effect. Memo lists `json` and `async` method names (JS reads `memo.json` in request/action.js:108).
2. Files: BaseJson.php (attribute TARGET_METHOD; `dehydrate` pushes memo `json` and `async`); SupportJson.php (`provide()` only: global `on('call')` listener).
3. Hooks: global `on('call', ...)` (static listener, fires for every call on every component), attribute-level dehydrate. No instance hook class work (class still registered). No skip().
4. State: effect `returnsMeta`; `skipRender()` flag.
5. PERF
- SupportJson.php:17-18,54-60: on EVERY method call of EVERY component the listener runs `isJsonMethod()`: `getAttributes()` -> `filter` closure over all attributes -> `->filter(getName === $method)` -> `isNotEmpty` (2 collection passes + 2 new collections) per call. Not cached per class/method. Same pattern exists in SupportMagicActions (cheap) but this one walks the attributes collection. This listener is registered on `call` BEFORE regular hooks.
- :22-26 authorize chain does 3 chained `filter` passes (Authorize attrs) only for json methods.
- BaseJson.php:14-15 `pushMemo` x2 per #[Json] method per dehydrate (every request); memo bloat (method names listed twice: `json` and `async`).

---------------------------------------------------------------------
## SupportLargePayloads / SupportInterceptors / SupportJsComponent / SupportMagicErrors
(see above: no PHP.)

---------------------------------------------------------------------
## SupportLazyLoading (`#[Lazy]`, `#[Defer]`, `lazy`/`defer` params, `Route::lazy()`)
1. On mount, if a component should be lazy/deferred it skips mount and renders a placeholder with `x-intersect`/`x-init` = `$wire.__lazyLoad('<base64 snapshot of mount params>')`. The follow-up request rehydrates a throwaway "container" component to recover mount params, calls the real `mount` lifecycle, and renders for real.
2. Files
- SupportLazyLoading.php: hook (262 lines). `registerRouteMacro()` adds `Route::lazy/defer`. `mount($params)` (:48-106) decides lazy; `hydrate($memo)`, `dehydrate($context)`, `call()` for `__lazyLoad`, `generatePlaceholderHtml` (:161), `getPlaceholderView` (:204), `resurrectMountParams` (:230), `callMountLifecycleMethod` (:245: `new SupportLifecycleHooks` + manual `mount`), `registerContainerComponent` (:255: registers anonymous class each call).
- BaseLazy.php, BaseDefer.php: attributes with `$isolate`, `$bundle` ctor props (TARGET_CLASS). They're read through native reflection, not through AttributeCollection.
3. Hooks: mount, hydrate, dehydrate, call. No skip(). Static `$disableWhileTesting` reset on flush-state.
4. State: store keys `isLazyLoadMounting`, `isLazyIsolated`, `isLazyLoadAlreadyLoaded`, `isLazyLoadHydrating`; memo `lazyLoaded` (false/true), `lazyIsolated`. Also calls `skipMount()`, `skipRender(html)`, `skipHydrate()`.
5. PERF
- SupportLazyLoading.php:67-69 `mount()` on EVERY component mount: `new \ReflectionClass($this->component)` + `getAttributes(Lazy::class)` + `getAttributes(Defer::class)` (2 attribute lookups; UNCACHED per class) even when no lazy params. 14 `isset($params[..])` checks precede. All before the cheap early return `if (! $shouldBeLazy) return;` (:82) - reflection happens first. NB for a loop of N children: N ReflectionClass + 2 getAttributes.
- :84-96 `newInstance()` of attribute per lazy mount.
- :161-202 `generatePlaceholderHtml` per lazy component: `registerContainerComponent()` (re-registers the anonymous container class every time: `app('livewire')->component(...)` with a `new class` instance) + `app('livewire')->new(container)` + `ComponentContext` + `trigger('dehydrate', $container, $context)` (full dehydrate hook fan-out on a throwaway component, including all hooks' instantiation) + `snapshot()` (dehydrateProperties + checksum HMAC + ...) + `json_encode` + `base64_encode`. Snapshot is embedded in the HTML attribute of each placeholder (size ~ mount params + checksum + hook memos).
- :204-227 `getPlaceholderView`: `str()->afterLast`, `view()->exists('livewire-compiled::..')` (finder lookup + file stat) per lazy mount; `config('livewire.component_placeholder')`; if a global placeholder: `view($globalPlaceholder)->render()` per lazy component (a full Blade render each time); `wrap($component)->withFallback(..)->placeholder($params)` (DI call); `Utils::getPublicPropertiesDefinedOnSubclass` (reflection); `Utils::generateBladeView` (anonymous Component class + `app('view')->make`, per CODEMAP). `$placeholder->render(...)`, `ViewContext->extractFromEnvironment`, `Utils::insertAttributesIntoHtmlRoot` (HTML parse/regex; UNSURE implementation) and the `render.placeholder` trigger.
- :230-243 `resurrectMountParams` on `__lazyLoad` request: base64 decode + json_decode + `registerContainerComponent` + `fromSnapshot` (full checksum verify + hydrate of container) per lazy component, in addition to the component's own checksum verify/hydrate. With N lazy children bundled: N container hydrates (each HMAC).
- :245-252 `callMountLifecycleMethod` instantiates a new `SupportLifecycleHooks` manually (bypasses registry cache).
- Placeholder payload repeated twice per lazy component in initial HTML (snapshot of container in `x-intersect` attribute + component's own snapshot).

---------------------------------------------------------------------
## SupportLegacyModels (opt-in: config `livewire.legacy_model_binding === true`)
1. v2-style model binding: dehydrates public Eloquent models/collections into data arrays (only fields listed in `$rules`) so wire:model can bind to `model.field`; rehydrates by re-querying then applying the data.
2. Files
- SupportLegacyModels.php: static helper; `provide()` returns immediately unless config true (:19), else registers `EloquentModelSynth` + `EloquentCollectionSynth` (key `elmdl`, `elcl`) and flush-state. Rule helpers: `hasRuleFor`, `missingRuleFor`, `getRules` (cached per component id in static `$rules`), `getRulesFor`, `dataGetWithoutWildcardSupport`, `ruleWithNumbersReplacedByStars` (2 regex), `processRules` (calls `$component->getRules()`), `recusivelyProcessDotNotation` (recursive with `str()`).
- EloquentModelSynth.php: `dehydrate` (:21-62), `hydrate` (:64-95) (re-query `loadModel` :215-245), `set` (:127-142), `unwrapForValidation`, `getAttributes` (:172-188), `filterAttributes`, `filterRelations`.
- EloquentCollectionSynth.php: dehydrate/hydrate/get/set with `loadCollection` (:147-176).
- CannotBindToModelDataWithoutValidationRuleException.php; Tests/Concerns/EnableLegacyModels.php (test trait).
3. Hooks: no instance hooks (class SupportLegacyModels is registered in bootFeatures so is still `new`ed per component UNSURE); static provide only. Synths active only if config on. No skip().
4. State: static `$rules[componentId]`. flushed on flush-state.
5. PERF (only if legacy binding enabled)
- EloquentModelSynth.php:35-43: `$class::make()->getConnectionName()` instantiates a fresh model per dehydrated model (:41) and `getQueueableRelations()` (:45) walks loaded relations recursively. EloquentCollectionSynth.php:30 same `make()` per collection.
- :51 `getRules()` -> `getRulesFor` -> `ruleWithNumbersReplacedByStars` (2 regex on the path) + `data_get` + recursive `dataGetWithoutWildcardSupport` (`explode`/`implode` per level) for EVERY model/collection node dehydrated.
- :172-188 `getAttributes()`: `attributesToArray()` (applies casts/accessors for ALL attributes) then per cast `class_exists` + `class_implements($cast)` (reflection-ish, uncached) per dehydrate per model.
- SupportLegacyModels.php:83-85 `getRules` cached by `$component->getId()` but `processRules` calls `$component->getRules()` (user-defined or attribute-collected rules; UNSURE cost) once per component per request; cache reset on flush-state only (static grows with number of components in a long-lived process like Octane between flushes).
- hydrate (:215-245): one DB query per model on each request (`newQueryForRestoration($key)->first()`, not lazy, not deduped with SupportModels cache), collections one query per collection (:156-164, `keyBy->getKey()`), `loadMissing` for relations (:65). N models => N queries (N+1) on every request.
- :88-91 EloquentModelSynth hydrate loops `hydrateChild` per attribute (each goes through HandleSynths). `setDataOnModel` assigns attributes one by one (triggers mutators).
- SupportLegacyModels.php:36-77 `hasRuleFor` on every `set()` (per wire:model update to a model attribute): `str()->explode` + loops.
- EloquentCollectionSynth.php:121-130 `getConnection()` iterates the entire collection with `each`.
- EloquentCollectionSynth.php:68-76 hydrate per element `$collection->get($key)`, `$collection::wrap($data)`.

---------------------------------------------------------------------
## SupportLifecycleHooks (`mount/boot/booted/hydrate/updating/updated/rendering/rendered/dehydrate/exception` user lifecycle methods + trait variants)
1. Calls the component's own lifecycle methods (`boot()`, `mount()`, `hydrateFoo()`, `updatingFoo()`, ...) and trait-suffixed ones (`bootWithPagination`, `mountMyTrait`, ...). Blocks directly calling lifecycle methods as actions.
2. Files: SupportLifecycleHooks.php (217 lines): hooks, `callHook` (:164-177), `callTraitHook` (:179-216). DirectlyCallingLifecycleHooksNotAllowedException.php.
3. Hooks: mount, hydrate, update (returns finisher), call, exception, render (returns finisher), dehydrate. No skip(). Honours store flags `skipMount`, `skipHydrate` (set by lazy loading).
4. State: static caches `$traitCache[class]` (class_uses_recursive) and `$methodCache["class::method"]` (method_exists), flushed on flush-state. Store: reads `skipMount`/`skipHydrate`.
5. PERF
- This hook runs on EVERY component/lifecycle phase. Caching exists: trait list and method_exists are cached per class (:11-15, :117-119, :184-186). Positive.
- :54-56 `hydrate()`: for EVERY public property, on EVERY request: `str($property)->studly()` (new Stringable + Str::studly cached internally by Laravel's static cache) + string concat + `callHook()` ($cacheKey string build + isset). Same in `dehydrate()` (:159-161). So 2 x P x (Stringable alloc + studly + 2 string concats + array lookup) per component per request. `$this->getProperties()` is called twice per request (hydrate+dehydrate): UNSURE cost (Component::getProperties => `Utils::getPublicProperties`, reflection read).
- :62-96 `update()` per property update: builds ~7 Stringable objects and strings (`studly`, `before`, `after`, `afterLast`, `replace`...) and 4+ `callHook` calls + 2 `callTraitHook` loops, even if none exist. Closure created per update.
- :98-134 `call()` executes for EVERY action call of EVERY component and is the UNCACHED hot spot of this file: builds `$protectedMethods` array (11 patterns + 3 x #traits strings, with `class_basename($trait)` per trait per call, NOT cached; `class_basename` does `str_replace` + `basename`), then `str($methodName)->is($protectedMethods)` which runs `Str::is` over every pattern with `preg_match` (pattern quoting/regex build per pattern per call: `Str::is` does `preg_quote` + `str_replace('\*','.*')` + `preg_match` for each pattern when no exact match). With ~15-30 patterns, ~15-30 regex compiles/matches per method call (PCRE cache helps match but preg_quote/str_replace done each time).
- :133 `callTraitHook('call', ...)` loops all traits (typically 5-15 incl. nested, Component core traits like HandlesEvents etc.) per method call and per phase: each iteration `class_basename` + concat + cache lookup. This is invoked for boot/initialize/mount/booted (mount: 4 loops), hydrate (4 loops), update (2+2), render (2+2), dehydrate (1), call (1), exception (1). Each loop = #traits x (class_basename + string concat + string key build + isset). For a component with ~25 traits (core Component uses ~15 Handles* traits + user traits) that's ~25 x 15 string ops = ~400 basic ops per request minimum (UNSURE trait count: Component.php not read). Could be cached as list of existing trait-method names per (class, phase).
- :200-212 per matching trait method: `array_keys`, 2 `array_filter` (is_string/is_int) per call to compute spread params.
- :175 `wrap($this->component)->__call($name, $params)` -> ImplicitlyBoundMethod DI (container call) for each existing hook method (boot, mount, hydrate, booted, etc.). Also each existing `hydrateFoo/dehydrateFoo` etc.
- :41-60 `hydrate()` calls `callHook('boot')`, `callTraitHook('boot')`, `initialize`, `hydrate`, `booted`: 8 callHook/callTraitHook invocations per hydrate; mount likewise. So per request per component ~ 8 (hydrate) + 8 (render/dehydrate...) trait loops.
- Cache key string `"{$class}::{$name}"` built each call (string interpolation) rather than nested array lookup.

---------------------------------------------------------------------
## SupportLocales
1. Persist app locale across requests via memo `locale`.
2. Files: SupportLocales.php. Hooks: hydrate (`app()->setLocale($memo['locale'])`), dehydrate (`addMemo('locale', app()->getLocale())`). No skip().
3. State: memo `locale`.
4. PERF
- hydrate: `$memo['locale']` with no `??` -> undefined index warning if memo lacks it (UNSURE; correctness, not perf). `app()->setLocale` per component per request (sets translator locale, fires LocaleUpdated event dispatch each time; per component not once per request).
- dehydrate: `app()->getLocale()` + a memo string in every component snapshot ("locale":"en") -> adds bytes to every snapshot and thus to checksum + JSON per component.

---------------------------------------------------------------------
## SupportLockedProperties (`#[Locked]`)
1. Attribute blocking client updates to a property (throws CannotUpdateLockedPropertyException).
2. Files: BaseLocked.php (`update()` throws); CannotUpdateLockedPropertyException.php (report/render: 419 in prod).
3. Hooks: attribute-level `update` (invoked via SupportAttributes when a property update occurs for that property). No Support*Locked hook class. No skip().
4. State: none.
5. PERF: none of its own. The enforcement relies on the attribute system dispatching `update` for matching properties (cost lives in SupportAttributes AttributeCollection, not read in this batch). Note: for `wire:model` on nested paths the attribute match is by property name (UNSURE).

---------------------------------------------------------------------
## SupportMagicActions
1. Makes `$refresh`, `$set`, `$sync`, `$commit` valid actions that do nothing (return early so the method isn't executed; the update itself is handled elsewhere).
2. Files: SupportMagicActions.php (`provide()` registers global `on('call')` listener; static `$magicActions` list).
3. Hooks: global `call` listener only. No skip().
4. PERF
- SupportMagicActions.php:20-26: runs on EVERY method call of every component: `in_array($method, array)` (4 elements) strict-not-set. Cheap but it is yet another `on('call')` listener adding EventBus merge/iteration cost (CODEMAP: `trigger()` re-merges listener arrays each call). Note listener ordering relative to SupportJson/SupportFileUploads `on('call')` listeners.

---------------------------------------------------------------------
## SupportModels (v4 model binding: Eloquent model/collection as ID-only references)
1. Eloquent models and collections are serialised as just class + key(s) (no attributes), and rehydrated as PHP 8.4 lazy proxies that only query when touched; resolved models are shared within a request (route-bound models, other properties).
2. Files
- SupportModels.php: `provide()` registers synths (ModelSynth, EloquentCollectionSynth) and flush-state; static `$resolvedModels[class:key]`, `$staleModels`. `rememberResolvedModel`, `getResolvedModel` (:39-62): looks up local array else `app(PersistentMiddleware::class)->getResolvedRouteModel($class,$key)`; validates `exists/isDirty/wasChanged`.
- ModelSynth.php: key `mdl`. `match` instanceof Model. `dehydrate` (:20-52): lazy check, `Relation::morphMap()`, `in_array`+`array_search` on map, `getSerializedPropertyValue` (Laravel trait) if exists. `hydrate` (:54-100). `get/set/call` throw.
- EloquentCollectionSynth.php: key `elcln`. `dehydrate` (:21-57) `getQueueableClass()`, morph map, `getSerializedPropertyValue($target)` (builds a ModelIdentifier incl. relations list via `getQueueableRelations`, ids via `getQueueableIds`). `hydrate` (:59-112) -> lazy proxy -> single query for missing keys.
- IsLazy.php: trait; `isLazy()` creates `new \ReflectionClass($target)` EVERY call (:13) and calls `isUninitializedLazyObject`; `makeLazyProxy` creates `new ReflectionClass($class)` + `newLazyProxy` per model (:41-43); WeakMap `$lazyMetas` (static in trait => one per using class: UNSURE; static props declared in a trait are per-using-class so ModelSynth and EloquentCollectionSynth each have their own map).
3. Hooks: no instance hooks. Synth-only. No skip().
4. State: static `SupportModels::$resolvedModels`, `$staleModels` (grow per request until flush-state); static WeakMap lazyMetas.
5. PERF
- Synth instantiation: per CODEMAP `findByKey` instantiates a synth per property per call, so the trait/ctor overhead is per property.
- ModelSynth.php:32-36 and EloquentCollectionSynth.php:36-39 `Relation::morphMap()` + `in_array($class, $morphMap)` + `array_search` : O(size of morphMap) linear per model/collection dehydrate; `in_array` w/o strict flag (loose comparison) then `array_search` strict (two scans). Could be cached per class.
- IsLazy.php:9-13 `isLazy()` is called first in every dehydrate: PHP_VERSION_ID check then `new ReflectionClass($target)` per model/collection per dehydrate (allocation; UNCACHED). 
- ModelSynth.php:38-46 `getSerializedPropertyValue($target)` (trait from Laravel): for models builds `ModelIdentifier` via `getQueueableRelations()` which recursively walks loaded relations; plus `getQueueableConnection`, `getQueueableId`. Cost scales with loaded relations (even though only 'id' is used (:46)). For EloquentCollectionSynth:47-49 same, scanned over all models in collection to derive ids and relations; relations walk touches every model's relations: O(models x relations).
- ModelSynth.php:85-99 hydrate: lazy proxy so a DB query occurs when the property is first touched: good (saves query if never read). BUT `useWritePdo()` forces the primary connection for reads (:94, :97 collection) - cannot use replicas. `(new $class)->newQueryForRestoration($key)->firstOrFail()` = one query per distinct model property (N queries for N model props; collections batch one query/collection). Cross-property dedupe only via `resolvedModels` after the first is resolved (properties resolved sequentially each hits DB unless first resolved earlier).
- ModelSynth.php:79 + SupportModels.php:39-62 `getResolvedModel` per model hydrate: string concat key, plus on miss `app(PersistentMiddleware::class)->getResolvedRouteModel` (container resolve of mechanism each call), then `isDirty()`/`wasChanged()` on found model (`isDirty` computes `getDirty` -> compares all attributes) executed on each lookup of a cached model (every hydration of the same key + every collection element). O(attributes) per lookup.
- EloquentCollectionSynth.php:85-91 per element `array_unique($keys)` once + `getResolvedModel` per key (each w/ the PersistentMiddleware container lookup + isDirty on hits): O(n x attributes).
- EloquentCollectionSynth.php:106-110 rebuilds collection with `collect(...)->map(...)->filter()` per hydrate (O(n)); `$models[$id]` lookups fine.
- EloquentCollectionSynth.php:97 `->useWritePdo()->get()` single query; `newQueryForRestoration($missingKeys)` with `whereIn`; relations not restored (unlike legacy).
- Dehydrated `[null, $meta]`: payload small (key only), but `meta['keys']` for a collection includes ALL ids (payload grows linearly with collection size; for large collections with ids the snapshot is big and goes through HMAC + json_encode both on request and response).
- `rememberResolvedModel` (:28-37) retains every resolved model for the whole request (memory), keyed by string.
- IsLazy::getLazyMeta/setLazyMeta WeakMap: fine.
- Typical update request: every model property is re-dehydrated every request; since `isLazy()` is true for untouched proxies, `getLazyMeta` returns the original meta without querying: good fast path (PHP >= 8.4 only; PHP < 8.4 `makeLazyProxy` queries eagerly on every hydrate (:37-39 IsLazy)). Host has PHP 8.4.1 per CODEMAP.

---------------------------------------------------------------------
## JS <-> PHP contract (brief)
- download effect: `effects.download {name, content(base64), contentType}` -> js/features/supportFileDownloads.js (`on('commit')`->succeed).
- islandFragments effect + memo `islands` -> js/features/supportIslands.js:60-61; fragment markers `<!--[if FRAGMENT:type=island|name=..|token=..|mode=..]><![endif]-->` (HandlesIslands:302-320) parsed in js/fragment.js, js/island.js. Island action metadata `{island:{name,mode}}` consumed by SupportIslands::call; `__lazyLoadIsland` call magic name.
- lazy: memo `lazyLoaded` (false=placeholder, true=loaded), `lazyIsolated` -> js/component.js:199-207; placeholder attribute `x-intersect`/`x-init` = `$wire.__lazyLoad('<base64 json snapshot>')`.
- isolate: memo `isolate` -> component.js:195; request/interactions.js:4.
- json: memo `json` method names -> request/action.js:108; effect `returnsMeta` keyed by action index -> request/message.js:216-268.
- js evaluation: effect `xjs` [{expression, params}] -> js/features/supportJsEvaluation.js:26-37; `js` effects (via #[Js]) pushed as named entries.
- scriptModule effect (crc32 of filemtime) / `childScriptModules` -> js/component.js:281, js/features/supportJsModules.js.
- uploads: JS supportFileUploads.js (391 lines) drives `_startUpload` / signed URL events `upload:generatedSignedUrl`, `upload:generatedSignedUrlForS3`, `_finishUpload`, `_uploadErrored`, `_removeUpload`; paths are `hmac8:path` signed strings, snapshot value `livewire-file:<signed>` / `livewire-files:[json]`.
- memo `locale`, memo `attributes` (html attribute forwarding): server-only consumption.

## Cross-cutting per-request hook cost summary for this batch (no skip() anywhere)
Instance hook classes in this batch (new + boot + hydrate trigger per component): SupportFileDownloads, SupportFormObjects, SupportHtmlAttributeForwarding, SupportIslands, SupportIsolating, SupportJsEvaluation, SupportJsModules, SupportLazyLoading, SupportLifecycleHooks, SupportLocales (+ provide-only classes: SupportFileUploads, SupportJson, SupportLegacyModels, SupportMagicActions, SupportModels). Global `on('call')` listeners in this batch: SupportFileUploads, SupportJson, SupportMagicActions (3 closures per action call). Global `before('dehydrate')`: SupportIslands. Always-on memos added to snapshot: `locale` (always), `islands` (always, probably), plus `attributes`, `isolate`, `json`/`async`, `lazyLoaded` when applicable.
