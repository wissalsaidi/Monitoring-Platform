<?php

namespace App\Http\Requests;

use App\Enums\TargetType;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Monitoring\ProvisionerRegistry;
use App\Monitoring\TargetProvisioner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'une target (SCRUM-33, cahier des charges §11).
 * Champs communs validés ici ; champs propres au type validés par les règles de son provisioner (SCRUM-66).
 */
class StoreTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->team();

        return $team !== null && $this->user()->can('create', [MonitoringTarget::class, $team]);
    }

    public function rules(): array
    {
        $supported = array_map(fn (TargetType $type) => $type->value, app(ProvisionerRegistry::class)->supportedTypes());

        return [
            'type' => ['required', Rule::in($supported)],
            ...$this->commonRules(),
            ...($this->provisioner()?->rules() ?? []),
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Your team already has a target with this name.',
            'type.in' => 'This type of target is not available yet.',
            ...($this->provisioner()?->messages() ?? []),
        ];
    }

    public function attributes(): array
    {
        return [
            'check_interval' => 'check interval',
            ...($this->provisioner()?->attributes() ?? []),
        ];
    }

    /** Team dans laquelle la target est créée (MVP : la team par défaut de l'utilisateur). */
    public function team(): ?Team
    {
        return $this->user()?->defaultTeam();
    }

    /** Type demandé ; null s'il est inconnu ou sans provisioner (la règle "type" le refuse). */
    public function targetType(): ?TargetType
    {
        $type = TargetType::tryFrom((string) $this->input('type'));

        return $type !== null && app(ProvisionerRegistry::class)->supports($type) ? $type : null;
    }

    /** Champs à enregistrer : communs + propres au type, sans le type lui-même. */
    public function targetData(): array
    {
        return $this->safe()->except('type');
    }

    protected function commonRules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('monitoring_targets')->where('team_id', $this->team()?->id),
            ],
            'check_interval' => ['required', 'integer', Rule::in(array_keys(MonitoringTarget::CHECK_INTERVALS))],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function provisioner(): ?TargetProvisioner
    {
        $type = $this->targetType();

        return $type !== null ? app(ProvisionerRegistry::class)->for($type) : null;
    }
}
