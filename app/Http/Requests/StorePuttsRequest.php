<?php

namespace App\Http\Requests;

use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Enums\PuttSlope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePuttsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'putts' => ['required', 'array', 'min:1', 'max:500'],
            'putts.*.uuid' => ['required', 'uuid'],
            'putts.*.distance_ft' => ['required', 'integer', 'min:1', 'max:120'],
            'putts.*.result' => ['required', Rule::enum(PuttResult::class)],
            'putts.*.context' => ['required', Rule::enum(PuttContext::class)],
            'putts.*.putter' => ['nullable', Rule::enum(Putter::class)],
            'putts.*.miss_cause' => ['nullable', Rule::enum(LineMissCause::class)],
            'putts.*.slope' => ['nullable', Rule::enum(PuttSlope::class)],
            'putts.*.break_direction' => ['nullable', 'string', 'max:40'],
            'putts.*.location' => ['nullable', 'string', 'max:120'],
            'putts.*.surface' => ['nullable', 'string', 'max:120'],
            'putts.*.notes' => ['nullable', 'string', 'max:500'],
            'putts.*.hit_at' => ['required', 'date'],
        ];
    }
}
