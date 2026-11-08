<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compte de secours (« break-glass », SCRUM-90) : Super Admin désigné par platform:break-glass, qui garde le mot de passe
 * et la 2FA de la plateforme quand le SSO est obligatoire. Date de désignation, null = compte ordinaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('break_glass_at')->nullable()->after('platform_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('break_glass_at');
        });
    }
};
