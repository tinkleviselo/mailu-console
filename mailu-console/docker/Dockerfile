# Mailu Console - Docker image (php:8.2-apache with pdo_sqlite/pdo_mysql/curl/zip)
FROM php:8.2-apache

RUN a2enmod rewrite headers \
 && apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev unzip curl \
 && docker-php-ext-install pdo_sqlite pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/mailu-console.conf \
 && a2enconf mailu-console \
 && echo 'ServerName localhost' >> /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/config \
 && chmod -R 775 /var/www/html/storage /var/www/html/config \
 && chmod +x /var/www/html/docker/entrypoint.sh

# Container entrypoint: auto-initialise the console on first boot (MC_AUTO_INSTALL=1)
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]

EXPOSE 80