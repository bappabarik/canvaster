<?php
declare(strict_types=1);

namespace App\Application\Actions\Project;

use App\Application\Actions\Action;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class SubmitProjectAction extends Action
{
    public function __construct(
        LoggerInterface         $logger,
        private ProjectRepository $projects,
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

        $rowsNeeded = $project->getTotalRows();

        // ── Check existing credits first ──────────────────────────────────────
        $this->pdo->beginTransaction();
        try {
            // Lock the user row to prevent race conditions
            $stmt = $this->pdo->prepare(
                'SELECT credits FROM users WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$user->getId()]);
            $currentCredits = (int) $stmt->fetchColumn();

            if ($currentCredits >= $rowsNeeded) {
                // Enough credits — deduct and queue immediately, no payment needed
                $this->pdo->prepare(
                    'UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?'
                )->execute([$rowsNeeded, $user->getId(), $rowsNeeded]);

                $this->projects->update($projectId, ['status' => 'queued']);
                $this->projects->createJob($projectId);

                $this->pdo->commit();

                $this->logger->info(
                    "Project {$projectId} queued directly using {$rowsNeeded} credits. " .
                    "User {$user->getId()} remaining: " . ($currentCredits - $rowsNeeded)
                );

                $updated = $this->projects->findById($projectId);

                return $this->respondWithData([
                    'project'          => $updated->jsonSerialize(),
                    'total_rows'       => $updated->getTotalRows(),
                    'queued_directly'  => true,
                    'credits_used'     => $rowsNeeded,
                    'credits_remaining'=> $currentCredits - $rowsNeeded,
                    'message'          => 'Project queued using existing credits. Generation will start shortly.',
                ]);
            }

            // Not enough credits — move to pending_payment
            $this->projects->update($projectId, ['status' => 'pending_payment']);
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $updated = $this->projects->findById($projectId);

        return $this->respondWithData([
            'project'         => $updated->jsonSerialize(),
            'total_rows'      => $updated->getTotalRows(),
            'queued_directly' => false,
            'credits_needed'  => $rowsNeeded,
            'credits_have'    => $currentCredits,
            'message'         => 'Project submitted. Complete payment to start generation.',
        ]);
    }
}