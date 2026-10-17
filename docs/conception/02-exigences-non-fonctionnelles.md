# Exigences non fonctionnelles

**Objectif :** fixer des exigences **mesurables** (sécurité, performance, disponibilité, maintenabilité, RGPD) et vérifier où en est le MVP.

**Ticket Jira :** [SCRUM-17](https://wissalshalaoui.atlassian.net/browse/SCRUM-17)

Légende du statut : ✅ atteint dans le MVP (v0.1.0-mvp) · 🟡 partiel · ⏳ prévu (V1 ou plus tard).

## Performance

| Id | Exigence | Mesure / seuil | Statut |
|---|---|---|---|
| PERF-1 | Le dashboard se charge vite | < 1 s côté serveur | ✅ ≈ 150 ms mesurés, 11 requêtes SQL |
| PERF-2 | Le nombre de requêtes ne grossit pas avec le nombre de targets | ≤ 12 requêtes avec 30 targets | ✅ vérifié par un test automatique |
| PERF-3 | Les pages ne dépendent pas de Zabbix | 0 appel Zabbix au chargement d'une page | ✅ données lues en base, remplies par la synchro |
| PERF-4 | Un problème apparaît vite dans la plateforme | ≤ 2 min entre la panne et l'alerte | ✅ check Zabbix toutes les 30 s + synchro chaque minute |
| PERF-5 | Le graphique reste lisible et léger | ≤ 360 points affichés, réponse < 1 s | ✅ regroupement des mesures, cache de 30 s |

## Sécurité

| Id | Exigence | Mesure / seuil | Statut |
|---|---|---|---|
| SEC-1 | Mots de passe jamais stockés en clair | Hachage bcrypt, coût 12 | ✅ |
| SEC-2 | Mots de passe d'une longueur minimale | ≥ 8 caractères, confirmation obligatoire | ✅ |
| SEC-3 | Limiter les attaques par force brute | ≤ 5 tentatives de connexion par minute (email + IP) ; 1 email de réinitialisation par minute | ✅ |
| SEC-4 | Liens envoyés par email sûrs | Lien signé, valable 60 min (vérification et réinitialisation) | ✅ |
| SEC-5 | Email vérifié avant tout accès | 100 % des pages de l'application derrière `verified` | ✅ |
| SEC-6 | Protection CSRF et XSS | Jeton CSRF sur tous les formulaires, sorties Blade échappées | ✅ |
| SEC-7 | Cloisonnement entre teams | Aucun accès à une target ou alerte d'une autre team (403) | ✅ couvert par des tests Feature |
| SEC-8 | Moindre privilège | Viewer en lecture seule ; création, modification, suppression et acquittement réservés aux Admin/Manager | ✅ |
| SEC-9 | Secrets hors du code | Token API Zabbix et mots de passe uniquement dans `.env` (jamais commité) ; le token n'apparaît jamais dans les logs | ✅ |
| SEC-10 | Aucune erreur technique montrée | Message générique quand Zabbix est indisponible (§47) | ✅ |
| SEC-11 | HTTPS | Tout le trafic en HTTPS, cookies `secure` | ⏳ à la mise en ligne (en local : HTTP) |
| SEC-12 | Dépendances sans faille connue | `composer audit` sans alerte à chaque push | ✅ dans la CI |

## Disponibilité et fiabilité

| Id | Exigence | Mesure / seuil | Statut |
|---|---|---|---|
| DISP-1 | La plateforme reste utilisable si Zabbix tombe | Les pages s'affichent avec les dernières données + bandeau d'avertissement | ✅ |
| DISP-2 | Pas de doublon d'alerte | 1 problème Zabbix = 1 alerte (`zabbix_event_id` unique) | ✅ |
| DISP-3 | Création, modification, suppression « tout ou rien » | Si Zabbix échoue, rien n'est enregistré et Zabbix est remis dans son état précédent | ✅ |
| DISP-4 | Les tâches de fond redémarrent seules | Worker et scheduler en `restart: unless-stopped` | ✅ |
| DISP-5 | Sauvegarde des données | Sauvegarde quotidienne des bases MySQL, conservée 7 jours | ⏳ |

## Maintenabilité

| Id | Exigence | Mesure / seuil | Statut |
|---|---|---|---|
| MAIN-1 | Code testé automatiquement | Tests unitaires et Feature verts à chaque push | ✅ 214 tests |
| MAIN-2 | Style de code homogène | `pint --test` sans erreur dans la CI | ✅ |
| MAIN-3 | Appels Zabbix centralisés | 100 % des appels via `ZabbixService` | ✅ |
| MAIN-4 | Environnement reproductible | `docker compose up` suffit à lancer toute la stack | ✅ |
| MAIN-5 | Traçabilité | Chaque commit et chaque PR référencent un ticket Jira | ✅ |

## RGPD et journalisation

| Id | Exigence | Mesure / seuil | Statut |
|---|---|---|---|
| RGPD-1 | Données personnelles limitées au nécessaire | Nom, email, mot de passe haché, IP (sessions et audit) | ✅ |
| RGPD-2 | Actions sensibles tracées | Création, modification, suppression de target et acquittement dans `audit_logs` | ✅ |
| RGPD-3 | Durée de conservation des journaux d'audit | 1 an, puis suppression automatique | ✅ `audit:purge` chaque nuit (SCRUM-60) |
| RGPD-4 | Durée de conservation des logs techniques | Logs Zabbix : 14 jours (rotation quotidienne) | 🟡 le log Laravel principal n'a pas encore de rotation |
| RGPD-5 | Droit à l'effacement | Suppression de son compte et de ses données personnelles | ✅ page My account, audit anonymisé (SCRUM-63) |
| RGPD-6 | Hébergement maîtrisé | Toutes les données restent sur l'infrastructure de l'utilisateur (Docker), aucune donnée envoyée à un service tiers | ✅ |
