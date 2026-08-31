<?php

namespace App\Modules\Cabanas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEventoCamaPreciosRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $this->user()?->can('update', $event)
            || $this->user()?->hasPermission('cabanas.assign')
            || $this->user()?->hasPermission('cabanas.update');
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:500'],
            'items.*.id' => ['required', 'integer', 'distinct', 'exists:evento_cabana_camas,id'],
            'items.*.precio' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
