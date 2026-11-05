HEAD
FROM php:8.2-apache
RUN apt-get update && apt-get install -y libpq-dev && docker-php-ext-install pdo pdo_pgsql
=======
FROM php:8.2-apache
RUN apt-get update && apt-get install -y libpq-dev && docker-php-ext-install pdo pdo_pgsql
>>>>>>> f9a89d56a0e71dc2c5a7357ed0c292b8dca0eadf
COPY . /var/www/html/
