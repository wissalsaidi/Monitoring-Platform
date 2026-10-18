<?php

use App\Jobs\SyncZabbixEvents;
use Illuminate\Support\Facades\Schedule;

// Synchro des problèmes Zabbix → alertes et statuts des targets (cahier des charges §37)
Schedule::job(new SyncZabbixEvents)->everyMinute();

// Sauvegarde nocturne des bases Laravel et Zabbix, conservée 7 jours (SCRUM-62)
Schedule::command('backup:run')->dailyAt(config('backup.time'))->withoutOverlapping();

// Journal d'audit conservé un an (RGPD-3, SCRUM-60)
Schedule::command('audit:purge')->dailyAt('03:30');
