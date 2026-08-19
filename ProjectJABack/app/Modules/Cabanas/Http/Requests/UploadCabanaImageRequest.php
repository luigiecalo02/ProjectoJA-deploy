<?php

namespace App\Modules\Cabanas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadCabanaImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cabana = $this->route('cabana');

        return $this->user()?->can('update', $cabana) ?? false;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
        ];
    }
}
