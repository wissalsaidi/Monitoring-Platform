# Conventions Git

## Branches

| Branche | Rôle |
|---|---|
| `main` | Version stable, démontrable. On n'y pousse jamais directement. |
| `develop` | Intégration : toutes les features y sont fusionnées. |
| `feature/SCRUM-<n>-<sujet>` | Une branche par ticket Jira, créée depuis `develop`. |
| `fix/SCRUM-<n>-<sujet>` | Correction de bug. |

Exemple : `feature/SCRUM-37-zabbix-service`

Le numéro de ticket dans le nom de branche permet à Jira de relier automatiquement
la branche, les commits et la pull request au ticket.

## Commits : Conventional Commits

```
<type>(<portée optionnelle>): <description courte> [SCRUM-<n>]
```

| Type | Usage |
|---|---|
| `feat` | nouvelle fonctionnalité |
| `fix` | correction de bug |
| `test` | ajout ou modification de tests |
| `docs` | documentation |
| `refactor` | restructuration sans changement de comportement |
| `chore` | outillage, dépendances, config |
| `ci` | pipeline GitHub Actions |

Exemples :

```
feat(zabbix): add ZabbixService with token authentication [SCRUM-37]
fix(alerts): handle unavailable Zabbix server [SCRUM-40]
test(targets): add monitoring target feature tests [SCRUM-33]
```

## Workflow

1. `git switch develop && git pull`
2. `git switch -c feature/SCRUM-37-zabbix-service`
3. Commits réguliers, puis `git push -u origin feature/SCRUM-37-zabbix-service`
4. Pull request vers `develop`, CI verte, relecture, puis merge
5. En fin de sprint : pull request `develop` → `main`

## Avant chaque push

```bash
vendor/bin/pint           # formate le code
php artisan test          # les tests doivent passer
```

Ne jamais committer `.env` ni de secrets : seul `.env.example` est versionné.
