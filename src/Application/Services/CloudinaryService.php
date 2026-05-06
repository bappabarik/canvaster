<?php

declare(strict_types=1);

namespace App\Application\Services;

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use RuntimeException;

class CloudinaryService
{
    private Cloudinary $cloudinary;

    public function __construct(array $config)
    {
        $this->cloudinary = new Cloudinary([
            'cloud' => [
                'cloud_name' => $config['cloud_name'],
                'api_key'  => $config['api_key'],
                'api_secret' => $config['api_secret'],
                'url' => [
                    'secure' => true
                ]
            ]
        ]);
    }

    /**
     * Upload a local file to Cloudinary.
     *
     * @param string $resourceType  'auto' for images, 'raw' for CSV/ZIP/PDF/non-image files
     * @return array ['public_id' => ..., 'secure_url' => ..., 'bytes' => ...]
     */
    public function upload(
        string $localPath,
        string $folder,
        string $publicId,
        string $resourceType = 'auto'  // ← 'raw' for CSV, ZIP, PDF; 'auto' for images
    ): array {
        $result = $this->cloudinary->uploadApi()->upload($localPath, [
            'folder'          => $folder,
            'public_id'       => $publicId,
            'resource_type'   => $resourceType,
            'use_filename'    => false,
            'unique_filename' => false,
            'overwrite'       => true,
        ]);

        return [
            'public_id'  => $result['public_id'],
            'secure_url' => $result['secure_url'],
            'bytes'      => $result['bytes'] ?? 0,
        ];
    }

    /**
     * Extract a ZIP, upload each image to Cloudinary, return array of
     * ['original_filename' => ..., 'public_id' => ..., 'secure_url' => ..., 'bytes' => ...]
     */
    public function extractAndUploadZip(string $zipPath, int $projectId): array
    {
        $extractDir = sys_get_temp_dir() . '/bdp_zip_' . $projectId . '_' . time();
        mkdir($extractDir, 0755, true);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Failed to open ZIP file');
        }
        $zip->extractTo($extractDir);
        $zip->close();

        $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $uploaded = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $allowed, true)) continue;

            $originalFilename = $file->getFilename();
            $publicIdName     = pathinfo($originalFilename, PATHINFO_FILENAME);
            $folder           = "bdp/projects/{$projectId}/images";

            try {
                // Images use default 'auto' resource_type
                $result     = $this->upload($file->getPathname(), $folder, $publicIdName);
                $uploaded[] = [
                    'original_filename' => $originalFilename,
                    'public_id'         => $result['public_id'],
                    'secure_url'        => $result['secure_url'],
                    'bytes'             => $result['bytes'],
                ];
            } catch (\Exception $e) {
                error_log("Cloudinary upload failed for {$originalFilename}: " . $e->getMessage());
            }
        }

        $this->rmDir($extractDir);

        return $uploaded;
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
