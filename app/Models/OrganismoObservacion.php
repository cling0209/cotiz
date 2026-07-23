<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganismoObservacion extends Model
{
    protected $table = 'organismo_observaciones';

    protected $fillable = [
        'rut_organismo',
        'nombre',
        'observacion',
        'observacion_automatica',
        'observacion_automatica_casos',
        'observacion_automatica_en',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'observacion_automatica_casos' => 'integer',
            'observacion_automatica_en' => 'datetime',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function tieneObservacion(): bool
    {
        return trim((string) ($this->observacion ?? '')) !== '';
    }

    public function tieneObservacionAutomatica(): bool
    {
        return trim((string) ($this->observacion_automatica ?? '')) !== '';
    }
}
