<?php
declare(strict_types=1);

namespace App\Application\Actions\Payment;

use App\Application\Actions\Action;
use App\Application\Services\RazorpayService;
use App\Application\Validation\RequestValidator;
use App\Domain\Payment\PaymentRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validator as v;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class CreateOrderAction extends Action
{
    public function __construct(
        LoggerInterface       $logger,
        private PaymentRepository  $payments,
        private RazorpayService    $razorpay,
        private RequestValidator   $validator,
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
                'tier_id'    => v::notEmpty()->intType()->positive(),
                'project_id' => v::optional(v::intType()->positive()),
            ]
        );

        // Look up the pricing tier
        $tiers = $this->payments->listTiers();
        $tier  = null;
        foreach ($tiers as $t) {
            if ((int) $t['id'] === (int) $data['tier_id']) {
                $tier = $t;
                break;
            }
        }

        if (!$tier) {
            throw new HttpNotFoundException($this->request, 'Pricing tier not found');
        }

        $receipt = 'bdp_u' . $user->getId() . '_t' . $tier['id'] . '_' . time();

        // Create order in Razorpay
        $rzpOrder = $this->razorpay->createOrder(
            (int) $tier['price_paise'],
            $receipt,
            [
                'user_id'       => $user->getId(),
                'tier_id'       => $tier['id'],
                'credits'       => $tier['designs_count'],
                'project_id'    => $data['project_id'] ?? '',
            ]
        );

        // Save payment record locally — status: created
        $payment = $this->payments->create([
            'user_id'          => $user->getId(),
            'project_id'       => $data['project_id'] ?? null,
            'gateway_order_id' => $rzpOrder['id'],
            'amount_paise'     => (int) $tier['price_paise'],
            'credits_granted'  => (int) $tier['designs_count'],
        ]);

        return $this->respondWithData([
            'payment'          => $payment,
            'razorpay_order_id'=> $rzpOrder['id'],
            'amount_paise'     => (int) $tier['price_paise'],
            'amount_inr'       => number_format($tier['price_paise'] / 100, 2),
            'credits'          => (int) $tier['designs_count'],
            'key_id'           => $_ENV['RAZORPAY_KEY_ID'],  // frontend needs this
            'prefill' => [
                'name'  => $user->getName(),
                'email' => $user->getEmail(),
            ],
        ], 201);
    }
}