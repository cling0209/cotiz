<?php

namespace App\Models;

use App\Services\CompraAgilRegionScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OportunidadPalabraClave extends Model
{
    protected $table = 'oportunidad_palabras_clave';

    protected $fillable = [
        'frase',
        'excluir',
        'orden',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function regiones(): HasMany
    {
        return $this->hasMany(OportunidadPalabraClaveRegion::class, 'palabra_clave_id');
    }

    /**
     * @return list<int>
     */
    public function codigosRegion(): array
    {
        return $this->regiones
            ->pluck('region_codigo')
            ->map(fn ($c) => (int) $c)
            ->values()
            ->all();
    }

    public function aplicaATodasLasRegiones(): bool
    {
        if ($this->relationLoaded('regiones')) {
            return $this->regiones->isEmpty();
        }

        return ! $this->regiones()->exists();
    }

    public function aplicaARegion(int $region): bool
    {
        if ($this->aplicaATodasLasRegiones()) {
            return true;
        }

        if ($this->relationLoaded('regiones')) {
            return $this->regiones->contains('region_codigo', $region);
        }

        return $this->regiones()->where('region_codigo', $region)->exists();
    }

    public function etiquetaRegiones(): string
    {
        if ($this->aplicaATodasLasRegiones()) {
            return 'Todas';
        }

        $nombres = array_map(
            fn (int $codigo) => CompraAgilRegionScope::nombreRegion($codigo),
            $this->codigosRegion(),
        );

        return $nombres !== [] ? implode(', ', $nombres) : 'Todas';
    }

    /**
     * Términos que descartan una oportunidad encontrada por esta frase
     * (separados por coma, punto y coma o salto de línea).
     *
     * @return list<string>
     */
    public function terminosExcluidos(): array
    {
        return self::parsearTerminosExcluidos((string) ($this->excluir ?? ''));
    }

    /**
     * @return list<string>
     */
    public static function parsearTerminosExcluidos(string $texto): array
    {
        $out = [];
        $vistos = [];
        foreach (preg_split('/[,;\r\n]+/u', $texto) ?: [] as $termino) {
            $termino = trim(preg_replace('/\s+/u', ' ', $termino) ?? $termino);
            $clave = mb_strtolower($termino, 'UTF-8');
            if ($termino === '' || isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            $out[] = $termino;
        }

        return $out;
    }

    /**
     * Forma canónica para persistir: "termino1, termino2" o null si no hay términos.
     */
    public static function normalizarExcluir(?string $texto): ?string
    {
        $terminos = self::parsearTerminosExcluidos((string) ($texto ?? ''));

        return $terminos !== [] ? implode(', ', $terminos) : null;
    }
}

