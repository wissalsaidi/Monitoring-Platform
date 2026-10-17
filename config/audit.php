<?php

return [

    // Durée de conservation du journal d'audit (exigence RGPD-3, SCRUM-60) : audit:purge chaque nuit
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

];
