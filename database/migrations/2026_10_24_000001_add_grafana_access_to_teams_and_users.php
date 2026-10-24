<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accès à Grafana par team (SCRUM-48) : une organisation Grafana et un utilisateur Zabbix en lecture seule par team,
 * un compte Grafana par utilisateur de la plateforme. Ids des objets créés, pour les retrouver et les supprimer.
 * Aucun mot de passe enregistré : celui de l'utilisateur Zabbix d'une team n'est connu que de sa datasource Grafana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('grafana_org_id')->nullable()->after('zabbix_hostgroup_id');
            $table->string('zabbix_usrgrp_id', 20)->nullable()->after('grafana_org_id');
            $table->string('zabbix_user_id', 20)->nullable()->after('zabbix_usrgrp_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('grafana_user_id')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['grafana_org_id', 'zabbix_usrgrp_id', 'zabbix_user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('grafana_user_id');
        });
    }
};
