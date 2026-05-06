<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Application\Services\CloudinaryService;
use App\Application\Services\CsvParser;
use App\Domain\Asset\AssetRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class UploadCsvAction extends Action
{
    public function __construct(
        LoggerInterface           $logger,
        private ProjectRepository $projects,
        private AssetRepository   $assets,
        private CsvParser         $csvParser,
        private CloudinaryService $cloudinary,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        /** @var User $user */
        $user      = $this->request->getAttribute('user');
        $projectId = (int) $this->resolveArg('id');

        if (!$this->projects->belongsToUser($projectId, $user->getId())) {
            throw new HttpNotFoundException($this->request, 'Project not found');
        }

        $project = $this->projects->findById($projectId);

        if (!in_array($project->getStatus(), ['draft'], true)) {
            throw new HttpBadRequestException(
                $this->request,
                'CSV can only be uploaded when project is in draft status'
            );
        }

        $body = (array) $this->request->getParsedBody();

        // ── Option A: reuse a previously uploaded CSV asset ──────────────
        if (isset($body['asset_id'])) {
            return $this->reuseExistingCsv($projectId, (int) $body['asset_id'], $user->getId());
        }

        // ── Option B: fresh CSV file upload ──────────────────────────────
        $uploadedFiles = $this->request->getUploadedFiles();
        $csvFile       = $uploadedFiles['csv'] ?? null;

        if (!$csvFile || $csvFile->getError() !== UPLOAD_ERR_OK) {
            throw new HttpBadRequestException($this->request, 'No valid CSV file uploaded');
        }

        $ext = strtolower(pathinfo($csvFile->getClientFilename(), PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new HttpBadRequestException($this->request, 'File must be a .csv');
        }

        // Move to temp for parsing — temp is fine here, we upload to Cloudinary immediately
        $tmpPath = sys_get_temp_dir() . '/bdp_csv_' . $projectId . '_' . time() . '.csv';
        $csvFile->moveTo($tmpPath);

        try {
            $parsed = $this->csvParser->parse($tmpPath);
        } catch (\RuntimeException $e) {
            @unlink($tmpPath);
            throw new HttpBadRequestException($this->request, $e->getMessage());
        }

        // ── Upload CSV to Cloudinary as raw resource ──────────────────────
        try {
            $uploadResult = $this->cloudinary->upload(
                $tmpPath,
                'bdp/csvs/' . $user->getId(),
                'project_' . $projectId . '_' . time(),
                'raw'  // CSV is not an image — must use raw resource type
            );
        } catch (\Exception $e) {
            @unlink($tmpPath);
            throw new HttpBadRequestException($this->request, 'Failed to upload CSV: ' . $e->getMessage());
        }

        @unlink($tmpPath); // safe to delete temp now — Cloudinary has it

        $cloudinaryUrl      = $uploadResult['secure_url'];
        $cloudinaryPublicId = $uploadResult['public_id'];
        $fileSizeBytes      = $uploadResult['bytes'];
        $originalFilename   = $csvFile->getClientFilename();

        // ── Save to uploaded_assets ───────────────────────────────────────
        $asset = $this->assets->create([
            'user_id'              => $user->getId(),
            'project_id'           => $projectId,
            'original_filename'    => $originalFilename,
            'cloudinary_public_id' => $cloudinaryPublicId,
            'cloudinary_url'       => $cloudinaryUrl,
            'asset_type'           => 'csv_file',
            'file_size_bytes'      => $fileSizeBytes,
        ]);

        // ── Insert parsed rows into project_rows ──────────────────────────
        $this->projects->insertRows($projectId, $parsed['rows']);

        // ── Update project — csv_path now holds the Cloudinary URL ────────
        $this->projects->update($projectId, [
            'csv_path'   => $cloudinaryUrl,
            'total_rows' => $parsed['total'],
        ]);

        return $this->respondWithData([
            'asset_id'        => $asset->getId(),
            'cloudinary_url'  => $cloudinaryUrl,
            'original_filename' => $originalFilename,
            'headers'         => $parsed['headers'],
            'total_rows'      => $parsed['total'],
            'preview'         => array_slice($parsed['rows'], 0, 3),
        ], 201);
    }

    // ── Reuse a CSV the user already uploaded in a previous project ───────
    private function reuseExistingCsv(int $projectId, int $assetId, int $userId): Response
    {
        $asset = $this->assets->findById($assetId);

        if (!$asset || $asset->getUserId() !== $userId) {
            throw new HttpNotFoundException($this->request, 'Asset not found');
        }

        if ($asset->getAssetType() !== 'csv_file') {
            throw new HttpBadRequestException($this->request, 'Asset is not a CSV file');
        }

        // Download from Cloudinary URL into temp for parsing
        $tmpPath  = sys_get_temp_dir() . '/bdp_reuse_' . $projectId . '_' . time() . '.csv';
        $contents = file_get_contents($asset->getCloudinaryUrl());

        if ($contents === false) {
            throw new HttpBadRequestException($this->request, 'Failed to fetch CSV from storage');
        }

        file_put_contents($tmpPath, $contents);

        try {
            $parsed = $this->csvParser->parse($tmpPath);
        } catch (\RuntimeException $e) {
            @unlink($tmpPath);
            throw new HttpBadRequestException($this->request, $e->getMessage());
        }

        @unlink($tmpPath);

        // Link this asset to the new project too
        $this->assets->linkToProject($assetId, $projectId);

        $this->projects->insertRows($projectId, $parsed['rows']);
        $this->projects->update($projectId, [
            'csv_path'   => $asset->getCloudinaryUrl(),
            'total_rows' => $parsed['total'],
        ]);

        return $this->respondWithData([
            'asset_id'         => $assetId,
            'cloudinary_url'   => $asset->getCloudinaryUrl(),
            'original_filename'=> $asset->getOriginalFilename(),
            'reused'           => true,
            'headers'          => $parsed['headers'],
            'total_rows'       => $parsed['total'],
            'preview'          => array_slice($parsed['rows'], 0, 3),
        ]);
    }
}