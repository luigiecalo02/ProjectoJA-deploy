<?php

namespace App\Modules\Cabanas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncEventoAlojamientoCuposRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $this->user()?->can('update', $event)
            || $this->user()?->hasPermission('cabanas.assign')
            || $this->user()?->hasPermission('events.update');
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:200'],
            'items.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'items.*.cupos' => ['required', 'integer', 'min:1'],
        ];
    }
}
