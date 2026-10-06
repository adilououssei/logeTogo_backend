#!/bin/bash
# À lancer UNE SEULE FOIS sur le VPS, dans le dossier du projet : crée les secrets de production
# (.env.prod.local) et les clés JWT. Ne remplace jamais des secrets déjà créés.
set -euo pipefail
cd "$(dirname "$0")/../.."

DOMAINE="${1:?Usage : outils/serveur/premiere-installation.sh logetogo.zonalchd.org}"

if [ -f .env.prod.local ]; then
  echo ".env.prod.local existe déjà : rien n'est remplacé."
else
  MDP_BD=$(openssl rand -hex 24)
  sed -e "s#^MYSQL_PASSWORD=CHANGER_MOI#MYSQL_PASSWORD=$MDP_BD#" \
      -e "s#^MYSQL_ROOT_PASSWORD=CHANGER_MOI#MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)#" \
      -e "s#logetogo:CHANGER_MOI@mysql#logetogo:$MDP_BD@mysql#" \
      -e "s#^APP_SECRET=CHANGER_MOI#APP_SECRET=$(openssl rand -hex 32)#" \
      -e "s#^JWT_PASSPHRASE=CHANGER_MOI#JWT_PASSPHRASE=$(openssl rand -hex 32)#" \
      -e "s#logetogo.zonalchd.org#$DOMAINE#g" \
      .env.prod.local.exemple > .env.prod.local
  chmod 600 .env.prod.local
  echo "Secrets créés dans .env.prod.local (lisible par vous seul)."
fi

mkdir -p config/jwt public/uploads var/verifications
if [ -f config/jwt/private.pem ]; then
  echo "Clés JWT déjà présentes : rien n'est remplacé."
else
  PASSE=$(grep '^JWT_PASSPHRASE=' .env.prod.local | cut -d= -f2-)
  openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -aes256 -pass "pass:$PASSE" -out config/jwt/private.pem
  openssl pkey -in config/jwt/private.pem -passin "pass:$PASSE" -pubout -out config/jwt/public.pem
  echo "Clés JWT créées dans config/jwt."
fi
echo "Étape suivante : outils/serveur/deployer.sh"
