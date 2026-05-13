<?php
declare(strict_types=1);

namespace App\Application\Actions\Payment;

use App\Application\Actions\Action;
use App\Domain\Payment\PaymentRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * POST /payments/use-credits
 *
 * Deduct credits from the user's existing wallet and queue a project,
 * without going through Razorpay. Only works if user has enough credits.
 */
class UseCreditsAction extends Action
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
        $body      = (array) $this->request->getParsedBody();
        $projectId = (int) ($body['project_id'] ?? 0);

        if (!$projectId) {
            throw new HttpBadRequestException($this->request, 'project_id is required');
        }

        if (!$this->projects->belongsToUser($projectId, $user->getId())) {
            throw new HttpNotFoundException($this->request, 'Project not found');
        }

        $project = $this->projects->findById($projectId);

        if ($project->getStatus() !== 'pending_payment') {
            throw new HttpBadRequestException(
                $this->request,
                'Project is not awaiting payment (status: ' . $project->getStatus() . ')'
            );
        }

        $rowsNeeded = $project->getTotalRows();

        // Re-fetch credits inside transaction for accuracy
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT credits FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$user->getId()]);
            $currentCredits = (int) $stmt->fetchColumn();

            if ($currentCredits < $rowsNeeded) {
                $this->pdo->rollBack();
                throw new HttpBadRequestException(
                    $this->request,
                    "Insufficient credits. You have {$currentCredits}, need {$rowsNeeded}."
                );
            }

            // Deduct credits
            $this->pdo->prepare(
                'UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?'
            )->execute([$rowsNeeded, $user->getId(), $rowsNeeded]);

            // Queue the project
            $this->projects->update($projectId, ['status' => 'queued']);
            $this->projects->createJob($projectId);

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Fetch updated balance
        $stmt = $this->pdo->prepare('SELECT credits FROM users WHERE id = ?');
        $stmt->execute([$user->getId()]);
        $newCredits = (int) $stmt->fetchColumn();

        $this->logger->info(
            "Project {$projectId} queued using existing credits. " .
            "User {$user->getId()}: -{$rowsNeeded} credits, remaining: {$newCredits}"
        );

        return $this->respondWithData([
            'message'        => 'Credits deducted and project queued',
            'credits_used'   => $rowsNeeded,
            'credits_remaining' => $newCredits,
            'project_id'     => $projectId,
        ]);
    }
}