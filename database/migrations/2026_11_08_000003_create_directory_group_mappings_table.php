<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groupes de l'annuaire vers teams et rôles (SCRUM-92) : correspondances définies par le Super Admin, appliquées à chaque
 * connexion SSO. team_members.directory_group garde le groupe qui a donné l'appartenance (null = ajout à la main) :
 * seules ces appartenances-là sont retirées quand le groupe disparaît du jeton.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_group_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('group');
            $table->string('role', 20);
            $table->timestamps();
            $table->unique(['team_id', 'group']);
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->string('directory_group')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropColumn('directory_group');
        });
        Schema::dropIfExists('directory_group_mappings');
    }
};
