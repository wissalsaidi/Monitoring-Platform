<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Console d'administration (SCRUM-84) : compte désactivé (au lieu d'être supprimé) et date de dernière connexion.
 * Les comptes existants restent actifs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->index()->after('platform_role');
            $table->timestamp('last_login_at')->nullable()->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deactivated_at']);
            $table->dropColumn(['deactivated_at', 'last_login_at']);
        });
    }
};
