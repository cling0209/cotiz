<?php

namespace App\Models;

use App\Support\ProductCodeNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Maeprod extends Model
{
    protected $table = 'maeprod';

    public $incrementing = false;

    protected $primaryKey = 'prod_item';

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return MaeprodBuilder
     */
    public function newEloquentBuilder($query): MaeprodBuilder
    {
        return new MaeprodBuilder($query);
    }

    /**
     * El índice único de prod_item no aplica trim; comparar trim(columna) = trim(valor).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        $query->whereRaw(
            'trim('.$this->getTable().'.prod_item) = trim(?)',
            [(string) $this->getKeyForSelectQuery()]
        );

        return $query;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->whereRaw(
            'trim('.$this->getTable().'.prod_item) = trim(?)',
            [(string) $this->getKeyForSaveQuery()]
        );

        return $query;
    }

    /**
     * Búsqueda directa por código: trim del índice y, si no hay match exacto, sin distinguir mayúsculas.
     */
    public static function encontrarPorCodigo(?string $prodItem): ?self
    {
        $codigo = trim((string) $prodItem);
        if ($codigo === '') {
            return null;
        }

        $exacto = static::query()->find($codigo);
        if ($exacto) {
            return $exacto;
        }

        $tabla = (new static)->getTable();

        return static::query()
            ->whereRaw('lower(trim('.$tabla.'.prod_item)) = lower(?)', [$codigo])
            ->first();
    }

    /**
     * @param  list<string|null>|array<int|string, string|null>  $codigos
     * @return Collection<string, static> keyed by trim(prod_item)
     */
    public static function mapPorCodigos(array $codigos): Collection
    {
        $normalizados = [];
        foreach ($codigos as $codigo) {
            $codigo = trim((string) $codigo);
            if ($codigo !== '') {
                $normalizados[$codigo] = true;
            }
        }

        $lista = array_keys($normalizados);
        if ($lista === []) {
            return collect();
        }

        $lowered = array_map(static fn (string $codigo) => mb_strtolower($codigo, 'UTF-8'), $lista);
        $placeholders = implode(',', array_fill(0, count($lowered), '?'));

        return static::query()
            ->where(function ($query) use ($lista, $lowered, $placeholders) {
                $query->whereKey($lista)
                    ->orWhereRaw('lower(trim(prod_item)) in ('.$placeholders.')', $lowered);
            })
            ->get()
            ->keyBy(fn (self $producto) => trim((string) $producto->prod_item));
    }

    public static function desdeMapa(Collection $mapa, ?string $prodItem): ?self
    {
        $codigo = trim((string) $prodItem);
        if ($codigo === '' || $mapa->isEmpty()) {
            return null;
        }

        $directo = $mapa->get($codigo);
        if ($directo instanceof self) {
            return $directo;
        }

        $hallado = $mapa->first(
            fn (self $producto, mixed $key) => ProductCodeNormalizer::equals((string) $key, $codigo)
                || ProductCodeNormalizer::equals($producto->prod_item, $codigo)
        );

        return $hallado instanceof self ? $hallado : null;
    }

    /** @var array<string, string>|null codigo/nombre familia → carpeta imagen */
    private static ?array $familiaFolderLookup = null;

    protected $fillable = [
        'prod_item', 'prod_nombre', 'prod_imagen', 'prod_valor', 'prod_stock_real',
        'prod_gramaje', 'peso_kg', 'prod_familia', 'prod_item_softland', 'prod_valor_fecha',
        'prod_item_softland_fecha', 'prod_valor_costo', 'prod_user_upd',
    ];

    protected function casts(): array
    {
        return [
            'prod_valor_fecha' => 'datetime',
            'prod_item_softland_fecha' => 'datetime',
            'peso_kg' => 'decimal:3',
        ];
    }

    public function frases(): HasMany
    {
        return $this->hasMany(MaeprodFrase::class, 'prod_item', 'prod_item')
            ->orderBy('frase');
    }

    public function frasesBusqueda(): HasMany
    {
        return $this->hasMany(MaeprodFraseBusqueda::class, 'prod_item', 'prod_item')
            ->orderBy('frase');
    }

    public function softlandAuditorias(): HasMany
    {
        return $this->hasMany(MaeprodSoftlandAuditoria::class, 'prod_item', 'prod_item')
            ->orderByDesc('fechahora')
            ->orderByDesc('id');
    }

    public function imageUrl(): string
    {
        return $this->resolveImageUrl();
    }

    public function resolveImageUrl(): string
    {
        return $this->buildExternalImageUrl() ?? '';
    }

    public function buildExternalImageUrl(): ?string
    {
        $base = rtrim((string) config('products.image_base_url'), '/');
        $folder = $this->resolveFamiliaFolder();
        $filename = trim((string) $this->prod_imagen);

        if ($base === '' || $folder === '') {
            return null;
        }

        if ($filename === '') {
            $filename = $this->guessImageFilename();
        }

        if ($filename === '') {
            return null;
        }

        return $base.'/'.trim($folder, '/').'/'.ltrim($filename, '/');
    }

    /**
     * @return list<string>
     */
    public function imageUrlCandidates(): array
    {
        $base = rtrim((string) config('products.image_base_url'), '/');
        $folder = $this->resolveFamiliaFolder();

        if ($base === '' || $folder === '') {
            return [];
        }

        $filenames = $this->imageFilenameCandidates();
        $urls = [];

        foreach ($filenames as $filename) {
            $urls[] = $base.'/'.trim($folder, '/').'/'.ltrim($filename, '/');
        }

        return array_values(array_unique($urls));
    }

    public static function flushFamiliaFolderCache(): void
    {
        self::$familiaFolderLookup = null;
    }

    public static function resolveFamiliaFolderFor(?string $prodFamilia): string
    {
        $familia = trim((string) $prodFamilia);

        if ($familia === '') {
            return '';
        }

        $lookup = self::familiaFolderLookup();
        if (isset($lookup[$familia])) {
            return $lookup[$familia];
        }

        // Búsqueda case-insensitive sobre el catálogo ya cargado (1 query por request).
        foreach ($lookup as $clave => $codigo) {
            if (strcasecmp((string) $clave, $familia) === 0) {
                return $codigo;
            }
        }

        return match (mb_strtoupper($familia)) {
            'PAPELERIA' => 'PAPEL',
            'LIBRERIA' => 'LIBR',
            default => $familia,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function familiaFolderLookup(): array
    {
        if (self::$familiaFolderLookup !== null) {
            return self::$familiaFolderLookup;
        }

        $map = [];
        foreach (Famprod::query()->get(['codigo', 'nombre']) as $row) {
            $codigo = trim((string) $row->codigo);
            if ($codigo === '') {
                continue;
            }
            $map[$codigo] = $codigo;
            $nombre = trim((string) $row->nombre);
            if ($nombre !== '') {
                $map[$nombre] = $codigo;
            }
        }

        return self::$familiaFolderLookup = $map;
    }

    private function resolveFamiliaFolder(): string
    {
        return self::resolveFamiliaFolderFor($this->prod_familia);
    }

    private function guessImageFilename(): string
    {
        $item = trim((string) $this->prod_item);

        if ($item === '') {
            return '';
        }

        return $item.'.jpg';
    }

    /**
     * @return list<string>
     */
    private function imageFilenameCandidates(): array
    {
        $primary = trim((string) $this->prod_imagen);

        if ($primary === '') {
            $primary = $this->guessImageFilename();
        }

        if ($primary === '') {
            return [];
        }

        $candidates = [$primary];

        if (preg_match('/^(.+)_medium(\.[^.]+)$/i', $primary, $matches)) {
            $candidates[] = $matches[1].$matches[2];
        } elseif (preg_match('/^(.+)(\.[^.]+)$/i', $primary, $matches)) {
            $candidates[] = $matches[1].'_medium'.$matches[2];
        }

        return array_values(array_unique($candidates));
    }
}
