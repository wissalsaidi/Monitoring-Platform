# Modèle de données MySQL (MCD / MLD)

**Objectif :** décrire les données métier stockées par Laravel, leurs liens, leurs clés et leurs index. Les mesures brutes restent dans Zabbix (cahier des charges §38) : Laravel stocke les targets, les alertes, les utilisateurs et l'audit.

**Ticket Jira :** [SCRUM-21](https://wissalshalaoui.atlassian.net/browse/SCRUM-21)

## MCD : les entités et leurs relations

```mermaid
erDiagram
    USER }o--o{ TEAM : "est membre de (rôle)"
    TEAM ||--o{ MONITORING_TARGET : "possède"
    USER |o--o{ MONITORING_TARGET : "a créé"
    MONITORING_TARGET ||--o{ ALERT : "déclenche"
    USER |o--o{ ALERT : "a acquitté"
    USER |o--o{ AUDIT_LOG : "a fait"
    TEAM ||--o{ ALERT_RULE : "définit"
    ALERT ||--o{ NOTIFICATION : "est notifiée par"
    USER |o--o{ NOTIFICATION : "reçoit"
    TEAM ||--o{ WEBHOOK : "configure"
    WEBHOOK |o--o{ NOTIFICATION : "reçoit"
```

- Un **utilisateur** appartient à une ou plusieurs **teams**, avec un **rôle** dans chacune (Admin, Manager, Viewer).
- Une **target** appartient à une seule team (pas à un utilisateur) ; on garde son auteur pour l'audit.
- Une target déclenche des **alertes** ; une alerte peut être acquittée par un utilisateur.
- Chaque action sensible laisse une ligne dans l'**audit**, qui peut concerner n'importe quel objet (target, alerte…).
- Une team a des **règles de notification** (V1, SCRUM-52) : pour chaque sévérité et chaque événement (ouverture,
  résolution), email ou dashboard seulement. Chaque **notification** envoyée pour une alerte est gardée.

## MLD : les tables

```mermaid
erDiagram
    users {
        bigint id PK
        varchar name
        varchar email UK
        timestamp email_verified_at "null tant que non vérifié"
        varchar password "haché (bcrypt)"
        varchar platform_role "super_admin ou null (SCRUM-81)"
        text two_factor_secret "2FA TOTP, chiffré (SCRUM-86)"
        text two_factor_recovery_codes "codes de secours, chiffrés"
        timestamp two_factor_confirmed_at "2FA active depuis"
        timestamp deactivated_at "compte désactivé (SCRUM-84)"
        timestamp last_login_at "dernière connexion (SCRUM-84)"
        varchar remember_token
        timestamps created_updated
    }
    teams {
        bigint id PK
        varchar name
        varchar zabbix_hostgroup_id UK "host group Zabbix de la team"
        timestamps created_updated
    }
    team_members {
        bigint id PK
        bigint team_id FK
        bigint user_id FK
        varchar role "admin, manager, viewer"
        timestamps created_updated
    }
    monitoring_targets {
        bigint id PK
        bigint team_id FK
        bigint created_by FK "null si l'auteur est supprimé"
        varchar name
        varchar type "api, website, server… (types avec provisioner)"
        text description
        varchar status "unknown, up, warning, critical"
        boolean enabled
        varchar url "check HTTP (API, site web), sinon null"
        int check_interval "secondes, tous les types"
        int warning_threshold_ms "check HTTP, sinon null"
        int critical_threshold_ms "check HTTP, sinon null"
        json settings "configuration propre au type (SCRUM-66)"
        varchar zabbix_host_id UK
        timestamp last_checked_at
        decimal last_response_ms
        timestamps created_updated
    }
    alerts {
        bigint id PK
        bigint monitoring_target_id FK
        varchar zabbix_event_id UK "un problème Zabbix = une alerte"
        varchar zabbix_trigger_id
        varchar severity "info, warning, high, critical"
        varchar status "open, acknowledged, resolved"
        varchar message
        varchar metric "response_time, availability"
        decimal value "ms"
        int threshold "ms"
        timestamp started_at
        timestamp acknowledged_at
        bigint acknowledged_by FK
        timestamp resolved_at
        timestamps created_updated
    }
    alert_rules {
        bigint id PK
        bigint team_id FK
        varchar severity "info, warning, high, critical"
        varchar event "opened, resolved"
        varchar channel "email, dashboard"
        timestamps created_updated
    }
    notifications {
        bigint id PK
        bigint alert_id FK
        bigint user_id FK "null si le compte est supprimé"
        bigint webhook_id FK "webhook destinataire (SCRUM-75)"
        varchar recipient "email, ou nom du webhook, au moment de l'envoi"
        varchar channel "email, webhook"
        varchar event "opened, resolved"
        varchar status "pending, sent, failed"
        tinyint attempts
        varchar error
        timestamp sent_at
        timestamps created_updated
    }
    webhooks {
        bigint id PK
        bigint team_id FK
        varchar name "unique dans la team"
        varchar type "generic, slack, teams"
        text url "chiffrée (APP_KEY)"
        text secret "chiffré, signature HMAC"
        json severities "ex. critical, high"
        json events "opened, resolved"
        boolean active
        smallint consecutive_failures
        timestamps created_updated
    }
    audit_logs {
        bigint id PK
        bigint user_id FK
        varchar action "ex. target.update"
        varchar subject_type "objet concerné"
        bigint subject_id
        json details "ancienne et nouvelle valeur…"
        varchar ip_address
        timestamp created_at
    }

    users ||--o{ team_members : ""
    teams ||--o{ team_members : ""
    teams ||--o{ monitoring_targets : ""
    users |o--o{ monitoring_targets : "created_by"
    monitoring_targets ||--o{ alerts : ""
    users |o--o{ alerts : "acknowledged_by"
    users |o--o{ audit_logs : ""
    teams ||--o{ alert_rules : ""
    alerts ||--o{ notifications : ""
    users |o--o{ notifications : ""
    teams ||--o{ webhooks : ""
    webhooks |o--o{ notifications : ""
```

## Clés étrangères et règles de suppression

| Colonne | Référence | Si la ligne référencée est supprimée |
|---|---|---|
| `team_members.team_id` | `teams.id` | les adhésions sont supprimées (cascade) |
| `team_members.user_id` | `users.id` | les adhésions sont supprimées (cascade) |
| `monitoring_targets.team_id` | `teams.id` | les targets sont supprimées (cascade) |
| `monitoring_targets.created_by` | `users.id` | mis à `null` (la target reste) |
| `alerts.monitoring_target_id` | `monitoring_targets.id` | les alertes sont supprimées (cascade) |
| `alerts.acknowledged_by` | `users.id` | mis à `null` (l'alerte reste) |
| `audit_logs.user_id` | `users.id` | mis à `null` (la trace reste) |
| `alert_rules.team_id` | `teams.id` | les règles sont supprimées (cascade) |
| `notifications.alert_id` | `alerts.id` | les notifications sont supprimées (cascade) |
| `notifications.user_id` | `users.id` | mis à `null` ; l'email du destinataire est remplacé par « deleted user » (RGPD) |
| `webhooks.team_id` | `teams.id` | les webhooks sont supprimés (cascade) |
| `notifications.webhook_id` | `webhooks.id` | mis à `null` (l'historique reste, avec le nom du webhook) |

## Contraintes d'unicité et index

| Table | Contrainte / index | Pourquoi |
|---|---|---|
| `users` | `email` unique | un compte par adresse |
| `team_members` | (`team_id`, `user_id`) unique | une seule adhésion par personne et par team |
| `teams` | `zabbix_hostgroup_id` unique | une team = un host group Zabbix |
| `monitoring_targets` | `zabbix_host_id` unique | une target = un host Zabbix |
| `monitoring_targets` | index (`team_id`, `status`) | compteurs du dashboard par team et par statut |
| `alerts` | `zabbix_event_id` unique | pas de doublon d'une synchro à l'autre |
| `alerts` | index (`monitoring_target_id`, `status`) | alertes actives d'une target |
| `alerts` | index (`status`, `started_at`) | pages Alertes actives / Historique triées par date |
| `audit_logs` | index (`action`, `created_at`) | recherche dans l'audit par type d'action |
| `audit_logs` | index (`subject_type`, `subject_id`) | historique d'un objet précis |
| `alert_rules` | (`team_id`, `severity`, `event`) unique | une seule règle par sévérité et par événement |
| `notifications` | (`alert_id`, `recipient`, `channel`, `event`) unique | une notification n'est jamais envoyée deux fois pour le même événement |
| `notifications` | index (`status`, `created_at`) | historique des notifications, filtre par statut |
| `webhooks` | (`team_id`, `name`) unique | le nom d'un webhook est le destinataire de ses notifications |

Le nom d'une target est aussi unique **dans sa team** (règle de validation applicative).

## Tables techniques de Laravel

`sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches` et `failed_jobs` sont créées par Laravel et ne portent pas de données métier.

## Évolutions de la V2

- **`webhooks`** (SCRUM-75) : webhooks d'une team (générique, Slack, Microsoft Teams), abonnés à des sévérités et des
  événements, indépendamment des règles email. URL et secret chiffrés. Chaque envoi est une ligne de `notifications`
  (canal `webhook`, destinataire = nom du webhook, `webhook_id`).
- **`users`** : `platform_role` (SCRUM-81), `deactivated_at` et `last_login_at` (SCRUM-84), colonnes 2FA (SCRUM-86).

## Évolutions de la V1

- **`alert_rules`** et **`notifications`** (SCRUM-52) : voir ci-dessus. Règles par défaut de chaque team : CRITICAL et
  HIGH par email à l'ouverture et à la résolution, WARNING et INFO au dashboard seulement.
- **`target_daily_stats`** (SCRUM-51) : statistiques journalières par target (disponibilité, temps de réponse moyen,
  incidents, MTTR), une ligne par target et par jour.
- Grafana : pas de table `grafana_dashboards` (prévue au départ). Un dashboard générique sert pour toutes les targets
  (SCRUM-46) ; les ids de l'organisation Grafana et de l'utilisateur Zabbix d'une team sont dans `teams` (SCRUM-48).
