<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OportunidadPalabraClave extends Model
{
    protected $table = 'oportunidad_palabras_clave';

    protected $fillable = [
        'frase',
        'created_by',
    ];

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
