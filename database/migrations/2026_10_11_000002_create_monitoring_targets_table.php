<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('unknown');
            $table->boolean('enabled')->default(true);

            // Configuration d'un check d'API (MVP)
            $table->string('url', 2048);
            $table->unsignedInteger('check_interval')->default(60);      // secondes
            $table->unsignedInteger('warning_threshold_ms');
            $table->unsignedInteger('critical_threshold_ms');

            $table->string('zabbix_host_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_targets');
    }
};
