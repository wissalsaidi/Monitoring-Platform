<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Targets multi-types (SCRUM-66) :
 * - settings : configuration propre au type (adresse, port, conteneur, seuils CPU/RAM/disque…), validée par son provisioner ;
 * - url et seuils en ms : check HTTP (API, site web), vides pour les autres types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_targets', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('critical_threshold_ms');
            $table->string('url', 2048)->nullable()->change();
            $table->unsignedInteger('warning_threshold_ms')->nullable()->change();
            $table->unsignedInteger('critical_threshold_ms')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_targets', function (Blueprint $table) {
            $table->dropColumn('settings');
            $table->string('url', 2048)->nullable(false)->change();
            $table->unsignedInteger('warning_threshold_ms')->nullable(false)->change();
            $table->unsignedInteger('critical_threshold_ms')->nullable(false)->change();
        });
    }
};
