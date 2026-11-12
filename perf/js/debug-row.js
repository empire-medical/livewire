'use strict'
// Dump the first data row's HTML after a ping, for A/B DOM diffs. usage (browser container): node perf/js/debug-row.js fork|baseline OUTFILE
let puppeteer = require('puppeteer-core'); let { start } = require('./server'); let fs = require('fs')
let side = process.argv[2]
;(async () => {
    let srv = await start({ distDir: side === 'fork' ? '/app/dist' : '/app/.perf/baseline/dist', artifactsDir: '/app/perf/results/js/artifacts' })
    let browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', headless: 'new', args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'] })
    let page = await browser.newPage()
    await page.goto(`http://127.0.0.1:${srv.port}/`, { waitUntil: 'load' })
    await page.waitForFunction(() => window.Livewire && window.Livewire.initialRenderIsFinished, { timeout: 20000 })
    await new Promise(r => setTimeout(r, 1500))
    let html = await page.evaluate(() => {
        let tr = document.querySelector('[data-testid^="row-select-"]').closest('tr')
        return tr.outerHTML + '\n\n=====NEXT=====\n\n' + tr.nextElementSibling.outerHTML
    })
    fs.writeFileSync(process.argv[3], html)
    await browser.close(); srv.server.close()
})().catch(e => { console.error(e); process.exit(1) })
