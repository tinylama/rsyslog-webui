FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/* \
	&& a2enmod expires headers

COPY docker/apache/static-cache.conf /etc/apache2/conf-available/rsyslog-static-cache.conf
RUN a2enconf rsyslog-static-cache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY docker/entrypoint-web.sh /usr/local/bin/entrypoint-web.sh
RUN chmod +x /usr/local/bin/entrypoint-web.sh
COPY . .

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint-web.sh"]
CMD ["apache2-foreground"]
