<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valeur maximale atteinte pendant un incident (SCRUM-50), dans l'unité de la valeur de l'alerte (ms, %, Mo…).
 * Lue dans Zabbix à la première consultation de l'onglet History, puis gardée une fois l'incident résolu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->decimal('peak_value', 14, 3)->nullable()->after('threshold');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('peak_value');
        });
    }
};
