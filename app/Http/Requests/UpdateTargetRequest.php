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
            ...$this->provisioner()->updateRules(),
        ];
    }

    public function targetType(): TargetType
    {
        return $this->target()->type;
    }

    /** Secret laissé vide (mot de passe d'une base, SCRUM-71) : on garde celui qui est enregistré. */
    public function targetData(): array
    {
        $data = $this->validated();

        if (blank(data_get($data, 'credentials.password'))) {
            unset($data['credentials']);
        }

        return $data;
    }

    public function target(): MonitoringTarget
    {
        return $this->route('target');
    }
}
