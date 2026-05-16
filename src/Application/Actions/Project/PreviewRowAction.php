<?php

declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Domain\Asset\AssetRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class PreviewRowAction extends Action
{
    public function __construct(
        LoggerInterface         $logger,
        private ProjectRepository $projects,
        private AssetRepository   $assets,
        private PDO               $pdo,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        /** @var User $user */
        $user      = $this->request->getAttribute('user');
        $projectId = (int) $this->resolveArg('id');
        $rowIndex  = (int) ($this->request->getQueryParams()['row'] ?? 0);

        if (!$this->projects->belongsToUser($projectId, $user->getId())) {
            throw new HttpNotFoundException($this->request, 'Project not found');
        }

        $project = $this->projects->findById($projectId);

        if (!$project->getColumnMap()) {
            throw new HttpBadRequestException($this->request, 'Map columns before previewing');
        }

        $row = $this->projects->getRow($projectId, $rowIndex);
        if (!$row) {
            throw new HttpNotFoundException($this->request, "Row {$rowIndex} not found");
        }

        $rawData   = json_decode($row['data'], true);
        $columnMap = $project->getColumnMap();

        // Resolve placeholder keys → CSV values
        $resolved = [];
        foreach ($columnMap as $placeholder => $csvHeader) {
            $resolved[$placeholder] = $rawData[$csvHeader] ?? null;
        }

        // Build imageMap: for any resolved value that looks like an image filename,
        // find its Cloudinary URL from uploaded_assets so the frontend can render it
        $imageMap       = [];
        $imageExtensions = '/\.(jpg|jpeg|png|gif|webp)$/i';

        foreach ($resolved as $placeholder => $value) {
            if (!$value || !preg_match($imageExtensions, (string) $value)) continue;

            // Check project-scoped assets first, then user-level fallback
            $stmt = $this->pdo->prepare(
                'SELECT cloudinary_url FROM uploaded_assets
                 WHERE project_id = ? AND original_filename = ?
                   AND asset_type IN ("row_image", "zip_extract")
                 LIMIT 1'
            );
            $stmt->execute([$projectId, $value]);
            $url = $stmt->fetchColumn();

            if (!$url) {
                // Fallback: any asset this user owns with the same filename
                $stmt = $this->pdo->prepare(
                    'SELECT cloudinary_url FROM uploaded_assets
                     WHERE user_id = ? AND original_filename = ?
                       AND asset_type IN ("row_image", "zip_extract")
                     ORDER BY created_at DESC LIMIT 1'
                );
                $stmt->execute([$user->getId(), $value]);
                $url = $stmt->fetchColumn();
            }

            if ($url) {
                $imageMap[$value] = $url;
            }
        }

        // Also replace direct HTTP URLs in resolved data into imageMap
        foreach ($resolved as $placeholder => $value) {
            if ($value && (str_starts_with((string) $value, 'http://') || str_starts_with((string) $value, 'https://'))) {
                $imageMap[$value] = $value;
            }
        }

        // After resolving the row data, extract frozen dimensions from snapshot
        $canvasData = json_decode($project->getCanvasSnapshotJson(), true) ?? [];
        $widthPx    = $canvasData['_bdp_width']  ?? 800;
        $heightPx   = $canvasData['_bdp_height'] ?? 600;

        return $this->respondWithData([
            'row_index'            => $rowIndex,
            'total_rows'           => $project->getTotalRows(),
            'resolved_data'        => $resolved,
            'canvas_snapshot_json' => $project->getCanvasSnapshotJson(),
            'width_px'             => $widthPx,   // ← frozen at creation, not live template
            'height_px'            => $heightPx,
        ]);
    }
}
