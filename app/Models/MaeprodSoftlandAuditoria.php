<?php

namespace App\Models;

use App\Enums\MaeprodSoftlandOrigen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaeprodSoftlandAuditoria extends Model
{
    protected $table = 'maeprod_softland_auditoria';

    public $timestamps = false;

    protected $fillable = [
        'prod_item',
        'usuario',
        'fechahora',
        'valor_anterior',
        'valor_nuevo',
        'origen',
        'nronota',
    ];

    protected function casts(): array
    {
        return [
            'fechahora' => 'datetime',
            'origen' => MaeprodSoftlandOrigen::class,
            'nronota' => 'integer',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Maeprod::class, 'prod_item', 'prod_item');
    }
}
