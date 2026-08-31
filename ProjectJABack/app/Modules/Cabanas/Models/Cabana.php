<?php

namespace App\Modules\Cabanas\Models;

use App\Models\User;
use App\Modules\Lugares\Models\Lugar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cabana extends Model
{
    protected $table = 'cabanas';

    protected $fillable = ['lugar_id', 'nombre', 'descripcion', 'image_url', 'ancho', 'alto', 'estado', 'created_by'];

    public function lugar(): BelongsTo
    {
        return $this->belongsTo(Lugar::class, 'lugar_id');
    }

    public function pisos(): HasMany
    {
        return $this->hasMany(CabanaPiso::class)->orderBy('orden');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoCabana::class);
    }
}
