<?php

namespace App\Http\Requests;

use App\Enums\TargetType;
use App\Models\MonitoringTarget;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'une target API (SCRUM-33, cahier des charges §11).
 */
class StoreApiTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->team();

        return $team !== null && $this->user()->can('create', [MonitoringTarget::class, $team]);
    }

    public function rules(): array
    {
        return [
            // Le MVP ne supervise que des APIs ; les autres types du §5 arrivent en V1.
            'type' => ['required', Rule::in([TargetType::Api->value])],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('monitoring_targets')->where('team_id', $this->team()?->id),
            ],
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'check_interval' => ['required', 'integer', Rule::in(array_keys(MonitoringTarget::CHECK_INTERVALS))],
            'warning_threshold_ms' => ['required', 'integer', 'min:1', 'max:'.MonitoringTarget::MAX_THRESHOLD_MS],
            'critical_threshold_ms' => ['required', 'integer', 'gt:warning_threshold_ms', 'max:'.MonitoringTarget::MAX_THRESHOLD_MS],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Your team already has a target with this name.',
            'critical_threshold_ms.gt' => 'The critical threshold must be greater than the warning threshold.',
            'type.in' => 'Only API targets are available for now.',
        ];
    }

    public function attributes(): array
    {
        return [
            'url' => 'URL',
            'check_interval' => 'check interval',
            'warning_threshold_ms' => 'warning threshold',
            'critical_threshold_ms' => 'critical threshold',
        ];
    }

    /** Team dans laquelle la target est créée (MVP : la team par défaut de l'utilisateur). */
    public function team(): ?Team
    {
        return $this->user()?->defaultTeam();
    }
}
