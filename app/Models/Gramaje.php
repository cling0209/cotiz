<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gramaje extends Model
{
    protected $table = 'gramaje';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre'];
}
