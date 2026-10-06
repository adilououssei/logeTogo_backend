#!/bin/bash
# Sauvegarde nocturne de LogeTogo (sur le modèle de backup-zonal.sh) : base MySQL, photos et vidéos
# des annonces, pièces d'identité en attente. Copie locale 14 jours + Google Drive 90 jours (rclone).
# crontab : 30 3 * * * /home/deploy/var/www/logetogo_backend/outils/serveur/sauvegarde.sh >> /home/deploy/backups/logetogo/sauvegarde.log 2>&1
set -euo pipefail

PROJET=/home/deploy/var/www/logetogo_backend
DOSSIER="$HOME/backups/logetogo"
DISTANT="gdrive:logetogo-backups"
RCLONE="$HOME/bin/rclone"
DATE=$(date +%Y-%m-%d_%H-%M)
mkdir -p "$DOSSIER"

# Mot de passe lu dans le conteneur MySQL lui-même, jamais recopié ici.
docker compose -f "$PROJET/docker-compose.prod.yml" exec -T mysql \
  sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$MYSQL_DATABASE"' | gzip > "$DOSSIER/bd_$DATE.sql.gz"
tar czf "$DOSSIER/fichiers_$DATE.tar.gz" -C "$PROJET" public/uploads var/verifications

if [ -x "$RCLONE" ]; then
  "$RCLONE" copy "$DOSSIER/bd_$DATE.sql.gz" "$DISTANT/" --quiet
  "$RCLONE" copy "$DOSSIER/fichiers_$DATE.tar.gz" "$DISTANT/" --quiet
  "$RCLONE" delete --min-age 90d "$DISTANT/" --quiet
fi
find "$DOSSIER" \( -name '*.sql.gz' -o -name '*.tar.gz' \) -mtime +14 -delete

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Sauvegarde OK : bd_$DATE.sql.gz, fichiers_$DATE.tar.gz"
