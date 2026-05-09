<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Payment;

use App\Domain\Payment\PaymentRepository;
use PDO;

class PdoPaymentRepository implements PaymentRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments
             (user_id, project_id, gateway, gateway_order_id,
              amount_paise, currency, credits_granted, status)
             VALUES (?, ?, "razorpay", ?, ?, "INR", ?, "created")'
        );
        $stmt->execute([
            $data['user_id'],
            $data['project_id']      ?? null,
            $data['gateway_order_id'],
            $data['amount_paise'],
            $data['credits_granted'],
        ]);

        return $this->findByGatewayOrderId($data['gateway_order_id']);
    }

    public function findByGatewayOrderId(string $gatewayOrderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payments WHERE gateway_order_id = ? LIMIT 1'
        );
        $stmt->execute([$gatewayOrderId]);
        return $stmt->fetch() ?: null;
    }

    public function findByProjectId(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payments WHERE project_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public function markPaid(string $gatewayOrderId, string $gatewayPaymentId): void
    {
        $this->pdo->prepare(
            'UPDATE payments
             SET status = "paid",
                 gateway_payment_id = ?,
                 paid_at = NOW()
             WHERE gateway_order_id = ?
               AND status = "created"'
        )->execute([$gatewayPaymentId, $gatewayOrderId]);
    }

    public function markFailed(string $gatewayOrderId): void
    {
        $this->pdo->prepare(
            'UPDATE payments SET status = "failed"
             WHERE gateway_order_id = ? AND status = "created"'
        )->execute([$gatewayOrderId]);
    }

    public function listTiers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM pricing_tiers
             WHERE is_active = 1
             ORDER BY sort_order ASC'
        );
        return $stmt->fetchAll();
    }
}