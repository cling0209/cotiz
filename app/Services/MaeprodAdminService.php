<?php

namespace App\Services;

use App\Models\Famprod;
use App\Models\Maeprod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaeprodAdminService
{
    public function __construct(
        protected ProductImageStorageService $imageStorage,
    ) {}

    public function listado(?string $term, ?string $familia, int $perPage = 20): LengthAwarePaginator
    {
        $query = Maeprod::query();

        if ($term) {
            $like = '%'.mb_strtolower(trim($term)).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(prod_nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(prod_item) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(prod_item_softland, \'\')) LIKE ?', [$like]);
            });
        }

        if ($familia) {
            $query->where('prod_familia', trim($familia));
        }

        return $query
            ->orderBy('prod_familia')
            ->orderBy('prod_nombre')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function familias(): Collection
    {
        $catalogo = Famprod::query()
            ->orderBy('nombre')
            ->orderBy('codigo')
            ->get();

        if ($catalogo->isNotEmpty()) {
            return $catalogo;
        }

        return new Collection(
            Maeprod::query()
                ->whereNotNull('prod_familia')
                ->where('prod_familia', '!=', '')
                ->distinct()
                ->orderBy('prod_familia')
                ->pluck('prod_familia')
                ->map(fn (string $codigo) => new Famprod([
                    'codigo' => $codigo,
                    'nombre' => $codigo,
                ]))
                ->all()
        );
    }

    public function crear(array $datos, ?string $usuarioUpd = null): Maeprod
    {
        $item = trim((string) $datos['prod_item']);

        return Maeprod::query()->create([
            'prod_item' => $item,
            'prod_nombre' => mb_strtoupper(trim((string) ($datos['prod_nombre'] ?? ''))),
            'prod_imagen' => trim((string) ($datos['prod_imagen'] ?? '')) ?: null,
            'prod_valor' => (int) ($datos['prod_valor'] ?? 0),
            'prod_valor_costo' => (int) ($datos['prod_valor_costo'] ?? 0),
            'prod_stock_real' => isset($datos['prod_stock_real']) ? (int) $datos['prod_stock_real'] : null,
            'prod_gramaje' => trim((string) ($datos['prod_gramaje'] ?? '')) ?: null,
            'prod_familia' => trim((string) ($datos['prod_familia'] ?? '')) ?: null,
            'prod_item_softland' => trim((string) ($datos['prod_item_softland'] ?? '')) ?: null,
            'prod_valor_fecha' => now(),
            'prod_user_upd' => $usuarioUpd,
        ]);
    }

    public function actualizar(Maeprod $producto, array $datos, ?string $usuarioUpd = null): Maeprod
    {
        $updates = [
            'prod_nombre' => mb_strtoupper(trim((string) ($datos['prod_nombre'] ?? $producto->prod_nombre))),
            'prod_imagen' => trim((string) ($datos['prod_imagen'] ?? '')) ?: null,
            'prod_gramaje' => trim((string) ($datos['prod_gramaje'] ?? '')) ?: null,
            'prod_familia' => trim((string) ($datos['prod_familia'] ?? '')) ?: null,
            'prod_item_softland' => trim((string) ($datos['prod_item_softland'] ?? '')) ?: null,
            'prod_stock_real' => isset($datos['prod_stock_real']) ? (int) $datos['prod_stock_real'] : $producto->prod_stock_real,
        ];

        $nuevoValor = (int) ($datos['prod_valor'] ?? $producto->prod_valor);
        $nuevoCosto = (int) ($datos['prod_valor_costo'] ?? $producto->prod_valor_costo);

        if ((int) $producto->prod_valor !== $nuevoValor || (int) ($producto->prod_valor_costo ?? 0) !== $nuevoCosto) {
            $updates['prod_valor'] = $nuevoValor;
            $updates['prod_valor_costo'] = $nuevoCosto;
            $updates['prod_valor_fecha'] = now();
            $updates['prod_user_upd'] = $usuarioUpd;
        } else {
            $updates['prod_valor'] = $nuevoValor;
            $updates['prod_valor_costo'] = $nuevoCosto;
        }

        $softlandAnterior = (string) ($producto->prod_item_softland ?? '');
        $softlandNuevo = (string) ($updates['prod_item_softland'] ?? '');
        if ($softlandNuevo !== $softlandAnterior) {
            $updates['prod_item_softland_fecha'] = now();
        }

        $producto->update($updates);

        return $producto->fresh();
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function normalizarDatosConImagen(array $datos, ?UploadedFile $imagen, ?Maeprod $producto = null): array
    {
        if (! $imagen) {
            return $datos;
        }

        if (! $this->imageStorage->isConfigured()) {
            throw ValidationException::withMessages([
                'imagen' => 'Configure R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET y R2_PUBLIC_URL en .env.',
            ]);
        }

        $item = trim((string) ($datos['prod_item'] ?? $producto?->prod_item ?? ''));
        if ($item === '') {
            throw ValidationException::withMessages([
                'imagen' => 'Defina el código del producto antes de subir la imagen.',
            ]);
        }

        $familia = trim((string) ($datos['prod_familia'] ?? $producto?->prod_familia ?? '')) ?: 'OTRO';
        $datos['prod_imagen'] = $this->imageStorage->upload($imagen, $familia, $item);

        return $datos;
    }

    public function almacenamientoImagenConfigurado(): bool
    {
        return $this->imageStorage->isConfigured();
    }

    /**
     * @return array<string, mixed>
     */
    public function reglasValidacion(bool $esNuevo): array
    {
        $reglasFamilia = $esNuevo
            ? ['required', 'string', 'max:120']
            : ['nullable', 'string', 'max:120'];

        if (Famprod::query()->exists()) {
            $reglasFamilia[] = Rule::exists('famprod', 'codigo');
        }

        $reglas = [
            'prod_nombre' => ['required', 'string', 'max:255'],
            'prod_imagen' => ['nullable', 'string', 'max:255'],
            'imagen' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'prod_valor' => ['required', 'integer', 'min:0'],
            'prod_valor_costo' => ['nullable', 'integer', 'min:0'],
            'prod_stock_real' => ['nullable', 'integer', 'min:0'],
            'prod_gramaje' => ['nullable', 'string', 'max:120'],
            'prod_familia' => $reglasFamilia,
            'prod_item_softland' => ['nullable', 'string', 'max:50'],
        ];

        if ($esNuevo) {
            $reglas['prod_item'] = ['required', 'string', 'max:50', 'unique:maeprod,prod_item'];
        }

        return $reglas;
    }
}
