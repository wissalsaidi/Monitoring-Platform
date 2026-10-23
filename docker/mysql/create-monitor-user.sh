#!/bin/sh
# Utilisateur MySQL de l'agent Zabbix 2 (SCRUM-67), lancé par le service mysql-monitor-user.
# Idempotent : crée l'utilisateur, ou remet le mot de passe du .env s'il existe déjà.
# Droits du template officiel « MySQL by Zabbix agent 2 » : état du serveur et des réplicas, aucune donnée lue.
set -eu

if [ -z "${MONITOR_PASSWORD:-}" ]; then
    echo "ZBX_MONITOR_DB_PASSWORD est vide dans .env : utilisateur $MONITOR_USER non créé." >&2
    exit 1
fi

# Apostrophes doublées : le mot de passe reste une chaîne SQL quoi qu'il contienne
password=$(printf '%s' "$MONITOR_PASSWORD" | sed "s/'/''/g")

mysql --host="$MYSQL_HOST" --user=root <<SQL
CREATE USER IF NOT EXISTS '$MONITOR_USER'@'%' IDENTIFIED BY '$password';
ALTER USER '$MONITOR_USER'@'%' IDENTIFIED BY '$password';
GRANT REPLICATION CLIENT, PROCESS, SHOW DATABASES, SHOW VIEW ON *.* TO '$MONITOR_USER'@'%';
SQL

echo "Utilisateur MySQL $MONITOR_USER prêt (lecture seule)."
