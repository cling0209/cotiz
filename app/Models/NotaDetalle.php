<?php

namespace App\Models;

use App\Support\AgileDescripcion;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotaDetalle extends Model
{
    protected $table = 'notasdetalle';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'nronota', 'prod_item', 'prod_valor', 'cantidad', 'fechahora',
        'orden', 'prod_valor_costo', 'prod_item_agile', 'prod_descripcion_agile',
        'prod_descripcion_maestro', 'observacion', 'observacion_cliente',
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

    public function codigoProducto(): string
    {
        return trim((string) ($this->prod_item ?? ''));
    }

    public function resolveProducto(): ?Maeprod
    {
        $codigo = $this->codigoProducto();

        return $codigo !== '' ? Maeprod::query()->find($codigo) : null;
    }

    public function lineTotal(): int
    {
        return $this->prod_valor * $this->cantidad;
    }

    public function descripcionMaestroVisible(): string
    {
        $maestro = trim((string) ($this->prod_descripcion_maestro ?? ''));
        if ($maestro !== '') {
            return $maestro;
        }

        return trim((string) ($this->prod_descripcion_agile ?? ''));
    }

    protected function prodDescripcionAgile(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => AgileDescripcion::paraDetalle($value),
        );
    }

    protected function prodDescripcionMaestro(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => AgileDescripcion::paraDetalle($value),
        );
    }
}
