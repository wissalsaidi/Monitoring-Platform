<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modèle Alert du cahier des charges (§33), rempli par la synchro Zabbix (SCRUM-40)
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitoring_target_id')->constrained()->cascadeOnDelete();
            $table->string('zabbix_event_id')->unique();          // pas de doublon d'une synchro à l'autre
            $table->string('zabbix_trigger_id')->nullable();
            $table->string('severity', 20);
            $table->string('status', 20)->default('open');
            $table->string('message');
            $table->string('metric', 50)->nullable();             // tag "metric" du trigger
            $table->decimal('value', 12, 3)->nullable();           // ms pour response_time
            $table->unsignedInteger('threshold')->nullable();     // ms
            $table->timestamp('started_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['monitoring_target_id', 'status']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
