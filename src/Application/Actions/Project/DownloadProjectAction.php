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

/**
 * GET /projects/{id}/download
 *
 * Returns the download URL for a completed project's output ZIP.
 * Does NOT redirect — returns JSON so the frontend can handle it
 * (open in new tab, show a button, etc.).
 *
 * Only available when project status = 'done'.
 *
 * Response shape:
 * {
 *   "project_id": 1,
 *   "project_name": "Class 10A ID Cards",
 *   "output_format": "zip_png",
 *   "total_rows": 5,
 *   "rows_done": 5,
 *   "rows_failed": 0,
 *   "download_url": "https://res.cloudinary.com/...",
 *   "completed_at": "2024-01-15 10:30:00"
 * }
 */
class DownloadProjectAction extends Action
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

        if ($project->getStatus() !== 'done') {
            throw new HttpBadRequestException(
                $this->request,
                'Project is not ready for download (status: ' . $project->getStatus() . ')'
            );
        }

        $job = $this->projects->getJob($projectId);

        if (!$job || empty($job['output_zip_path'])) {
            throw new HttpNotFoundException(
                $this->request,
                'Output file not found — the job may still be processing'
            );
        }

        return $this->respondWithData([
            'project_id'    => $project->getId(),
            'project_name'  => $project->getName(),
            'output_format' => $project->getOutputFormat(),
            'total_rows'    => $project->getTotalRows(),
            'rows_done'     => (int) ($job['rows_done']   ?? 0),
            'rows_failed'   => (int) ($job['rows_failed']  ?? 0),
            'download_url'  => $job['output_zip_path'],
            'completed_at'  => $job['completed_at'],
        ]);
    }
}