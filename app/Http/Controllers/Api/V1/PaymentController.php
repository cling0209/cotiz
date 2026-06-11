<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Order;
use App\Services\Payments\WebpayGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Payments')]
class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(protected WebpayGateway $webpay) {}

    #[OA\Post(path: '/api/v1/payments/webpay/create', summary: 'Iniciar pago Webpay', tags: ['Payments'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_uuid' => ['required', 'uuid', 'exists:orders,uuid'],
        ]);

        $order = Order::where('uuid', $data['order_uuid'])->firstOrFail();

        if ($order->payment_status === 'paid') {
            return $this->error('La orden ya está pagada.', 422);
        }

        try {
            $result = $this->webpay->createTransaction($order);

            return $this->success($result);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('No se pudo iniciar el pago Webpay.', 502);
        }
    }

    #[OA\Post(path: '/api/v1/payments/webpay/commit', summary: 'Confirmar pago Webpay', tags: ['Payments'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function commit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token_ws' => ['required_without:token', 'string'],
            'token' => ['required_without:token_ws', 'string'],
        ]);

        $token = $data['token_ws'] ?? $data['token'];

        try {
            $result = $this->webpay->commitTransaction($token);

            return $this->success($result);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('No se pudo confirmar el pago.', 502);
        }
    }
}
