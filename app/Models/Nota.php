<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Nota extends Model
{
    protected $table = 'notas';

    public $incrementing = false;

    protected $primaryKey = 'nronota';

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'nronota', 'descripcion', 'fecha', 'usuario', 'empresa', 'encargado',
        'celular', 'contacto', 'contactocorreo', 'rutempresa', 'nota_softland',
        'diashabiles', 'notaorigen', 'sistema', 'enviadoapi', 'estado',
        'estadofecha', 'estadousuario', 'ocompra', 'fechaentrega', 'factor_precio_venta',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'fechaentrega' => 'date',
            'estadofecha' => 'datetime',
            'factor_precio_venta' => 'decimal:4',
            'enviadoapi' => 'integer',
            'diashabiles' => 'integer',
        ];
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(NotaDetalle::class, 'nronota', 'nronota')->orderBy('orden');
    }

    public function usuarioRel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario', 'username');
    }

    public function total(): int
    {
        return (int) $this->detalle->sum(fn (NotaDetalle $linea) => $linea->prod_valor * $linea->cantidad);
    }

    public function requiereNumeroCotizacion(): bool
    {
        return trim((string) $this->encargado) === '';
    }
}
