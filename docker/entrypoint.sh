#!/bin/sh
# Démarrage du conteneur PHP : attendre MySQL, appliquer les migrations, préparer le cache.
set -e

echo "[entrypoint] En attente de MySQL (${DB_HOST:-mysql}:${DB_PORT:-3306})..."
until php -r '
$c = @fsockopen(getenv("DB_HOST") ?: "mysql", (int) (getenv("DB_PORT") ?: 3306), $n, $m, 1);
if ($c) { fclose($c); exit(0); }
exit(1);
'; do
  echo "[entrypoint]   ...pas encore prêt, nouvel essai dans 2 s"
  sleep 2
done

if [ ! -f config/jwt/private.pem ]; then
  echo "[entrypoint] ERREUR : clés JWT absentes (config/jwt). Voir DEPLOIEMENT.md, étape « Clés JWT »." >&2
  exit 1
fi

echo "[entrypoint] Migrations Doctrine..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "[entrypoint] Cache Symfony..."
php bin/console cache:clear --no-warmup
php bin/console cache:warmup

echo "[entrypoint] Prêt : $*"
exec "$@"
