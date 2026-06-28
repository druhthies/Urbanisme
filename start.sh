#!/bin/bash
set -e

# ---------------------------------------------------------------------------
# start.sh — script de démarrage du conteneur (PHP + Apache)
#
# Rôle :
#   1. Configurer Apache pour écouter sur le port fourni par Render (variable
#      d'environnement $PORT, différente à chaque déploiement). Le Dockerfile
#      ne peut pas faire ça au moment du build car $PORT n'existe qu'à
#      l'exécution du conteneur — c'est pour ça que ça doit être fait ici.
#   2. Monter le partage réseau (NAS) si les variables d'environnement
#      nécessaires sont fournies (NAS_HOST, NAS_SHARE, NAS_USER, NAS_PASS).
#      Si le montage échoue ou si les variables ne sont pas définies, on
#      n'arrête PAS le démarrage du site pour autant : on log un message
#      et on continue.
#   3. Démarrer Apache au premier plan (requis par Docker).
# ---------------------------------------------------------------------------

# ----- 1. Port dynamique Render -----
PORT="${PORT:-10000}"
echo "[start.sh] Configuration d'Apache pour écouter sur le port $PORT"

sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf

# ----- 2. Montage NAS (optionnel) -----
MOUNT_POINT="${NAS_MOUNT_POINT:-/mnt/nas}"

if [ -n "$NAS_HOST" ] && [ -n "$NAS_SHARE" ]; then
  echo "[start.sh] Tentative de montage du partage NAS : //$NAS_HOST/$NAS_SHARE -> $MOUNT_POINT"
  mkdir -p "$MOUNT_POINT"

  if [ -n "$NAS_USER" ] && [ -n "$NAS_PASS" ]; then
    CREDENTIALS_OPT="username=$NAS_USER,password=$NAS_PASS"
  else
    CREDENTIALS_OPT="guest"
  fi

  if mount -t cifs "//$NAS_HOST/$NAS_SHARE" "$MOUNT_POINT" \
      -o "$CREDENTIALS_OPT,vers=3.0,uid=www-data,gid=www-data,iocharset=utf8" 2>/tmp/mount_error.log; then
    echo "[start.sh] Partage NAS monté avec succès sur $MOUNT_POINT"
  else
    echo "[start.sh] ATTENTION : échec du montage du partage NAS (le site démarre quand même) :"
    cat /tmp/mount_error.log || true
  fi
else
  echo "[start.sh] Variables NAS_HOST / NAS_SHARE non définies : montage NAS ignoré."
fi

# ----- 3. Démarrage d'Apache -----
echo "[start.sh] Démarrage d'Apache..."
exec apache2-foreground

