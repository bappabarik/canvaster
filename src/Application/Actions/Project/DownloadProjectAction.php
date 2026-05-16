<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Domain\Project\ProjectRepository;
use App\Application\Services\CloudinaryService; // <-- Add this import
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class DownloadProjectAction extends Action
{
    public function __construct(
        LoggerInterface         $logger,
        private ProjectRepository $projects,
        private CloudinaryService $cloudinaryService // <-- Inject the service here
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

        // Generate the official Cloudinary ZIP URL dynamically
        $downloadUrl = $this->cloudinaryService->getZipDownloadUrl($projectId);

        return $this->respondWithData([
            'project_id'    => $project->getId(),
            'project_name'  => $project->getName(),
            'output_format' => $project->getOutputFormat(),
            'total_rows'    => $project->getTotalRows(),
            'rows_done'     => (int) ($job['rows_done']   ?? 0),
            'rows_failed'   => (int) ($job['rows_failed']  ?? 0),
            'download_url'  => $downloadUrl, // <-- Use the newly generated URL
            'completed_at'  => $job['completed_at'] ?? null,
        ]);
    }
}