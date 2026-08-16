<?php

namespace App\Modules\Cabanas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncEventoCabanasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cabanas.assign')
            || $this->user()?->hasPermission('cabanas.update');
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:100'],
            'items.*.cabana_id' => ['required', 'integer', 'distinct', 'exists:cabanas,id'],
            'items.*.orden' => ['required', 'integer', 'min:0', 'distinct'],
        ];
    }
}
