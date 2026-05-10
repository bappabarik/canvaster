#!/usr/bin/env node
'use strict';

const path   = require('path');
const fs     = require('fs');
const mysql  = require('mysql2/promise');
require('dotenv').config({ path: path.resolve(__dirname, '../../.env') });

const config                       = require('./config');
const { renderRow, closeBrowser }  = require('./renderer');

const args = Object.fromEntries(
    process.argv.slice(2)
        .filter(a => a.startsWith('--'))
        .map(a => { const [k, v] = a.slice(2).split('='); return [k, v ?? true]; })
);

const projectId = parseInt(args.project ?? args.p, 10);
const rowIndex  = parseInt(args.row     ?? args.r ?? '0', 10);
const outPath   = path.resolve(args.out ?? `./render-test-row${rowIndex}.png`);

if (!projectId) {
    console.error('Usage: node src/render-one.js --project=<id> --row=<rowIndex>');
    process.exit(1);
}

async function main() {
    console.log(`\nBDP single-row render test`);
    console.log(`  project_id: ${projectId}`);
    console.log(`  row_index:  ${rowIndex}`);
    console.log(`  output:     ${outPath}\n`);

    const db = await mysql.createConnection({
        host:     config.db.host,
        port:     config.db.port,
        database: config.db.database,
        user:     config.db.user,
        password: config.db.password,
    });

    try {
        const [projects] = await db.execute(
            `SELECT p.*, t.width_px, t.height_px
             FROM projects p
             JOIN templates t ON t.id = p.template_id
             WHERE p.id = ?`,
            [projectId]
        );

        if (!projects.length) throw new Error(`Project ${projectId} not found`);

        const project      = projects[0];
        const columnMap    = JSON.parse(project.column_map   || '{}');
        const outputFormat = project.output_format            || 'zip_png';

        console.log(`  template:   ${project.width_px}x${project.height_px}px`);
        console.log(`  format:     ${outputFormat}`);
        console.log(`  column_map: ${JSON.stringify(columnMap)}\n`);

        const [rows] = await db.execute(
            `SELECT * FROM project_rows WHERE project_id = ? AND row_index = ?`,
            [projectId, rowIndex]
        );

        if (!rows.length) throw new Error(`Row ${rowIndex} not found in project ${projectId}`);

        const rawRowData = JSON.parse(rows[0].data || '{}');
        console.log('  row data:', rawRowData);

        const imageMap        = {};
        const imageExtensions = /\.(jpg|jpeg|png|gif|webp)$/i;

        for (const [, csvHeader] of Object.entries(columnMap)) {
            const value = rawRowData[csvHeader];
            if (value && imageExtensions.test(value)) {
                const [assets] = await db.execute(
                    `SELECT cloudinary_url FROM uploaded_assets
                     WHERE project_id = ? AND original_filename = ?
                       AND asset_type IN ('row_image', 'zip_extract')
                     LIMIT 1`,
                    [projectId, value]
                );
                if (assets.length) {
                    imageMap[value] = assets[0].cloudinary_url;
                    console.log(`  imageMap:   ${value} -> ${assets[0].cloudinary_url}`);
                } else {
                    console.log(`  imageMap:   ${value} -> (not in uploaded_assets - placeholder will render blank)`);
                }
            }
        }

        console.log('\nStarting Puppeteer...');
        const start = Date.now();

        await renderRow({
            canvasJson:   project.canvas_snapshot_json,
            columnMap,
            rawRowData,
            widthPx:      project.width_px  || 800,
            heightPx:     project.height_px || 600,
            imageMap,
            outputFormat,
            outputPath:   outPath,
        });

        const elapsed = Date.now() - start;
        const size    = fs.statSync(outPath).size;

        console.log(`\nRendered in ${elapsed}ms`);
        console.log(`Output:    ${outPath} (${(size / 1024).toFixed(1)} KB)\n`);

    } finally {
        await db.end();
        await closeBrowser();
    }
}

main().catch(err => {
    console.error('\nError:', err.message);
    process.exit(1);
});