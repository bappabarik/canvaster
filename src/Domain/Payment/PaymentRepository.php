<?php
declare(strict_types=1);

namespace App\Domain\Payment;

interface PaymentRepository
{
    public function create(array $data): array;
    public function findByGatewayOrderId(string $gatewayOrderId): ?array;
    public function findByProjectId(int $projectId): array;
    public function markPaid(string $gatewayOrderId, string $gatewayPaymentId): void;
    public function markFailed(string $gatewayOrderId): void;
    public function listTiers(): array;
}