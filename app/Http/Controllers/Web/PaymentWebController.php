<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\Payments\WebpayGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentWebController extends Controller
{
    public function __construct(
        protected WebpayGateway $webpay,
        protected CartService $cartService,
        protected OrderService $orderService,
    ) {}

    public function return(Request $request): View|RedirectResponse
    {
        $token = $request->input('token_ws') ?? $request->input('TBK_TOKEN');

        if (! $token) {
            return $this->handleCancelledReturn($request);
        }

        try {
            $result = $this->webpay->commitTransaction($token);
            $order = Order::where('uuid', $result['order_uuid'])->firstOrFail();

            if ($result['approved']) {
                $this->cartService->clearItems($this->cartService->resolve($request));
                $request->session()->forget('pending_order_uuid');

                return view('shop.order-success', [
                    'order' => $order->load(['items', 'paymentTransactions']),
                    'payment' => $result,
                    'cartCount' => 0,
                ]);
            }

            $request->session()->put('pending_order_uuid', $order->uuid);

            return view('shop.order-failed', [
                'order' => $order,
                'cartCount' => $this->cartService->formatCart($this->cartService->resolve($request))['item_count'],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('home')->with('error', 'Error al confirmar el pago.');
        }
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        $order = Order::where('uuid', $uuid)->firstOrFail();

        if (! $this->orderService->canRetryPayment(
            $order,
            $request->user(),
            $request->session()->get('pending_order_uuid'),
        )) {
            abort(403);
        }

        try {
            $this->orderService->prepareForPaymentRetry($order);
            $payment = $this->webpay->createTransaction($order);
            $request->session()->put('pending_order_uuid', $order->uuid);

            return redirect()->away($payment['url'].'?token_ws='.$payment['token']);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('cart.index')
                ->with('error', 'No se pudo reintentar el pago. Intenta desde el checkout.');
        }
    }

    protected function handleCancelledReturn(Request $request): View|RedirectResponse
    {
        $uuid = $request->session()->get('pending_order_uuid');

        if ($uuid) {
            $order = Order::where('uuid', $uuid)
                ->whereIn('status', ['pending_payment', 'payment_failed'])
                ->first();

            if ($order) {
                return view('shop.order-failed', [
                    'order' => $order,
                    'cartCount' => $this->cartService->formatCart($this->cartService->resolve($request))['item_count'],
                    'cancelled' => true,
                ]);
            }
        }

        return redirect()->route('home')->with('error', 'Pago cancelado o sesión inválida.');
    }
}
