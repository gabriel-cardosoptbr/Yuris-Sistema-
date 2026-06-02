FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN a2enmod rewrite

RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/yuris-memory.ini

# Endurecimento de erros em produção: NUNCA exibir avisos/erros do PHP no corpo da
# resposta (quebra JSON do front com "Unexpected token '<'" e vaza detalhe interno =
# risco LGPD). Erros continuam indo pro log (stderr -> docker logs) via log_errors.
RUN printf 'display_errors=Off\ndisplay_startup_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/yuris-errors.ini

RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

RUN sed -i 's|^[[:space:]]*DocumentRoot .*|DocumentRoot /var/www/html/public|g' /etc/apache2/sites-available/000-default.conf
