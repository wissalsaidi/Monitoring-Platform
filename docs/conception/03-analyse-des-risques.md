# Analyse des risques

**Objectif :** repérer ce qui peut faire échouer ou retarder le projet, l'évaluer, et prévoir une parade pour chaque risque.

**Ticket Jira :** [SCRUM-19](https://wissalshalaoui.atlassian.net/browse/SCRUM-19)

## Échelle

- **Probabilité** : 1 = peu probable · 2 = possible · 3 = probable
- **Impact** : 1 = gêne légère · 2 = retard ou fonctionnalité dégradée · 3 = blocage du projet ou de la démo
- **Criticité** = probabilité × impact : 1-2 faible · 3-4 moyenne · 6-9 forte

## Registre des risques

| Id | Risque | P | I | Criticité | Mesure de mitigation | Situation après le MVP |
|---|---|---|---|---|---|---|
| R1 | **Complexité de l'API Zabbix** (hosts, items, web scenarios, triggers, événements) | 3 | 3 | 9 forte | POC avant la conception (SCRUM-18) ; un seul point d'entrée `ZabbixService` ; Zabbix simulé dans les tests | Maîtrisé. Deux pièges réels trouvés et corrigés grâce aux tests sur le vrai Zabbix (étape du web scenario recréée, triggers perdus). |
| R2 | **Dérive du périmètre** : le cahier des charges couvre MVP, V1, V2, V3 | 3 | 2 | 6 forte | Découpage MVP / V1 / V2 dans Jira ; seul le type API dans le MVP ; un ticket = une branche = une PR | Maîtrisé. MVP livré (v0.1.0-mvp), les autres types et Grafana restent en V1. |
| R3 | **Temps disponible limité** (alternance) | 3 | 2 | 6 forte | Tickets courts et indépendants ; démo possible à chaque étape ; documentation de reprise (README, docs) | En cours : à surveiller pour la V1. |
| R4 | **Dépendance à Grafana** pour les graphiques | 2 | 2 | 4 moyenne | Graphique essentiel fait dans l'appli (Chart.js, SCRUM-44) ; Grafana réservé à l'analyse avancée (V1) | Maîtrisé : le MVP n'en dépend pas. |
| R5 | **Zabbix indisponible** pendant l'utilisation | 2 | 2 | 4 moyenne | Pages alimentées par la base ; bandeau générique ; statuts conservés ; création « tout ou rien » | Maîtrisé (vérifié par des tests). |
| R6 | **Environnement Docker instable sous Windows** (WSL, mises à jour) | 2 | 3 | 6 forte | Données dans des volumes ; sauvegarde avant les opérations risquées ; procédure de redémarrage documentée | Survenu deux fois (mises à jour de WSL) ; résolu par la mise à jour de Docker Desktop, sans perte de données. |
| R7 | **Faille de sécurité** (auth, cloisonnement des teams, secrets) | 2 | 3 | 6 forte | Fortify, policies Laravel, tests de cloisonnement, token API dans `.env`, `composer audit` en CI ; analyse STRIDE (SCRUM-24) | Maîtrisé pour le MVP ; RBAC fin et HTTPS en V1. |
| R8 | **Incohérence entre la plateforme et Zabbix** (opération à moitié faite) | 2 | 2 | 4 moyenne | Transactions + remise de Zabbix dans l'état précédent ; synchro idempotente ; identifiants Zabbix stockés | Maîtrisé. |
| R9 | **Montée en charge** (beaucoup de targets ou d'alertes) | 1 | 2 | 2 faible | Index SQL, pagination, nombre de requêtes constant, cache des graphiques | Acceptable pour un portfolio ; à mesurer en V1. |
| R10 | **Perte de données** (bases MySQL) | 1 | 3 | 3 moyenne | Volumes Docker ; sauvegarde quotidienne à mettre en place | Partiel : sauvegarde automatique prévue (exigence DISP-5). |

## Matrice probabilité × impact

|  | **Impact 1** | **Impact 2** | **Impact 3** |
|---|---|---|---|
| **Probabilité 3** |  | R2, R3 | R1 |
| **Probabilité 2** |  | R4, R5, R8 | R6, R7 |
| **Probabilité 1** |  | R9 | R10 |

## Conclusion

Les risques les plus forts au départ étaient la complexité de Zabbix (R1) et la dérive du périmètre (R2). Le POC et le découpage strict MVP / V1 les ont rendus gérables : le MVP est livré. Les points à suivre pour la V1 sont le temps disponible (R3), la sauvegarde des données (R10) et le renforcement de la sécurité (HTTPS, RBAC fin).
