<?php
declare(strict_types=1);

namespace App\Application\Actions\Payment;

use App\Application\Actions\Action;
use App\Domain\Payment\PaymentRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListPricingAction extends Action
{
    public function __construct(
        LoggerInterface       $logger,
        private PaymentRepository $payments,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $tiers = $this->payments->listTiers();

        // Format price for display
        $formatted = array_map(function ($tier) {
            return [
                'id'            => (int) $tier['id'],
                'label'         => $tier['label'],
                'designs_count' => (int) $tier['designs_count'],
                'price_paise'   => (int) $tier['price_paise'],
                'price_inr'     => number_format($tier['price_paise'] / 100, 2),
                'per_design_paise' => (int) round($tier['price_paise'] / $tier['designs_count']),
            ];
        }, $tiers);

        return $this->respondWithData(['tiers' => $formatted]);
    }
}