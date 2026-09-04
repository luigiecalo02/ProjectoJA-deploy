<?php

namespace App\Modules\Events\Models;

use App\Modules\Shared\Models\StoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoArchivo extends Model
{
    public const TIPO_PDF = 'pdf';

    public const TIPO_IMAGEN = 'imagen';

    public const TIPO_VIDEO = 'video';

    public const TIPO_YOUTUBE = 'youtube';

    public const TIPOS = [
        self::TIPO_PDF,
        self::TIPO_IMAGEN,
        self::TIPO_VIDEO,
        self::TIPO_YOUTUBE,
    ];

    protected $table = 'evento_archivo';

    protected $fillable = [
        'evento_id',
        'file_id',
        'url',
        'titulo',
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
