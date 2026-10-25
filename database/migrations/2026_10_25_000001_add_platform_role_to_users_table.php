<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôle de plateforme (SCRUM-81) : null pour tous les utilisateurs existants, qui gardent leurs teams et leurs rôles.
 * Le premier Super Admin est désigné par `php artisan platform:create-admin`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('platform_role', 20)->nullable()->after('password')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['platform_role']);
            $table->dropColumn('platform_role');
        });
    }
};
