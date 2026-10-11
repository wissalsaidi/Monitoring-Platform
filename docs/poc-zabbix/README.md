# POC Zabbix — SCRUM-18

Objectif : prouver qu'on peut piloter Zabbix **uniquement via son API**
(créer un host, un web scenario, des triggers, puis récupérer un problème).

## Contenu

| Fichier | Rôle |
|---|---|
| `docker-compose.yml` | Zabbix 7.0 (server + web + MySQL) + une fausse API à surveiller |
| `fake-api/index.php` | Fausse "Payment API" dont on règle la lenteur |
| `poc.php` | Script qui parle à l'API Zabbix (deviendra le `ZabbixService` Laravel) |

## Prérequis

Docker Desktop lancé, et PHP 8.1+ avec l'extension `curl` (`php -m | grep curl`).

## Scénario de test (≈ 10 min)

```bash
# 1. Démarrer (le 1er lancement prend 2-3 min : Zabbix crée sa base)
docker compose up -d

# 2. Vérifier que l'interface répond : http://localhost:8080  (Admin / zabbix)

# 3. Créer le monitoring via l'API
php poc.php setup

# 4. Après ~1 min : état normal (🟢, quelques ms)
php poc.php status

# 5. Ralentir la fausse API à 1,2 s
curl "http://localhost:8081/control?delay=1200"

# 6. Après ~1 min : problèmes Warning + High (🔴)
php poc.php status

# 7. Retour à la normale -> les problèmes se résolvent tout seuls
curl "http://localhost:8081/control?delay=0"

# Bonus : tester l'indisponibilité (erreur 500)
curl "http://localhost:8081/control?mode=error"
```

Nettoyer : `php poc.php cleanup` puis `docker compose down -v`.

## Points techniques validés

- Authentification : `user.login` puis header `Authorization: Bearer <token>` (Zabbix 7.0).
- Un "check d'API" = un **web scenario** (`httptest.create`), pas un item classique.
  Zabbix crée tout seul les items `web.test.time[...]` (temps en **secondes**) et `web.test.fail[...]`.
- Les triggers référencent ces items : `last(/payment-api/web.test.time[health-check,health,resp])>1`.
- Le check part du conteneur `zabbix-server` : l'URL surveillée est donc `http://fake-api:8080/health`
  (nom du service Docker), pas `localhost`.
- Zabbix a 6 sévérités (0 → 5), la plateforme en aura 4 → mapping à prévoir (ticket SCRUM-39).
- `item.get` **ignore les items de web scenario** par défaut : il faut passer `'webitems' => true`.
- `problem.get` ne renvoie que les problèmes actifs. Avec `'recent' => true` il renvoie aussi les
  problèmes résolus récemment, avec `r_eventid` / `r_clock` → c'est ce qui donnera `alerts.resolved_at`
  dans le job `SyncZabbixEvents` (SCRUM-40).
- Le host par défaut « Zabbix server » remonte un problème « agent is not available » :
  la synchro doit filtrer par host group (`groupids`) pour ne prendre que les targets de la plateforme.

## Résultat du test (11/10/2026, Zabbix 7.0.31)

| Étape | Mesure | Problèmes |
|---|---|---|
| Normal | 4 – 34 ms | 🟢 aucun |
| `delay=1200` | ~1215 ms | 🔴 Warning + High (détectés en < 1 min) |
| `delay=0` | 4 – 9 ms | 🟢 résolus automatiquement (~1 min 30) |
| `mode=error` | HTTP 500 | 🔴 Disaster « indisponible », résolu au retour en `ok` |

## Si ça bloque

- `Zabbix injoignable` → attendre la fin de l'init : `docker compose logs -f zabbix-server`.
- `Login name or password is incorrect` → identifiants par défaut `Admin` / `zabbix`.
- Pas de mesure après 2 min → dans l'interface : *Monitoring › Hosts › Payment API › Web*.
