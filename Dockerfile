FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN a2enmod rewrite

RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/yuris-memory.ini

RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

RUN sed -i 's|^[[:space:]]*DocumentRoot .*|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf
