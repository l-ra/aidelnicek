FROM php:8.2-apache

# Enable mod_rewrite
RUN a2enmod rewrite

# Install SQLite and curl dev libraries; enable pdo_sqlite + curl PHP extensions
RUN apt-get update && apt-get install -y libsqlite3-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_sqlite curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Copy application
COPY --chown=www-data:www-data . /var/www/html/
WORKDIR /var/www/html

# Allow .htaccess overrides — must run BEFORE the path substitution below,
# because the substitution renames <Directory /var/www/> and the pattern
# would no longer match if run afterwards.
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set document root to public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Data directory for SQLite
RUN mkdir -p /var/www/html/data && chown www-data:www-data /var/www/html/data

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader


EXPOSE 80
