<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secrets d'une target (SCRUM-71) : mot de passe de l'utilisateur de supervision d'une base de données.
 * Chiffré par Laravel (cast encrypted:array, clé APP_KEY), jamais affiché ni journalisé ; à part de settings (en clair).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitoring_targets', function (Blueprint $table) {
            $table->text('credentials')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_targets', function (Blueprint $table) {
            $table->dropColumn('credentials');
        });
    }
};
