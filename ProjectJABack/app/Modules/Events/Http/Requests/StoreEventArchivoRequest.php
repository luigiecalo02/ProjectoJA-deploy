<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Models\EventoArchivo;
use App\Modules\Events\Services\EventArchivoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventArchivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $this->user()?->can('update', $event) ?? false;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['nullable', 'string', Rule::in(EventoArchivo::TIPOS)],
            'titulo' => ['nullable', 'string', 'max:191'],
            'url' => ['nullable', 'string', 'max:2048'],
            'archivo' => [
                'nullable',
                'file',
                'max:'.EventArchivoService::MAX_VIDEO_KB,
                'mimes:pdf,jpg,jpeg,png,webp,gif,mp4,webm,mov,mkv',
            ],
        ];
    }
}
