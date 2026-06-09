# Utiliser l'image PHP officielle avec Apache
FROM php:8.1-apache

# Définir le répertoire de travail
WORKDIR /var/www/html

# Installer les dépendances de compilation et extensions PHP nécessaires
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libcurl4-openssl-dev \
    pkg-config \
    && docker-php-ext-install pdo pdo_mysql mysqli curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Activer mod_rewrite pour Apache (IMPORTANT pour .htaccess)
RUN a2enmod rewrite && a2enmod headers

# Copier les fichiers de l'application dans le répertoire web d'Apache
COPY . /var/www/html/

# Donner les permissions appropriées
RUN chown -R www-data:www-data /var/www/html/

# Configurer Apache pour DocumentRoot correct et mod_rewrite
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/override.conf && \
    a2enconf override

# Configurer Apache pour écouter sur le port dynamique de Render (par défaut 10000)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf

# Exposer le port dynamique
EXPOSE ${PORT:-10000}

# Commande de démarrage avec le port depuis la variable d'environnement
CMD sed -i "s/Listen .*/Listen ${PORT:-10000}/g" /etc/apache2/ports.conf && apache2-foreground