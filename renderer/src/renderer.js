'use strict';

const path      = require('path');
const fs        = require('fs');
const http      = require('http');
const puppeteer = require('puppeteer');
const config    = require('./config');

// ── Local HTTP server for the render template ─────────────────────────────────
let templateServer = null;
let templatePort   = 0;

async function getTemplateServer() {
    if (templateServer) return templatePort;

    const templateDir = path.dirname(config.worker.templatePath);

    return new Promise((resolve, reject) => {
        templateServer = http.createServer((req, res) => {
            // Strip query string and leading slash safely
            const urlPath  = req.url.split('?')[0].replace(/^\/+/, '') || 'index.html';
            // Prevent directory traversal
            const safeName = path.basename(urlPath) || 'index.html';
            const filePath = path.join(templateDir, safeName);

            if (!fs.existsSync(filePath)) {
                res.writeHead(404);
                res.end('Not found: ' + safeName);
                return;
            }

            const ext  = path.extname(filePath).toLowerCase();
            const mime = {
                '.html': 'text/html',
                '.js':   'application/javascript',
                '.css':  'text/css',
            }[ext] || 'application/octet-stream';

            res.writeHead(200, {
                'Content-Type':  mime,
                'Cache-Control': 'no-store',
            });
            fs.createReadStream(filePath).pipe(res);
        });

        templateServer.listen(0, '127.0.0.1', () => {
            templatePort = templateServer.address().port;
            console.log(`[renderer] Template server listening on port ${templatePort}`);
            resolve(templatePort);
        });

        templateServer.on('error', reject);
    });
}

function closeTemplateServer() {
    return new Promise(resolve => {
        if (templateServer) {
            templateServer.close(() => { templateServer = null; resolve(); });
        } else {
            resolve();
        }
    });
}

// ── Module-level browser instance (reused across rows in the same job) ────────
let browser = null;

async function getBrowser() {
    if (!browser || !browser.isConnected()) {
        browser = await puppeteer.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-features=VizDisplayCompositor',
                '--disable-background-timer-throttling',
                '--disable-backgrounding-occluded-windows',
                '--disable-renderer-backgrounding',
                // Allow loading images from Cloudinary (cross-origin)
                '--disable-web-security',
                '--allow-running-insecure-content',
            ],
        });
    }
    return browser;
}

async function closeBrowser() {
    if (browser) {
        await browser.close().catch(() => {});
        browser = null;
    }
    await closeTemplateServer();
}

/**
 * Resolve row data by applying column_map to raw CSV row data.
 * column_map:  { "name": "Student Name", "photo": "Photo Filename" }
 * rawRowData:  { "Student Name": "John", "Photo Filename": "john.jpg" }
 * → resolved:  { "name": "John", "photo": "john.jpg" }
 */
function resolveRowData(rawData, columnMap) {
    const resolved = {};
    for (const [placeholder, csvHeader] of Object.entries(columnMap)) {
        resolved[placeholder] = rawData[csvHeader] ?? '';
    }
    return resolved;
}

/**
 * Render a single row to a PNG or PDF file.
 */
async function renderRow({
    canvasJson,
    columnMap,
    rawRowData,
    widthPx,
    heightPx,
    imageMap = {},
    outputFormat,
    outputPath,
}) {
    const br   = await getBrowser();
    const page = await br.newPage();

    try {
        // FIX 1: deviceScaleFactor: 1 so screenshot pixels match canvas pixels exactly.
        // Using 2 doubles the image dimensions and breaks the output size.
        await page.setViewport({ width: widthPx, height: heightPx, deviceScaleFactor: 1 });

        const port        = await getTemplateServer();
        const templateUrl = `http://127.0.0.1:${port}/index.html`;

        await page.goto(templateUrl, { waitUntil: 'networkidle0', timeout: 30_000 });

        // Forward browser logs to Node stdout
        page.on('console', msg => {
            console.log(`  [browser ${msg.type()}] ${msg.text()}`);
        });
        page.on('pageerror', err => {
            console.error(`  [browser pageerror] ${err.message}`);
        });

        const resolvedData = resolveRowData(rawRowData, columnMap);

        const job = {
            canvasJson,
            resolvedData,
            width:    widthPx,
            height:   heightPx,
            imageMap,
        };

        // FIX 2: Kick off the render but don't await it here — it signals
        // completion via window.__bdpDone which we poll below.
        await page.evaluate((j) => {
            window.__bdpDone = null;   // reset in case of page reuse
            window.renderRow(j);       // intentionally not awaited inside evaluate
        }, job);

        // FIX 3: Poll for completion. waitForFunction returns a JSHandle
        // wrapping the return value of the predicate, not the window value.
        // We need to explicitly return the __bdpDone object from the predicate.
        const doneHandle = await page.waitForFunction(
            () => window.__bdpDone !== null ? window.__bdpDone : false,
            { timeout: 20_000, polling: 100 }
        );

        // FIX 4: jsonValue() gives us the actual {ok, error} object.
        const done = await doneHandle.jsonValue();

        if (!done || !done.ok) {
            const errMsg = (done && done.error) ? done.error : 'Unknown render error';
            throw new Error(`Canvas render failed: ${errMsg}`);
        }

        // Allow a short settle for any async image loads inside Fabric
        await new Promise(r => setTimeout(r, 200));

        // Debug: report how many Fabric objects were rendered
        const objectCount = await page.evaluate(() => {
            const c = window.__bdpFabricCanvas;
            return c ? c.getObjects().length : -1;
        }).catch(() => -1);
        console.log(`  [renderer] Fabric objects: ${objectCount}`);

        const isPdf = outputFormat === 'pdf' || outputFormat === 'zip_pdf';

        if (isPdf) {
            const pdfBuffer = await page.pdf({
                width:           `${widthPx}px`,
                height:          `${heightPx}px`,
                printBackground: true,
                margin:          { top: 0, right: 0, bottom: 0, left: 0 },
            });
            fs.writeFileSync(outputPath, pdfBuffer);
        } else {
            // FIX 5: Screenshot the canvas element directly with exact clip rect.
            // This is more reliable than page.screenshot() which can grab
            // extra whitespace, and avoids issues with deviceScaleFactor.
            const canvasEl = await page.$('#c');
            if (!canvasEl) {
                // Fallback: screenshot the viewport clipped to canvas size
                await page.screenshot({
                    path: outputPath,
                    type: 'png',
                    clip: { x: 0, y: 0, width: widthPx, height: heightPx },
                });
            } else {
                await canvasEl.screenshot({
                    path: outputPath,
                    type: 'png',
                    omitBackground: false,
                });
            }
        }

        console.log(`  [renderer] Written to ${outputPath}`);

    } finally {
        await page.close().catch(() => {});
    }
}

module.exports = { renderRow, closeBrowser };