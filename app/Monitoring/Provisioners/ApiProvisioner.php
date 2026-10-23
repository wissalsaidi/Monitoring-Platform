<?php

namespace App\Monitoring\Provisioners;

/**
 * Check d'API (SCRUM-38, déplacé de MonitoringService en SCRUM-66 sans changement de comportement) :
 * web scenario « api-check », étape « request ». Tout le check est dans HttpCheckProvisioner (SCRUM-68).
 */
class ApiProvisioner extends HttpCheckProvisioner
{
    /** Noms du web scenario et de son étape : ils apparaissent dans les clés d'items des hosts déjà créés. */
    public const SCENARIO = 'api-check';

    public const STEP = 'request';

    public function scenario(): string
    {
        return self::SCENARIO;
    }

    public function step(): string
    {
        return self::STEP;
    }
}
