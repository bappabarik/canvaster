'use strict';

const { v2: cloudinary } = require('cloudinary');
const config = require('./config');

// Configure once at module load
cloudinary.config({
    cloud_name: config.cloudinary.cloud_name,
    api_key:    config.cloudinary.api_key,
    api_secret: config.cloudinary.api_secret,
    secure:     true,
});

/**
 * Upload a rendered PNG or PDF file to Cloudinary.
 *
 * @param {string} localPath   - Absolute path to the file on disk
 * @param {number} projectId
 * @param {number} rowIndex
 * @param {'png'|'pdf'} format
 * @returns {Promise<string>}  - The Cloudinary secure_url
 */
async function uploadOutput(localPath, projectId, rowIndex, format) {
    const folder    = `bdp/projects/${projectId}/output`;
    const publicId  = `row_${String(rowIndex).padStart(6, '0')}`;
    const resourceType = format === 'pdf' ? 'raw' : 'image';

    const result = await cloudinary.uploader.upload(localPath, {
        folder,
        public_id:       publicId,
        resource_type:   resourceType,
        use_filename:    false,
        unique_filename: false,
        overwrite:       true,
    });

    return result.secure_url;
}

/**
 * Upload the final ZIP archive containing all rendered outputs.
 *
 * @param {string} localPath  - Absolute path to the ZIP file
 * @param {number} projectId
 * @returns {Promise<string>} - Cloudinary secure_url
 */
async function uploadZip(localPath, projectId) {
    const result = await cloudinary.uploader.upload(localPath, {
        folder:          `bdp/projects/${projectId}`,
        public_id:       'output_bundle',
        resource_type:   'raw',
        use_filename:    false,
        unique_filename: false,
        overwrite:       true,
    });

    return result.secure_url;
}

module.exports = { uploadOutput, uploadZip };
