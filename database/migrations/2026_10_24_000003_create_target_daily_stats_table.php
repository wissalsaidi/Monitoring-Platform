<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statistiques journalières par target (SCRUM-51, cahier des charges §37 « Every hour → calculate statistics »),
 * calculées chaque heure par php artisan stats:compute et lues par l'onglet History et le dashboard.
 * Un jour = une journée du fuseau d'affichage ; une seule ligne par target et par jour (recalculée, jamais dupliquée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('target_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitoring_target_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('covered_seconds');                 // durée comptée : jour entier, ou jusqu'à maintenant / depuis la création
            $table->unsignedInteger('checks')->default(0);              // checks de disponibilité relevés par Zabbix
            $table->decimal('availability', 6, 3)->nullable();          // %, null sans aucune mesure
            $table->unsignedInteger('downtime_seconds')->default(0);    // durée des incidents « indisponible » dans la journée
            $table->decimal('avg_response_ms', 12, 3)->nullable();
            $table->unsignedInteger('response_samples')->default(0);
            $table->unsignedInteger('incidents')->default(0);           // incidents commencés ce jour-là
            $table->unsignedInteger('resolved_incidents')->default(0);  // incidents résolus ce jour-là
            $table->unsignedInteger('mttr_seconds')->nullable();        // durée moyenne de ces incidents résolus
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['monitoring_target_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_daily_stats');
    }
};
