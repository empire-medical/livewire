'use strict'
// Prints a baseline-vs-fork comparison table from a driver output JSON.  usage: node compare.js <result.json> [--profiles]
let fs = require('fs')
let d = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'))
let showProfiles = process.argv.includes('--profiles')
let A = d.meta.sides.A, B = d.meta.sides.B
let pad = (s, n) => String(s).padEnd(n), lpad = (s, n) => String(s).padStart(n)
let pct = (a, b) => (a === 0 ? (b === 0 ? '0.0%' : 'n/a') : ((b - a) / a * 100 >= 0 ? '+' : '') + ((b - a) / a * 100).toFixed(1) + '%')
let spread = m => m.median ? ((m.max - m.min) / m.median * 100).toFixed(0) + '%' : '-'

console.log(`\nA = ${A}   B = ${B}   rounds=${d.meta.rounds} iters=${d.meta.iters} cpu-throttle=${d.meta.cpu}x   ${d.meta.chromium}   ${d.meta.time}`)
console.log('All times ms (median over pooled samples). %chg = (B-A)/A. spread = (max-min)/median within each side.\n')

function table(title, a, b, keys) {
    console.log(title)
    console.log(pad('metric', 22) + lpad(`A:${A}`, 14) + lpad(`B:${B}`, 14) + lpad('%chg', 9) + lpad('A min..max', 18) + lpad('B min..max', 18) + lpad('spreadA', 9) + lpad('spreadB', 9))
    for (let k of keys) {
        if (!a[k] || !b[k]) continue
        console.log(pad(k, 22) + lpad(a[k].median, 14) + lpad(b[k].median, 14) + lpad(pct(a[k].median, b[k].median), 9) +
            lpad(a[k].min + '..' + a[k].max, 18) + lpad(b[k].min + '..' + b[k].max, 18) + lpad(spread(a[k]), 9) + lpad(spread(b[k]), 9))
    }
    console.log()
}
table(`INITIAL LOAD (n=${d.load.A.initializedMs.n} loads/side; includes the fixture's wire:init ping round trip)`, d.load.A, d.load.B,
    ['initializedMs', 'initPingMorphedMs', 'domContentLoadedMs', 'loadMs', 'TaskDuration', 'ScriptDuration', 'LayoutDuration', 'RecalcStyleDuration', 'longTasks', 'longTaskMs', 'maxLongTaskMs'])
for (let sc of d.meta.scenarios) {
    let s = d.scenarios[sc]
    table(`SCENARIO ${sc} (n=${s.A.toMorphed.n}/side)`, s.A, s.B,
        ['toRespond', 'toMorphed', 'toPaint', 'settledMs', 'TaskDuration', 'ScriptDuration', 'LayoutDuration', 'RecalcStyleDuration', 'longTasks', 'longTaskMs', 'maxLongTaskMs'])
}

console.log('FINAL DOM (fingerprint of body.innerHTML after the interaction; A vs B)')
for (let sc of ['initial', ...d.meta.scenarios]) {
    let a = d.fingerprints['A:' + sc], b = d.fingerprints['B:' + sc]
    let same = JSON.stringify(a) === JSON.stringify(b)
    console.log(`  ${pad(sc, 10)} ${same ? 'IDENTICAL' : 'DIFFERENT'}  x-data=${a.xData}/${b.xData} rows=${a.rows}/${b.rows} firstRow=${a.firstRowId}/${b.firstRowId} wire:id=${a.wireIds}/${b.wireIds} checked=${a.checked}/${b.checked} bodyLen=${a.bodyLen}/${b.bodyLen}`)
}
console.log('\nserver stats', JSON.stringify(d.serverStats))

if (showProfiles && d.profiles && d.profiles.A) {
    for (let sc of ['load', ...d.meta.scenarios]) {
        for (let l of ['A', 'B']) {
            let p = d.profiles[l][sc]
            console.log(`\nPROFILE ${sc} side ${l}=${d.meta.sides[l]}: sampled ${p.totalMsPerRun} ms/run (JS self excl. (program)/GC: ${p.jsSelfMsPerRun})  top self-time:`)
            p.top.slice(0, 20).forEach((t, i) => console.log(`  ${lpad(i + 1, 2)}. ${lpad(t.selfMsPerRun, 8)} ms  ${t.fn}`))
        }
    }
}
