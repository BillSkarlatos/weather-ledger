FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libpq5 libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && apt-get purge -y libpq-dev && apt-get autoremove -y && rm -rf /var/lib/apt/lists/* \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && echo 'expose_php = Off' > "$PHP_INI_DIR/conf.d/security.ini" \
 && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
 && printf '%s\n' 'ServerName localhost' 'ServerTokens Prod' 'ServerSignature Off' '<VirtualHost *:8080>' \
    '  DocumentRoot /var/www/app/public' '  FallbackResource /index.php' '</VirtualHost>' \
    > /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/app
COPY . .
USER www-data
EXPOSE 8080
