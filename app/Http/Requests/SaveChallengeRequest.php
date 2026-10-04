<?php

namespace App\Http\Requests;

use App\Enums\ChallengeKind;
use App\Enums\ClockPosition;
use App\Enums\DrillMissRule;
use App\Enums\DrillOrder;
use App\Enums\GoalMetric;
use App\Enums\GoalPeriod;
use App\Enums\PuttContext;
use App\Enums\SurfaceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $drill = $this->input('kind') === ChallengeKind::Drill->value;

        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', Rule::enum(ChallengeKind::class)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],

            // Eligibility. Empty means any.
            'putter_ids' => ['array'],
            'putter_ids.*' => ['integer', Rule::exists('putters', 'id')->where('user_id', $this->user()->id)],
            'contexts' => ['array'],
            'contexts.*' => [Rule::enum(PuttContext::class)],
            'surface_types' => ['array'],
            'surface_types.*' => [Rule::enum(SurfaceType::class)],
            'min_distance_ft' => ['nullable', 'integer', 'min:1', 'max:120'],
            'max_distance_ft' => ['nullable', 'integer', 'min:1', 'max:120', 'gte:min_distance_ft'],

            // A goals challenge is nothing without a goal; a drill may add some.
            'goals' => [$drill ? 'nullable' : 'required', 'array', $drill ? 'min:0' : 'min:1', 'max:10'],
            'goals.*.metric' => ['required', Rule::enum(GoalMetric::class)],
            'goals.*.period' => ['required', Rule::enum(GoalPeriod::class)],
            'goals.*.target' => ['required', 'integer', 'min:1', 'max:1000000'],
            'goals.*.context' => ['nullable', Rule::enum(PuttContext::class)],
            'goals.*.min_distance_ft' => ['nullable', 'integer', 'min:1', 'max:120'],
            'goals.*.max_distance_ft' => ['nullable', 'integer', 'min:1', 'max:120'],
            'goals.*.min_attempts' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'goals.*.periods_required' => ['nullable', 'integer', 'min:1', 'max:3650'],

            'steps' => [$drill ? 'required' : 'nullable', 'array', 'max:50'],
            'steps.*.distance_ft' => ['required', 'integer', 'min:1', 'max:120'],
            'steps.*.clock_position' => ['nullable', Rule::enum(ClockPosition::class)],
            'steps.*.makes_required' => ['nullable', 'integer', 'min:1', 'max:20'],
            'drill_on_miss' => [$drill ? 'required' : 'nullable', Rule::enum(DrillMissRule::class)],
            'drill_order' => [$drill ? 'required' : 'nullable', Rule::enum(DrillOrder::class)],
            'drill_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'drill_makes_required' => ['nullable', 'integer', 'min:1', 'max:20'],
            'drill_rounds' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('goals', []) as $index => $goal) {
                    if (($goal['metric'] ?? null) === GoalMetric::MakePercent->value && (int) ($goal['target'] ?? 0) > 100) {
                        $validator->errors()->add("goals.{$index}.target", 'A make rate cannot be above 100%.');
                    }

                    if (isset($goal['min_distance_ft'], $goal['max_distance_ft']) && $goal['min_distance_ft'] !== '' && $goal['max_distance_ft'] !== ''
                        && (int) $goal['max_distance_ft'] < (int) $goal['min_distance_ft']) {
                        $validator->errors()->add("goals.{$index}.max_distance_ft", 'The longest distance must be at least the shortest.');
                    }
                }

                if ($this->input('kind') === ChallengeKind::Drill->value
                    && (int) ($this->input('drill_makes_required') ?? 1) > (int) ($this->input('drill_attempts') ?? 1)) {
                    $validator->errors()->add('drill_makes_required', 'A step cannot need more sunk than it has attempts.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'goals.required' => 'Add at least one goal.',
            'steps.required' => 'Add at least one step to the drill.',
        ];
    }
}
