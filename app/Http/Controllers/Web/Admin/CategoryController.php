<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\CategorySlug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $categories = Category::query()
            ->with('parent')
            ->withCount('products')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';

                return $query->where(function ($q) use ($term) {
                    $q->where('name', 'ilike', $term)
                        ->orWhere('slug', 'ilike', $term);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.categories.index', compact('categories'));
    }

    public function create(): View
    {
        return view('admin.categories.form', [
            'category' => new Category(['is_active' => true, 'sort_order' => 0]),
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $baseSlug = CategorySlug::normalize($data['slug'] ?? '') ?: CategorySlug::fromName($data['name']);

        $trashed = Category::onlyTrashed()->where('slug', $baseSlug)->first();

        if ($trashed) {
            $data['slug'] = $this->uniqueSlug($baseSlug, $trashed->id);
            $trashed->restore();
            $trashed->update($data);

            return redirect()
                ->route('admin.categories.index')
                ->with('success', 'Categoría reactivada correctamente.');
        }

        $data['slug'] = $this->uniqueSlug($baseSlug);
        Category::create($data);

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Categoría creada correctamente.');
    }

    public function edit(Category $category): View
    {
        return view('admin.categories.form', [
            'category' => $category,
            'parents' => $this->parentOptions($category),
        ]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);

        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $category->id);
        }

        $category->update($data);

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Categoría actualizada.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->archive();

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Categoría dada de baja del catálogo.');
    }

    protected function validated(Request $request, ?Category $category = null): array
    {
        $categoryId = $category?->id;
        $slugRule = Rule::unique('categories', 'slug')
            ->where(fn ($q) => $q->whereNull('deleted_at'));

        if ($categoryId) {
            $slugRule->ignore($categoryId);
        }

        $parentRule = ['nullable', 'exists:categories,id'];

        if ($categoryId) {
            $parentRule[] = Rule::notIn([$categoryId]);
        }

        $data = $request->validate([
            'parent_id' => $parentRule,
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9\-_]*$/', $slugRule],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    protected function parentOptions(?Category $except = null)
    {
        return Category::query()
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    protected function uniqueSlug(string $slug, ?int $exceptId = null): string
    {
        $base = CategorySlug::normalize($slug) ?: 'categoria';
        $candidate = $base;
        $i = 1;

        while (Category::withTrashed()
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }
}
