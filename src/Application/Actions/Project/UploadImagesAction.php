<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Application\Services\CloudinaryService;
use App\Domain\Asset\AssetRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class UploadImagesAction extends Action
{
    public function __construct(
        LoggerInterface          $logger,
        private ProjectRepository $projects,
        private AssetRepository   $assets,
        private CloudinaryService $cloudinary,
        private PDO               $pdo,
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
                'Images can only be uploaded when project is in draft status'
            );
        }

        $uploadedFiles = $this->request->getUploadedFiles();
        $zipFile       = $uploadedFiles['images'] ?? null;

        if (!$zipFile || $zipFile->getError() !== UPLOAD_ERR_OK) {
            throw new HttpBadRequestException($this->request, 'No valid ZIP file uploaded');
        }

        $ext = strtolower(pathinfo($zipFile->getClientFilename(), PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            throw new HttpBadRequestException($this->request, 'File must be a .zip');
        }

        $tmpPath = sys_get_temp_dir() . '/bdp_zip_' . $projectId . '_' . time() . '.zip';
        $zipFile->moveTo($tmpPath);

        try {
            $uploaded = $this->cloudinary->extractAndUploadZip($tmpPath, $projectId);
        } finally {
            if (file_exists($tmpPath)) unlink($tmpPath);
        }

        if (empty($uploaded)) {
            throw new HttpBadRequestException(
                $this->request,
                'ZIP contained no valid images (supported: jpg, jpeg, png, gif, webp)'
            );
        }

        // ── Save each image to uploaded_assets ────────────────────────────────
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO uploaded_assets
             (user_id, project_id, original_filename, cloudinary_public_id,
              cloudinary_url, asset_type, file_size_bytes)
             VALUES (?, ?, ?, ?, ?, "zip_extract", ?)'
        );

        foreach ($uploaded as $asset) {
            $stmt->execute([
                $user->getId(),
                $projectId,
                $asset['original_filename'],
                $asset['public_id'],
                $asset['secure_url'],
                $asset['bytes'],
            ]);
        }

        // Mark that a ZIP has been uploaded for this project
        $this->projects->update($projectId, ['image_zip_path' => 'cloudinary']);

        // ── Cross-reference uploaded filenames against CSV rows ───────────────
        // Find which CSV rows have a photo column value that matches an uploaded file.
        // This tells the user up front whether all images will resolve correctly.
        $matchReport = $this->buildMatchReport($projectId, $project->getColumnMap(), $uploaded);

        $uploadedFilenames = array_column($uploaded, 'original_filename');

        return $this->respondWithData([
            'uploaded_count'    => count($uploaded),
            'filenames'         => $uploadedFilenames,
            'match_report'      => $matchReport,
            'assets'            => array_map(fn($a) => [
                'original_filename' => $a['original_filename'],
                'cloudinary_url'    => $a['secure_url'],
                'bytes'             => $a['bytes'],
            ], $uploaded),
        ], 201);
    }

    /**
     * Cross-reference uploaded image filenames against the CSV rows to show
     * how many rows have a matching image vs how many will render blank.
     */
    private function buildMatchReport(int $projectId, ?array $columnMap, array $uploaded): array
    {
        if (!$columnMap) {
            return ['note' => 'Map columns first to see match results'];
        }

        // Find which column maps to an image-like placeholder
        $imageExtensions  = '/\.(jpg|jpeg|png|gif|webp)$/i';
        $uploadedFilenames = array_flip(array_column($uploaded, 'original_filename'));

        // Find CSV headers that are mapped to image placeholders
        // We detect image columns by checking what values look like filenames
        $stmt = $this->pdo->prepare(
            'SELECT data FROM project_rows WHERE project_id = ? ORDER BY row_index ASC'
        );
        $stmt->execute([$projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($rows)) {
            return ['note' => 'Upload a CSV first to see match results'];
        }

        $matched   = 0;
        $unmatched = [];

        foreach ($rows as $rowJson) {
            $rowData = json_decode($rowJson, true) ?? [];

            foreach ($columnMap as $placeholder => $csvHeader) {
                $value = $rowData[$csvHeader] ?? '';
                if (!preg_match($imageExtensions, (string) $value)) continue;

                if (isset($uploadedFilenames[$value])) {
                    $matched++;
                } else {
                    $unmatched[] = $value;
                }
            }
        }

        $unmatched = array_values(array_unique($unmatched));

        return [
            'total_rows'       => count($rows),
            'rows_with_image'  => $matched,
            'unmatched_files'  => $unmatched,
            'unmatched_count'  => count($unmatched),
            'fully_matched'    => empty($unmatched),
        ];
    }
}