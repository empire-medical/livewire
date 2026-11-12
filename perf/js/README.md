# Client-side (browser) A/B harness

Measures Livewire's JS cost for baseline (v4.4.8 worktree, `.perf/baseline/dist`) vs fork (`dist/`) on the Orders Manage fixture,
deterministically and without a PHP server at run time.

## Run

```bash
perf/js/run.sh                       # build fork dist, record artifacts if missing, 3 rounds x 7 iterations, print table
perf/js/run.sh --aa                  # baseline vs baseline: noise check
perf/js/run.sh --rerecord            # re-record artifacts (needed if the fork's PHP output changed, or the fixture did)
perf/js/run.sh --cpu-throttle=4 --scenarios=ping,sort --rounds=5 --iters=9 --no-profile
```
Run from the repo root. Needs docker (services `app`, `browser`). JSON goes to `perf/results/js/<timestamp>.json`
(raw samples included). Re-print a table: `docker compose -f docker/docker-compose.yml run --rm app node perf/js/compare.js /app/perf/results/js/<file>.json --profiles`.
A full run is roughly 10 minutes.

## Pieces

- `RecordArtifactsTest.php` (PHP, Testbench): renders `GET /perf/orders-manage` through the real route and records
  `perf/results/js/artifacts/page.html` (script src rewritten to `/dist/livewire.js`, `data-update-uri="/livewire/update"`)
  and `ping|sort|expand10|select.json` (exact `{components:[{snapshot,effects}],assets}` envelopes from `HandleRequests::handleUpdate`)
  plus `lazy.json` (responses for each lazy child's `__lazyLoad`, keyed by snapshot `memo.id`). Both sides replay the same bytes.
  Artifacts are produced by the fork's PHP; the checksum is irrelevant at run time because nothing verifies it.
  The fixture's `sort()` never reorders rows (all rows tie on vendor), so for the `sort` scenario the recorder adds a temporary
  global scope that reverses the same 200 rows (every keyed row moves).
- `server.js`: static server, no delay. Serves the page, the side's dist dir under `/dist/`, answers `POST /livewire/update`
  with the current scenario's recorded JSON (set by the driver via `/__scenario?name=`), or with lazy responses when every
  component in the request is a known lazy child.
- `driver.js` (puppeteer-core 24.10.0 pinned, `/usr/bin/chromium`, headless new, 1440x900, no GPU, cache off): see below.
- `compare.js`: prints the comparison. `run.sh`: orchestration.

## Method

Per round, per side (A then B, so A,B,A,B,A,B): fresh browser; per scenario a fresh page, one discarded warm-up interaction, then `--iters` measured ones.
`ping` repeats within one page (the replayed response gives an identical DOM). `sort`, `expand10`, `select` change the DOM on first
application, so every sample gets a fresh page load. Statistics are pooled over rounds: median, min, max. A separate profiling pass
(the sampling profiler inflates timings, so it is never mixed with timing runs) runs 3 profiled interactions per scenario plus one page load.

The fixture has `wire:init="ping"`, so every page load also does one update round trip (answered with the `ping` recording); it is part of "initial load".

## What the numbers mean (ms)

Initial load (per page load):
- `initializedMs`: navigationStart to the `livewire:initialized` event (HTML parse + Livewire/Alpine start, sync part).
- `initPingMorphedMs`: navigationStart to the end of the morph for the `wire:init` ping response.
- `domContentLoadedMs`, `loadMs`: navigation timing.
- `TaskDuration`, `ScriptDuration`, `LayoutDuration`, `RecalcStyleDuration`: CDP `Performance.getMetrics` deltas from before navigation until the page is quiet (no fetch pending and no morph for 200ms). TaskDuration = all main-thread task time.
- `longTasks`, `longTaskMs`, `maxLongTaskMs`: PerformanceObserver `longtask` (>50ms) entries.

Per interaction (call of the `$wire` action, at t0):
- `toRespond`: t0 to the `respond` callback of the `commit` hook. In 4.4.8 this fires after the morph, so it is about equal to `toMorphed`; kept only as a cross-check.
- `toMorphed`: t0 to the root component's `morphed` hook. This is the headline "apply a response" number (includes request build, fetch, JSON parse of ~3 MB, morph, Alpine init).
- `toPaint`: t0 to one `requestAnimationFrame` after `morphed`.
- `settledMs`: t0 until no fetch is pending and no morph for 150ms (matters for `expand10`, which triggers lazy child loads for visible placeholders).
- `TaskDuration`/`ScriptDuration`/`LayoutDuration`/`RecalcStyleDuration`: getMetrics deltas from before the call to settled.
  CAUTION: `ScriptDuration` badly undercounts JS that runs from promise/fetch continuations (about 5 ms for a 380 ms morph);
  trust `TaskDuration` and the profiler's `jsSelfMsPerRun` instead.
- `longTasks*`: long tasks starting after t0.

Profiles: top self-time functions per run (`url:line` refers to the unminified `/dist/livewire.js`). `(program)` is native
time (HTML parsing, DOM, JSON.parse, style/layout); `jsSelfMsPerRun` excludes `(program)` and GC.

Sanity output: fingerprint of each final DOM (x-data count, row count, first row id, `wire:id` count, checked boxes, innerHTML length+hash).
With identical dist both sides must report IDENTICAL. For A/B runs a DIFFERENT fingerprint means the fork changes the DOM.

## Caveats

Synthetic page, replayed payloads, local loopback: no network or server cost. Absolute numbers depend on the machine; compare
A vs B within one run. The `--cpu-throttle=N` flag uses CDP CPU throttling (Emulation.setCPUThrottlingRate).
