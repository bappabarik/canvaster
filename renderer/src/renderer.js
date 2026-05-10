'use strict';

const path      = require('path');
const fs        = require('fs');
const http      = require('http');
const puppeteer = require('puppeteer');
const config    = require('./config');

// ── Local HTTP server for the render template ─────────────────────────────────
// Serving via http:// instead of file:// means Cloudinary image URLs
// load without needing --disable-web-security.
let templateServer = null;
let templatePort   = 0;

async function getTemplateServer() {
    if (templateServer) return templatePort;

    const templateDir = path.dirname(config.worker.templatePath);

    return new Promise((resolve, reject) => {
        templateServer = http.createServer((req, res) => {
            // Only serve files from the template directory
            const safeName = path.basename(req.url.split('?')[0]) || 'index.html';
            const filePath = path.join(templateDir, safeName);

            if (!fs.existsSync(filePath)) {
                res.writeHead(404); res.end('Not found'); return;
            }

            const ext = path.extname(filePath).toLowerCase();
            const mime = {
                '.html': 'text/html',
                '.js':   'application/javascript',
                '.css':  'text/css',
            }[ext] || 'application/octet-stream';

            res.writeHead(200, { 'Content-Type': mime });
            fs.createReadStream(filePath).pipe(res);
        });

        templateServer.listen(0, '127.0.0.1', () => {
            templatePort = templateServer.address().port;
            resolve(templatePort);
        });

        templateServer.on('error', reject);
    });
}

function closeTemplateServer() {
    return new Promise(resolve => {
        if (templateServer) { templateServer.close(resolve); templateServer = null; }
        else resolve();
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
                '--disable-features=VizDisplayCompositor',  // Windows stability
                '--disable-background-timer-throttling',
                '--disable-backgrounding-occluded-windows',
                '--disable-renderer-backgrounding',
            ],
        });
    }
    return browser;
}

/**
 * Close the browser. Called after a job completes or on worker shutdown.
 */
async function closeBrowser() {
    if (browser) {
        await browser.close().catch(() => {});
        browser = null;
    }
    await closeTemplateServer();
}

/**
 * Resolve row data by applying the column_map to the raw CSV row data.
 *
 * column_map:   { "name": "Student Name", "photo": "Photo Filename" }
 * row.data:     { "Student Name": "John", "Photo Filename": "john.jpg" }
 * → resolved:   { "name": "John", "photo": "john.jpg" }
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
 *
 * @param {Object} params
 * @param {string} params.canvasJson        - Fabric.js canvas JSON string
 * @param {Object} params.columnMap         - { placeholder → csvHeader }
 * @param {Object} params.rawRowData        - Raw CSV row { csvHeader → value }
 * @param {number} params.widthPx
 * @param {number} params.heightPx
 * @param {Object} params.imageMap          - { filename → cloudinaryUrl }
 * @param {'png'|'pdf'|'zip_png'|'zip_pdf'} params.outputFormat
 * @param {string} params.outputPath        - Absolute path to write the output file
 *
 * @returns {Promise<void>}
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
        // Set viewport to exact canvas size (critical for screenshot accuracy)
        await page.setViewport({ width: widthPx, height: heightPx, deviceScaleFactor: 2 });

        // Load the render template via local HTTP server (avoids CORS on Cloudinary images)
        const port = await getTemplateServer();
        const templateUrl = `http://127.0.0.1:${port}/index.html`;
        await page.goto(templateUrl, { waitUntil: 'networkidle0', timeout: 30_000 });

        // Bridge browser console.log → Node stdout for debugging
        page.on('console', msg => {
            console.log(`  [browser ${msg.type()}] ${msg.text()}`);
        });
        page.on('pageerror', err => {
            console.error(`  [browser error] ${err.message}`);
        });

        // Resolve row data
        const resolvedData = resolveRowData(rawRowData, columnMap);

        // Build the job payload for the page
        const job = {
            canvasJson,
            resolvedData,
            width:    widthPx,
            height:   heightPx,
            imageMap,
        };

        // Call window.renderRow() inside the page
        await page.evaluate((j) => window.renderRow(j), job);

        // Wait for rendering to complete (max 15 seconds)
        const result = await page.waitForFunction(
            () => window.__bdpDone !== null,
            { timeout: 15_000 }
        );

        const done = await result.jsonValue();
        if (!done) {
            throw new Error(`Canvas render error: ${done.error}`);
        }

        // Debug: report how many objects Fabric loaded
        const objectCount = await page.evaluate(() => {
            const c = window.__bdpFabricCanvas;
            return c ? c.getObjects().length : -1;
        });
        console.log(`  [renderer] Fabric objects loaded: ${objectCount}`);

        // Small settle time for image loads — usually instant but safe guard
        await new Promise(r => setTimeout(r, 150));

        const isPdf = outputFormat === 'pdf' || outputFormat === 'zip_pdf';

        if (isPdf) {
            // PDF output — full-bleed, no margins
            const pdfBuffer = await page.pdf({
                width:             `${widthPx}px`,
                height:            `${heightPx}px`,
                printBackground:   true,
                margin:            { top: 0, right: 0, bottom: 0, left: 0 },
            });
            fs.writeFileSync(outputPath, pdfBuffer);
        } else {
            // PNG output — clip to exact canvas dimensions
            const canvasEl = await page.$('#c');
            if (!canvasEl) throw new Error('Canvas element not found in rendered page');

            await canvasEl.screenshot({
                path: outputPath,
                type: 'png',
                omitBackground: false,
            });
        }
    } finally {
        await page.close().catch(() => {});
    }
}

module.exports = { renderRow, closeBrowser };