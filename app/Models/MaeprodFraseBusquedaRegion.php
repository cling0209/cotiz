<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaeprodFraseBusquedaRegion extends Model
{
    protected $table = 'maeprod_frase_busqueda_regiones';

    protected $fillable = [
        'frase_busqueda_id',
        'region_codigo',
    ];

    protected function casts(): array
    {
        return [
            'frase_busqueda_id' => 'integer',
            'region_codigo' => 'integer',
        ];
    }

    public function frase(): BelongsTo
    {
        return $this->belongsTo(MaeprodFraseBusqueda::class, 'frase_busqueda_id');
    }
}
