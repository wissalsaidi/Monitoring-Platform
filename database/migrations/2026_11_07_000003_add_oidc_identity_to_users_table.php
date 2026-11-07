<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identité SSO d'un compte (SCRUM-88) : émetteur (iss) et identifiant stable (sub) chez le fournisseur OIDC.
 * Après la première liaison, la personne est toujours retrouvée par ce couple, jamais par son seul email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_issuer')->nullable()->after('email_verified_at');
            $table->string('oidc_subject')->nullable()->after('oidc_issuer');
            $table->unique(['oidc_issuer', 'oidc_subject']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oidc_issuer', 'oidc_subject']);
            $table->dropColumn(['oidc_issuer', 'oidc_subject']);
        });
    }
};
