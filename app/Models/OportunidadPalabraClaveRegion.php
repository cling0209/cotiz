<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OportunidadPalabraClaveRegion extends Model
{
    protected $table = 'oportunidad_palabra_clave_regiones';

    protected $fillable = [
        'palabra_clave_id',
        'region_codigo',
    ];

    protected function casts(): array
    {
        return [
            'palabra_clave_id' => 'integer',
            'region_codigo' => 'integer',
        ];
    }

    public function palabraClave(): BelongsTo
    {
        return $this->belongsTo(OportunidadPalabraClave::class, 'palabra_clave_id');
    }
}
