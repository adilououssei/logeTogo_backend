# Mise en ligne de l'API LogeTogo (VPS, Docker)

L'API et l'administration web tournent dans des conteneurs Docker, sur le VPS qui héberge déjà Zonal, **sans rien partager avec lui** (base de données, fichiers et port distincts).

```
Internet ──HTTPS──► Nginx du VPS (Certbot) ──► 127.0.0.1:8100 ──► conteneur web (Nginx)
                                                                     │
                                                       PHP ──► conteneur backend (PHP-FPM + ffmpeg)
                                                                     ├──► conteneur mysql (MySQL 8)
                                                                     └──► conteneur whisper (voix → texte)
```

| Élément | Valeur |
|---|---|
| Serveur | `ssh zonal-vps` (utilisateur `deploy`) |
| Dossier | `/home/deploy/var/www/logetogo_backend` |
| Adresse publique | `https://logetogo.zonalchd.org` (API : `/api`, administration : `/admin`) |
| Port local | `127.0.0.1:8100` (Zonal utilise `8000`) |
| Secrets | `.env.prod.local` sur le serveur uniquement (modèle : `.env.prod.local.exemple`) |
| Données conservées | volume `mysql_data`, `public/uploads` (photos/vidéos), `var/verifications` (pièces d'identité), `config/jwt` (clés) |

---

## Première installation

### 1. DNS
Chez le gestionnaire du domaine `zonalchd.org`, ajouter un enregistrement **A** :

| Nom | Type | Valeur |
|---|---|---|
| `logetogo` | A | `5.189.175.181` |

Vérifier (quelques minutes à quelques heures) : `nslookup logetogo.zonalchd.org` doit répondre `5.189.175.181`.

### 2. Code et secrets
```bash
ssh zonal-vps
cd /home/deploy/var/www
git clone https://github.com/adilououssei/logeTogo_backend.git logetogo_backend
cd logetogo_backend
outils/serveur/premiere-installation.sh logetogo.zonalchd.org
```
Le script crée `.env.prod.local` (mots de passe et clés générés au hasard, lisible par `deploy` seul) et les clés JWT. **Ne jamais commiter ni copier ce fichier ailleurs que dans une sauvegarde sûre.**

### 3. Démarrage des conteneurs
```bash
outils/serveur/deployer.sh
```
La première construction prend une dizaine de minutes (compilation de Whisper). Le script se termine par « API en ligne. ».

### 4. HTTPS (commandes avec `sudo`)
```bash
sudo cp outils/serveur/nginx-vps.conf /etc/nginx/sites-available/logetogo.zonalchd.org
sudo ln -s /etc/nginx/sites-available/logetogo.zonalchd.org /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d logetogo.zonalchd.org
```
Certbot ajoute le certificat, la redirection HTTP → HTTPS et le renouvellement automatique.

### 5. Compte administrateur
```bash
docker compose -f docker-compose.prod.yml exec backend php bin/console app:creer-admin
```
(Ne **pas** lancer `app:charger-donnees-demo` en production.)

### 6. Tâches planifiées
`crontab -e`, puis ajouter :
```
0 8 * * * /home/deploy/var/www/logetogo_backend/outils/serveur/suivi-disponibilite.sh >> /home/deploy/backups/logetogo/suivi-disponibilite.log 2>&1
30 3 * * * /home/deploy/var/www/logetogo_backend/outils/serveur/sauvegarde.sh >> /home/deploy/backups/logetogo/sauvegarde.log 2>&1
```
Créer le dossier des journaux : `mkdir -p ~/backups/logetogo`. La sauvegarde de 3 h 30 passe après celle de Zonal (3 h) et copie sur Google Drive (`gdrive:logetogo-backups`) avec le rclone déjà configuré.

### 7. Vérifications
- `https://logetogo.zonalchd.org/api/annonces` → `{"elements":[],…}`
- `https://logetogo.zonalchd.org/admin` → page de connexion de l'administration
- Application : profils `preview` / `production` de `eas.json` pointés sur `https://logetogo.zonalchd.org/api`.

---

## Mettre à jour
Après avoir poussé le code sur GitHub (branche `main`) :
```bash
ssh zonal-vps
cd /home/deploy/var/www/logetogo_backend && outils/serveur/deployer.sh
```
Les migrations de la base s'appliquent toutes seules au redémarrage.

## Commandes utiles
```bash
cd /home/deploy/var/www/logetogo_backend
docker compose -f docker-compose.prod.yml ps                 # état des conteneurs
docker compose -f docker-compose.prod.yml logs -f backend    # erreurs de l'API
docker compose -f docker-compose.prod.yml exec backend php bin/console <commande>
```

## Restaurer une sauvegarde
```bash
gunzip -c ~/backups/logetogo/bd_DATE.sql.gz | docker compose -f docker-compose.prod.yml exec -T mysql \
  sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
tar xzf ~/backups/logetogo/fichiers_DATE.tar.gz -C /home/deploy/var/www/logetogo_backend
```

## À configurer plus tard
- **Emails** : `MAILER_DSN` dans `.env.prod.local` (Brevo ou Gmail), puis `outils/serveur/deployer.sh`.
- **IA de l'annonce vocale** (Ollama, ≈ 3 Go de mémoire) : aujourd'hui `ANNONCE_VOCALE_IA=aucune` (règles seules, qui comprennent déjà la plupart des annonces).
- **Serveur dédié** à LogeTogo : mêmes étapes, seul le nom de domaine change.
