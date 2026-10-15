<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dernier check Zabbix, recopié par la synchro (SCRUM-34) : évite un appel Zabbix à chaque affichage.
        Schema::table('monitoring_targets', function (Blueprint $table) {
            $table->timestamp('last_checked_at')->nullable()->after('zabbix_host_id');
            $table->decimal('last_response_ms', 10, 3)->nullable()->after('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitoring_targets', function (Blueprint $table) {
            $table->dropColumn(['last_checked_at', 'last_response_ms']);
        });
    }
};
