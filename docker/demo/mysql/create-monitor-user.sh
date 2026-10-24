#!/bin/sh
# MySQL-01 (démo SCRUM-73) : utilisateur zbx_monitor de l'agent Zabbix 2, créé au premier démarrage de la base
# (docker-entrypoint-initdb.d), par le socket local avec le mot de passe root aléatoire de l'image.
# Mêmes droits que docker/mysql/create-monitor-user.sh : aucune donnée lue.
set -eu

if [ -z "${MONITOR_PASSWORD:-}" ]; then
    echo "DEMO_MYSQL_MONITOR_PASSWORD est vide dans .env : utilisateur zbx_monitor non créé." >&2
    exit 1
fi

password=$(printf '%s' "$MONITOR_PASSWORD" | sed "s/'/''/g")

MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket --user=root <<SQL
CREATE USER IF NOT EXISTS 'zbx_monitor'@'%' IDENTIFIED BY '$password';
GRANT REPLICATION CLIENT, PROCESS, SHOW DATABASES, SHOW VIEW ON *.* TO 'zbx_monitor'@'%';
SQL

echo "Utilisateur MySQL zbx_monitor prêt (lecture seule)."
