# Acteurs et cas d'utilisation

**Objectif :** savoir qui utilise la plateforme et ce que chacun peut faire, avant de parler technique.

**Ticket Jira :** [SCRUM-16](https://wissalshalaoui.atlassian.net/browse/SCRUM-16)

## Les acteurs

| Acteur | Type | Description |
|---|---|---|
| **Visiteur** | humain | Personne qui n'a pas encore de compte ou n'est pas connectée. Elle peut seulement créer un compte, se connecter ou réinitialiser son mot de passe. |
| **Viewer** | humain | Membre d'une team qui **consulte** : dashboard, targets, alertes, graphiques. Il ne modifie rien. |
| **Manager** | humain | Membre d'une team qui **gère la supervision** : il fait tout ce que fait un Viewer, et en plus il crée, modifie et supprime des targets et acquitte les alertes. |
| **Admin** | humain | Responsable de la team. Il a les mêmes droits qu'un Manager dans le MVP ; la gestion des membres et des rôles arrive en V1/V2. Depuis SCRUM-82, l'inscription ne crée plus de team : un compte arrive sans team et attend qu'un Admin l'ajoute. |
| **Zabbix** | système | Moteur de supervision. Il reçoit la configuration envoyée par la plateforme, vérifie les URLs, détecte les problèmes et les résout. |
| **Scheduler Laravel** | système | Horloge interne. Chaque minute, il lance la synchronisation des problèmes Zabbix vers les alertes. |

Les rôles s'emboîtent : **Viewer ⊂ Manager ⊂ Admin**. Chaque personne ne voit que les targets et les alertes des teams dont elle est membre.

Depuis la V1 (SCRUM-58), chaque rôle correspond à une liste de **permissions nommées** :

| Permission | Viewer | Manager | Admin |
|---|:---:|:---:|:---:|
| `dashboard.view`, `target.view`, `alert.view` | ✅ | ✅ | ✅ |
| `target.create`, `target.update`, `target.delete` | — | ✅ | ✅ |
| `alert.acknowledge` | — | ✅ | ✅ |
| `notification.manage` (règles de notification, SCRUM-55) | — | ✅ | ✅ |

Depuis la V2 (SCRUM-81), un rôle de **plateforme** s'ajoute aux rôles de team : le **Super Admin** (DSI), désigné en
ligne de commande (`php artisan platform:create-admin`), jamais par le web. Il a les permissions `platform.users.manage`,
`platform.teams.manage` et `platform.audit.view`, mais **aucun droit dans une team dont il n'est pas membre**. La
plateforme garde toujours au moins un Super Admin.
| `user.view`, `user.create`, `user.update`, `user.delete` | — | — | ✅ |
| `configuration.manage` | — | — | ✅ |

## Diagramme de cas d'utilisation

```mermaid
flowchart LR
    visiteur["👤 Visiteur"]
    viewer["👤 Viewer"]
    manager["👤 Manager"]
    admin["👤 Admin"]
    scheduler["⏱️ Scheduler<br/>(acteur système)"]

    subgraph Plateforme["Monitoring & Alerting Platform"]
        direction TB
        uc1([S'inscrire])
        uc4([Vérifier son email])
        uc2([Se connecter])
        uc3([Réinitialiser son mot de passe])
        uc5([Consulter le dashboard])
        uc6([Lister et filtrer les targets])
        uc7([Consulter une target])
        uc8([Voir le graphique du temps de réponse])
        uc9([Consulter les alertes et l'historique])
        uc10([Créer une target])
        uc11([Modifier une target])
        uc12([Supprimer une target])
        uc13([Acquitter une alerte])
        uc15([Gérer les membres de la team — V1])
        uc14([Synchroniser les problèmes Zabbix])
    end

    zabbix["⚙️ Zabbix<br/>(acteur système)"]

    visiteur --- uc1 & uc2 & uc3
    uc1 -. «include» .-> uc4
    viewer --- uc5 & uc6 & uc7 & uc8 & uc9
    manager --- uc10 & uc11 & uc12 & uc13
    admin --- uc15
    scheduler --- uc14
    uc8 & uc10 & uc11 & uc12 & uc13 & uc14 --- zabbix
```

**Héritage des rôles** (non dessiné pour garder le schéma lisible) : un Manager peut faire tout ce que fait un Viewer, et un Admin tout ce que fait un Manager.

## Description des cas d'utilisation

| Cas d'utilisation | Acteur principal | Ce qui se passe | Ticket |
|---|---|---|---|
| Gérer les teams | Super Admin | `/admin/teams` : liste (Admins, membres, targets, alertes actives) ; créer une team avec son premier Admin (compte existant ou invitation), host group Zabbix et organisation Grafana créés ; renommer (Zabbix et Grafana suivent) ; supprimer en tapant son nom exact. | SCRUM-85 |
| Lire l'audit de la plateforme | Super Admin | `/admin/audit` : toutes les teams et les actions de plateforme, filtres par team, action, auteur et dates. | SCRUM-85 |
| Gérer les utilisateurs de la plateforme | Super Admin | `/admin/users` : liste (teams, rôles, statut, dernière connexion, 2FA), recherche et filtres, invitations ; ajouter à une team, changer de rôle, retirer, promouvoir ou retirer Super Admin ; désactiver / réactiver un compte ; forcer la réinitialisation du mot de passe. Garde-fous : pas soi-même, dernier Super Admin actif, dernier Admin actif d'une team. | SCRUM-84 |
| Créer son compte depuis une invitation | Invité | Le lien signé (7 jours, usage unique) ouvre « Create your account » pour une adresse inconnue : email prérempli et vérifié d'office, la personne arrive dans la team avec le rôle choisi, sans autre team. Compte existant : connexion puis acceptation. Le Super Admin peut inviter dans n'importe quelle team (`platform:invite`). | SCRUM-83 |
| S'inscrire | Visiteur | Seulement si `REGISTRATION_MODE` vaut `domain` (domaines de l'entreprise) ou `open` ; en `invite` (par défaut), la page n'existe pas. Le compte est créé **sans team** et un email de vérification est envoyé ; une fois l'adresse vérifiée, les Super Admin sont prévenus. Jusqu'à son ajout dans une team, l'utilisateur voit « Waiting for access ». | SCRUM-30, 31, 82 |
| Se connecter | Visiteur | Email + mot de passe, 5 essais par minute maximum. | SCRUM-30 |
| Réinitialiser son mot de passe | Visiteur | Lien envoyé par email, valable 60 minutes. | SCRUM-32 |
| Vérifier son email | Visiteur | Lien signé valable 60 minutes ; obligatoire avant d'accéder à l'application. | SCRUM-32 |
| Consulter le dashboard | Viewer | KPIs (targets par statut, alertes actives), targets en difficulté, dernières alertes. | SCRUM-43 |
| Lister et filtrer les targets | Viewer | Filtres par statut, type et nom, pagination. | SCRUM-34 |
| Consulter une target | Viewer | Statut, dernier temps de réponse, seuils, alertes, configuration Zabbix. | SCRUM-36 |
| Voir le graphique du temps de réponse | Viewer | Courbe sur 1 h, 24 h ou 7 jours avec les seuils ; les mesures sont lues dans Zabbix. | SCRUM-44 |
| Consulter les alertes et l'historique | Viewer | Alertes actives et résolues, filtres par sévérité et par target, durée des incidents. | SCRUM-41 |
| Créer une target | Manager | Formulaire ; la plateforme crée dans Zabbix le host, le check et les 3 triggers. | SCRUM-33, 38 |
| Modifier une target | Manager | Nouveaux seuils, intervalle, nom ou URL envoyés à Zabbix. | SCRUM-35 |
| Supprimer une target | Manager | Après confirmation : suppression du host Zabbix, de la target et de ses alertes. | SCRUM-35 |
| Acquitter une alerte | Manager | L'alerte passe en ACKNOWLEDGED avec l'auteur ; l'acquittement est envoyé à Zabbix. | SCRUM-42 |
| Synchroniser les problèmes Zabbix | Scheduler | Chaque minute : nouveaux problèmes → alertes, résolutions → alertes RESOLVED, statut des targets mis à jour. | SCRUM-40 |
| Gérer les membres de la team | Admin | Inviter par email (lien signé valable 7 jours), changer un rôle, retirer un membre. La team garde toujours au moins un Admin. | SCRUM-59 |
