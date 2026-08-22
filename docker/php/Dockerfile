FROM php:8.3-fpm

# System packages
RUN apt-get update && apt-get install -y \
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

# Microsoft ODBC Driver 18 for SQL Server
RUN curl https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl https://packages.microsoft.com/config/debian/12/prod.list \
        -o /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y msodbcsql18 \
    && rm -rf /var/lib/apt/lists/*

# PHP SQL Server extensions
RUN pecl install sqlsrv pdo_sqlsrv \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv

# PHP Upload Settings
RUN { \
        echo 'upload_max_filesize = 50M'; \
        echo 'post_max_size = 100M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 300'; \
        echo 'max_input_time = 300'; \
        echo 'max_file_uploads = 20'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# OPcache
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Composer
RUN curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar \
    -o /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && composer --version

# Node.js 22
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && node --version \
    && npm --version \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy Laravel application
COPY . .

# Composer dependencies
# --no-dev intentionally removed because this is a development environment
# and laravel/pail is required.
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Node dependencies
RUN npm ci

# Build React / Vite
RUN npm run build

# Laravel permissions
RUN chown -R www-data:www-data \
    storage \
    bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]