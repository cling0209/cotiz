<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Order;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Orders')]
class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CartService $cartService,
        protected OrderService $orderService,
    ) {}

    #[OA\Post(path: '/api/v1/orders', summary: 'Crear orden desde carrito', tags: ['Orders'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function store(Request $request): JsonResponse
    {
        $shipping = $request->validate([
            'recipient_name' => ['required', 'string', 'max:120'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string', 'max:20'],
            'region' => ['required', 'string', 'max:80'],
            'comuna' => ['required', 'string', 'max:80'],
            'street' => ['required', 'string', 'max:200'],
            'street_number' => ['nullable', 'string', 'max:20'],
            'apartment' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $cart = $this->cartService->resolve($request);
            $order = $this->orderService->createFromCart($cart, $shipping, $request->user());

            return $this->success([
                'uuid' => $order->uuid,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'subtotal' => $order->subtotal,
                'shipping_amount' => $order->shipping_amount,
                'total' => $order->total,
                'items' => $order->items,
            ], [], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    #[OA\Get(path: '/api/v1/orders/{uuid}', summary: 'Detalle de orden', tags: ['Orders'])]
    #[OA\Response(response: 200, description: 'OK')]
    #[OA\Response(response: 403, description: 'Acceso no autorizado')]
    public function show(Request $request, string $uuid): JsonResponse
    {
        $order = Order::with(['items', 'paymentTransactions'])->where('uuid', $uuid)->firstOrFail();

        if (! $this->orderService->canViewOrder(
            $order,
            $request->user(),
            $request->session()->get('pending_order_uuid'),
        )) {
            return $this->error('Acceso no autorizado.', 403);
        }

        return $this->success($order);
    }

    #[OA\Get(path: '/api/v1/admin/orders', summary: 'Listar órdenes (admin)', tags: ['Admin'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function adminIndex(Request $request): JsonResponse
    {
        $orders = Order::with(['items', 'paymentTransactions'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->success($orders->items(), [
            'current_page' => $orders->currentPage(),
            'total' => $orders->total(),
        ]);
    }

    #[OA\Patch(path: '/api/v1/admin/orders/{id}', summary: 'Actualizar orden', tags: ['Admin'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function adminUpdate(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending_payment,paid,processing,shipped,delivered,cancelled,payment_failed'],
            'payment_status' => ['sometimes', 'string', 'in:pending,paid,failed,refunded'],
        ]);

        if (isset($data['status']) && $data['status'] !== $order->status) {
            $order->recordStatus($data['status'], $order->status, 'Actualizado por admin');
            unset($data['status']);
        }

        $order->update($data);

        return $this->success($order->fresh(['items', 'statusHistory', 'paymentTransactions']));
    }
}
