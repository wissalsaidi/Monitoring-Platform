<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Trace d'une action sensible (cahier des charges §30). Jamais modifiée après création,
 * supprimée au bout d'un an (audit:purge, SCRUM-60).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'team_id', 'action', 'subject_type', 'subject_id', 'details', 'ip_address'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    /**
     * Enregistre une action faite par un utilisateur sur un objet. La team est déduite de l'objet
     * et gardée dans la ligne : l'entrée reste visible par la team après la suppression de l'objet.
     */
    public static function record(string $action, ?User $user, ?Model $subject = null, array $details = []): self
    {
        return self::create([
            'user_id' => $user?->id,
            'team_id' => self::teamIdOf($subject),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }

    private static function teamIdOf(?Model $subject): ?int
    {
        return match (true) {
            $subject instanceof Team => $subject->id,
            $subject instanceof MonitoringTarget => $subject->team_id,
            $subject instanceof Alert => $subject->target?->team_id,
            $subject instanceof Webhook => $subject->team_id,
            default => null,
        };
    }

    /** Libellés affichés sur la page Audit log */
    public const ACTIONS = [
        'target.create' => 'Target created',
        'target.update' => 'Target updated',
        'target.delete' => 'Target deleted',
        'alert.acknowledge' => 'Alert acknowledged',
        'member.invite' => 'Member invited',
        'member.invite_cancel' => 'Invitation cancelled',
        'member.join' => 'Member joined',
        'member.role_change' => 'Role changed',
        'member.remove' => 'Member removed',
        'member.add' => 'Member added',
        'member.account_deleted' => 'Account deleted',
        'notification_rule.update' => 'Notification rules updated',
        'user.create' => 'Account created',
        'platform.admin_grant' => 'Super Admin granted',
        'platform.admin_revoke' => 'Super Admin revoked',
        'team.create' => 'Team created',
        'team.rename' => 'Team renamed',
        'team.delete' => 'Team deleted',
        'user.deactivate' => 'Account deactivated',
        'user.reactivate' => 'Account reactivated',
        'user.password_reset_forced' => 'Password reset forced',
        'user.2fa_enable' => 'Two-factor enabled',
        'user.2fa_disable' => 'Two-factor disabled',
        'user.2fa_recovery_codes' => 'Recovery codes regenerated',
        'user.2fa_reset' => 'Two-factor reset',
        'user.login_sso' => 'Signed in with SSO',
        'user.sso_link' => 'SSO identity linked',
        'webhook.create' => 'Webhook created',
        'webhook.update' => 'Webhook updated',
        'webhook.delete' => 'Webhook deleted',
        'webhook.secret_rotate' => 'Webhook secret regenerated',
        'webhook.auto_disable' => 'Webhook disabled automatically',
    ];

    /** Entrée d'une team supprimée depuis : team_id est passé à null, mais ce n'est pas une action de plateforme */
    public function isFromDeletedTeam(): bool
    {
        return $this->team_id === null && $this->subject_type === (new Team)->getMorphClass();
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /** Objet concerné ; s'il a été supprimé, son nom gardé dans les détails */
    public function subjectLabel(): string
    {
        return match (true) {
            $this->subject instanceof MonitoringTarget => $this->subject->name,
            $this->subject instanceof Alert => 'Alert on '.($this->subject->target?->name ?? $this->details['target'] ?? '?'),
            $this->subject instanceof Team => 'Team '.$this->subject->name,
            $this->subject instanceof User => $this->subject->name,
            $this->subject instanceof Webhook => 'Webhook '.$this->subject->name,
            isset($this->details['name']) => $this->details['name'].' (deleted)',
            // Team supprimée depuis (SCRUM-85) : son dernier nom connu
            isset($this->details['changes']['name'][1]) => 'Team '.$this->details['changes']['name'][1].' (deleted)',
            isset($this->details['target']) => 'Alert on '.$this->details['target'],
            default => class_basename((string) $this->subject_type).' #'.$this->subject_id,
        };
    }

    /**
     * Détails lisibles : « champ : ancienne → nouvelle » pour les modifications, sinon « clé : valeur ».
     *
     * @return list<array{label: string, old?: string, new?: string, value?: string}>
     */
    public function detailLines(): array
    {
        $details = $this->details ?? [];
        $format = fn ($value) => match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            $value === null => '—',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
        $label = fn (string $key) => ucfirst(str_replace('_', ' ', $key));

        if ($this->action === 'member.role_change') {
            return [
                ['label' => 'Member', 'value' => $format($details['email'] ?? null)],
                ['label' => 'Role', 'old' => $format($details['old'] ?? null), 'new' => $format($details['new'] ?? null)],
            ];
        }

        $lines = [];
        foreach ($details['changes'] ?? [] as $field => [$old, $new]) {
            $lines[] = ['label' => $label($field), 'old' => $format($old), 'new' => $format($new)];
        }
        foreach (array_diff_key($details, ['changes' => true]) as $key => $value) {
            $lines[] = ['label' => $label($key), 'value' => $format($value)];
        }

        return $lines;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
