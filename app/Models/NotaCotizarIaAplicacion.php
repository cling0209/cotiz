<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaCotizarIaAplicacion extends Model
{
    protected $table = 'nota_cotizar_ia_aplicaciones';

    protected $fillable = [
        'nronota',
        'codigo',
        'usuario',
        'lineas_agregadas',
        'aplicado_at',
    ];

    protected function casts(): array
    {
        return [
            'nronota' => 'integer',
            'lineas_agregadas' => 'integer',
            'aplicado_at' => 'datetime',
        ];
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class, 'nronota', 'nronota');
    }
}
