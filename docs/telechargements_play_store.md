# Nombre exact de téléchargements Google Play (page Rapports)

Google n'a pas d'API « nombre de téléchargements ». La Play Console exporte chaque jour les statistiques
d'installations vers un bucket Cloud Storage ; le serveur les lit avec un compte de service en lecture seule.
Le total affiché est la somme des « Daily User Installs » de tous les mois. Données avec ~2 jours de retard.

## Mise en place (une seule fois, par le titulaire du compte Play Console)
1. Play Console > Télécharger les rapports > Statistiques : copier l'identifiant du bucket
   (`pubsite_prod_rev_…`, sans `gs://`).
2. Google Cloud Console (projet lié à la Play Console, ou n'importe lequel) : IAM > Comptes de service >
   créer un compte (ex. `lecture-stats-play`), puis Clés > Ajouter une clé > JSON. Activer l'API Cloud Storage.
3. Play Console > Utilisateurs et autorisations > Inviter un utilisateur : e-mail du compte de service, droit
   « Afficher les informations sur l'application et télécharger des rapports groupés (lecture seule) ».
4. Copier le JSON sur le serveur, hors de Git et monté dans le conteneur php (ex. `/home/deploy/logetogo/jwt/play.json`
   si le dossier jwt est déjà monté), puis dans `.env.local` du serveur :

       GOOGLE_PLAY_CLE_JSON=/chemin/dans/le/conteneur/play.json
       GOOGLE_PLAY_BUCKET=pubsite_prod_rev_XXXXXXXXXXXX

   (`GOOGLE_PLAY_PACKAGE` vaut déjà `com.ouss_adilou.logeTogoV1`.)
5. Relancer le conteneur php. Le bouton « Actualiser » de la page Rapports vide le cache (6 h).

Les droits Play Console peuvent mettre jusqu'à 24 h à se propager.
