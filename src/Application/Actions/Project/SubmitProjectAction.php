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

class SubmitProjectAction extends Action
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

        if (!$this->projects->belongsToUser($projectId, $user->getId())) {
            throw new HttpNotFoundException($this->request, 'Project not found');
        }

        $project = $this->projects->findById($projectId);

        if ($project->getStatus() !== 'draft') {
            throw new HttpBadRequestException(
                $this->request,
                'Project is already submitted (status: ' . $project->getStatus() . ')'
            );
        }

        if ($project->getTotalRows() === 0) {
            throw new HttpBadRequestException($this->request, 'Upload a CSV before submitting');
        }

        if (!$project->getColumnMap()) {
            throw new HttpBadRequestException($this->request, 'Map columns before submitting');
        }

        // Move to pending_payment — payment group will handle the next step
        $updated = $this->projects->update($projectId, ['status' => 'pending_payment']);

        return $this->respondWithData([
            'project'    => $updated->jsonSerialize(),
            'total_rows' => $updated->getTotalRows(),
            'message'    => 'Project submitted. Complete payment to start generation.',
        ]);
    }
}