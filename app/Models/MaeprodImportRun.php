<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaeprodImportRun extends Model
{
    public const ESTADO_OK = 'ok';

    public const ESTADO_ERRORES = 'errores';

    public const ESTADO_FALLIDO = 'fallido';

    protected $fillable = [
        'usuario',
        'archivo',
        'creados',
        'actualizados',
        'omitidos',
        'total_errores',
        'estado',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'creados' => 'integer',
            'actualizados' => 'integer',
            'omitidos' => 'integer',
            'total_errores' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function errores(): HasMany
    {
        return $this->hasMany(MaeprodImportErrorLog::class, 'run_id');
    }

    public function tieneErrores(): bool
    {
        return $this->total_errores > 0;
    }
}
