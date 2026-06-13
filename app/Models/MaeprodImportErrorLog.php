<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaeprodImportErrorLog extends Model
{
    public $timestamps = false;

    protected $table = 'maeprod_import_errors';

    protected $fillable = [
        'run_id',
        'fila',
        'codigo',
        'nombre',
        'familia',
        'mensaje',
        'detalle',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(MaeprodImportRun::class, 'run_id');
    }
}
