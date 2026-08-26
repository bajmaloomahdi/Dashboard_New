FROM php:8.3-fpm

# -------------------------------------------------------
# System packages + PHP extensions
# -------------------------------------------------------
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        gnupg2 \
        ca-certificates \
        libzip-dev \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
        unixodbc-dev \
    && docker-php-ext-install \
        pdo \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        opcache \
    && rm -rf /var/lib/apt/lists/*

# -------------------------------------------------------
# Microsoft ODBC Driver 18 for SQL Server
# -------------------------------------------------------
RUN curl -4 -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -4 -fsSL https://packages.microsoft.com/config/debian/12/prod.list \
        -o /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && rm -rf /var/lib/apt/lists/*

# -------------------------------------------------------
# Microsoft SQL Server PHP extensions
# PHP 8.3 / NTS
# -------------------------------------------------------
RUN curl -4 -fL \
        https://github.com/microsoft/msphpsql/releases/download/v5.13.1/Linux_5.13.1RTW.tar.gz \
        -o /tmp/msphpsql.tar.gz \
    && tar -xzf /tmp/msphpsql.tar.gz -C /tmp \
    && EXT_DIR="$(php-config --extension-dir)" \
    && cp /tmp/Linux/SQLSRV_8.3_NTS.so "${EXT_DIR}/sqlsrv.so" \
    && cp /tmp/Linux/PDO_SQLSRV_8.3_NTS.so "${EXT_DIR}/pdo_sqlsrv.so" \
    && echo "extension=sqlsrv.so" > /usr/local/etc/php/conf.d/20-sqlsrv.ini \
    && echo "extension=pdo_sqlsrv.so" > /usr/local/etc/php/conf.d/30-pdo_sqlsrv.ini \
    && php -m | grep -E '^(sqlsrv|pdo_sqlsrv)$' \
    && rm -rf /tmp/msphpsql.tar.gz /tmp/Linux

# -------------------------------------------------------
# PHP settings
# -------------------------------------------------------
RUN { \
        echo 'upload_max_filesize=50M'; \
        echo 'post_max_size=100M'; \
        echo 'memory_limit=256M'; \
        echo 'max_execution_time=300'; \
        echo 'max_input_time=300'; \
        echo 'max_file_uploads=20'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# -------------------------------------------------------
# OPcache
# -------------------------------------------------------
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# -------------------------------------------------------
# Composer
# -------------------------------------------------------
RUN curl -4 -fsSL https://getcomposer.org/download/latest-stable/composer.phar \
        -o /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && composer --version

# -------------------------------------------------------
# Application
# -------------------------------------------------------
WORKDIR /var/www/html

COPY . .

# -------------------------------------------------------
# Laravel dependencies
# -------------------------------------------------------
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# -------------------------------------------------------
# Permissions
# -------------------------------------------------------
RUN chown -R www-data:www-data \
    storage \
    bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]