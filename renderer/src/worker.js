'use strict';

const path = require('path');
const fs   = require('fs');
const os   = require('os');

const config   = require('./config');
const db       = require('./db');
const { renderRow, closeBrowser } = require('./renderer');
const { uploadOutput, uploadZip } = require('./uploader');
const { createZip } = require('./zipper');

// ── Logging ───────────────────────────────────────────────────────────────────
function log(level, msg, extra = {}) {
    const line = JSON.stringify({
        ts: new Date().toISOString(),
        level,
        msg,
        ...extra,
    });
    if (level === 'error') process.stderr.write(line + '\n');
    else process.stdout.write(line + '\n');
}

const info  = (msg, e) => log('info',  msg, e);
const warn  = (msg, e) => log('warn',  msg, e);
const error = (msg, e) => log('error', msg, e);

// ── Row counter (for max-rows-before-restart hygiene) ─────────────────────────
let totalRowsRendered = 0;

// ── Job processor ─────────────────────────────────────────────────────────────

async function processJob(job) {
    const {
        id: jobId,
        project_id: projectId,
        canvas_snapshot_json: canvasJson,
        placeholders_snapshot: placeholders,
        column_map: columnMap,
        output_format: outputFormat,
        total_rows: totalRows,
        project_name: projectName,
    } = job;

    info('Job started', { jobId, projectId, projectName, totalRows, outputFormat });

    // Temp directory for this job's output files
    const jobTmpDir = path.join(config.worker.tmpDir, `bdp_job_${jobId}_${Date.now()}`);
    fs.mkdirSync(jobTmpDir, { recursive: true });

    const ext         = outputFormat.includes('pdf') ? 'pdf' : 'png';
    const outputFiles = []; // { filePath, archiveName, cloudinaryUrl }

    let rowsDone   = 0;
    let rowsFailed = 0;

    try {
        // Fetch all pending rows
        const rows = await db.getPendingRows(projectId);

        if (rows.length === 0) {
            warn('No pending rows found', { jobId, projectId });
            await db.finalizeJob(jobId, 'done');
            await db.finalizeProject(projectId, 'done');
            return;
        }

        info('Processing rows', { jobId, count: rows.length });

        for (const row of rows) {
            const { row_index: rowIndex, data: rawRowData } = row;

            await db.markRowProcessing(projectId, rowIndex);

            const rowFile = path.join(jobTmpDir, `row_${String(rowIndex).padStart(6, '0')}.${ext}`);

            try {
                // Build imageMap — only filenames with image extensions
                const imageMap       = {};
                const imageExtensions = /\.(jpg|jpeg|png|gif|webp)$/i;

                for (const [, csvHeader] of Object.entries(columnMap)) {
                    const value = rawRowData[csvHeader];
                    if (value && imageExtensions.test(value)) {
                        const url = await db.findImageAsset(projectId, value, job.user_id);
                        if (url) {
                            imageMap[value] = url;
                        } else {
                            console.warn(`No asset found for filename "${value}" in project ${projectId}`);
                        }
                    }
                }

                await renderRow({
                    canvasJson,
                    columnMap,
                    rawRowData,
                    widthPx:      job.width_px  || 800,
                    heightPx:     job.height_px || 600,
                    imageMap,
                    outputFormat,
                    outputPath: rowFile,
                });

                // Upload to Cloudinary
                const cloudinaryUrl = await uploadOutput(rowFile, projectId, rowIndex, ext);

                await db.markRowDone(projectId, rowIndex, cloudinaryUrl);
                await db.incrementJobCounter(jobId, 'rows_done');

                outputFiles.push({
                    filePath:     rowFile,
                    archiveName:  `row_${String(rowIndex + 1).padStart(4, '0')}.${ext}`,
                    cloudinaryUrl,
                });

                rowsDone++;
                totalRowsRendered++;

                info('Row done', { jobId, projectId, rowIndex, cloudinaryUrl });

            } catch (err) {
                rowsFailed++;
                error('Row failed', { jobId, projectId, rowIndex, err: err.message });
                await db.markRowFailed(projectId, rowIndex, err.message);
                await db.incrementJobCounter(jobId, 'rows_failed');
            }
        }

        // ── Bundle outputs into a ZIP ─────────────────────────────────────────
        // let zipCloudinaryUrl = null;

        // if (outputFiles.length > 0) {
        //     const zipPath = path.join(jobTmpDir, `project_${projectId}_output.zip`);

        //     info('Creating ZIP', { jobId, fileCount: outputFiles.length });

        //     await createZip(
        //         outputFiles.map(f => ({ filePath: f.filePath, archiveName: f.archiveName })),
        //         zipPath
        //     );

        //     zipCloudinaryUrl = await uploadZip(zipPath, projectId);

        //     info('ZIP uploaded', { jobId, zipCloudinaryUrl });
        // }

        // ── Finalize ──────────────────────────────────────────────────────────
        const jobStatus     = rowsFailed === 0 ? 'done' : (rowsDone === 0 ? 'failed' : 'done');
        const projectStatus = rowsFailed === 0 ? 'done' : (rowsDone === 0 ? 'failed' : 'done');

        await db.finalizeJob(jobId, jobStatus, {
            outputZipPath: zipCloudinaryUrl,
            errorLog: rowsFailed > 0
                ? `${rowsFailed} of ${rows.length} rows failed`
                : null,
        });

        await db.finalizeProject(projectId, projectStatus);

        info('Job complete', { jobId, projectId, rowsDone, rowsFailed, jobStatus });

    } catch (err) {
        error('Job failed fatally', { jobId, projectId, err: err.message });

        await db.finalizeJob(jobId, 'failed', { errorLog: err.message }).catch(() => {});
        await db.finalizeProject(projectId, 'failed').catch(() => {});

    } finally {
        // Clean up temp files
        fs.rmSync(jobTmpDir, { recursive: true, force: true });

        // Close browser after each job — frees memory, next job gets a fresh one
        await closeBrowser();
    }
}

// ── Main polling loop ─────────────────────────────────────────────────────────

async function poll() {
    try {
        const job = await db.claimNextJob();

        if (job) {
            await processJob(job);

            // If we've rendered a lot of rows, restart to free memory
            if (totalRowsRendered >= config.worker.maxRowsBeforeRestart) {
                info('Max rows reached, restarting', { totalRowsRendered });
                await shutdown(0);
            }
        }
    } catch (err) {
        error('Poll error', { err: err.message });
    }
}

async function shutdown(code = 0) {
    info('Worker shutting down');
    await closeBrowser();
    await db.closePool();
    process.exit(code);
}

// ── Entry point ───────────────────────────────────────────────────────────────

process.on('SIGTERM', () => shutdown(0));
process.on('SIGINT',  () => shutdown(0));
process.on('uncaughtException', async (err) => {
    error('Uncaught exception', { err: err.message, stack: err.stack });
    await shutdown(1);
});

info('BDP Renderer worker starting', {
    pollIntervalMs: config.worker.pollIntervalMs,
    concurrency:    config.worker.concurrency,
    tmpDir:         config.worker.tmpDir,
});

// Run once immediately, then on interval
poll();
setInterval(poll, config.worker.pollIntervalMs);