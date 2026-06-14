<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaeprodImportStaging extends Model
{
    protected $table = 'maeprod_import_staging';

    protected $primaryKey = 'upload_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'upload_id',
        'user_id',
        'username',
        'original_name',
        'source_path',
        'columns',
        'total_rows',
        'csv_content',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'total_rows' => 'integer',
        ];
    }
}
