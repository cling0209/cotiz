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
}

