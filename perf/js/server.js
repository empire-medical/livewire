'use strict'
// Tiny static server for the A/B harness. No framework, zero artificial delay.
//   GET  /                   -> artifacts/page.html
//   GET  /dist/<file>        -> files from the side's dist directory
//   POST /livewire/update    -> recorded JSON. If every component in the request is a known lazy child
//                               (keyed by snapshot memo.id, from lazy.json) the lazy responses are merged;
//                               otherwise the response for the *current scenario* is returned.
//   GET  /__scenario?name=x  -> set the current scenario (the driver does this before each interaction)
//   GET  /__stats            -> request counters
let http = require('http')
let fs = require('fs')
let path = require('path')

exports.start = function start({ distDir, artifactsDir, port = 0 }) {
    let page = fs.readFileSync(path.join(artifactsDir, 'page.html'))
    let lazy = JSON.parse(fs.readFileSync(path.join(artifactsDir, 'lazy.json'), 'utf8'))
    let scenarios = {}
    for (let f of fs.readdirSync(artifactsDir)) {
        if (!f.endsWith('.json') || f === 'lazy.json') continue
        scenarios[f.replace(/\.json$/, '')] = fs.readFileSync(path.join(artifactsDir, f))
    }

    let state = { scenario: 'ping', updates: 0, lazyUpdates: 0, scenarioUpdates: 0 }
    let files = {}
    let fileFor = name => files[name] || (files[name] = fs.readFileSync(path.join(distDir, name)))

    let server = http.createServer((req, res) => {
        let url = new URL(req.url, 'http://x')
        let send = (code, type, body) => {
            res.writeHead(code, { 'Content-Type': type, 'Content-Length': Buffer.byteLength(body), 'Cache-Control': 'no-store' })
            res.end(body)
        }

        if (req.method === 'POST' && url.pathname === '/livewire/update') {
            let chunks = []
            req.on('data', c => chunks.push(c))
            req.on('end', () => {
                state.updates++
                let body
                try { body = JSON.parse(Buffer.concat(chunks).toString('utf8')) } catch (e) { return send(400, 'text/plain', 'bad json') }
                let ids = (body.components || []).map(c => { try { return JSON.parse(c.snapshot).memo.id } catch (e) { return null } })
                if (ids.length && ids.every(id => lazy[id])) {
                    state.lazyUpdates++
                    let merged = { components: ids.flatMap(id => lazy[id].components), assets: [] }
                    return send(200, 'application/json', JSON.stringify(merged))
                }
                state.scenarioUpdates++
                send(200, 'application/json', scenarios[state.scenario])
            })
            return
        }
        if (url.pathname === '/__scenario') { state.scenario = url.searchParams.get('name'); return send(200, 'text/plain', 'ok') }
        if (url.pathname === '/__stats') return send(200, 'application/json', JSON.stringify(state))
        if (url.pathname === '/' || url.pathname === '/perf/orders-manage') return send(200, 'text/html; charset=utf-8', page)
        if (url.pathname.startsWith('/dist/')) {
            let name = path.basename(url.pathname)
            try { return send(200, name.endsWith('.map') ? 'application/json' : 'text/javascript; charset=utf-8', fileFor(name)) } catch (e) { return send(404, 'text/plain', 'nf') }
        }
        send(404, 'text/plain', 'nf')
    })
    server.keepAliveTimeout = 60000

    return new Promise(resolve => server.listen(port, '127.0.0.1', () => resolve({
        port: server.address().port, state, server, close: () => server.close(),
    })))
}
