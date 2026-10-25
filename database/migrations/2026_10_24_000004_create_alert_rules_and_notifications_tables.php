<?php

use App\Models\AlertRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications (SCRUM-52, modèle de données « Évolutions prévues », cahier des charges §24) :
 * - alert_rules : par team, sévérité et événement (ouverture, résolution), le canal (email ou dashboard seulement) ;
 * - notifications : chaque notification à envoyer ou envoyée, jamais deux fois pour le même événement
 *   (clé unique alerte + destinataire + canal + événement).
 * Les teams existantes reçoivent les règles par défaut (AlertRule::DEFAULTS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 20);
            $table->string('event', 20);
            $table->string('channel', 20);
            $table->timestamps();

            $table->unique(['team_id', 'severity', 'event']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient');                       // adresse email au moment de l'envoi
            $table->string('channel', 20);
            $table->string('event', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['alert_id', 'recipient', 'channel', 'event']);
            $table->index(['status', 'created_at']);
        });

        $now = now();
        foreach (DB::table('teams')->pluck('id') as $teamId) {
            DB::table('alert_rules')->insert(array_map(
                fn (array $rule) => ['team_id' => $teamId, ...$rule, 'created_at' => $now, 'updated_at' => $now],
                AlertRule::defaults(),
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('alert_rules');
    }
};
