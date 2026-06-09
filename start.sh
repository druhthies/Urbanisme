#!/bin/bash

# ===================================================================
# Script de démarrage - Monte le NAS et démarre Apache
# ===================================================================

set -e

echo "🚀 Démarrage du service..."

# ===================================================================
# MONTAGE SMB - NAS
# ===================================================================

NAS_HOST="${NAS_HOST:-Nas-urbanisme}"
NAS_SHARE="${NAS_SHARE:-permis de construire}"
NAS_USER="${NAS_USER:-}"
NAS_PASSWORD="${NAS_PASSWORD:-}"
NAS_DOMAIN="${NAS_DOMAIN:-}"
MOUNT_POINT="/mnt/nas"

# Créer le point de montage s'il n'existe pas
mkdir -p "$MOUNT_POINT"

# Vérifier si déjà monté
if mountpoint -q "$MOUNT_POINT" 2>/dev/null; then
    echo "✅ NAS déjà monté sur $MOUNT_POINT"
else
    echo "📂 Tentative de montage du NAS..."
    
    # Options de montage de base
    MOUNT_OPTIONS="ro,uid=33,gid=33,file_mode=0755,dir_mode=0755,noperm"
    
    if [ -n "$NAS_USER" ] && [ -n "$NAS_PASSWORD" ]; then
        # Authentification SMB
        MOUNT_OPTIONS="${MOUNT_OPTIONS},username=${NAS_USER},password=${NAS_PASSWORD}"
        if [ -n "$NAS_DOMAIN" ]; then
            MOUNT_OPTIONS="${MOUNT_OPTIONS},domain=${NAS_DOMAIN}"
        fi
        
        if mount -t cifs "//${NAS_HOST}/${NAS_SHARE}" "$MOUNT_POINT" -o "$MOUNT_OPTIONS" 2>/dev/null; then
            echo "✅ NAS monté avec authentification"
        else
            echo "⚠️  Montage SMB échoué - mode lecture seule"
        fi
    else
        # Sans authentification - mode anonyme
        if mount -t cifs "//${NAS_HOST}/${NAS_SHARE}" "$MOUNT_POINT" -o "${MOUNT_OPTIONS},guest" 2>/dev/null; then
            echo "✅ NAS monté en mode anonyme"
        else
            echo "⚠️  Montage NAS échoué - continuant sans NAS"
        fi
    fi
fi

# Vérifier l'accès
if [ -d "$MOUNT_POINT" ] && [ -r "$MOUNT_POINT" ]; then
    echo "✅ NAS accessible sur $MOUNT_POINT"
    export DOSSIER_BASE_PATH="$MOUNT_POINT/PLATEFORME/PERMIS DE CONSTRUIRE"
    echo "📁 DOSSIER_BASE_PATH=$DOSSIER_BASE_PATH"
else
    echo "⚠️  NAS non accessible - fonctionnalité documents désactivée"
    export DOSSIER_BASE_PATH=""
fi

# ===================================================================
# DÉMARRAGE APACHE
# ===================================================================

echo "🌐 Démarrage d'Apache sur le port ${PORT:-10000}..."
sed -i "s/Listen .*/Listen ${PORT:-10000}/g" /etc/apache2/ports.conf
exec apache2-foreground
