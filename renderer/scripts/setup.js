#!/usr/bin/env node
/**
 * BDP Renderer — setup script
 * ============================
 * Run once after `npm install`:
 *   node scripts/setup.js
 *
 * What it does:
 *   1. Copies fabric.min.js from node_modules into render-template/
 *      so the HTML template can load it via file:// (no network needed)
 *   2. Verifies Puppeteer can launch Chrome
 *   3. Prints next steps
 */

'use strict';

const path = require('path');
const fs   = require('fs');

async function main() {
    console.log('\nBDP Renderer — setup\n');

    // ── 1. Copy fabric.min.js ─────────────────────────────────────────────────
    const fabricSrc = path.resolve(__dirname, '../node_modules/fabric/dist/fabric.min.js');
    const fabricDst = path.resolve(__dirname, '../render-template/fabric.min.js');

    if (!fs.existsSync(fabricSrc)) {
        console.error('✗ fabric not found. Run: npm install fabric --save-dev');
        console.error('  (fabric is a devDependency for the renderer — the editor already uses it)');
        process.exit(1);
    }

    fs.copyFileSync(fabricSrc, fabricDst);
    const fabricSize = (fs.statSync(fabricDst).size / 1024).toFixed(0);
    console.log(`✓ fabric.min.js copied (${fabricSize} KB) → render-template/`);

    // ── 2. Verify Puppeteer / Chrome ──────────────────────────────────────────
    console.log('\nLaunching Puppeteer test...');
    let puppeteer;
    try {
        puppeteer = require('puppeteer');
    } catch {
        console.error('✗ puppeteer not installed. Run: npm install');
        process.exit(1);
    }

    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu'],
        });
        const page = await browser.newPage();
        await page.setContent('<h1>BDP test</h1>');
        const title = await page.title();
        await browser.close();
        console.log('✓ Puppeteer launched successfully');
    } catch (err) {
        if (browser) await browser.close().catch(() => {});
        console.error('✗ Puppeteer launch failed:', err.message);
        console.error('\nOn Windows, try:');
        console.error('  npx puppeteer browsers install chrome');
        process.exit(1);
    }

    // ── 3. Check .env ─────────────────────────────────────────────────────────
    const envPath = path.resolve(__dirname, '../../.env');
    if (!fs.existsSync(envPath)) {
        console.warn('\n⚠ No .env found at', envPath);
        console.warn('  Copy .env.example and fill in DB + Cloudinary credentials');
    } else {
        console.log('✓ .env found');
    }

    // ── 4. Summary ────────────────────────────────────────────────────────────
    console.log(`
╔══════════════════════════════════════════════════════════════╗
║  Setup complete — next steps                                 ║
╠══════════════════════════════════════════════════════════════╣
║                                                              ║
║  Test a single row (no DB polling, instant feedback):        ║
║    node src/render-one.js --project=1 --row=0               ║
║                                                              ║
║  Start the worker (polls DB every 4 seconds):                ║
║    npm start                                                 ║
║                                                              ║
║  Watch mode (auto-restarts on file change):                  ║
║    npm run dev                                               ║
║                                                              ║
║  The worker logs JSON to stdout. Pretty-print with:          ║
║    npm start | npx pino-pretty                               ║
║                                                              ║
╚══════════════════════════════════════════════════════════════╝
`);
}

main().catch(err => {
    console.error('Setup failed:', err.message);
    process.exit(1);
});
