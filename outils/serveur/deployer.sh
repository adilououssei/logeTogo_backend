#!/bin/bash
# Mise en ligne de la dernière version (première fois comme mises à jour), sur le VPS :
#   outils/serveur/deployer.sh
# Récupère le code sur GitHub, reconstruit les images, redémarre les conteneurs.
# Les migrations de la base sont appliquées au démarrage du conteneur PHP (docker/entrypoint.sh).
set -euo pipefail
cd "$(dirname "$0")/../.."
COMPOSE="docker compose -f docker-compose.prod.yml"

[ -f .env.prod.local ] || { echo "Lancer d'abord outils/serveur/premiere-installation.sh" >&2; exit 1; }

echo "== Code"
git pull --ff-only

echo "== Images"
$COMPOSE build

echo "== Droits des dossiers de données (utilisateur www-data du conteneur, uid 82)"
mkdir -p public/uploads var/verifications config/jwt
docker run --rm -v "$PWD/public/uploads:/u" -v "$PWD/var/verifications:/v" -v "$PWD/config/jwt:/j" alpine:3 \
  sh -c 'chown -R 82:82 /u /v /j && chmod 700 /v && chmod 600 /j/*.pem'

echo "== Démarrage"
$COMPOSE up -d --remove-orphans
$COMPOSE ps

echo "== Vérification"
for i in $(seq 1 30); do
  if curl -fsS -o /dev/null http://127.0.0.1:8100/api/annonces; then
    echo "API en ligne."
    exit 0
  fi
  sleep 3
done
echo "L'API ne répond pas : voir « $COMPOSE logs backend »." >&2
exit 1
