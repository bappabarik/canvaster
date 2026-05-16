'use strict';

const mysql = require('mysql2/promise');
const config = require('./config');

// Single pool shared across the whole worker process
let pool = null;

function getPool() {
    if (!pool) {
        pool = mysql.createPool({
            host:            config.db.host,
            port:            config.db.port,
            database:        config.db.database,
            user:            config.db.user,
            password:        config.db.password,
            waitForConnections: true,
            connectionLimit: 5,
            // Return plain objects, not RowDataPacket instances
            rowsAsArray:     false,
            // Parse JSON columns automatically
            typeCast(field, next) {
                if (field.type === 'JSON' || field.name === 'data' ||
                    field.name === 'column_map' || field.name === 'placeholders_snapshot') {
                    const val = field.string();
                    try { return val ? JSON.parse(val) : null; } catch { return val; }
                }
                return next();
            },
        });
    }
    return pool;
}

// ── Job queries ───────────────────────────────────────────────────────────────

/**
 * Claim the oldest queued job atomically.
 * Uses UPDATE … LIMIT 1 + SELECT to avoid race conditions between
 * multiple worker instances (safe on a single server, good habit).
 */
async function claimNextJob() {
    const db = getPool();

    const [result] = await db.execute(
        `UPDATE generation_jobs
         SET status = 'processing', started_at = NOW()
         WHERE status = 'queued'
         ORDER BY id ASC
         LIMIT 1`
    );

    if (result.affectedRows === 0) return null;

    const [rows] = await db.execute(
        `SELECT gj.*, p.canvas_snapshot_json, p.placeholders_snapshot,
                p.column_map, p.output_format, p.total_rows, p.user_id,
                p.name AS project_name,
                t.width_px, t.height_px
         FROM generation_jobs gj
         JOIN projects p ON p.id = gj.project_id
         JOIN templates t ON t.id = p.template_id
         WHERE gj.status = 'processing'
           AND gj.started_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
         ORDER BY gj.id ASC
         LIMIT 1`
    );

    const job = rows[0] ?? null;

    if (job && job.canvas_snapshot_json) {
        // Prefer dimensions frozen into the snapshot at project creation time.
        // Fall back to current template dimensions if the snapshot predates this fix.
        const snapshot = typeof job.canvas_snapshot_json === 'string'
            ? JSON.parse(job.canvas_snapshot_json)
            : job.canvas_snapshot_json;

        if (snapshot._bdp_width)  job.width_px  = snapshot._bdp_width;
        if (snapshot._bdp_height) job.height_px = snapshot._bdp_height;
    }

    return job;
}

/**
 * Fetch all pending rows for a project, in order.
 */
async function getPendingRows(projectId) {
    const db = getPool();
    const [rows] = await db.execute(
        `SELECT * FROM project_rows
         WHERE project_id = ? AND status = 'pending'
         ORDER BY row_index ASC`,
        [projectId]
    );
    return rows;
}

/**
 * Mark a single row as processing before we start rendering it.
 */
async function markRowProcessing(projectId, rowIndex) {
    const db = getPool();
    await db.execute(
        `UPDATE project_rows SET status = 'processing'
         WHERE project_id = ? AND row_index = ?`,
        [projectId, rowIndex]
    );
}

/**
 * Mark a row done and record its Cloudinary output path.
 */
async function markRowDone(projectId, rowIndex, outputPath) {
    const db = getPool();
    await db.execute(
        `UPDATE project_rows
         SET status = 'done', output_path = ?, rendered_at = NOW(), error_msg = NULL
         WHERE project_id = ? AND row_index = ?`,
        [outputPath, projectId, rowIndex]
    );
}

/**
 * Mark a row failed with an error message.
 */
async function markRowFailed(projectId, rowIndex, errorMsg) {
    const db = getPool();
    await db.execute(
        `UPDATE project_rows
         SET status = 'failed', error_msg = ?
         WHERE project_id = ? AND row_index = ?`,
        [String(errorMsg).slice(0, 65535), projectId, rowIndex]
    );
}

/**
 * Increment rows_done or rows_failed on the job record.
 */
async function incrementJobCounter(jobId, field) {
    const db = getPool();
    await db.execute(
        `UPDATE generation_jobs SET ${field} = ${field} + 1 WHERE id = ?`,
        [jobId]
    );
}

/**
 * Mark a job as done or failed with optional ZIP path and error log.
 */
async function finalizeJob(jobId, status, { outputZipPath = null, errorLog = null } = {}) {
    const db = getPool();
    await db.execute(
        `UPDATE generation_jobs
         SET status = ?, completed_at = NOW(),
             output_zip_path = ?, error_log = ?
         WHERE id = ?`,
        [status, outputZipPath, errorLog, jobId]
    );
}

/**
 * Mark the parent project done or failed.
 */
async function finalizeProject(projectId, status) {
    const db = getPool();
    await db.execute(
        `UPDATE projects SET status = ? WHERE id = ?`,
        [status, projectId]
    );
}

/**
 * Look up a Cloudinary URL for a row image by filename.
 * The renderer uses this to resolve {photo} placeholders.
 *
 * Resolution order:
 *   1. Exact match on project_id + filename (ZIP uploaded to this project)
 *   2. Fallback: match on user_id + filename (ZIP uploaded to a different project, reused)
 */
async function findImageAsset(projectId, filename, userId = null) {
    const db = getPool();

    // 1. Project-scoped lookup (most common case)
    const [rows] = await db.execute(
        `SELECT cloudinary_url FROM uploaded_assets
         WHERE project_id = ?
           AND original_filename = ?
           AND asset_type IN ('row_image', 'zip_extract')
         LIMIT 1`,
        [projectId, filename]
    );

    if (rows.length) return rows[0].cloudinary_url;

    // 2. User-scoped fallback — finds the most recently uploaded matching file
    if (userId) {
        const [fallback] = await db.execute(
            `SELECT cloudinary_url FROM uploaded_assets
             WHERE user_id = ?
               AND original_filename = ?
               AND asset_type IN ('row_image', 'zip_extract')
             ORDER BY created_at DESC
             LIMIT 1`,
            [userId, filename]
        );
        if (fallback.length) return fallback[0].cloudinary_url;
    }

    return null;
}

/**
 * Gracefully close the pool (called on SIGTERM/SIGINT).
 */
async function closePool() {
    if (pool) {
        await pool.end();
        pool = null;
    }
}

module.exports = {
    claimNextJob,
    getPendingRows,
    markRowProcessing,
    markRowDone,
    markRowFailed,
    incrementJobCounter,
    finalizeJob,
    finalizeProject,
    findImageAsset,
    closePool,
};