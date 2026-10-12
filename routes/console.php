<?php

use App\Jobs\SyncZabbixEvents;
use Illuminate\Support\Facades\Schedule;

// Synchro des problèmes Zabbix → alertes et statuts des targets (cahier des charges §37)
Schedule::job(new SyncZabbixEvents)->everyMinute();
