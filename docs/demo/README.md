# Environnement de démonstration

SCRUM-73, cahier des charges §42 et §43 : des ressources de chaque type, et des pannes qu'on provoque à la demande
pour montrer la plateforme de bout en bout.

## Ressources de démo

| Target | Type | Service Docker | Ce qui est supervisé | Seuils |
|---|---|---|---|---|
| **Payment API** | API | `fake-api` | `http://fake-api:8080/health` : disponibilité, temps de réponse, code HTTP | 500 / 1000 ms |
| **Website-01** | Website | `demo-website-01` | `http://demo-website-01:8080/` (vitrine d'une boutique) | 800 / 2000 ms |
| **Server-01** | Server | `demo-server-01` | Agent Zabbix 2 : CPU, mémoire, disque `/` (template Linux) | CPU 60 / 85 %, mémoire 85 / 95 %, disque 70 / 90 % |
| **Redis-01** | Container | `demo-redis-01` | État, redémarrages, CPU, mémoire (plugin Docker de `zabbix-agent2`) | mémoire 64 Mo |
| **Nginx-01** | Container | `demo-nginx-01` | Idem | mémoire 64 Mo |
| **MySQL-01** | Database | `demo-mysql-01` | Disponibilité, temps de réponse, connexions, requêtes/s (utilisateur `zbx_monitor`) | 20 connexions |

Les seuils sont bas exprès : les pannes ci-dessous les dépassent en quelques minutes.

Deux simplifications propres à la démo :

- **Server-01** est un agent Zabbix 2 dans un conteneur. Son CPU et sa mémoire sont ceux de la machine Docker
  (la VM WSL sous Windows). Son disque `/` est simulé : l'agent lit à la place un tmpfs de 64 Mo monté sur
  `/demo-disk` (alias dans `docker/demo/server/demo-server.conf`). On peut donc le remplir sans risque.
- **MySQL-01** est réglée pour peu de mémoire (environ 100 Mo). Son mot de passe root est aléatoire et n'est pas utilisé.
  Seul `zbx_monitor` sert, en lecture seule.

## Démarrer

Les services de démo sont dans le profil `demo` : un simple `docker compose up -d` ne les lance pas, ce qui économise
la mémoire.

```bash
docker compose --profile demo up -d
docker compose exec -u www-data app php artisan db:seed --class=DemoSeeder
```

Le seeder crée :

- le compte `DEMO_USER_EMAIL` (par défaut `demo@example.com`), avec son email déjà vérifié ;
- sa team **Demo**, dont il est administrateur ;
- les six targets ci-dessus. Elles sont créées comme depuis le formulaire : mêmes règles de validation, host, checks et
  triggers créés dans Zabbix.

Pour le mot de passe :

- c'est `DEMO_USER_PASSWORD` dans `.env` ;
- s'il est vide, le seeder en génère un et l'affiche une seule fois.

Relancer le seeder ne recrée rien. Il ajoute seulement les targets supprimées.

Variables du `.env` :

- `DEMO_USER_EMAIL`, `DEMO_USER_PASSWORD` : le compte de démo ;
- `DEMO_MYSQL_MONITOR_PASSWORD` : le mot de passe de `zbx_monitor` sur MySQL-01. Il est lu au premier démarrage de la
  base. Après un changement, lancer `docker compose rm -sfv demo-mysql-01`, puis relancer le profil ;
- `TARGET_ALLOWED_INTERNAL_HOSTS` doit contenir `fake-api,demo-website-01`. Ces deux URL internes sont autorisées
  malgré la protection SSRF (SCRUM-57).

Les premières valeurs arrivent en 1 à 2 minutes. La synchronisation des alertes tourne chaque minute.

## Provoquer les pannes

Les commandes sont à lancer depuis le dossier du projet. Sous PowerShell, écrire `curl.exe -s` au lieu de `curl -s`.
Sous Git Bash, commencer chaque commande `docker exec` par `MSYS_NO_PATHCONV=1` : sinon `/demo-disk/fill` devient un
chemin Windows.
Les délais indiqués sont ceux de l'apparition de l'alerte dans la plateforme.

### API lente ou en erreur : Payment API

```bash
curl -s "http://localhost:8081/control?delay=1200"       # WARNING puis HIGH (> 500 ms puis > 1000 ms), environ 1 à 2 min
curl -s "http://localhost:8081/control?mode=error"       # erreurs 500 → CRITICAL (indisponible)
curl -s "http://localhost:8081/control?mode=ok&delay=0"  # retour à la normale : alertes résolues
```

### Site lent : Website-01

```bash
curl -s "http://localhost:8083/control?delay=2500"       # > 2000 ms → HIGH, environ 1 à 2 min
curl -s "http://localhost:8083/control?delay=0"          # retour à la normale
```

La page elle-même est visible sur http://localhost:8083.

### CPU élevé : Server-01

Pendant 7 minutes, une boucle infinie tourne sur chaque cœur. Le PC est chargé pendant ce temps.

```bash
docker exec -d demo-server-01 sh -c 'for i in $(seq $(nproc)); do timeout 420 sh -c "while :; do :; done" & done; wait'
```

Les seuils portent sur la moyenne des 5 dernières minutes :

- WARNING (> 60 %) au bout de 3 à 4 minutes ;
- HIGH (> 85 %) vers 5 minutes.

Pour arrêter avant la fin : `docker restart demo-server-01`.

### Disque qui se remplit : Server-01

```bash
docker exec demo-server-01 dd if=/dev/zero of=/demo-disk/fill bs=1M count=50   # 78 % → WARNING (> 70 %)
docker exec demo-server-01 dd if=/dev/zero of=/demo-disk/fill bs=1M count=60   # 94 % → HIGH (> 90 %)
docker exec demo-server-01 rm /demo-disk/fill                                  # disque vidé
```

Les seuils portent là aussi sur la moyenne des 5 dernières minutes : l'alerte arrive en 3 à 5 minutes, et se résout de
la même façon.

### Conteneur arrêté ou qui redémarre en boucle : Nginx-01, Redis-01

```bash
docker compose stop demo-nginx-01        # CRITICAL « stopped », en 1 à 2 min
docker compose start demo-nginx-01       # résolu au relevé suivant

# Redis-01 « plante » 3 fois de suite : Docker le relance seul (restart policy) → HIGH « restart loop »
# (> 2 redémarrages en 10 min), en 1 à 2 min. Il se résout 10 min après le dernier redémarrage.
for i in 1 2 3; do docker exec demo-redis-01 redis-cli shutdown nosave; sleep 15; done
```

`docker compose restart` ne compte pas : Docker ne compte que les redémarrages faits par sa restart policy, après un
arrêt que personne n'a demandé.

### Base injoignable ou saturée : MySQL-01

```bash
docker compose stop demo-mysql-01        # CRITICAL « unreachable », en 1 à 3 min
docker compose start demo-mysql-01

# 25 connexions ouvertes pendant 6 min : WARNING (> 20 connexions en moyenne sur 5 min)
docker exec -d demo-mysql-01 sh -c 'for i in $(seq 25); do MYSQL_PWD="$MONITOR_PASSWORD" mysql -uzbx_monitor -h127.0.0.1 -e "SELECT SLEEP(360)" & done; wait'
```

## Scénario de démonstration (§43)

Il dure environ 20 minutes. Avant de commencer, l'environnement est démarré et le seeder lancé. Attendre que toutes les
targets soient **UP**.

1. **Connexion.** Se connecter avec le compte de démo. Le dashboard affiche la team Demo : 6 targets UP, aucune
   alerte.
2. **Vue par catégorie.** Cliquer sur les pastilles APIs, Websites, Servers, Containers et Databases : chaque liste a
   ses colonnes (temps de réponse, CPU / RAM / disque, état du conteneur, connexions…). Le menu Monitoring mène aux
   mêmes listes.
3. **Détail d'une target.** Ouvrir Server-01 : les cartes d'état (OS, uptime, charge) et les graphiques CPU / mémoire /
   disque, avec les lignes de seuil. Changer de période (1 h, 24 h…).
4. **Lancer les pannes.** Rendre l'API lente (`delay=1200`), arrêter Nginx-01, remplir le disque de Server-01 à 78 %
   (`count=50`).
5. **Voir arriver les alertes, en 1 à 5 minutes.**
   - Sur le dashboard, les KPIs passent en orange et en rouge.
   - La page Alerts liste Payment API (WARNING puis HIGH), Nginx-01 (CRITICAL) et Server-01 (WARNING disque).
   - Le graphique de Server-01 montre le disque qui franchit la ligne de seuil.
6. **Pendant ce temps, côté Zabbix** (http://localhost:8080) : les mêmes problèmes, sur les hosts du groupe
   `Monitoring Platform/Demo #…`. La plateforme est seule à les avoir configurés, par l'API.
7. **Un site lent.** `delay=2500` sur Website-01 : HIGH. Le temps de réponse monte sur son graphique.
8. **Retour à la normale.**
   - API : `mode=ok&delay=0`.
   - `docker compose start demo-nginx-01`.
   - `rm /demo-disk/fill`.
   - Website-01 : `delay=0`.

   Les alertes passent en résolues, avec leur durée. L'historique des alertes et le journal d'audit gardent la trace de
   ce qui s'est passé.
9. **Rôles.** Facultatif : inviter un Viewer dans la team Demo et montrer qu'il voit tout mais ne peut rien modifier.

La charge CPU (7 min) et la saturation de MySQL-01 complètent la démo si le temps le permet.

## Arrêter et nettoyer

```bash
docker compose --profile demo stop        # arrête les ressources de démo : leurs targets passent en alerte
docker compose --profile demo rm -sfv     # supprime aussi leurs conteneurs (MySQL-01 repart vide)
```

Ensuite, supprimer les targets de la team Demo depuis l'application : cela supprime aussi leurs hosts Zabbix.
