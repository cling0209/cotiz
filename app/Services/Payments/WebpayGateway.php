<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\OrderConfirmationMailer;
use App\Support\WebpayPaymentType;
use Transbank\Webpay\Options;
use Transbank\Webpay\WebpayPlus;
use Transbank\Webpay\WebpayPlus\Transaction;

class WebpayGateway implements PaymentGatewayInterface
{
    public function __construct(
        protected OrderConfirmationMailer $confirmationMailer,
    ) {}

    protected function options(): Options
    {
        $env = config('transbank.environment') === 'production'
            ? Options::ENVIRONMENT_PRODUCTION
            : Options::ENVIRONMENT_INTEGRATION;

        return new Options(
            config('transbank.api_key') ?? WebpayPlus::INTEGRATION_API_KEY,
            config('transbank.commerce_code') ?? WebpayPlus::INTEGRATION_COMMERCE_CODE,
            $env
        );
    }

    public function createTransaction(Order $order): array
    {
        $buyOrder = 'CR-'.$order->id.'-'.time();
        $transaction = new Transaction($this->options());

        $response = $transaction->create(
            $buyOrder,
            (string) $order->uuid,
            (int) round($order->total),
            config('transbank.return_url')
        );

        PaymentTransaction::create([
            'order_id' => $order->id,
            'gateway' => 'webpay',
            'buy_order' => $buyOrder,
            'token' => $response->getToken(),
            'amount' => $order->total,
            'status' => 'created',
            'raw_response' => [
                'url' => $response->getUrl(),
                'token' => $response->getToken(),
            ],
        ]);

        $order->update([
            'payment_method' => 'webpay',
            'payment_status' => 'pending',
        ]);

        return [
            'token' => $response->getToken(),
            'url' => $response->getUrl(),
            'buy_order' => $buyOrder,
        ];
    }

    public function commitTransaction(string $token): array
    {
        $transaction = new Transaction($this->options());
        $response = $transaction->commit($token);

        $payment = PaymentTransaction::where('token', $token)->firstOrFail();
        $order = $payment->order;

        $approved = $response->isApproved();
        $paymentTypeCode = $response->getPaymentTypeCode();
        $cardType = WebpayPaymentType::resolveCardType($paymentTypeCode);
        $cardLastFour = $response->getCardNumber();
        $installmentsNumber = $response->getInstallmentsNumber();

        $payment->update([
            'status' => $approved ? 'approved' : 'rejected',
            'payment_type_code' => $paymentTypeCode,
            'card_type' => $cardType,
            'card_last_four' => $cardLastFour,
            'installments_number' => $installmentsNumber,
            'raw_response' => [
                'response_code' => $response->getResponseCode(),
                'status' => $response->getStatus(),
                'buy_order' => $response->getBuyOrder(),
                'amount' => $response->getAmount(),
                'authorization_code' => $response->getAuthorizationCode(),
                'payment_type_code' => $paymentTypeCode,
                'payment_type_label' => WebpayPaymentType::label($paymentTypeCode),
                'card_type' => $cardType,
                'card_last_four' => $cardLastFour,
                'installments_number' => $installmentsNumber,
                'transaction_date' => $response->getTransactionDate(),
            ],
        ]);

        $previousStatus = $order->status;

        if ($approved) {
            $order->update([
                'payment_status' => 'paid',
                'status' => 'paid',
            ]);
            $order->recordStatus('paid', $previousStatus, 'Pago Webpay aprobado');

            if ($previousStatus !== 'paid') {
                try {
                    $this->confirmationMailer->send(
                        $order->fresh(['items']),
                        [
                            'payment_type_label' => WebpayPaymentType::label($paymentTypeCode),
                            'card_last_four' => $cardLastFour,
                            'installments_number' => $installmentsNumber,
                        ],
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        } else {
            $order->update([
                'payment_status' => 'failed',
                'status' => 'payment_failed',
            ]);
            $order->recordStatus('payment_failed', $previousStatus, 'Pago Webpay rechazado');
        }

        return [
            'approved' => $approved,
            'order_uuid' => $order->uuid,
            'response_code' => $response->getResponseCode(),
            'amount' => $response->getAmount(),
            'payment_type_code' => $paymentTypeCode,
            'payment_type_label' => WebpayPaymentType::label($paymentTypeCode),
            'card_type' => $cardType,
            'card_last_four' => $cardLastFour,
            'installments_number' => $installmentsNumber,
        ];
    }
}
