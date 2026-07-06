<?php

namespace App\Models;

use App\Support\AgileDescripcion;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgileMaeprod extends Model
{
    protected $table = 'agilemaeprod';

    protected $primaryKey = 'prod_item_agile';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'prod_item_agile',
        'prod_descripcion_agile',
        'prod_item',
        'descripcion_norm_hash',
        'prod_codigo_categoria_mp',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Maeprod::class, 'prod_item', 'prod_item');
    }

    protected function prodDescripcionAgile(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => AgileDescripcion::paraMaeprod($value),
        );
    }
}
