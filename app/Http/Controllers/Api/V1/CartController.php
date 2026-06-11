<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Cart')]
class CartController extends Controller
{
    use ApiResponse;

    public function __construct(protected CartService $cartService) {}

    #[OA\Get(path: '/api/v1/cart', summary: 'Obtener carrito', tags: ['Cart'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartService->resolve($request);
        $response = $this->success($this->cartService->formatCart($cart));

        if ($sessionId = $cart->session_id ?? $cart->getAttribute('session_token')) {
            $response->cookie('cart_session', $sessionId, 60 * 24 * 30, '/', null, false, false);
        }

        return $response;
    }

    #[OA\Post(path: '/api/v1/cart/items', summary: 'Agregar al carrito', tags: ['Cart'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        try {
            $cart = $this->cartService->resolve($request);
            $product = Product::active()->findOrFail($data['product_id']);
            $this->cartService->addItem($cart, $product, $data['quantity']);
            $response = $this->success($this->cartService->formatCart($cart->fresh()));

            if ($sessionId = $cart->session_id) {
                $response->cookie('cart_session', $sessionId, 60 * 24 * 30);
            }

            return $response;
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    #[OA\Patch(path: '/api/v1/cart/items/{id}', summary: 'Actualizar cantidad', tags: ['Cart'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function updateItem(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:99']]);

        $cart = $this->cartService->resolve($request);
        $item = $cart->items()->where('id', $id)->firstOrFail();
        $product = $item->product;

        if ($product->stock < $data['quantity']) {
            return $this->error('Stock insuficiente.', 422);
        }

        $item->update(['quantity' => $data['quantity'], 'unit_price' => $product->price]);

        return $this->success($this->cartService->formatCart($cart->fresh()));
    }

    #[OA\Delete(path: '/api/v1/cart/items/{id}', summary: 'Eliminar del carrito', tags: ['Cart'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function removeItem(Request $request, int $id): JsonResponse
    {
        $cart = $this->cartService->resolve($request);
        $cart->items()->where('id', $id)->delete();

        return $this->success($this->cartService->formatCart($cart->fresh()));
    }
}
