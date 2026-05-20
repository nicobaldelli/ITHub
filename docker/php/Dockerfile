# ============================================================
# PHP 8.2 + Apache para desarrollo local de la API
# Réplica las extensiones disponibles en Hostinger.
# ============================================================
FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive

# Sistema base + dependencias para extensiones
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        zlib1g-dev \
        ca-certificates \
        tzdata \
    && rm -rf /var/lib/apt/lists/*

# Timezone Argentina
ENV TZ=America/Argentina/Buenos_Aires
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Extensiones PHP (idénticas a las habilitadas en Hostinger)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        mbstring \
        intl \
        zip \
        gd \
        bcmath \
        opcache

# Habilitar mod_rewrite y headers (necesarios para Slim + seguridad)
RUN a2enmod rewrite headers expires

# Apuntar Apache a public/ (front controller pattern)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

# Permitir .htaccess
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/override.conf \
    && a2enconf override

# Ocultar versiones (defensa en profundidad)
RUN sed -ri -e 's/^ServerTokens .*/ServerTokens Prod/' /etc/apache2/conf-enabled/security.conf \
    && sed -ri -e 's/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-enabled/security.conf

# php.ini con valores seguros por defecto
RUN { \
    echo "expose_php = Off"; \
    echo "display_errors = Off"; \
    echo "display_startup_errors = Off"; \
    echo "log_errors = On"; \
    echo "error_log = /var/log/php_errors.log"; \
    echo "post_max_size = 30M"; \
    echo "upload_max_filesize = 30M"; \
    echo "max_input_time = 60"; \
    echo "memory_limit = 256M"; \
    echo "session.cookie_httponly = 1"; \
    echo "session.cookie_secure = 1"; \
    echo "session.cookie_samesite = Strict"; \
    echo "session.use_strict_mode = 1"; \
    echo "date.timezone = America/Argentina/Buenos_Aires"; \
    } > /usr/local/etc/php/conf.d/zz-ithub.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

EXPOSE 80
