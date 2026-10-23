<?php

namespace App\Http\Requests;

use App\Enums\TargetType;
use App\Models\MonitoringTarget;
use Illuminate\Validation\Rule;

/**
 * Modification d'une target (SCRUM-35) : mêmes règles qu'à la création, pour le type de la target (non modifiable).
 */
class UpdateTargetRequest extends StoreTargetRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->target());
    }

    public function rules(): array
    {
        return [
            ...$this->commonRules(),
            // Nom unique dans la team, sauf pour la target elle-même
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('monitoring_targets')->where('team_id', $this->target()->team_id)->ignore($this->target()->id),
            ],
            ...$this->provisioner()->rules(),
        ];
    }

    public function targetType(): TargetType
    {
        return $this->target()->type;
    }

    public function targetData(): array
    {
        return $this->validated();
    }

    public function target(): MonitoringTarget
    {
        return $this->route('target');
    }
}
