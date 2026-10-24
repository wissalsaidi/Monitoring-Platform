<?php

namespace App\Monitoring;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;

/**
 * Supervision Zabbix d'un type de target (SCRUM-66). Un provisioner par type, choisi par ProvisionerRegistry.
 *
 * MonitoringService fait la partie commune : host Zabbix (nom, host group de la team, tag target_id),
 * transaction, retour arrière en cas d'échec, suppression. Supprimer le host supprime tout ce que le provisioner
 * a créé dessus (items, web scenarios, triggers).
 *
 * Les triggers portent les tags target_id, metric et level : la synchro des alertes (AlertService) est commune à tous les types.
 */
interface TargetProvisioner
{
    /**
     * Règles de validation des champs propres au type (colonnes de la target, ou `settings.*` pour le JSON).
     * Les champs communs (type, name, description, check_interval) sont validés par le formulaire.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Règles à la modification : en général les mêmes. Un secret (mot de passe, SCRUM-71) y devient facultatif :
     * laissé vide, l'ancien est gardé.
     *
     * @return array<string, mixed>
     */
    public function updateRules(): array;

    /** @return array<string, string> messages de validation propres au type */
    public function messages(): array;

    /** @return array<string, string> noms des champs dans les messages d'erreur */
    public function attributes(): array;

    /**
     * Attributs de la target dont un changement doit être répercuté dans Zabbix.
     *
     * @return list<string>
     */
    public function zabbixFields(): array;

    /**
     * Paramètres ajoutés à host.create : interfaces (agent), templates, macros…
     *
     * @return array<string, mixed>
     */
    public function hostSettings(MonitoringTarget $target): array;

    /**
     * Crée les checks et les triggers sur le host déjà créé.
     * En cas d'exception, MonitoringService supprime le host : rien ne reste dans Zabbix.
     *
     * @throws ZabbixException
     */
    public function create(MonitoringTarget $target, string $hostId): void;

    /**
     * Aligne les checks et les triggers sur la target (modification, ou retour à l'état d'avant après un échec).
     *
     * @throws ZabbixException
     */
    public function update(MonitoringTarget $target): void;

    /**
     * Items lus à chaque synchro :
     * - availability : son lastclock donne la date du dernier check (target UP si aucune alerte active) ;
     * - response_time : sa dernière valeur, en secondes, devient last_response_ms (null si le type n'en a pas).
     *
     * @return array{availability: string, response_time: ?string}
     */
    public function statusItemKeys(): array;

    /**
     * Autres valeurs recopiées sur la target à chaque synchro (colonne last_metrics, SCRUM-68), pour les afficher
     * sans appel Zabbix : nom de la métrique → clé de l'item. Ex. ['http_code' => 'web.test.rspcode[…]'].
     *
     * @return array<string, string>
     */
    public function metricItemKeys(): array;

    /**
     * Séries affichables en graphique sur la page de la target (SCRUM-72), lues dans l'historique Zabbix :
     * nom → libellé, clé d'item, unité affichée, facteur (valeur Zabbix × scale) et type de valeur Zabbix (0 flottant, 3 entier).
     * Clé facultative availability (SCRUM-49) : la série est une disponibilité en % (MetricsService::UP_WHEN_*).
     * La première série est affichée par défaut ; la disponibilité est toujours la dernière.
     *
     * @return array<string, array{label: string, key: string, unit: string, scale: float|int, value_type: int, availability?: string}>
     */
    public function chartSeries(): array;

    /**
     * Seuils tracés sur le graphique d'une série, dans son unité (null : pas de ligne).
     *
     * @return array{warning: int|float|null, critical: int|float|null}
     */
    public function chartThresholds(MonitoringTarget $target, string $series): array;

    /**
     * Crée ou met à jour les items Zabbix qui portent les seuils des graphiques (SCRUM-46, lus par Grafana).
     * Ne lève pas d'exception : un échec est journalisé.
     *
     * @return bool false si Zabbix a refusé
     */
    public function syncThresholdItems(MonitoringTarget $target, string $hostId): bool;

    /** Seuil d'un niveau d'alerte (tag "level"), enregistré avec l'alerte. */
    public function threshold(MonitoringTarget $target, string $level): int|float|null;

    /**
     * Ce que Zabbix vérifie, en clair, pour l'onglet Configuration de la target (une ligne par check).
     *
     * @return list<string>
     */
    public function checkSummary(MonitoringTarget $target): array;
}
