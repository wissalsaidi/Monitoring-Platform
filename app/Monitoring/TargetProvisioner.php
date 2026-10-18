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

    /** Seuil d'un niveau d'alerte (tag "level"), enregistré avec l'alerte. */
    public function threshold(MonitoringTarget $target, string $level): int|float|null;
}
