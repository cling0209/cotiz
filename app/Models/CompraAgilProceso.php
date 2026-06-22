<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompraAgilProceso extends Model
{
    protected $table = 'compra_agil_procesos';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'codigo', 'nombre', 'estado_codigo', 'estado_glosa', 'organismo', 'rut_organismo',
        'region', 'monto_presupuesto_clp', 'fecha_publicacion', 'fecha_cierre',
        'fecha_ultimo_cambio', 'cantidad_productos', 'total_ofertas', 'rut_ganador', 'sincronizado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_publicacion' => 'datetime',
            'fecha_cierre' => 'datetime',
            'fecha_ultimo_cambio' => 'datetime',
            'sincronizado_en' => 'datetime',
            'monto_presupuesto_clp' => 'integer',
            'cantidad_productos' => 'integer',
            'total_ofertas' => 'integer',
            'region' => 'integer',
        ];
    }

    public function lineasMercado(): HasMany
    {
        return $this->hasMany(CompraAgilLineaMercado::class, 'codigo_proceso', 'codigo');
    }
}
