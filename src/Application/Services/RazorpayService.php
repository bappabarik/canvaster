<?php
declare(strict_types=1);

namespace App\Application\Services;

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;
use RuntimeException;

class RazorpayService
{
    private Api $api;
    private string $webhookSecret;

    public function __construct(array $config)
    {
        $this->api           = new Api($config['key_id'], $config['key_secret']);
        $this->webhookSecret = $config['webhook_secret'];
    }

    /**
     * Create a Razorpay order.
     * Amount must be in paise (INR × 100).
     * receipt is your internal reference — we use payment row id.
     */
    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        $order = $this->api->order->create([
            'amount'          => $amountPaise,
            'currency'        => 'INR',
            'receipt'         => $receipt,
            'payment_capture' => 1,  // auto-capture — no manual capture needed
            'notes'           => $notes,
        ]);

        return $order->toArray();
    }

    /**
     * Verify the payment signature sent by Razorpay checkout on frontend callback.
     * Called after the user completes payment in the UPI app.
     */
    public function verifyPaymentSignature(
        string $orderId,
        string $paymentId,
        string $signature
    ): bool {
        try {
            $this->api->utility->verifyPaymentSignature([
                'razorpay_order_id'   => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature'  => $signature,
            ]);
            return true;
        } catch (SignatureVerificationError $e) {
            return false;
        }
    }

    /**
     * Verify the webhook signature from X-Razorpay-Signature header.
     * Always verify before trusting any webhook payload.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        try {
            $this->api->utility->verifyWebhookSignature(
                $rawBody,
                $signature,
                $this->webhookSecret
            );
            return true;
        } catch (SignatureVerificationError $e) {
            return false;
        }
    }

    public function fetchPayment(string $paymentId): array
    {
        return $this->api->payment->fetch($paymentId)->toArray();
    }
}