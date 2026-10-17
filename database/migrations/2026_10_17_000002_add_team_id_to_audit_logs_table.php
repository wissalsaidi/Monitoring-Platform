<?php

use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Team concernée par chaque entrée du journal (SCRUM-60) : la page Audit log n'affiche que
     * les actions sur les objets de la team, même quand l'objet a été supprimé depuis.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['team_id', 'created_at']);
        });

        // Lignes existantes : team de l'objet s'il existe encore, sinon la team de l'auteur
        // (avant SCRUM-59, chaque utilisateur n'appartenait qu'à une team).
        DB::table('audit_logs')->whereNull('team_id')->orderBy('id')->each(function ($log) {
            $teamId = match ($log->subject_type) {
                Team::class => $log->subject_id,
                MonitoringTarget::class => DB::table('monitoring_targets')->where('id', $log->subject_id)->value('team_id'),
                Alert::class => DB::table('alerts')->join('monitoring_targets', 'monitoring_targets.id', '=', 'alerts.monitoring_target_id')
                    ->where('alerts.id', $log->subject_id)->value('monitoring_targets.team_id'),
                default => null,
            };

            if ($teamId === null && $log->user_id !== null) {
                $teams = DB::table('team_members')->where('user_id', $log->user_id)->pluck('team_id');
                $teamId = $teams->count() === 1 ? $teams->first() : null;
            }

            if ($teamId !== null) {
                DB::table('audit_logs')->where('id', $log->id)->update(['team_id' => $teamId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'created_at']);
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
