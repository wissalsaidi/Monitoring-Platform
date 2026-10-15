<?php

namespace App\Http\Requests;

use App\Models\MonitoringTarget;
use Illuminate\Validation\Rule;

/**
 * Modification d'une target API (SCRUM-35) : mêmes règles qu'à la création, sans le type (non modifiable).
 */
class UpdateApiTargetRequest extends StoreApiTargetRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->target());
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['type']);

        // Nom unique dans la team, sauf pour la target elle-même
        $rules['name'] = [
            'required', 'string', 'max:100',
            Rule::unique('monitoring_targets')->where('team_id', $this->target()->team_id)->ignore($this->target()->id),
        ];

        return $rules;
    }

    public function target(): MonitoringTarget
    {
        return $this->route('target');
    }
}
