<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShopController extends Controller
{
    public function __construct(protected CartService $cartService) {}

    public function home(Request $request): View
    {
        $featured = Product::active()
            ->with(['images', 'category'])
            ->featured()
            ->limit(8)
            ->get();

        $categories = Category::where('is_active', true)
            ->orderBy('sort_order')
            ->limit(6)
            ->get();

        return view('shop.home', [
            'featured' => $featured,
            'categories' => $categories,
            'cartCount' => $this->cartCount($request),
        ]);
    }

    public function catalog(Request $request): View
    {
        $query = Product::active()->with(['images', 'category']);

        if ($search = $request->string('q')->trim()->toString()) {
            $query->search($search);
        }

        if ($category = $request->string('category')->trim()->toString()) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $category));
        }

        $products = $query->orderByDesc('is_featured')->orderBy('name')->paginate(12);
        $categories = Category::where('is_active', true)->orderBy('sort_order')->get();

        return view('shop.catalog', [
            'products' => $products,
            'categories' => $categories,
            'search' => $search ?? '',
            'activeCategory' => $category ?? '',
            'cartCount' => $this->cartCount($request),
        ]);
    }

    public function about(Request $request): View
    {
        return view('shop.about', [
            'cartCount' => $this->cartCount($request),
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $product = Product::active()
            ->with(['images', 'attributes', 'category'])
            ->where('slug', $slug)
            ->firstOrFail();

        $related = Product::active()
            ->with('images')
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->limit(4)
            ->get();

        return view('shop.product', [
            'product' => $product,
            'related' => $related,
            'cartCount' => $this->cartCount($request),
        ]);
    }

    protected function cartCount(Request $request): int
    {
        $cart = $this->cartService->resolve($request);

        return (int) $cart->items->sum('quantity');
    }
}
