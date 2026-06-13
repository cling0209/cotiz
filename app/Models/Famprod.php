<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Famprod extends Model
{
    protected $table = 'famprod';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre'];
}
