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

# ── SEO + segurança do docroot (2026-06-11) ─────────────────────────────────
# A imagem php:8.2-apache vem com "AllowOverride None" em /var/www/ — ou seja,
# TODOS os .htaccess dentro de public/ eram IGNORADOS em produção. Isso deixava:
#   · /uploads/ (contratos, laudos — LGPD!) acessível por URL direta;
#   · directory listing ativo (Options Indexes) em /uploads/, /api/, /assets/;
#   · 404 default do Apache com assinatura do servidor.
# Este conf corrige tudo direto no Apache (não depende de .htaccess):
RUN { \
    echo '<Directory /var/www/html/public>'; \
    echo '    Options -Indexes +FollowSymLinks'; \
    echo '    AllowOverride All'; \
    echo '</Directory>'; \
    echo '<Directory /var/www/html/public/uploads>'; \
    echo '    Require all denied'; \
    echo '</Directory>'; \
    echo 'ErrorDocument 404 /404.php'; \
    echo 'ServerTokens Prod'; \
    echo 'ServerSignature Off'; \
} > /etc/apache2/conf-available/zz-yuris.conf \
 && a2enconf zz-yuris

# Cache de assets (landing.css/js usam ?v=filemtime; imagens/fonts mudam pouco).
# max-age conservador em css/js genéricos — nem todos têm versionamento.
RUN a2enmod expires headers \
 && { \
    echo '<FilesMatch "\.(css|js)$">'; \
    echo '    Header set Cache-Control "public, max-age=86400"'; \
    echo '</FilesMatch>'; \
    echo '<FilesMatch "\.(png|webp|jpg|jpeg|svg|ico|woff2)$">'; \
    echo '    Header set Cache-Control "public, max-age=2592000"'; \
    echo '</FilesMatch>'; \
} > /etc/apache2/conf-available/zz-yuris-cache.conf \
 && a2enconf zz-yuris-cache
