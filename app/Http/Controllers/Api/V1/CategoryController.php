<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Category;
use App\Support\CategorySlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Categories')]
class CategoryController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/categories', summary: 'Listar categorías', tags: ['Categories'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function index(): JsonResponse
    {
        $categories = Category::where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return $this->success($categories);
    }

    #[OA\Post(path: '/api/v1/admin/categories', summary: 'Crear categoría (admin)', tags: ['Admin'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9\-_]*$/', Rule::unique('categories', 'slug')->where(fn ($q) => $q->whereNull('deleted_at'))],
            'description' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $baseSlug = CategorySlug::normalize($data['slug'] ?? '') ?: CategorySlug::fromName($data['name']);
        $trashed = Category::onlyTrashed()->where('slug', $baseSlug)->first();

        if ($trashed) {
            $data['slug'] = $baseSlug;
            $trashed->restore();
            $trashed->update($data);

            return $this->success($trashed->fresh(), [], 200);
        }

        $data['slug'] = $baseSlug;
        $category = Category::create($data);

        return $this->success($category, [], 201);
    }

    #[OA\Put(path: '/api/v1/admin/categories/{id}', summary: 'Actualizar categoría', tags: ['Admin'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:categories,id'],
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:120', 'regex:/^[A-Za-z0-9\-_]*$/', Rule::unique('categories', 'slug')->ignore($category->id)->where(fn ($q) => $q->whereNull('deleted_at'))],
            'description' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        if (array_key_exists('slug', $data)) {
            $data['slug'] = CategorySlug::normalize($data['slug']) ?: CategorySlug::fromName($data['name'] ?? $category->name);
        }

        $category->update($data);

        return $this->success($category->fresh());
    }

    #[OA\Delete(path: '/api/v1/admin/categories/{id}', summary: 'Eliminar categoría', tags: ['Admin'], security: [['sanctum' => []]])]
    #[OA\Response(response: 200, description: 'OK')]
    public function destroy(Category $category): JsonResponse
    {
        $category->archive();

        return $this->success(['archived' => true]);
    }
}
