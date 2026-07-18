<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OportunidadVisita extends Model
{
    protected $table = 'oportunidad_visitas';

    protected $fillable = [
        'user_id',
        'codigo',
        'veces',
        'ultima_visita_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'veces' => 'integer',
            'ultima_visita_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
