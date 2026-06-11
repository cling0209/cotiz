<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartService
{
    public function resolve(Request $request): Cart
    {
        $sessionId = $request->header('X-Cart-Session') ?? $request->cookie('cart_session');

        if ($request->user()) {
            $cart = Cart::firstOrCreate(['user_id' => $request->user()->id]);

            if ($sessionId) {
                $guestCart = Cart::where('session_id', $sessionId)->whereNull('user_id')->first();
                if ($guestCart) {
                    $this->mergeCarts($guestCart, $cart);
                    $guestCart->delete();
                }
            }

            $cart->load('items.product.images');
            $this->purgeUnavailableItems($cart, $request);

            return $cart;
        }

        if (! $sessionId) {
            $sessionId = (string) Str::uuid();
        }

        $cart = Cart::firstOrCreate(['session_id' => $sessionId], ['session_id' => $sessionId]);

        $cart->setAttribute('session_token', $sessionId);

        $cart->load('items.product.images');
        $this->purgeUnavailableItems($cart, $request);

        return $cart;
    }

    protected function mergeCarts(Cart $from, Cart $to): void
    {
        foreach ($from->items as $item) {
            if (! $this->isProductAvailable($item->product_id)) {
                continue;
            }

            $existing = $to->items()->where('product_id', $item->product_id)->first();
            if ($existing) {
                $existing->update(['quantity' => $existing->quantity + $item->quantity]);
            } else {
                $to->items()->create([
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                ]);
            }
        }
    }

    public function stockInsufficientMessage(Product $product, int $requested): string
    {
        if ($product->stock < 1) {
            return "Lamentamos informarte que {$product->name} ya no tiene stock disponible.";
        }

        return "Lamentamos informarte que no hay stock suficiente para {$product->name}. Solo hay {$product->stock} unidad(es) disponible(s).";
    }

    /**
     * @param  array<int|string, int>  $quantities
     */
    public function syncQuantities(Cart $cart, array $quantities): void
    {
        $cart->loadMissing('items.product');

        if ($cart->items->isEmpty()) {
            throw new \InvalidArgumentException('Tu carro está vacío.');
        }

        $messages = [];

        foreach ($cart->items as $item) {
            $qty = (int) ($quantities[$item->id] ?? $quantities[(string) $item->id] ?? $item->quantity);
            $product = $item->product;

            if ($product === null || ! $product->is_active) {
                $messages[] = 'Lamentamos informarte que un producto de tu carro ya no está disponible.';

                continue;
            }

            if ($product->stock < $qty) {
                $messages[] = $this->stockInsufficientMessage($product, $qty);
            }
        }

        if ($messages !== []) {
            throw new \InvalidArgumentException(implode(' ', array_unique($messages)));
        }

        foreach ($cart->items as $item) {
            $qty = (int) ($quantities[$item->id] ?? $quantities[(string) $item->id] ?? $item->quantity);
            $product = $item->product;

            if ($product === null) {
                continue;
            }

            $item->update([
                'quantity' => $qty,
                'unit_price' => $product->price,
            ]);
        }
    }

    public function addItem(Cart $cart, Product $product, int $quantity): CartItem
    {
        if ($product->stock < $quantity) {
            throw new \InvalidArgumentException($this->stockInsufficientMessage($product, $quantity));
        }

        $item = $cart->items()->where('product_id', $product->id)->first();

        if ($item) {
            $newQty = $item->quantity + $quantity;
            if ($product->stock < $newQty) {
                throw new \InvalidArgumentException($this->stockInsufficientMessage($product, $newQty));
            }
            $item->update(['quantity' => $newQty, 'unit_price' => $product->price]);

            return $item->fresh();
        }

        return $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->price,
        ]);
    }

    public function clearItems(Cart $cart): void
    {
        $cart->items()->delete();
    }

    public function formatCart(Cart $cart): array
    {
        $this->purgeUnavailableItems($cart);
        $cart->loadMissing('items.product.images');
        $totals = $cart->recalculateTotals();

        return [
            'id' => $cart->id,
            'session_id' => $cart->session_id ?? $cart->getAttribute('session_token'),
            'items' => $cart->items
                ->filter(fn (CartItem $item) => $item->product !== null)
                ->values()
                ->map(fn (CartItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => round($item->unit_price * $item->quantity, 2),
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'slug' => $item->product->slug,
                        'stock' => $item->product->stock,
                        'image' => $item->product->resolveImageUrl(),
                    ],
                ]),
            'subtotal' => $totals['subtotal'],
            'item_count' => $totals['item_count'],
        ];
    }

    protected function purgeUnavailableItems(Cart $cart, ?Request $request = null): int
    {
        $cart->loadMissing('items.product');

        $orphanIds = $cart->items
            ->filter(fn (CartItem $item) => $item->product === null || ! $item->product->is_active)
            ->pluck('id');

        if ($orphanIds->isEmpty()) {
            return 0;
        }

        CartItem::query()->whereIn('id', $orphanIds)->delete();
        $cart->unsetRelation('items');

        if ($request && ! $request->expectsJson()) {
            session()->flash(
                'warning',
                'Se quitaron del carro producto(s) que ya no están disponibles.'
            );
        }

        return $orphanIds->count();
    }

    protected function isProductAvailable(?int $productId): bool
    {
        if ($productId === null) {
            return false;
        }

        return Product::query()->active()->whereKey($productId)->exists();
    }
}
