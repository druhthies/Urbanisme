# Utiliser l'image PHP officielle avec Apache (dernière version stable)
FROM php:8.3-apache

# Définir le répertoire de travail
WORKDIR /var/www/html

# Installer les dépendances de compilation et extensions PHP nécessaires
RUN apt-get update && apt-get upgrade -y && apt-get install -y \
    libpq-dev \
    libcurl4-openssl-dev \
    pkg-config \
    && docker-php-ext-install pdo pdo_mysql mysqli curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Activer mod_rewrite et mod_headers pour Apache (IMPORTANT pour .htaccess)
RUN a2enmod rewrite headers

# Copier les fichiers de l'application dans le répertoire web d'Apache
COPY . /var/www/html/

# Copier le script de démarrage
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Donner les permissions appropriées
RUN chown -R www-data:www-data /var/www/html/

# Autoriser .htaccess (AllowOverride All) sur le DocumentRoot, sinon Apache
# renvoie 403 Forbidden ou ignore les règles de réécriture du projet
COPY apache-override.conf /etc/apache2/conf-available/override.conf
RUN a2enconf override

# Port par défaut documenté (Render fournit le vrai port via $PORT au
# démarrage du conteneur ; cette ligne est juste informative pour Docker)
EXPOSE 10000

# Script qui configure le port puis démarre Apache
CMD ["/usr/local/bin/start.sh"]
