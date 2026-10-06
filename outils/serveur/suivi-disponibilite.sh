#!/bin/bash
# Rappels « toujours disponible ? » (7e jour) et retrait des annonces sans réponse (14e jour).
# crontab : 0 8 * * * /home/deploy/var/www/logetogo_backend/outils/serveur/suivi-disponibilite.sh >> /home/deploy/backups/logetogo/suivi-disponibilite.log 2>&1
set -euo pipefail
docker compose -f /home/deploy/var/www/logetogo_backend/docker-compose.prod.yml exec -T backend \
  php bin/console app:annonces:suivi-disponibilite --no-interaction
