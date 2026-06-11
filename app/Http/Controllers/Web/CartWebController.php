<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartWebController extends Controller
{
    public function __construct(protected CartService $cartService) {}

    public function index(Request $request): View
    {
        $cart = $this->cartService->resolve($request);
        $formatted = $this->cartService->formatCart($cart);

        return view('shop.cart', [
            'cart' => $cart,
            'formatted' => $formatted,
            'cartCount' => $formatted['item_count'],
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        try {
            $cart = $this->cartService->resolve($request);
            $product = Product::active()->findOrFail($data['product_id']);
            $this->cartService->addItem($cart, $product, (int) $data['quantity']);

            return redirect()
                ->back()
                ->with('success', "{$product->name} agregado al carro.")
                ->cookie('cart_session', $cart->session_id, 60 * 24 * 30);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:99']]);

        $cart = $this->cartService->resolve($request);
        $item = $cart->items()->where('id', $id)->firstOrFail();
        $product = $item->product;

        if ($product->stock < $data['quantity']) {
            return redirect()->back()->with('error', $this->cartService->stockInsufficientMessage($product, (int) $data['quantity']));
        }

        $item->update(['quantity' => $data['quantity'], 'unit_price' => $product->price]);

        return redirect()->route('cart.index')->with('success', 'Carro actualizado.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        try {
            $cart = $this->cartService->resolve($request);
            $this->cartService->syncQuantities($cart, $data['items']);

            $response = redirect()->route('checkout.index');

            if ($cart->session_id) {
                $response->cookie('cart_session', $cart->session_id, 60 * 24 * 30);
            }

            return $response;
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('cart.index')->with('error', $e->getMessage());
        }
    }

    public function remove(Request $request, int $id): RedirectResponse
    {
        $cart = $this->cartService->resolve($request);
        $cart->items()->where('id', $id)->delete();

        return redirect()->route('cart.index')->with('success', 'Producto eliminado del carro.');
    }
}
