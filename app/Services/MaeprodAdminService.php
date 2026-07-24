<?php

namespace App\Services;

use App\Models\Famprod;
use App\Models\Gramaje;
use App\Models\Maeprod;
use App\Models\MaeprodFrase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaeprodAdminService
{
    public function __construct(
        protected ProductImageStorageService $imageStorage,
        protected MaeprodBusquedaSimilitudService $busquedaSimilitud,
    ) {}

    public function listado(?string $term, ?string $familia, int $perPage = 20): LengthAwarePaginator
    {
        $query = Maeprod::query();

        if ($term) {
            $tokens = preg_split('/\s+/u', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $token) {
                $like = '%'.$token.'%';
                $query->where(function ($q) use ($like) {
                    $q->whereRaw('LOWER(prod_nombre) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(prod_item) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(prod_item_softland, \'\')) LIKE ?', [$like]);
                });
            }
        }

        if ($familia) {
            $query->where('prod_familia', trim($familia));
        }

        return $query
            ->with(['frases' => fn ($q) => $q->orderBy('frase')])
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

    public function gramajes(): Collection
    {
        $catalogo = Gramaje::query()
            ->orderBy('nombre')
            ->get();

        if ($catalogo->isNotEmpty()) {
            return $catalogo;
        }

        return new Collection(
            Maeprod::query()
                ->whereNotNull('prod_gramaje')
                ->where('prod_gramaje', '!=', '')
                ->distinct()
                ->orderBy('prod_gramaje')
                ->pluck('prod_gramaje')
                ->map(fn (string $nombre, int $idx) => new Gramaje([
                    'codigo' => $idx + 1,
                    'nombre' => $nombre,
                ]))
                ->values()
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
            'peso_kg' => $this->nullablePesoKg($datos['peso_kg'] ?? null),
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
            'peso_kg' => array_key_exists('peso_kg', $datos)
                ? $this->nullablePesoKg($datos['peso_kg'])
                : $producto->peso_kg,
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

    public function actualizarImagen(
        Maeprod $producto,
        ?UploadedFile $imagen,
        ?string $prodImagenManual,
        ?string $usuarioUpd = null,
    ): Maeprod {
        $datos = [
            'prod_item' => $producto->prod_item,
            'prod_familia' => $producto->prod_familia,
            'prod_imagen' => $prodImagenManual ?? $producto->prod_imagen,
        ];

        $datos = $this->normalizarDatosConImagen($datos, $imagen, $producto);

        $producto->update([
            'prod_imagen' => trim((string) ($datos['prod_imagen'] ?? '')) ?: null,
            'prod_user_upd' => $usuarioUpd,
        ]);

        return $producto->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function reglasValidacionImagen(): array
    {
        return [
            'prod_imagen' => ['nullable', 'string', 'max:255'],
            'imagen' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
        ];
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

        $familiaRaw = trim((string) ($datos['prod_familia'] ?? $producto?->prod_familia ?? '')) ?: 'OTRO';
        $familia = Maeprod::resolveFamiliaFolderFor($familiaRaw) ?: 'OTRO';
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
    public function reglasValidacion(bool $esNuevo, bool $incluirSoftland = true, ?string $gramajeActual = null): array
    {
        $reglasFamilia = $esNuevo
            ? ['required', 'string', 'max:120']
            : ['nullable', 'string', 'max:120'];

        if (Famprod::query()->exists()) {
            $reglasFamilia[] = Rule::exists('famprod', 'codigo');
        }

        $reglasGramaje = ['nullable', 'string', 'max:120'];
        if (Gramaje::query()->exists()) {
            $permitidos = Gramaje::query()->orderBy('nombre')->pluck('nombre');
            if (! $esNuevo && $gramajeActual && ! $permitidos->contains($gramajeActual)) {
                $permitidos->push($gramajeActual);
            }
            $reglasGramaje = [
                'required',
                'string',
                'max:120',
                Rule::in($permitidos->all()),
            ];
        }

        $reglas = [
            'prod_nombre' => ['required', 'string', 'max:255'],
            'prod_imagen' => ['nullable', 'string', 'max:255'],
            'imagen' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'prod_valor' => ['required', 'integer', 'min:0'],
            'prod_valor_costo' => ['nullable', 'integer', 'min:0'],
            'prod_stock_real' => ['nullable', 'integer', 'min:0'],
            'prod_gramaje' => $reglasGramaje,
            'peso_kg' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'prod_familia' => $reglasFamilia,
        ];

        if ($incluirSoftland) {
            $reglas['prod_item_softland'] = ['nullable', 'string', 'max:50'];
        }

        if ($esNuevo) {
            $reglas['prod_item'] = ['required', 'string', 'max:50', 'unique:maeprod,prod_item'];
        }

        return $reglas;
    }

    public function agregarFrase(Maeprod $producto, string $frase): MaeprodFrase
    {
        $fraseDisplay = $this->normalizarFraseDisplay($frase);
        $fraseNorm = $this->busquedaSimilitud->normalizarTexto($fraseDisplay);

        if (mb_strlen($fraseDisplay) < 2) {
            throw ValidationException::withMessages([
                'frase' => 'La frase debe tener al menos 2 caracteres.',
            ]);
        }

        if ($fraseNorm === '') {
            throw ValidationException::withMessages([
                'frase' => 'La frase no es válida para vincular.',
            ]);
        }

        if (MaeprodFrase::query()->where('frase_norm', $fraseNorm)->exists()) {
            throw ValidationException::withMessages([
                'frase' => 'Esa frase ya está asignada a otro producto (o a este).',
            ]);
        }

        return MaeprodFrase::query()->create([
            'prod_item' => $producto->prod_item,
            'frase' => mb_substr($fraseDisplay, 0, 200),
            'frase_norm' => mb_substr($fraseNorm, 0, 200),
        ]);
    }

    public function eliminarFrase(Maeprod $producto, MaeprodFrase $frase): void
    {
        if ($frase->prod_item !== $producto->prod_item) {
            abort(404);
        }

        $frase->delete();
    }

    private function normalizarFraseDisplay(string $frase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $frase) ?? $frase);
    }

    private function nullablePesoKg(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $peso = round((float) $value, 3);

        return $peso < 0 ? null : $peso;
    }
}
