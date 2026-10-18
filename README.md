# Monitoring & Alerting Platform

Plateforme web Full-Stack pour déclarer, surveiller et visualiser des ressources informatiques
(APIs, sites web, serveurs, conteneurs, bases de données…).

- **Laravel** : interface (Blade + Bootstrap 5 + Bootstrap Icons), métier, alertes, RBAC
- **Zabbix** : moteur de supervision, piloté uniquement via son API
- **Grafana** : visualisation avancée des métriques (datasource Zabbix)

Cahier des charges : v1.2 · Suivi : Jira, projet `SCRUM`.

## Aperçu

| **Dashboard** | **Page d'une target** |
|---|---|
| ![Dashboard](docs/screenshots/02-dashboard.png) | ![Target](docs/screenshots/05-target-overview.png) |
| **Historique des alertes** | **Ajouter une target** |
| ![Alertes](docs/screenshots/08-alerts-history.png) | ![Formulaire](docs/screenshots/04-target-form.png) |

Tous les écrans, annotés : [docs/conception/07-ecrans.md](docs/conception/07-ecrans.md).

## Architecture

```
navigateur ──► nginx :8000 ──► app (Laravel, PHP-FPM) ──► mysql (base Laravel)
                                   │                  └─► redis (cache / queues) ◄── queue + scheduler
                                   └── API JSON-RPC ──► zabbix-web :8080 ──► zabbix-server ──► targets
                                                                  │                          (ex : fake-api)
navigateur ──► grafana :3000 ── datasource Zabbix ────────────────┘
```

| Service | URL depuis le PC | Rôle |
|---|---|---|
| `nginx` + `app` | http://localhost:8000 | Application Laravel |
| `mysql` | `localhost:3307` | Base Laravel |
| `redis` | — | Cache et queues |
| `queue` + `scheduler` | — | Worker de la queue et Laravel Scheduler : synchro Zabbix chaque minute |
| `zabbix-web` | http://localhost:8080 (`Admin` / `zabbix`) | Interface et API Zabbix |
| `zabbix-server` + `zabbix-db` | — | Moteur Zabbix et sa propre base |
| `grafana` | http://localhost:3000 (`admin` / `GRAFANA_ADMIN_PASSWORD`) | Dashboards |
| `fake-api` | http://localhost:8081/health | API de démo à latence réglable |
| `phpmyadmin` | http://localhost:8082 | phpMyAdmin : interface web des bases MySQL (dev) |
| `mailpit` | http://localhost:8025 | Boîte mail de dev : reset de mot de passe, vérification d'email |

## Installation

Prérequis : Docker Desktop, Node 20+ (pour compiler le front).

```bash
cp .env.example .env               # puis changer les mots de passe
docker compose up -d --build       # 1er démarrage ≈ 5 min (Zabbix crée sa base)

docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

npm install
npm run build                      # ou "npm run dev" pendant le développement
```

### Token API Zabbix

Laravel s'authentifie auprès de Zabbix avec un **token API** (jamais avec un mot de passe) :

1. Zabbix (http://localhost:8080) → *Users* › *API tokens* › **Create API token**
   (nom : `laravel-app`, utilisateur : `Admin`, sans date d'expiration en dev)
2. Copier le token dans `.env` → `ZABBIX_API_TOKEN=...`
3. Vérifier : `docker compose exec app php artisan zabbix:check`

Les appels sont journalisés dans `storage/logs/zabbix-<date>.log` (INFO / ERROR, sans token ni paramètres).

### Synchronisation des alertes

Chaque minute, le scheduler place le job `SyncZabbixEvents` dans la queue Redis : il lit les problèmes Zabbix,
crée ou résout les alertes et met à jour le statut des targets (UP / WARNING / CRITICAL / UNKNOWN).

| Zabbix | Plateforme |
|---|---|
| 0 Not classified, 1 Information | INFO |
| 2 Warning, 3 Average | WARNING |
| 4 High | HIGH |
| 5 Disaster | CRITICAL |

Lancer une synchro tout de suite : `docker compose exec app php artisan zabbix:sync`.
Après une modification du code PHP, redémarrer le worker : `docker compose restart queue`.

Sous Windows, `vendor/` du conteneur vit dans un volume Docker (sinon chaque page prend plusieurs secondes).
Après toute modification de `composer.json` : `docker compose exec app composer install`.

Toutes les commandes `artisan` passent par le conteneur : `docker compose exec app php artisan …`
(depuis Windows, les hôtes `mysql`, `redis` et `zabbix-web` n'existent pas).

## Équipes, rôles et sécurité

| Rôle | Peut |
|---|---|
| **Viewer** | voir le dashboard, les targets et les alertes |
| **Manager** | + créer, modifier, supprimer des targets et acquitter les alertes |
| **Admin** | + inviter et gérer les membres, consulter le journal d'audit, configurer la team |

- **Membres** (`/teams/{team}/members`) : invitation par email (lien signé valable 7 jours), changement de rôle, retrait ;
  une team garde toujours au moins un Admin.
- **Journal d'audit** (`/teams/{team}/audit`) : qui a fait quoi et quand, filtres par action, membre et période ;
  purgé après 1 an (`audit:purge`).
- **My account** : suppression du compte et des données personnelles (RGPD), audit anonymisé.
- **SSRF** : une target ne peut pas viser une adresse interne (`localhost`, réseau privé…), contrôlé après résolution DNS.
- **En-têtes** : CSP sans script inline, `X-Frame-Options`, `nosniff`, HSTS en HTTPS. Pas de `onclick=` / `onchange=`
  dans les vues : utiliser `data-auto-submit` et `data-confirm`.

Analyse de menaces : [docs/conception/06-menaces-stride-owasp.md](docs/conception/06-menaces-stride-owasp.md).

## Exploitation

| Sujet | Commande | Documentation |
|---|---|---|
| Sauvegardes des 2 bases (chaque nuit à 02:30 UTC, gardées 7 jours) | `php artisan backup:run`, `backup:list`, `backup:restore` | [sauvegardes.md](docs/exploitation/sauvegardes.md) |
| Mise en production (HTTPS, `APP_DEBUG=false`, logs 14 jours) | `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d`, puis `php artisan security:check` | [production.md](docs/exploitation/production.md) |
| Mises à jour des dépendances et des images | routine mensuelle, `npm audit` et `composer audit` dans la CI | [mises-a-jour.md](docs/exploitation/mises-a-jour.md) |

## Voir les bases de données (phpMyAdmin)

http://localhost:8082. Le port 3307 est un port MySQL : il ne s'ouvre pas dans un navigateur.

| Serveur (liste déroulante) | Utilisateur | Mot de passe |
|---|---|---|
| **Laravel** | `DB_USERNAME` | `DB_PASSWORD` |
| **Zabbix** | `zabbix` | `ZBX_DB_PASSWORD` |

Valeurs dans `.env`. La base Zabbix se consulte en lecture seule : on la modifie toujours par l'API.

## Fausse API de démo

```bash
curl -s "http://localhost:8081/control?delay=1200"      # rend l'API lente (1,2 s)
curl -s "http://localhost:8081/control?mode=error"      # renvoie des erreurs 500
curl -s "http://localhost:8081/control?mode=ok&delay=0" # retour à la normale
```

Vue depuis Zabbix, son adresse est `http://fake-api:8080/health` (nom du service Docker).

## Tests et qualité

```bash
php artisan test          # tests (SQLite en mémoire, pas besoin de Docker)
vendor/bin/pint           # style de code (PSR-12 / Laravel)
```

La CI GitHub Actions (`.github/workflows/ci.yml`) lance : syntaxe PHP, Pint, `composer audit`, `npm audit`,
tests, build Vite et build de l'image Docker.

## Documentation

- [`docs/conception/`](docs/conception/README.md) : cas d'utilisation, exigences, risques, modèle de données, séquences, STRIDE, écrans
- [`docs/exploitation/`](docs/exploitation/) : sauvegardes, mise en production, mises à jour
- [`docs/poc-zabbix/`](docs/poc-zabbix/README.md) : POC SCRUM-18, pilotage de Zabbix par l'API (validé)
- [`CONTRIBUTING.md`](CONTRIBUTING.md) : branches, commits, pull requests
