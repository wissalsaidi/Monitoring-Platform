<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhooks d'une team (SCRUM-75) : Slack, Microsoft Teams ou générique, abonnés à des sévérités et des événements.
 * L'URL et le secret sont chiffrés (cast « encrypted », APP_KEY) : d'où des colonnes text.
 * Les notifications d'un webhook vont dans la table notifications (canal « webhook »), liées par webhook_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->text('url');
            $table->text('secret');
            $table->json('severities');
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamps();
            $table->unique(['team_id', 'name']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            // Historique gardé si le webhook est supprimé ; le nom reste dans « recipient »
            $table->foreignId('webhook_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('webhook_id');
        });
        Schema::dropIfExists('webhooks');
    }
};
