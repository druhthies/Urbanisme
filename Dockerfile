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

# Configurer Apache pour écouter sur le port dynamique de Render
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf

# Exposer le port dynamique
EXPOSE ${PORT:-10000}

# Script qui monte NAS puis démarre Apache
CMD ["/usr/local/bin/start.sh"]