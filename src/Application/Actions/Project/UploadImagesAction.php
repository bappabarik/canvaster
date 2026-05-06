<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Application\Services\CloudinaryService;
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
        LoggerInterface    $logger,
        private ProjectRepository  $projects,
        private CloudinaryService  $cloudinary,
        private PDO                $pdo,
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
                'ZIP contained no valid images (jpg, jpeg, png, gif, webp)'
            );
        }

        // Record each asset in uploaded_assets
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO uploaded_assets
             (user_id, project_id, original_filename, cloudinary_public_id, cloudinary_url, asset_type, file_size_bytes)
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

        // Save zip path reference on project
        $this->projects->update($projectId, ['image_zip_path' => 'cloudinary']);

        return $this->respondWithData([
            'uploaded_count' => count($uploaded),
            'assets'         => $uploaded,
        ]);
    }
}