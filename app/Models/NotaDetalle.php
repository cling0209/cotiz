<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaDetalle extends Model
{
    protected $table = 'notasdetalle';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'nronota', 'prod_item', 'prod_valor', 'cantidad', 'fechahora',
        'orden', 'prod_valor_costo', 'prod_item_agile',
    ];

    protected function casts(): array
    {
        return [
            'fechahora' => 'datetime',
        ];
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class, 'nronota', 'nronota');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Maeprod::class, 'prod_item', 'prod_item');
    }

    public function lineTotal(): int
    {
        return $this->prod_valor * $this->cantidad;
    }
}
