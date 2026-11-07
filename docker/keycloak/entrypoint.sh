#!/bin/bash
# Keycloak de démo (SCRUM-91) : aucun mot de passe de démo dans le dépôt.
# Au premier démarrage, les secrets absents de .env sont générés et gardés dans le volume keycloak-data
# (/opt/keycloak/data/demo/secrets.env, lisible seulement par Keycloak). Les démarrages suivants les relisent.
# Les afficher : docker compose exec keycloak cat /opt/keycloak/data/demo/secrets.env
set -euo pipefail

secrets=/opt/keycloak/data/demo/secrets.env
mkdir -p "$(dirname "$secrets")"

random() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

if [ ! -f "$secrets" ]; then
    umask 077
    {
        echo "KEYCLOAK_ADMIN_PASSWORD=${KEYCLOAK_ADMIN_PASSWORD:-$(random 24)}"
        echo "KEYCLOAK_DEMO_CLIENT_SECRET=${KEYCLOAK_DEMO_CLIENT_SECRET:-$(random 40)}"
        echo "KEYCLOAK_DEMO_USER_PASSWORD=${KEYCLOAK_DEMO_USER_PASSWORD:-$(random 20)}"
    } > "$secrets"
    echo "Keycloak demo: secrets generated in $secrets (docker compose exec keycloak cat $secrets)"
fi

set -a
# shellcheck source=/dev/null
. "$secrets"
set +a

# Compte d'administration de Keycloak (console http://localhost:8180/admin), créé au premier démarrage seulement
export KC_BOOTSTRAP_ADMIN_USERNAME=admin
export KC_BOOTSTRAP_ADMIN_PASSWORD="$KEYCLOAK_ADMIN_PASSWORD"

# Realm « monitoring » importé s'il n'existe pas encore ; ${KEYCLOAK_DEMO_*} y sont remplacés par Keycloak
exec /opt/keycloak/bin/kc.sh start-dev --import-realm
