<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET /projects/{id}/status
 *
 * Lightweight polling endpoint — returns only what the frontend
 * needs to update a progress bar. Cheaper than GET /projects/{id}
 * which returns the full canvas snapshot JSON.
 *
 * Response shape:
 * {
 *   "project_id": 1,
 *   "status": "processing",
 *   "total_rows": 5,
 *   "rows_done": 3,
 *   "rows_failed": 0,
 *   "progress_pct": 60,
 *   "output_zip_url": null,
 *   "job": { "status": "processing", "rows_done": 3, "rows_failed": 0 }
 * }
 */
class GetProjectStatusAction extends Action
{
    public function __construct(
        LoggerInterface         $logger,
        private ProjectRepository $projects,
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
        $job     = $this->projects->getJob($projectId);

        $totalRows  = $project->getTotalRows();
        $rowsDone   = (int) ($job['rows_done']   ?? 0);
        $rowsFailed = (int) ($job['rows_failed']  ?? 0);

        // Progress percent based on rows completed (done + failed) out of total
        $progressPct = $totalRows > 0
            ? (int) round((($rowsDone + $rowsFailed) / $totalRows) * 100)
            : 0;

        return $this->respondWithData([
            'project_id'     => $project->getId(),
            'status'         => $project->getStatus(),
            'total_rows'     => $totalRows,
            'rows_done'      => $rowsDone,
            'rows_failed'    => $rowsFailed,
            'progress_pct'   => $progressPct,
            'output_zip_url' => $job['output_zip_path'] ?? null,
            'job'            => $job ? [
                'id'             => (int) $job['id'],
                'status'         => $job['status'],
                'rows_done'      => $rowsDone,
                'rows_failed'    => $rowsFailed,
                'started_at'     => $job['started_at'],
                'completed_at'   => $job['completed_at'],
                'error_log'      => $job['error_log'],
            ] : null,
        ]);
    }
}