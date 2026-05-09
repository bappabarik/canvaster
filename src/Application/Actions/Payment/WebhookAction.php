<?php
declare(strict_types=1);

namespace App\Application\Actions\Payment;

use App\Application\Actions\Action;
use App\Application\Services\RazorpayService;
use App\Domain\Payment\PaymentRepository;
use App\Domain\Project\ProjectRepository;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class WebhookAction extends Action
{
    public function __construct(
        LoggerInterface       $logger,
        private PaymentRepository  $payments,
        private ProjectRepository  $projects,
        private RazorpayService    $razorpay,
        private PDO                $pdo,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        // Read raw body — must not be parsed
        $rawBody  = (string) $this->request->getBody();
        $signature = $this->request->getHeaderLine('X-Razorpay-Signature');

        // Always verify webhook signature first
        if (!$this->razorpay->verifyWebhookSignature($rawBody, $signature)) {
            $this->logger->warning('Razorpay webhook signature mismatch');
            return $this->respondWithData(['error' => 'Invalid signature'], 400);
        }

        $payload = json_decode($rawBody, true);
        $event   = $payload['event'] ?? '';

        $this->logger->info("Razorpay webhook received: {$event}");

        match ($event) {
            'payment.captured' => $this->handlePaymentCaptured($payload),
            'payment.failed'   => $this->handlePaymentFailed($payload),
            default            => null,  // ignore other events
        };

        // Razorpay expects 200 OK — always return it even for unknown events
        return $this->respondWithData(['status' => 'ok']);
    }

    private function handlePaymentCaptured(array $payload): void
    {
        $payment   = $payload['payload']['payment']['entity'] ?? [];
        $orderId   = $payment['order_id']  ?? '';
        $paymentId = $payment['id']        ?? '';

        if (!$orderId || !$paymentId) return;

        $dbPayment = $this->payments->findByGatewayOrderId($orderId);
        if (!$dbPayment) {
            $this->logger->warning("Webhook: no DB payment found for order {$orderId}");
            return;
        }

        // Idempotency — already processed (verify endpoint got there first)
        if ($dbPayment['status'] === 'paid') {
            $this->logger->info("Webhook: order {$orderId} already paid, skipping");
            return;
        }

        $userId        = (int) $dbPayment['user_id'];
        $creditsToAdd  = (int) $dbPayment['credits_granted'];
        $projectId     = $dbPayment['project_id'] ? (int) $dbPayment['project_id'] : null;

        $this->pdo->beginTransaction();
        try {
            $this->payments->markPaid($orderId, $paymentId);

            $this->pdo->prepare(
                'UPDATE users SET credits = credits + ? WHERE id = ?'
            )->execute([$creditsToAdd, $userId]);

            if ($projectId) {
                $project = $this->projects->findById($projectId);
                if ($project && $project->getStatus() === 'pending_payment') {
                    $rowsNeeded = $project->getTotalRows();

                    $stmt = $this->pdo->prepare('SELECT credits FROM users WHERE id = ?');
                    $stmt->execute([$userId]);
                    $totalCredits = (int) $stmt->fetchColumn();

                    if ($totalCredits >= $rowsNeeded) {
                        $this->pdo->prepare(
                            'UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?'
                        )->execute([$rowsNeeded, $userId, $rowsNeeded]);

                        $this->projects->update($projectId, ['status' => 'queued']);
                        $this->projects->createJob($projectId);

                        $this->logger->info(
                            "Webhook: project {$projectId} queued via payment.captured"
                        );
                    }
                }
            }

            $this->pdo->commit();
            $this->logger->info(
                "Webhook: payment captured for user {$userId}, +{$creditsToAdd} credits"
            );
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error("Webhook: failed to process payment: " . $e->getMessage());
        }
    }

    private function handlePaymentFailed(array $payload): void
    {
        $payment = $payload['payload']['payment']['entity'] ?? [];
        $orderId = $payment['order_id'] ?? '';

        if (!$orderId) return;

        $this->payments->markFailed($orderId);
        $this->logger->info("Webhook: payment failed for order {$orderId}");
    }
}