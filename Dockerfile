FROM php:8.2-apache

# Install required packages
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    unzip wget curl && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache rewrite for pretty URLs (/start)
RUN a2enmod rewrite && \
    sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride[[:space:]]+None/AllowOverride All/' /etc/apache2/apache2.conf

# PHP configuration (50MB upload limit, suppress display errors in production)
RUN echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 55M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "display_errors = Off" >> /usr/local/etc/php/conf.d/uploads.ini

# Install DokuWiki (pinned version: 2024-02-06a "Kaos")
WORKDIR /var/www/html
ENV DOKUWIKI_VERSION=2024-02-06a
RUN wget -O dokuwiki.tgz "https://download.dokuwiki.org/src/dokuwiki/dokuwiki-$DOKUWIKI_VERSION.tgz" && \
    tar xzf dokuwiki.tgz --strip-components=1 && \
    cp .htaccess.dist .htaccess && \
    printf '\n<IfModule mod_rewrite.c>\nRewriteEngine on\nRewriteRule ^_media/(.*) lib/exe/fetch.php?media=$1 [QSA,L]\nRewriteRule ^_detail/(.*) lib/exe/detail.php?media=$1 [QSA,L]\nRewriteRule ^_export/([^/]+)/(.*) doku.php?do=export_$1&id=$2 [QSA,L]\nRewriteRule ^$ doku.php [L]\nRewriteCond %%{REQUEST_FILENAME} !-f\nRewriteCond %%{REQUEST_FILENAME} !-d\nRewriteRule (.*) doku.php?id=$1 [QSA,L]\nRewriteRule ^index.php$ doku.php\n</IfModule>\n' >> .htaccess && \
    rm -f install.php && \
    rm dokuwiki.tgz

# Copy plugin installation script and version configuration
COPY plugins.conf /tmp/plugins.conf
COPY install-plugins.sh /tmp/
RUN chmod +x /tmp/install-plugins.sh && /tmp/install-plugins.sh

# Backup DokuWiki default config files before volume mount hides them
RUN mkdir -p /docker-defaults/conf && \
    cp /var/www/html/conf/*.php /var/www/html/conf/*.conf /docker-defaults/conf/ 2>/dev/null; \
    cp /var/www/html/conf/manifest.json /docker-defaults/conf/ 2>/dev/null; \
    cp /var/www/html/conf/.htaccess /docker-defaults/conf/ 2>/dev/null; true

# Set file permissions
RUN chown -R www-data:www-data /var/www/html

# Entrypoint restores default configs into mounted volume and starts Apache
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
