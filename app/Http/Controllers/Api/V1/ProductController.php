<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Products')]
class ProductController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/products', summary: 'Listar productos', tags: ['Products'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function index(Request $request): JsonResponse
    {
        $query = Product::active()
            ->with(['images', 'category', 'attributes'])
            ->search($request->query('q'));

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->boolean('featured')) {
            $query->featured();
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->float('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->float('max_price'));
        }

        $products = $query->orderByDesc('is_featured')
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', 20), 100));

        return $this->success($products->items(), [
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
        ]);
    }

    #[OA\Get(path: '/api/v1/products/{slug}', summary: 'Detalle producto', tags: ['Products'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function show(string $slug): JsonResponse
    {
        $product = Product::active()
            ->with(['images', 'category', 'attributes'])
            ->where('slug', $slug)
            ->firstOrFail();

        $related = Product::active()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->with('images')
            ->limit(4)
            ->get();

        return $this->success([
            'product' => $product,
            'related' => $related,
        ]);
    }

    #[OA\Post(path: '/api/v1/admin/products', summary: 'Crear producto', tags: ['Admin'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateProduct($request);

        $trashed = Product::onlyTrashed()->where('sku', $data['sku'])->first();

        if ($trashed) {
            $trashed->restore();
            $trashed->update($data);
            $product = $trashed;
        } else {
            $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
            $product = Product::create($data);
        }

        if ($request->has('images')) {
            $product->images()->delete();
            $this->syncImages($product, $request->input('images', []));
        }

        if ($request->has('attributes')) {
            $product->attributes()->delete();
            $this->syncAttributes($product, $request->input('attributes', []));
        }

        return $this->success($product->load(['images', 'attributes']), [], $trashed ? 200 : 201);
    }

    #[OA\Put(path: '/api/v1/admin/products/{id}', summary: 'Actualizar producto', tags: ['Admin'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validateProduct($request, $product->id);
        $product->update($data);

        if ($request->has('images')) {
            $product->images()->delete();
            $this->syncImages($product, $request->input('images', []));
        }

        if ($request->has('attributes')) {
            $product->attributes()->delete();
            $this->syncAttributes($product, $request->input('attributes', []));
        }

        return $this->success($product->fresh(['images', 'attributes', 'category']));
    }

    #[OA\Delete(path: '/api/v1/admin/products/{id}', summary: 'Eliminar producto', tags: ['Admin'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function destroy(Product $product): JsonResponse
    {
        $product->archive();

        return $this->success(['archived' => true]);
    }

    protected function validateProduct(Request $request, ?int $id = null): array
    {
        $skuRule = Rule::unique('products', 'sku')
            ->where(fn ($q) => $q->whereNull('deleted_at'));
        $slugRule = Rule::unique('products', 'slug')
            ->where(fn ($q) => $q->whereNull('deleted_at'));

        if ($id) {
            $skuRule->ignore($id);
            $slugRule->ignore($id);
        }

        return $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'sku' => ['required', 'string', 'max:60', $skuRule],
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', $slugRule],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'familia' => ['nullable', 'string', 'max:120'],
            'image_filename' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);
    }

    protected function syncImages(Product $product, array $images): void
    {
        foreach ($images as $i => $image) {
            $product->images()->create([
                'url' => $image['url'],
                'alt' => $image['alt'] ?? $product->name,
                'sort_order' => $image['sort_order'] ?? $i,
                'is_primary' => $image['is_primary'] ?? ($i === 0),
            ]);
        }
    }

    protected function syncAttributes(Product $product, array $attributes): void
    {
        foreach ($attributes as $attr) {
            $product->attributes()->create([
                'name' => $attr['name'],
                'value' => $attr['value'],
            ]);
        }
    }
}
