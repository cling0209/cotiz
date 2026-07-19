<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CorreosChileDexTarifa extends Model
{
    protected $table = 'correos_chile_dex_tarifas';

    protected $fillable = [
        'origen',
        'destino',
        'destino_key',
        'recargo_pct',
        'tarifas',
        'archivo_origen',
        'imported_by',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'recargo_pct' => 'integer',
            'tarifas' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function tieneRecargo(): bool
    {
        return $this->recargo_pct !== null && $this->recargo_pct > 0;
    }

    public static function normalizeDestinoKey(string $destino): string
    {
        $normalized = Str::upper(Str::ascii(trim($destino)));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return $normalized;
    }
}
