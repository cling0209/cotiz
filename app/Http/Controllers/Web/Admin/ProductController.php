<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductChunkUploadService;
use App\Services\ProductImportJobService;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::query()
            ->with('category')
            ->when($request->filled('q'), fn ($q) => $q->search($request->query('q')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        $categories = Category::query()->orderBy('name')->get();

        return view('admin.products.index', compact('products', 'categories'));
    }

    public function create(): View
    {
        return view('admin.products.form', [
            'product' => new Product(['is_active' => true, 'stock' => 0]),
            'categories' => Category::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $trashed = Product::onlyTrashed()->where('sku', $data['sku'])->first();

        if ($trashed) {
            $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug($data['name']), $trashed->id);
            $trashed->restore();
            $trashed->update($data);

            return redirect()
                ->route('admin.products.index')
                ->with('success', 'Producto reactivado correctamente.');
        }

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug($data['name']));
        Product::create($data);

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Producto creado correctamente.');
    }

    public function edit(Product $product): View
    {
        $product->load('category');

        return view('admin.products.form', [
            'product' => $product,
            'categories' => Category::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product->id);

        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $product->id);
        }

        $product->update($data);

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Producto actualizado.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->archive();

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Producto dado de baja del catálogo.');
    }

    public function importForm(): View
    {
        return view('admin.products.import');
    }

    public function downloadImportTemplate(ProductImportService $importService): StreamedResponse
    {
        $content = $importService->generateTemplateCsv();

        return response()->streamDownload(
            fn () => print($content),
            'plantilla_productos.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function exportProducts(ProductImportService $importService): StreamedResponse
    {
        return $importService->exportProductsCsvResponse();
    }

    public function storeImportChunk(Request $request, ProductChunkUploadService $chunkUpload): JsonResponse
    {
        if (! $request->hasFile('chunk') || ! $request->file('chunk')->isValid()) {
            return response()->json([
                'message' => 'El fragmento no llegó al servidor. Reintenta la carga.',
            ], 422);
        }

        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:500'],
            'original_name' => ['required', 'string', 'max:255'],
            'chunk' => ['required', 'file', 'max:7168'],
        ]);

        try {
            $result = $chunkUpload->storeChunk(
                $data['upload_id'],
                (int) $data['chunk_index'],
                (int) $data['total_chunks'],
                $data['original_name'],
                $request->file('chunk'),
                (int) $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error interno al procesar la carga. Reintenta en unos minutos.',
            ], 500);
        }

        if (! $result['ready']) {
            return response()->json([
                'done' => false,
                'received' => (int) $data['chunk_index'] + 1,
                'total' => (int) $data['total_chunks'],
            ]);
        }

        return response()->json([
            'done' => true,
            'upload_id' => $result['upload_id'],
            'batch_count' => $result['batch_count'],
        ]);
    }

    public function processImportBatch(Request $request, ProductImportJobService $importJob): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
        ]);

        try {
            $progress = $importJob->processNextBatch(
                $data['upload_id'],
                (int) $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error interno al importar productos. Reintenta en unos minutos.',
            ], 500);
        }

        $payload = [
            'finished' => $progress['finished'],
            'processed_batches' => $progress['processed_batches'],
            'total_batches' => $progress['total_batches'],
        ];

        if ($progress['finished']) {
            $payload['redirect'] = $this->flashImportResultAndGetRedirectUrl($progress['result']);
        }

        return response()->json($payload);
    }

    /**
     * @param  array{created: int, updated: int, reactivated: int, skipped: int, errors: list<string>}  $result
     */
    protected function flashImportResultAndGetRedirectUrl(array $result): string
    {
        $parts = [];

        if ($result['created'] > 0) {
            $parts[] = $result['created'].' creado(s)';
        }

        if ($result['updated'] > 0) {
            $parts[] = $result['updated'].' actualizado(s)';
        }

        if ($result['reactivated'] > 0) {
            $parts[] = $result['reactivated'].' reactivado(s)';
        }

        if ($parts === []) {
            session()->flash('error', 'No se importó ningún producto.');
            session()->flash('import_errors', array_slice($result['errors'], 0, 20));

            return route('admin.products.import');
        }

        session()->flash('success', 'Importación completada: '.implode(', ', $parts).'.');

        if ($result['errors'] !== []) {
            session()->flash('import_errors', array_slice($result['errors'], 0, 20));

            if (count($result['errors']) > 20) {
                session()->flash('error', 'Algunas filas fallaron. Se muestran los primeros 20 errores.');
            }
        }

        return route('admin.products.index');
    }

    protected function validated(Request $request, ?int $productId = null): array
    {
        $skuRule = Rule::unique('products', 'sku')
            ->where(fn ($q) => $q->whereNull('deleted_at'));
        $slugRule = Rule::unique('products', 'slug')
            ->where(fn ($q) => $q->whereNull('deleted_at'));

        if ($productId) {
            $skuRule->ignore($productId);
            $slugRule->ignore($productId);
        }

        $data = $request->validate([
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
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['is_featured'] = $request->boolean('is_featured');

        return $data;
    }

    protected function uniqueSlug(string $slug, ?int $exceptId = null): string
    {
        $base = Str::slug($slug) ?: 'producto';
        $candidate = $base;
        $i = 1;

        while (Product::withTrashed()
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }
}
