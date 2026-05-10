'use strict';

const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '../../.env') });

function required(key) {
    const val = process.env[key];
    if (!val) throw new Error(`Missing required env var: ${key}`);
    return val;
}

function optional(key, fallback = '') {
    return process.env[key] ?? fallback;
}

module.exports = {
    db: {
        host:     optional('DB_HOST', '127.0.0.1'),
        port:     parseInt(optional('DB_PORT', '3306'), 10),
        database: optional('DB_NAME', 'bdp'),
        user:     optional('DB_USER', 'root'),
        password: optional('DB_PASS', ''),
    },

    cloudinary: {
        cloud_name: required('CLOUDINARY_CLOUD_NAME'),
        api_key:    required('CLOUDINARY_API_KEY'),
        api_secret: required('CLOUDINARY_API_SECRET'),
    },

    worker: {
        // How often the worker polls for new queued jobs (ms)
        pollIntervalMs: parseInt(optional('RENDERER_POLL_INTERVAL_MS', '4000'), 10),

        // How many rows to render in parallel per job
        // Keep at 1 for XAMPP/local — Puppeteer is already heavy
        concurrency: parseInt(optional('RENDERER_CONCURRENCY', '1'), 10),

        // Max rows before worker restarts itself (memory hygiene)
        maxRowsBeforeRestart: parseInt(optional('RENDERER_MAX_ROWS', '500'), 10),

        // Where the render HTML template lives
        templatePath: path.resolve(__dirname, '../render-template/index.html'),

        // Temp directory for intermediate files
        tmpDir: optional('RENDERER_TMP_DIR', require('os').tmpdir()),
    },
};
