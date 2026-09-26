<?php

namespace App\Http\Requests;

use App\Enums\PutterHeadType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePutterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'head_type' => ['required', Rule::enum(PutterHeadType::class)],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'length_in' => ['nullable', 'numeric', 'min:20', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_default' => ['boolean'],
            'retired' => ['boolean'],
        ];
    }
}
