<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraAgilBenchmark extends Model
{
    protected $table = 'compra_agil_benchmarks';

    protected $primaryKey = 'prod_item';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'prod_item', 'observaciones', 'precio_mercado_mediana',
        'precio_mercado_min', 'precio_mercado_max', 'ultima_observacion',
    ];

    protected function casts(): array
    {
        return [
            'observaciones' => 'integer',
            'precio_mercado_mediana' => 'integer',
            'precio_mercado_min' => 'integer',
            'precio_mercado_max' => 'integer',
            'ultima_observacion' => 'datetime',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Maeprod::class, 'prod_item', 'prod_item');
    }
}
