<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaeprodFrase extends Model
{
    protected $table = 'maeprod_frases';

    protected $fillable = [
        'prod_item',
        'frase',
        'frase_norm',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Maeprod::class, 'prod_item', 'prod_item');
    }
}
