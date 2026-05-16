'use strict';

const path = require('path');
const fs = require('fs');
const http = require('http');
const puppeteer = require('puppeteer');
const config = require('./config');

let templateServer = null;
let templatePort = 0;

async function getTemplateServer() {
  if (templateServer) return templatePort;

  const templateDir = path.dirname(config.worker.templatePath);

  return new Promise((resolve, reject) => {
    templateServer = http.createServer((req, res) => {
      const urlPath =
        req.url.split('?')[0].replace(/^\/+/, '') || 'index.html';

      const safeName = path.basename(urlPath);
      const filePath = path.join(templateDir, safeName);

      if (!fs.existsSync(filePath)) {
        res.writeHead(404);
        return res.end('Not found');
      }

      const ext = path.extname(filePath).toLowerCase();

      const mime = {
        '.html': 'text/html',
        '.js': 'application/javascript',
        '.css': 'text/css',
      }[ext] || 'application/octet-stream';

      res.writeHead(200, {
        'Content-Type': mime,
        'Cache-Control': 'no-store',
      });

      fs.createReadStream(filePath).pipe(res);
    });

    templateServer.listen(0, '127.0.0.1', () => {
      templatePort = templateServer.address().port;

      console.log(
        `[renderer] template server started on ${templatePort}`
      );

      resolve(templatePort);
    });

    templateServer.on('error', reject);
  });
}

function closeTemplateServer() {
  return new Promise((resolve) => {
    if (!templateServer) return resolve();

    templateServer.close(() => {
      templateServer = null;
      resolve();
    });
  });
}

let browser = null;

async function getBrowser() {
  if (browser && browser.isConnected()) {
    return browser;
  }

  browser = await puppeteer.launch({
    headless: 'new',

    defaultViewport: null,

    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--font-render-hinting=medium',
      '--force-color-profile=srgb',
      '--disable-web-security',
      '--allow-running-insecure-content',
    ],
  });

  return browser;
}

async function closeBrowser() {
  if (browser) {
    await browser.close().catch(() => {});
    browser = null;
  }

  await closeTemplateServer();
}

function resolveRowData(rawData, columnMap) {
  const resolved = {};

  for (const [placeholder, csvHeader] of Object.entries(columnMap)) {
    resolved[placeholder] = rawData[csvHeader] ?? '';
  }

  return resolved;
}

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
  const browser = await getBrowser();

  const EXPORT_SCALE = 3;

  const renderWidth = Math.round(widthPx * EXPORT_SCALE);
  const renderHeight = Math.round(heightPx * EXPORT_SCALE);

  const page = await browser.newPage();

  try {
    await page.setViewport({
      width: renderWidth,
      height: renderHeight,
      deviceScaleFactor: 1,
    });

    const port = await getTemplateServer();

    await page.goto(`http://127.0.0.1:${port}/index.html`, {
      waitUntil: 'networkidle0',
      timeout: 30000,
    });

    page.on('console', (msg) => {
      console.log(`[browser:${msg.type()}] ${msg.text()}`);
    });

    page.on('pageerror', (err) => {
      console.error('[browser:error]', err);
    });

    const resolvedData = resolveRowData(rawRowData, columnMap);

    const job = {
      canvasJson,
      resolvedData,
      width: widthPx,
      height: heightPx,
      imageMap,
      exportScale: EXPORT_SCALE,
    };

    await page.evaluate((payload) => {
      window.__bdpDone = null;
      window.renderRow(payload);
    }, job);

    const doneHandle = await page.waitForFunction(
      () => {
        return window.__bdpDone || false;
      },
      {
        timeout: 30000,
        polling: 100,
      }
    );

    const done = await doneHandle.jsonValue();

    if (!done.ok) {
      throw new Error(done.error || 'render failed');
    }

    await page.evaluate(() => {
      return new Promise((resolve) => {
        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            requestAnimationFrame(resolve);
          });
        });
      });
    });

    const isPdf =
      outputFormat === 'pdf' || outputFormat === 'zip_pdf';

    if (isPdf) {
      const pdfBuffer = await page.pdf({
        width: `${renderWidth}px`,
        height: `${renderHeight}px`,
        printBackground: true,
        preferCSSPageSize: true,
        margin: {
          top: 0,
          right: 0,
          bottom: 0,
          left: 0,
        },
      });

      fs.writeFileSync(outputPath, pdfBuffer);
    } else {
      await page.screenshot({
        path: outputPath,
        type: 'png',

        clip: {
          x: 0,
          y: 0,
          width: renderWidth,
          height: renderHeight,
        },

        omitBackground: false,
      });
    }

    console.log('[renderer] file generated:', outputPath);
  } finally {
    await page.close().catch(() => {});
  }
}

module.exports = {
  renderRow,
  closeBrowser,
};