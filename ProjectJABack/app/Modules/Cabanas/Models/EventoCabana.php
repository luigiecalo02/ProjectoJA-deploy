<?php

namespace App\Modules\Cabanas\Models;

use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoCabana extends Model
{
    protected $table = 'evento_cabanas';

    protected $fillable = [
        'evento_id', 'cabana_id', 'nombre', 'descripcion', 'image_url', 'ancho', 'alto',
        'estado', 'orden',
    ];

    protected function casts(): array
    {
        return ['ancho' => 'integer', 'alto' => 'integer', 'orden' => 'integer'];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function cabana(): BelongsTo
    {
        return $this->belongsTo(Cabana::class);
    }

    public function pisos(): HasMany
    {
        return $this->hasMany(EventoCabanaPiso::class)->orderBy('orden');
    }
}
