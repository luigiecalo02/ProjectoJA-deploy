<?php

namespace App\Modules\Events\Models;

use App\Modules\Shared\Models\StoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoArchivo extends Model
{
    protected $table = 'evento_archivo';

    protected $fillable = [
        'evento_id',
        'file_id',
        'tipo',
        'orden',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
