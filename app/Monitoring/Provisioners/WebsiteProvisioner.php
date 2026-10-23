<?php

namespace App\Monitoring\Provisioners;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use Illuminate\Support\Facades\Log;

/**
 * Site web (SCRUM-68, cahier des charges §5.1) : le check HTTP de l'API (web scenario « website-check »),
 * plus, pour une URL https://, la date d'expiration du certificat SSL.
 *
 * Certificat : l'agent Zabbix 2 de la plateforme (SCRUM-67) le lit (plugin WebCertificate, item
 * web.certificate.get[hôte,port]) ; un item dépendant en tire le nombre de jours restants. Deux triggers
 * chaînés entre eux mais indépendants de la disponibilité : < 14 jours WARNING, < 3 jours HIGH.
 * Un certificat expiré donne un nombre négatif, donc l'alerte HIGH.
 */
class WebsiteProvisioner extends HttpCheckProvisioner
{
    public const SCENARIO = 'website-check';

    public const STEP = 'page';

    /** Item dépendant : jours avant l'expiration du certificat (négatif s'il a expiré). */
    public const CERTIFICATE_DAYS_KEY = 'website.certificate.days';

    /** Seuils d'alerte du certificat, en jours (fixés par le cahier des charges). */
    public const CERTIFICATE_WARNING_DAYS = 14;

    public const CERTIFICATE_CRITICAL_DAYS = 3;

    /** Un certificat change rarement : un relevé par heure suffit (le premier est demandé tout de suite). */
    private const CERTIFICATE_INTERVAL = '1h';

    private const ITEM_ZABBIX_AGENT = 0;

    private const ITEM_DEPENDENT = 18;

    private const VALUE_FLOAT = 0;

    private const VALUE_TEXT = 4;

    private const PREPROCESSING_JSONPATH = 12;

    private const PREPROCESSING_JAVASCRIPT = 21;

    public function scenario(): string
    {
        return self::SCENARIO;
    }

    public function step(): string
    {
        return self::STEP;
    }

    /** Toujours une interface agent : passer l'URL en https:// plus tard ajoute le check du certificat sans toucher au host. */
    public function hostSettings(MonitoringTarget $target): array
    {
        return $this->agentInterface();
    }

    public function metricItemKeys(): array
    {
        return [...parent::metricItemKeys(), 'certificate_days' => self::CERTIFICATE_DAYS_KEY];
    }

    public function threshold(MonitoringTarget $target, string $level): int|float|null
    {
        return match ($level) {
            'certificate_warning' => self::CERTIFICATE_WARNING_DAYS,
            'certificate_critical' => self::CERTIFICATE_CRITICAL_DAYS,
            default => parent::threshold($target, $level),
        };
    }

    public function checkSummary(MonitoringTarget $target): array
    {
        return [
            ...parent::checkSummary($target),
            self::checksCertificate($target)
                ? sprintf('SSL certificate of %s, read every hour by the Zabbix agent: warning under %d days, high under %d days',
                    $this->certificateAddress($target), self::CERTIFICATE_WARNING_DAYS, self::CERTIFICATE_CRITICAL_DAYS)
                : 'No SSL certificate check: the URL is not https://',
        ];
    }

    /** Le certificat n'est vérifié que pour une URL https:// */
    public static function checksCertificate(MonitoringTarget $target): bool
    {
        return strtolower((string) parse_url((string) $target->url, PHP_URL_SCHEME)) === 'https';
    }

    protected function createExtraChecks(MonitoringTarget $target, string $hostId): void
    {
        if (self::checksCertificate($target)) {
            $this->createCertificateItems($target, $hostId);
        }
    }

    /**
     * URL modifiée : crée, modifie ou supprime le check du certificat (passage en https://, autre hôte ou port, retour en http://).
     * Supprimer l'item du certificat supprime aussi son item dépendant et leurs triggers.
     */
    protected function syncExtraChecks(MonitoringTarget $target): void
    {
        $hostId = $target->zabbix_host_id;
        $current = $this->zabbix->getItems([
            'output' => ['itemid', 'key_'],
            'hostids' => [$hostId],
            'search' => ['key_' => 'web.certificate.get['],
            'startSearch' => true,
        ])[0] ?? null;

        if (! self::checksCertificate($target)) {
            if ($current !== null) {
                $this->zabbix->deleteItems($current['itemid']);
            }

            return;
        }

        if ($current === null) {
            $this->createCertificateItems($target, $hostId);
            $this->createChainedTriggers($target, $this->certificateTriggers($target));
        } elseif ($current['key_'] !== $this->certificateKey($target)) {
            $this->zabbix->updateItems([['itemid' => $current['itemid'], 'key_' => $this->certificateKey($target)]]);
        }
    }

    protected function triggerDefinitions(MonitoringTarget $target): array
    {
        return [
            ...parent::triggerDefinitions($target),
            ...(self::checksCertificate($target) ? $this->certificateTriggers($target) : []),
        ];
    }

    /** Clé de l'item agent qui lit le certificat : web.certificate.get[hôte,port] */
    public function certificateKey(MonitoringTarget $target): string
    {
        [$host, $port] = self::certificateEndpoint($target);

        return "web.certificate.get[$host,$port]";
    }

    /** « hôte:port » du certificat lu, pour l'affichage */
    private function certificateAddress(MonitoringTarget $target): string
    {
        [$host, $port] = self::certificateEndpoint($target);

        return "$host:$port";
    }

    /** @return array{0: string, 1: int} hôte de l'URL (sans crochets IPv6) et port, 443 par défaut */
    private static function certificateEndpoint(MonitoringTarget $target): array
    {
        $parts = parse_url((string) $target->url);

        return [strtolower(trim($parts['host'] ?? '', '[]')), $parts['port'] ?? 443];
    }

    /** Triggers du certificat : chaîne à part, 3 jours (HIGH) ← 14 jours (WARNING). */
    private function certificateTriggers(MonitoringTarget $target): array
    {
        $days = '/'.$target->zabbixHostName().'/'.self::CERTIFICATE_DAYS_KEY;

        return [
            'certificate_critical' => [
                'description' => sprintf('%s SSL certificate expires in less than %d days', $target->name, self::CERTIFICATE_CRITICAL_DAYS),
                'expression' => "last($days)<".self::CERTIFICATE_CRITICAL_DAYS,
                'priority' => self::PRIORITY_HIGH,
                'tags' => $this->triggerTags($target, 'certificate', 'certificate_critical'),
                'chain' => 'certificate',
            ],
            'certificate_warning' => [
                'description' => sprintf('%s SSL certificate expires in less than %d days', $target->name, self::CERTIFICATE_WARNING_DAYS),
                'expression' => "last($days)<".self::CERTIFICATE_WARNING_DAYS,
                'priority' => self::PRIORITY_WARNING,
                'tags' => $this->triggerTags($target, 'certificate', 'certificate_warning'),
                'chain' => 'certificate',
            ],
        ];
    }

    /** Item agent (JSON du certificat) + item dépendant (jours restants), puis premier relevé immédiat. */
    private function createCertificateItems(MonitoringTarget $target, string $hostId): void
    {
        $interfaceId = $this->agentInterfaceOf($hostId)['interfaceid'];

        [$certificateId] = $this->zabbix->createItems([
            'hostid' => $hostId,
            'interfaceid' => $interfaceId,
            'name' => 'SSL certificate',
            'key_' => $this->certificateKey($target),
            'type' => self::ITEM_ZABBIX_AGENT,
            'value_type' => self::VALUE_TEXT,
            'delay' => self::CERTIFICATE_INTERVAL,
            'history' => '7d',
        ]);

        $this->zabbix->createItems([
            'hostid' => $hostId,
            'name' => 'SSL certificate: days left',
            'key_' => self::CERTIFICATE_DAYS_KEY,
            'type' => self::ITEM_DEPENDENT,
            'master_itemid' => $certificateId,
            'value_type' => self::VALUE_FLOAT,
            'units' => 'days',
            'preprocessing' => [
                ['type' => self::PREPROCESSING_JSONPATH, 'params' => '$.x509.not_after.timestamp', 'error_handler' => 0, 'error_handler_params' => ''],
                ['type' => self::PREPROCESSING_JAVASCRIPT, 'params' => 'return Math.floor((value - Date.now() / 1000) / 86400);', 'error_handler' => 0, 'error_handler_params' => ''],
            ],
        ]);

        // Sans ça, le premier relevé peut attendre jusqu'à une heure. Facultatif : un échec ne bloque pas la création.
        try {
            $this->zabbix->checkNow($certificateId);
        } catch (ZabbixException $e) {
            Log::channel('zabbix')->warning('Unable to request the first SSL certificate check', ['target_id' => $target->id, 'error' => $e->getMessage()]);
        }
    }
}
