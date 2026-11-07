<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Code HTTP du dernier essai d'un webhook (SCRUM-79), affiché dans l'historique des notifications.
 * Null pour un email, ou quand le receveur n'a pas répondu (timeout, connexion refusée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedSmallInteger('http_status')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('http_status');
        });
    }
};
