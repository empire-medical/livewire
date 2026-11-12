'use strict'
// Client-side A/B driver. See perf/js/README.md.
let fs = require('fs')
let path = require('path')
let puppeteer = require('puppeteer-core')
let { start } = require('./server')

let args = Object.fromEntries(process.argv.slice(2).map(a => {
    let [k, v] = a.replace(/^--/, '').split('=')
    return [k, v === undefined ? true : v]
}))

let DIRS = { baseline: '/app/.perf/baseline/dist', fork: '/app/dist' }
let sideA = args.a || 'baseline'
let sideB = args.b || 'fork'
let rounds = parseInt(args.rounds || 3)
let iters = parseInt(args.iters || 7)
let cpu = parseFloat(args['cpu-throttle'] || 1)
let scenarioNames = (args.scenarios || 'ping,sort,expand10,select').split(',')
let doProfile = args.profile !== 'false'
let profIters = parseInt(args['profile-iters'] || 3)
let artifactsDir = args.artifacts || path.resolve(__dirname, '../results/js/artifacts')
let outFile = args.out || path.resolve(__dirname, '../results/js/driver-out.json')
let log = (...a) => console.error(...a)

// ping is idempotent (same response replayed gives the same DOM) so it repeats within one page load.
// The others change the DOM on the first application and are replayed as no-ops afterwards, so each
// measured sample gets a fresh page load.
let SCENARIOS = {
    ping: { reload: false, run: '$wire.ping()' },
    sort: { reload: true, run: "$wire.sort('part_number')" },
    expand10: { reload: true, run: '$wire.toggleExpanded(1)' },
    select: { reload: true, run: "$wire.$set('selected', [1, 2, 3], true)" },
}

// Installed before any page script runs.
let INSTRUMENT = `
(() => {
    let p = window.__perf = { longtasks: [], morphs: [], responds: [], pending: 0, lastActivity: 0, marks: {} }
    try { new PerformanceObserver(l => { for (let e of l.getEntries()) p.longtasks.push({ start: e.startTime, dur: e.duration }) }).observe({ type: 'longtask', buffered: true }) } catch (e) {}
    let origFetch = window.fetch
    window.fetch = function () {
        p.pending++; p.lastActivity = performance.now()
        return origFetch.apply(this, arguments).finally(() => { p.pending--; p.lastActivity = performance.now() })
    }
    document.addEventListener('livewire:init', () => { p.marks.init = performance.now()
        Livewire.hook('commit', ({ component, respond }) => { respond(() => { p.responds.push({ t: performance.now(), id: component.id }) }) })
        Livewire.hook('morphed', ({ component }) => { p.morphs.push({ t: performance.now(), id: component.id }); p.lastActivity = performance.now() })
    })
    document.addEventListener('livewire:initialized', () => { p.marks.initialized = performance.now() })
})()
`

let median = a => { let s = [...a].sort((x, y) => x - y); let n = s.length; return n % 2 ? s[(n - 1) / 2] : (s[n / 2 - 1] + s[n / 2]) / 2 }
let sleep = ms => new Promise(r => setTimeout(r, ms))

async function metrics(client) {
    let { metrics } = await client.send('Performance.getMetrics')
    return Object.fromEntries(metrics.map(m => [m.name, m.value * (m.name.endsWith('Duration') ? 1000 : 1)])) // ms
}
let METRIC_KEYS = ['TaskDuration', 'ScriptDuration', 'LayoutDuration', 'RecalcStyleDuration']
let delta = (a, b) => Object.fromEntries(METRIC_KEYS.map(k => [k, b[k] - a[k]]))

async function launch() {
    return puppeteer.launch({
        executablePath: '/usr/bin/chromium', headless: 'new',
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--headless=new', '--disable-gpu', '--window-size=1440,900',
            '--disable-extensions', '--disable-background-timer-throttling', '--disable-renderer-backgrounding',
            '--disable-backgrounding-occluded-windows', '--no-first-run', '--mute-audio'],
        defaultViewport: { width: 1440, height: 900, deviceScaleFactor: 1 },
    })
}

async function newPage(browser, origin) {
    let page = await browser.newPage()
    await page.setCacheEnabled(false)
    await page.evaluateOnNewDocument(INSTRUMENT)
    let client = await page.createCDPSession()
    await client.send('Performance.enable')
    if (cpu !== 1) await client.send('Emulation.setCPUThrottlingRate', { rate: cpu })
    page.on('pageerror', e => log('  [pageerror]', String(e).slice(0, 200)))
    return { page, client }
}

// Wait until no fetch is pending and nothing morphed for `quiet` ms.
async function settle(page, quiet = 150, timeout = 20000) {
    await page.evaluate((quiet, timeout) => new Promise((resolve, reject) => {
        let t0 = performance.now()
        let tick = () => {
            let p = window.__perf
            if (p.pending === 0 && performance.now() - p.lastActivity > quiet) return resolve()
            if (performance.now() - t0 > timeout) return reject(new Error('settle timeout'))
            setTimeout(tick, 25)
        }
        tick()
    }), quiet, timeout)
}

async function fingerprint(page) {
    return page.evaluate(() => {
        let rows = [...document.querySelectorAll('[data-testid^="row-select-"]')]
        let h = 5381, s = document.body.innerHTML.replace(/ data-has-alpine-state="true"/g, '')
        for (let i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) | 0
        return {
            xData: document.querySelectorAll('[x-data]').length,
            rows: rows.length,
            firstRowId: rows[0] ? rows[0].getAttribute('data-testid') : null,
            wireIds: document.querySelectorAll('[wire\\:id]').length,
            checked: document.querySelectorAll('input[type=checkbox]:checked').length,
            bodyLen: s.length,
            bodyHash: h,
        }
    })
}

// One page load; returns the initial-load metrics.
async function load(browser, origin, client, page, profile = null) {
    // The fixture has wire:init="ping", so every page load fires one update request: answer it with the ping response.
    await fetch(origin + '/__scenario?name=ping').then(r => r.text())
    let before = await metrics(client)
    if (profile) { await client.send('Profiler.enable'); await client.send('Profiler.setSamplingInterval', { interval: 100 }); await client.send('Profiler.start') }
    await page.goto(origin + '/', { waitUntil: 'load', timeout: 60000 })
    await page.waitForFunction('window.Livewire && window.Livewire.initialRenderIsFinished === true', { timeout: 30000 })
    await settle(page, 200)
    let prof = null
    if (profile) { prof = (await client.send('Profiler.stop')).profile }
    let after = await metrics(client)
    let r = await page.evaluate(() => {
        let p = window.__perf, nav = performance.getEntriesByType('navigation')[0]
        let lt = p.longtasks
        return {
            initMs: p.marks.init, initializedMs: p.marks.initialized,
            domContentLoadedMs: nav.domContentLoadedEventEnd, loadMs: nav.loadEventEnd,
            initPingMorphedMs: p.morphs.length ? p.morphs[0].t : null,
            longTasks: lt.length, longTaskMs: lt.reduce((a, l) => a + l.dur, 0),
            maxLongTaskMs: lt.reduce((a, l) => Math.max(a, l.dur), 0),
        }
    })
    return { ...r, ...delta(before, after), profile: prof }
}

// One interaction on a loaded page; returns timings relative to the call.
async function interact(page, client, scenarioKey, origin, profile = false) {
    await fetch(origin + '/__scenario?name=' + scenarioKey).then(r => r.text())
    await page.evaluate(() => { let p = window.__perf; p.morphs.length = 0; p.responds.length = 0 })
    if (profile) { await client.send('Profiler.enable'); await client.send('Profiler.setSamplingInterval', { interval: 100 }); await client.send('Profiler.start') }
    let before = await metrics(client)
    let r = await page.evaluate(run => new Promise((resolve, reject) => {
        let p = window.__perf
        let $wire = Livewire.first()
        let root = $wire.__instance
        let t0 = performance.now()
        let promise = eval(run)
        let wait = () => {
            let m = p.morphs.find(m => m.id === root.id)
            if (!m) return performance.now() - t0 > 20000 ? reject(new Error('no morph')) : setTimeout(wait, 0)
            requestAnimationFrame(() => {
                let tRaf = performance.now()
                resolve({ t0, toRespond: p.responds.length ? p.responds[0].t - t0 : null, toMorphed: m.t - t0, toPaint: tRaf - t0 })
            })
        }
        wait()
    }), SCENARIOS[scenarioKey].run)
    await settle(page, 150)
    let after = await metrics(client)
    let prof = null
    if (profile) prof = (await client.send('Profiler.stop')).profile
    let extra = await page.evaluate(t0 => {
        let p = window.__perf
        let lt = p.longtasks.filter(l => l.start >= t0)
        return { longTasks: lt.length, longTaskMs: lt.reduce((a, l) => a + l.dur, 0), maxLongTaskMs: lt.reduce((a, l) => Math.max(a, l.dur), 0), settledMs: p.lastActivity - t0 }
    }, r.t0)
    delete r.t0
    return { ...r, ...extra, ...delta(before, after), profile: prof }
}

function topSelf(profiles, n = 20) {
    let acc = new Map(), total = 0
    for (let profile of profiles) {
        let byId = new Map(profile.nodes.map(nd => [nd.id, nd]))
        for (let i = 0; i < profile.samples.length; i++) {
            let nd = byId.get(profile.samples[i])
            let dt = (profile.timeDeltas[i] || 0) / 1000
            let cf = nd.callFrame
            if (cf.functionName === '(idle)') continue
            let key = (cf.functionName || '(anonymous)') + ' ' + (cf.url ? cf.url.replace(/^https?:\/\/[^/]+/, '') : '') + ':' + (cf.lineNumber + 1)
            acc.set(key, (acc.get(key) || 0) + dt)
            total += dt
        }
    }
    let k = profiles.length
    let jsMs = 0
    for (let [key, ms] of acc) if (!/^\((program|garbage collector|root)\)/.test(key)) jsMs += ms
    return { totalMsPerRun: +(total / k).toFixed(1), jsSelfMsPerRun: +(jsMs / k).toFixed(1), top: [...acc.entries()].sort((a, b) => b[1] - a[1]).slice(0, n).map(([fn, ms]) => ({ fn, selfMsPerRun: +(ms / k).toFixed(2) })) }
}

async function main() {
    let sides = { A: sideA, B: sideB }
    let servers = {}
    for (let [label, name] of Object.entries(sides)) servers[label] = await start({ distDir: DIRS[name], artifactsDir })
    let samples = {} // samples[label][scenario] = {interactions:[], fingerprints:[]}; samples[label].load = []
    for (let l of Object.keys(sides)) samples[l] = { load: [] }
    let fps = {}

    for (let round = 1; round <= rounds; round++) {
        for (let label of ['A', 'B']) {
            let origin = 'http://127.0.0.1:' + servers[label].port
            log(`round ${round} side ${label} (${sides[label]})`)
            let browser = await launch()
            try {
                for (let sc of scenarioNames) {
                    let def = SCENARIOS[sc]
                    let list = (samples[label][sc] ||= { interactions: [] })
                    let { page, client } = await newPage(browser, origin)
                    samples[label].load.push(stripProf(await load(browser, origin, client, page)))
                    await interact(page, client, sc, origin) // warm-up, discarded
                    if (def.reload) { await page.close(); ({ page, client } = await newPage(browser, origin)); samples[label].load.push(stripProf(await load(browser, origin, client, page))) }
                    for (let i = 0; i < iters; i++) {
                        if (i > 0 && def.reload) { await page.close(); ({ page, client } = await newPage(browser, origin)); samples[label].load.push(stripProf(await load(browser, origin, client, page))) }
                        await sleep(150)
                        let r = stripProf(await interact(page, client, sc, origin))
                        list.interactions.push(r)
                        if (i === 0 && round === 1) fps[label + ':' + sc] = await fingerprint(page)
                    }
                    await page.close()
                }
            } finally { await browser.close() }
        }
    }

    // Initial-load fingerprint (no interaction)
    for (let label of ['A', 'B']) {
        let origin = 'http://127.0.0.1:' + servers[label].port
        let browser = await launch()
        let { page, client } = await newPage(browser, origin)
        await load(browser, origin, client, page)
        fps[label + ':initial'] = await fingerprint(page)
        await browser.close()
    }

    // Profiles (separate pass: the sampling profiler inflates timings)
    let profiles = {}
    if (doProfile) {
        for (let label of ['A', 'B']) {
            let origin = 'http://127.0.0.1:' + servers[label].port
            log('profiling side ' + label)
            let browser = await launch()
            profiles[label] = {}
            let { page, client } = await newPage(browser, origin)
            let lp = await load(browser, origin, client, page, true)
            profiles[label].load = topSelf([lp.profile])
            await page.close()
            for (let sc of scenarioNames) {
                let def = SCENARIOS[sc], profs = []
                ;({ page, client } = await newPage(browser, origin))
                await load(browser, origin, client, page)
                await interact(page, client, sc, origin)
                for (let i = 0; i < profIters; i++) {
                    if (def.reload) { await page.close(); ({ page, client } = await newPage(browser, origin)); await load(browser, origin, client, page) }
                    await sleep(150)
                    profs.push((await interact(page, client, sc, origin, true)).profile)
                }
                profiles[label][sc] = topSelf(profs)
                await page.close()
            }
            await browser.close()
        }
    }

    let stats = Object.fromEntries(Object.keys(servers).map(l => [l, servers[l].state]))
    for (let s of Object.values(servers)) s.close()

    // Summaries
    let summarize = (rows, keys) => Object.fromEntries(keys.map(k => {
        let v = rows.map(r => r[k]).filter(x => x != null)
        return [k, { median: +median(v).toFixed(2), min: +Math.min(...v).toFixed(2), max: +Math.max(...v).toFixed(2), n: v.length }]
    }))
    let LOAD_KEYS = ['initializedMs', 'initPingMorphedMs', 'domContentLoadedMs', 'loadMs', 'TaskDuration', 'ScriptDuration', 'LayoutDuration', 'RecalcStyleDuration', 'longTasks', 'longTaskMs', 'maxLongTaskMs']
    let INT_KEYS = ['toRespond', 'toMorphed', 'toPaint', 'settledMs', 'TaskDuration', 'ScriptDuration', 'LayoutDuration', 'RecalcStyleDuration', 'longTasks', 'longTaskMs', 'maxLongTaskMs']
    let summary = { meta: { sides, rounds, iters, cpu, scenarios: scenarioNames, chromium: await versionOf(), time: new Date().toISOString() }, load: {}, scenarios: {}, fingerprints: fps, serverStats: stats, profiles }
    for (let l of ['A', 'B']) {
        summary.load[l] = summarize(samples[l].load, LOAD_KEYS)
        for (let sc of scenarioNames) {
            summary.scenarios[sc] ||= {}
            summary.scenarios[sc][l] = summarize(samples[l][sc].interactions, INT_KEYS)
        }
    }
    summary.raw = samples
    fs.mkdirSync(path.dirname(outFile), { recursive: true })
    fs.writeFileSync(outFile, JSON.stringify(summary, null, 1))
    log('wrote ' + outFile)
}

let stripProf = r => { delete r.profile; return r }
async function versionOf() { let b = await launch(); let v = await b.version(); await b.close(); return v }

main().catch(e => { console.error(e); process.exit(1) })
