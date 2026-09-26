<?php

namespace App\Http\Requests;

use App\Enums\ClockPosition;
use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Enums\PuttSlope;
use App\Enums\SurfaceType;
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
            // Deliberately not checked against the player's putters here: a queued
            // putt naming a putter since deleted must still store, so RecordPutts
            // falls back to the default rather than 422ing the whole batch.
            'putts.*.putter_id' => ['nullable', 'integer'],
            'putts.*.surface_type' => ['nullable', Rule::enum(SurfaceType::class)],
            'putts.*.miss_cause' => ['nullable', Rule::enum(LineMissCause::class)],
            'putts.*.clock_position' => ['nullable', Rule::enum(ClockPosition::class)],
            // Superseded by clock_position, but still accepted: a phone running a
            // cached bundle from before the ring posts them, and together they
            // reconstruct the position exactly.
            'putts.*.slope' => ['nullable', Rule::enum(PuttSlope::class)],
            'putts.*.break_direction' => ['nullable', 'string', 'max:40'],
            'putts.*.location' => ['nullable', 'string', 'max:120'],
            'putts.*.notes' => ['nullable', 'string', 'max:500'],
            'putts.*.hit_at' => ['required', 'date'],
        ];
    }
}
