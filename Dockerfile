# Utiliser l'image PHP officielle avec Apache (dernière version stable)
FROM php:8.3-apache

# Définir le répertoire de travail
WORKDIR /var/www/html

# Installer les dépendances de compilation et extensions PHP nécessaires
RUN apt-get update && apt-get upgrade -y && apt-get install -y \
    libpq-dev \
    libcurl4-openssl-dev \
    pkg-config \
    cifs-utils \
    && docker-php-ext-install pdo pdo_mysql mysqli curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Activer mod_rewrite pour Apache (IMPORTANT pour .htaccess)
RUN a2enmod rewrite && a2enmod headers

# Copier les fichiers de l'application dans le répertoire web d'Apache
COPY . /var/www/html/

# Copier le script de démarrage
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Donner les permissions appropriées
RUN chown -R www-data:www-data /var/www/html/

# Configurer Apache pour DocumentRoot correct et mod_rewrite
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/override.conf && \
    a2enconf override

# NOTE IMPORTANTE : on NE configure PAS le port ici avec sed/EXPOSE ${PORT}.
# ${PORT} n'existe pas au moment du build (c'est une variable d'environnement
# fournie par Render uniquement au démarrage du conteneur) — la remplacer ici
# écrivait littéralement le texte "${PORT}" dans ports.conf, ce qui cassait
# Apache au lancement. C'est maintenant start.sh qui adapte le port Apache
# dynamiquement à chaque démarrage, avec la vraie valeur de $PORT.

# Port par défaut documenté (Render redirige son propre $PORT vers le
# conteneur quel que soit ce qui est déclaré ici ; cette ligne est juste
# informative pour Docker)
EXPOSE 10000

# Script qui configure le port, monte le NAS, puis démarre Apache
CMD ["/usr/local/bin/start.sh"]
