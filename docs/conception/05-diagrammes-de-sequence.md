# Diagrammes de séquence des flux critiques

**Objectif :** montrer, étape par étape, comment les composants échangent pendant les deux flux les plus importants : créer une target et transformer un problème Zabbix en alerte.

**Ticket Jira :** [SCRUM-22](https://wissalshalaoui.atlassian.net/browse/SCRUM-22)

## 1. Création d'une target

Un Manager remplit le formulaire « Add Monitoring Target ». Tout se fait **en tout ou rien** : si Zabbix refuse une étape, rien ne reste ni dans la base ni dans Zabbix.

```mermaid
sequenceDiagram
    autonumber
    actor M as Manager
    participant C as MonitoringTargetController
    participant S as MonitoringService
    participant DB as MySQL (Laravel)
    participant Z as ZabbixService
    participant API as API Zabbix

    M->>C: POST /targets (nom, URL, intervalle, seuils)
    C->>C: StoreTargetRequest : droits (Admin/Manager) + validation (champs communs + règles du type)
    C->>S: createTarget(team, auteur, type, données)
    S->>DB: BEGIN TRANSACTION
    S->>DB: INSERT monitoring_targets
    opt la team n'a pas encore de host group
        S->>Z: hostgroup.get / hostgroup.create
        Z->>API: JSON-RPC (Bearer token)
        S->>DB: UPDATE teams.zabbix_hostgroup_id
    end
    S->>Z: host.create (target-id, host group)
    Z->>API: JSON-RPC
    API-->>Z: hostid
    Note over S,Z: provisioner du type (SCRUM-66), ici ApiProvisioner
    S->>Z: httptest.create (web scenario « api-check » : URL, intervalle)
    S->>Z: trigger.create × 3 (indisponible ← critical ← warning)
    alt Zabbix répond OK
        S->>DB: UPDATE zabbix_host_id, COMMIT
        C->>DB: INSERT audit_logs (target.create)
        C-->>M: redirection vers la page de la target + « Monitoring started »
    else erreur Zabbix (panne, refus)
        S->>Z: host.delete (si le host a été créé)
        S->>DB: ROLLBACK
        C-->>M: retour au formulaire + « Monitoring service temporarily unavailable »
    end
```

## 2. Synchronisation d'une alerte

Chaque minute, sans intervention humaine, les problèmes détectés par Zabbix deviennent des alertes dans la plateforme.

```mermaid
sequenceDiagram
    autonumber
    participant SCH as Scheduler Laravel
    participant Q as Queue Redis + worker
    participant J as SyncZabbixEvents
    participant A as AlertService
    participant Z as ZabbixService / API Zabbix
    participant DB as MySQL (Laravel)
    actor U as Utilisateur

    SCH->>Q: chaque minute : place le job (unique)
    Q->>J: le worker exécute le job
    J->>A: syncFromZabbix()
    A->>DB: targets ayant un zabbix_host_id
    A->>Z: problem.get (hosts des targets, recent, tags)
    Z-->>A: problèmes actifs + résolus récemment
    A->>Z: item.get (dernier check, dernier temps de réponse)
    loop pour chaque problème
        A->>A: target retrouvée par le tag target_id, sévérité Zabbix → INFO / WARNING / HIGH / CRITICAL
        alt nouveau problème
            A->>Z: history.get (temps de réponse au début du problème)
            A->>DB: INSERT alerts (OPEN, valeur, seuil)
        else problème résolu
            A->>DB: UPDATE alerts : RESOLVED, resolved_at
        end
    end
    A->>Z: event.get (alertes encore ouvertes mais absentes de la liste)
    A->>DB: UPDATE statut et dernier check de chaque target
    alt synchro réussie
        J->>J: log « N events retrieved », marque la synchro comme OK
    else Zabbix injoignable
        J->>J: log CRITICAL, statuts inchangés, bandeau d'avertissement dans l'appli
    end
    U->>DB: ouvre le dashboard / la page Alerts (lecture en base, aucun appel Zabbix)
    DB-->>U: alertes et statuts à jour
```

## Points importants

- **Aucun appel Zabbix au chargement d'une page** : l'utilisateur lit la base, que la synchro tient à jour. Une panne de Zabbix ne casse donc pas l'interface.
- **Pas de doublon** : une alerte est identifiée par l'`eventid` Zabbix ; relancer la synchro ne crée rien de plus.
- **Une seule alerte par incident** : les triggers sont chaînés (indisponible ← critical ← warning), Zabbix ne lève que le plus grave.
