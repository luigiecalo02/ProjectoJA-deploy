<?php

namespace App\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Model;

class Cargo extends Model
{
    protected $table = 'cargo';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];
}
