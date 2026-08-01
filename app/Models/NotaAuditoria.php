<?php

namespace App\Models;

use App\Enums\NotaAuditoriaAccion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaAuditoria extends Model
{
    protected $table = 'notasauditoria';

    public $timestamps = false;

    protected $fillable = [
        'nronota',
        'usuario',
        'fechahora',
        'accion',
        'observacion',
    ];

    protected function casts(): array
    {
        return [
            'fechahora' => 'datetime',
            'accion' => NotaAuditoriaAccion::class,
            'nronota' => 'integer',
        ];
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class, 'nronota', 'nronota');
    }
}
