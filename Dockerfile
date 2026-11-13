FROM php:8.2-apache

# Install required packages
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    unzip wget curl && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache rewrite for pretty URLs
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

# Install plugins (pinned versions from plugins.conf)
COPY plugins.conf /tmp/plugins.conf
COPY install-plugins.sh /tmp/
RUN chmod +x /tmp/install-plugins.sh && /tmp/install-plugins.sh

# Install Composer and plugin dependencies (commonmark requires league/commonmark)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN cd /var/www/html/lib/plugins/commonmark && composer install --no-dev --optimize-autoloader && \
    rm -f /usr/bin/composer

# Apply ELOG configuration overlay on top of DokuWiki defaults
RUN { \
    echo '<?php'; \
    echo '// ELOG overlay configuration'; \
    echo "\$conf['title'] = 'E-Log';"; \
    echo "\$conf['template'] = 'elog';"; \
    echo "\$conf['useacl'] = 1;"; \
    echo "\$conf['superuser'] = '@admin';"; \
    echo "\$conf['defaultgroup'] = 'pending';"; \
    echo "\$conf['userewrite'] = '1';"; \
    echo "\$conf['useslash'] = 1;"; \
    echo "\$conf['uploadsize'] = '52428800';"; \
    echo "\$conf['savedir'] = '/var/www/html/data';"; \
    echo "\$conf['openregister'] = '1';"; \
    echo "\$conf['fulltext_minlen'] = '2';"; \
    } > /var/www/html/conf/local.php

# Default ACL: @ALL can read, @admin has full access
RUN printf '*\t\t@ALL\t\t1\n*\t\t@admin\t\t255\n' > /var/www/html/conf/acl.auth.php && \
    touch /var/www/html/conf/users.auth.php

# Copy custom stylesheet
COPY conf/userstyle.css /var/www/html/conf/userstyle.css

# Upload types for ELOG attachments (ROOT files, phone image formats)
COPY conf/mime.local.conf.dist /var/www/html/conf/mime.local.conf

# ELOG guard plugin: entries are append-only for non-administrators
COPY lib/plugins/elogguard /var/www/html/lib/plugins/elogguard

# Set file permissions
RUN chown -R www-data:www-data /var/www/html

# Entrypoint ensures data directories exist on mounted volume
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
