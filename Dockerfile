FROM php:8.2-apache

# Enable mod_rewrite
RUN a2enmod rewrite

# Install SQLite dev libraries required for pdo_sqlite extension
RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy application
COPY --chown=www-data:www-data . /var/www/html/
WORKDIR /var/www/html

# Set document root to public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Data directory for SQLite
RUN mkdir -p /var/www/html/data && chown www-data:www-data /var/www/html/data

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader

EXPOSE 80
