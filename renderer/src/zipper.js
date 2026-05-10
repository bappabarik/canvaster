'use strict';

const fs   = require('fs');
const path = require('path');
const zlib = require('zlib');

// Node.js has no built-in ZIP writer — we use a simple manual implementation
// to avoid an extra dependency. For production at scale, swap in 'archiver'.

/**
 * Create a ZIP archive from a list of files.
 * Uses the built-in zlib deflate so there are zero extra dependencies.
 *
 * @param {Array<{filePath: string, archiveName: string}>} files
 * @param {string} outputPath  - Where to write the .zip
 * @returns {Promise<void>}
 */
async function createZip(files, outputPath) {
    // Build a ZIP file manually using the ZIP format spec
    // (Local file header + data for each file, then central directory)
    const localHeaders = [];
    const centralDir   = [];
    let offset = 0;

    const buffers = [];

    for (const { filePath, archiveName } of files) {
        const fileData    = fs.readFileSync(filePath);
        const compressed  = zlib.deflateRawSync(fileData, { level: 6 });
        const crc32       = computeCrc32(fileData);
        const nameBytes   = Buffer.from(archiveName, 'utf8');
        const now         = new Date();
        const dosTime     = dosDateTime(now);

        // Local file header (30 bytes + name)
        const localHeader = Buffer.alloc(30 + nameBytes.length);
        localHeader.writeUInt32LE(0x04034b50, 0);   // Local file header signature
        localHeader.writeUInt16LE(20, 4);            // Version needed: 2.0
        localHeader.writeUInt16LE(0x0800, 6);        // Flags: UTF-8 name
        localHeader.writeUInt16LE(8, 8);             // Compression: deflate
        localHeader.writeUInt16LE(dosTime.time, 10); // Last mod time
        localHeader.writeUInt16LE(dosTime.date, 12); // Last mod date
        localHeader.writeInt32LE(crc32, 14);         // CRC-32
        localHeader.writeUInt32LE(compressed.length, 18); // Compressed size
        localHeader.writeUInt32LE(fileData.length, 22);   // Uncompressed size
        localHeader.writeUInt16LE(nameBytes.length, 26);  // File name length
        localHeader.writeUInt16LE(0, 28);                 // Extra field length
        nameBytes.copy(localHeader, 30);

        // Central directory entry (46 bytes + name)
        const cdEntry = Buffer.alloc(46 + nameBytes.length);
        cdEntry.writeUInt32LE(0x02014b50, 0);   // Central directory signature
        cdEntry.writeUInt16LE(20, 4);            // Version made by
        cdEntry.writeUInt16LE(20, 6);            // Version needed
        cdEntry.writeUInt16LE(0x0800, 8);        // Flags
        cdEntry.writeUInt16LE(8, 10);            // Compression
        cdEntry.writeUInt16LE(dosTime.time, 12);
        cdEntry.writeUInt16LE(dosTime.date, 14);
        cdEntry.writeInt32LE(crc32, 16);
        cdEntry.writeUInt32LE(compressed.length, 20);
        cdEntry.writeUInt32LE(fileData.length, 24);
        cdEntry.writeUInt16LE(nameBytes.length, 28);
        cdEntry.writeUInt16LE(0, 30);            // Extra length
        cdEntry.writeUInt16LE(0, 32);            // Comment length
        cdEntry.writeUInt16LE(0, 34);            // Disk start
        cdEntry.writeUInt16LE(0, 36);            // Internal attr
        cdEntry.writeUInt32LE(0, 38);            // External attr
        cdEntry.writeUInt32LE(offset, 42);       // Offset of local header
        nameBytes.copy(cdEntry, 46);

        buffers.push(localHeader, compressed);
        centralDir.push(cdEntry);

        offset += localHeader.length + compressed.length;
    }

    // End of central directory record
    const cdBuffer = Buffer.concat(centralDir);
    const eocd     = Buffer.alloc(22);
    eocd.writeUInt32LE(0x06054b50, 0);        // EOCD signature
    eocd.writeUInt16LE(0, 4);                 // Disk number
    eocd.writeUInt16LE(0, 6);                 // Disk with CD
    eocd.writeUInt16LE(centralDir.length, 8); // CD entries on disk
    eocd.writeUInt16LE(centralDir.length, 10);// Total CD entries
    eocd.writeUInt32LE(cdBuffer.length, 12);  // CD size
    eocd.writeUInt32LE(offset, 16);           // CD offset
    eocd.writeUInt16LE(0, 20);               // Comment length

    fs.writeFileSync(outputPath, Buffer.concat([...buffers, cdBuffer, eocd]));
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function dosDateTime(date) {
    const time = ((date.getHours() << 11) | (date.getMinutes() << 5) | (date.getSeconds() >> 1));
    const d    = (((date.getFullYear() - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate());
    return { time, date: d };
}

const CRC32_TABLE = (() => {
    const t = new Uint32Array(256);
    for (let i = 0; i < 256; i++) {
        let c = i;
        for (let j = 0; j < 8; j++) c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
        t[i] = c;
    }
    return t;
})();

function computeCrc32(buf) {
    let crc = 0xffffffff;
    for (const byte of buf) crc = CRC32_TABLE[(crc ^ byte) & 0xff] ^ (crc >>> 8);
    return (crc ^ 0xffffffff) | 0;
}

module.exports = { createZip };
