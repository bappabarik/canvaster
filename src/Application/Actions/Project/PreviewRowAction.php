<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class PreviewRowAction extends Action
{
    public function __construct(
        LoggerInterface   $logger,
        private ProjectRepository $projects,
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

        $rowData   = json_decode($row['data'], true);
        $columnMap = $project->getColumnMap();

        // Apply column map: replace placeholder keys with actual CSV values
        $resolved = [];
        foreach ($columnMap as $placeholder => $csvHeader) {
            $resolved[$placeholder] = $rowData[$csvHeader] ?? null;
        }

        return $this->respondWithData([
            'row_index'     => $rowIndex,
            'total_rows'    => $project->getTotalRows(),
            'resolved_data' => $resolved,
            'canvas_snapshot_json' => $project->getCanvasSnapshotJson(),
        ]);
    }
}