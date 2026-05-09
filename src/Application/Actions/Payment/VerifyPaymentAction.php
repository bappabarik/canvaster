<?php
declare(strict_types=1);

namespace App\Application\Actions\Payment;

use App\Application\Actions\Action;
use App\Application\Services\RazorpayService;
use App\Application\Validation\RequestValidator;
use App\Domain\Payment\PaymentRepository;
use App\Domain\Project\ProjectRepository;
use App\Domain\User\User;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validator as v;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class VerifyPaymentAction extends Action
{
    public function __construct(
        LoggerInterface       $logger,
        private PaymentRepository  $payments,
        private ProjectRepository  $projects,
        private RazorpayService    $razorpay,
        private RequestValidator   $validator,
        private PDO                $pdo,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        /** @var User $user */
        $user = $this->request->getAttribute('user');

        $data = $this->validator->validate(
            (array) $this->request->getParsedBody(),
            [
                'razorpay_order_id'   => v::notEmpty()->stringType(),
                'razorpay_payment_id' => v::notEmpty()->stringType(),
                'razorpay_signature'  => v::notEmpty()->stringType(),
            ]
        );

        // Find our payment record
        $payment = $this->payments->findByGatewayOrderId($data['razorpay_order_id']);
        if (!$payment || (int) $payment['user_id'] !== $user->getId()) {
            throw new HttpNotFoundException($this->request, 'Payment not found');
        }

        if ($payment['status'] === 'paid') {
            return $this->respondWithData([
                'message' => 'Payment already verified',
                'credits' => $user->getCredits(),
            ]);
        }

        // Verify signature — this is the critical security check
        $valid = $this->razorpay->verifyPaymentSignature(
            $data['razorpay_order_id'],
            $data['razorpay_payment_id'],
            $data['razorpay_signature']
        );

        if (!$valid) {
            $this->payments->markFailed($data['razorpay_order_id']);
            throw new HttpBadRequestException($this->request, 'Payment signature verification failed');
        }

        // Mark paid and credit the user — wrapped in a transaction
        $this->pdo->beginTransaction();
        try {
            $this->payments->markPaid(
                $data['razorpay_order_id'],
                $data['razorpay_payment_id']
            );

            $creditsToAdd = (int) $payment['credits_granted'];

            // Add credits to user wallet
            $this->pdo->prepare(
                'UPDATE users SET credits = credits + ? WHERE id = ?'
            )->execute([$creditsToAdd, $user->getId()]);

            // If payment was for a specific project, move it to queued
            if ($payment['project_id']) {
                $this->deductAndQueueProject(
                    (int) $payment['project_id'],
                    $user->getId(),
                    $creditsToAdd
                );
            }

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Fetch updated credit balance
        $stmt = $this->pdo->prepare('SELECT credits FROM users WHERE id = ?');
        $stmt->execute([$user->getId()]);
        $newCredits = (int) $stmt->fetchColumn();

        $this->logger->info(
            "Payment verified for user {$user->getId()}: +{$creditsToAdd} credits"
        );

        return $this->respondWithData([
            'message'    => 'Payment successful',
            'credits'    => $newCredits,
            'added'      => $creditsToAdd,
            'project_queued' => (bool) $payment['project_id'],
        ]);
    }

    private function deductAndQueueProject(int $projectId, int $userId, int $creditsAdded): void
    {
        $project = $this->projects->findById($projectId);
        if (!$project || $project->getUserId() !== $userId) return;
        if ($project->getStatus() !== 'pending_payment') return;

        $rowsNeeded = $project->getTotalRows();

        // Check user now has enough credits (just added + existing)
        $stmt = $this->pdo->prepare('SELECT credits FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $totalCredits = (int) $stmt->fetchColumn();

        // totalCredits already includes the credits we just added above
        if ($totalCredits < $rowsNeeded) {
            // Not enough even after payment — keep in pending_payment
            // This handles edge case where user bought fewer credits than rows
            return;
        }

        // Deduct credits for this project
        $this->pdo->prepare(
            'UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?'
        )->execute([$rowsNeeded, $userId, $rowsNeeded]);

        // Move project to queued and create generation job
        $this->projects->update($projectId, ['status' => 'queued']);
        $this->projects->createJob($projectId);

        $this->logger->info(
            "Project {$projectId} queued with {$rowsNeeded} rows deducted from user {$userId}"
        );
    }
}