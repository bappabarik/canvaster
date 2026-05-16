"use strict";

const path = require("path");
const fs = require("fs");
const mysql = require("mysql2/promise");
require("dotenv").config({ path: path.resolve(__dirname, "../../.env") });

const config = require("./config");
const { renderRow, closeBrowser } = require("./renderer");

// ── Parse CLI args: --project=1 --row=0 --out=./out.png ──────────────────────
const args = Object.fromEntries(
  process.argv
    .slice(2)
    .filter((a) => a.startsWith("--"))
    .map((a) => {
      const [k, ...rest] = a.slice(2).split("=");
      return [k, rest.join("=") || true];
    }),
);

const projectId = parseInt(args.project ?? args.p, 10);
const rowIndex = parseInt(args.row ?? args.r ?? "0", 10);
const outPath = path.resolve(args.out ?? `./render-test-row${rowIndex}.png`);

if (!projectId || isNaN(projectId)) {
  console.error(
    "Usage: node src/render-one.js --project=<id> [--row=<rowIndex>] [--out=<path>]",
  );
  process.exit(1);
}

// ── Helper: safely parse a value that may already be an object or a JSON string
function safeJsonParse(value, fallback) {
  if (value === null || value === undefined) return fallback;
  if (typeof value === "object") return value; // already parsed by mysql2 typeCast
  try {
    return JSON.parse(value);
  } catch (e) {
    console.error("  JSON parse error:", e.message);
    console.error(
      "  Raw value (first 200 chars):",
      String(value).slice(0, 200),
    );
    return fallback;
  }
}

async function main() {
  console.log("\nBDP single-row render test");
  console.log("  project_id :", projectId);
  console.log("  row_index  :", rowIndex);
  console.log("  output     :", outPath, "\n");

  // FIX: use the same typeCast as db.js so JSON columns are auto-parsed,
  // and use createConnection (not createPool) to keep it simple for a one-shot script.
  const db = await mysql.createConnection({
    host: config.db.host,
    port: config.db.port,
    database: config.db.database,
    user: config.db.user,
    password: config.db.password,
    typeCast(field, next) {
      if (
        field.type === "JSON" ||
        [
          "data",
          "column_map",
          "placeholders_snapshot",
          "canvas_snapshot_json",
        ].includes(field.name)
      ) {
        const val = field.string();
        try {
          return val ? JSON.parse(val) : null;
        } catch {
          return val;
        }
      }
      return next();
    },
  });

  try {
    // ── 1. Load project + template dimensions ─────────────────────────────
    const [projects] = await db.execute(
      `SELECT p.id,
                    p.canvas_snapshot_json,
                    p.column_map,
                    p.output_format,
                    p.total_rows,
                    p.status,
                    t.width_px,
                    t.height_px
             FROM projects p
             JOIN templates t ON t.id = p.template_id
             WHERE p.id = ?
             LIMIT 1`,
      [projectId],
    );

    if (!projects.length) {
      throw new Error(`Project ${projectId} not found in the database`);
    }

    const project = projects[0];

    // FIX: always run through safeJsonParse — typeCast covers the pool path
    // but a direct createConnection may or may not trigger it depending on
    // mysql2 version and whether the column has the JSON type declared in the schema.
    const columnMap = safeJsonParse(project.column_map, {});
    const canvasJson =
      typeof project.canvas_snapshot_json === "string"
        ? project.canvas_snapshot_json // keep as string — Fabric parses it
        : JSON.stringify(project.canvas_snapshot_json);
    const outputFormat = project.output_format || "zip_png";

    console.log("  status     :", project.status);
    console.log(
      "  canvas     :",
      project.width_px + "x" + project.height_px + "px",
    );
    console.log("  format     :", outputFormat);
    console.log("  total_rows :", project.total_rows);
    console.log("  column_map :", JSON.stringify(columnMap));

    if (!canvasJson || canvasJson === "null") {
      throw new Error(
        "canvas_snapshot_json is empty — was the project created properly?",
      );
    }

    if (Object.keys(columnMap).length === 0) {
      console.warn(
        "\n  ⚠ column_map is empty. Did you map columns via POST /{id}/map?\n",
      );
    }

    // ── 2. Load the row ───────────────────────────────────────────────────
    const [rows] = await db.execute(
      `SELECT row_index, data, status
             FROM project_rows
             WHERE project_id = ? AND row_index = ?
             LIMIT 1`,
      [projectId, rowIndex],
    );

    if (!rows.length) {
      throw new Error(
        `Row ${rowIndex} not found in project ${projectId}. ` +
          `Total rows: ${project.total_rows}. ` +
          `Did you upload a CSV?`,
      );
    }

    const row = rows[0];
    const rawRowData = safeJsonParse(row.data, {});

    console.log("\n  row status :", row.status);
    console.log("  row data   :", JSON.stringify(rawRowData));

    // ── 3. Build imageMap from uploaded_assets ────────────────────────────
    const imageMap = {};
    const imageExtensions = /\.(jpg|jpeg|png|gif|webp)$/i;

    for (const [placeholder, csvHeader] of Object.entries(columnMap)) {
      const value = rawRowData[csvHeader];
      if (value && imageExtensions.test(String(value))) {
        const [assets] = await db.execute(
          `SELECT cloudinary_url
                     FROM uploaded_assets
                     WHERE project_id = ?
                       AND original_filename = ?
                       AND asset_type IN ('row_image', 'zip_extract')
                     LIMIT 1`,
          [projectId, value],
        );

        if (assets.length) {
          imageMap[value] = assets[0].cloudinary_url;
          console.log(`  imageMap   : ${value} → ${assets[0].cloudinary_url}`);
        } else {
          console.warn(
            `  imageMap   : ${value} → ⚠ not found in uploaded_assets (will render blank)`,
          );
        }
      }
    }

    // ── 4. Render ─────────────────────────────────────────────────────────
    console.log("\nStarting Puppeteer...");
    const start = Date.now();

    // After fetching the project, read frozen dims from snapshot first
    const snapshotJson =
      typeof project.canvas_snapshot_json === "string"
        ? JSON.parse(project.canvas_snapshot_json)
        : project.canvas_snapshot_json;

    const widthPx = snapshotJson._bdp_width || project.width_px || 800;
    const heightPx = snapshotJson._bdp_height || project.height_px || 600;

    console.log(`  template:   ${widthPx}x${heightPx}px (from snapshot)`);

    // Then pass these to renderRow:
    await renderRow({
      canvasJson: project.canvas_snapshot_json,
      columnMap,
      rawRowData,
      widthPx,
      heightPx,
      imageMap,
      outputFormat,
      outputPath: outPath,
    });

    const elapsed = ((Date.now() - start) / 1000).toFixed(2);
    const sizeKb = (fs.statSync(outPath).size / 1024).toFixed(1);

    console.log(`\n✓ Done in ${elapsed}s`);
    console.log(`  Output: ${outPath} (${sizeKb} KB)\n`);
  } finally {
    await db.end().catch(() => {});
    await closeBrowser();
  }
}

main().catch((err) => {
  console.error("\n✗ Error:", err.message);
  if (process.env.DEBUG) console.error(err.stack);
  process.exit(1);
});
