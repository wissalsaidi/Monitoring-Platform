<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Compte créé par SSO (SCRUM-89) : pas de mot de passe local, la connexion passe par le fournisseur d'identité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Les comptes sans mot de passe reçoivent un mot de passe aléatoire inutilisable (« mot de passe oublié » pour en choisir un)
        DB::table('users')->whereNull('password')->update(['password' => bcrypt(Str::random(64))]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
