<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dernières valeurs des métriques propres au type (SCRUM-68) : code HTTP, jours avant l'expiration du certificat…
 * Recopiées par la synchro (TargetProvisioner::metricItemKeys) pour être affichées sans appel Zabbix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_targets', function (Blueprint $table) {
            $table->json('last_metrics')->nullable()->after('last_response_ms');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_targets', function (Blueprint $table) {
            $table->dropColumn('last_metrics');
        });
    }
};
