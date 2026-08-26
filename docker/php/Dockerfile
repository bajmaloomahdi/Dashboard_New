FROM php:8.3-fpm

# System packages + PHP build dependencies
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
        libltdl-dev \
        libtool \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        pdo \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
    && rm -rf /var/lib/apt/lists/*

# Microsoft ODBC Driver 18 for SQL Server
RUN curl -4 -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -4 -fsSL https://packages.microsoft.com/config/debian/12/prod.list \
        -o /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && rm -rf /var/lib/apt/lists/*

# Microsoft PHP drivers for SQL Server
# Build from the official source instead of downloading release assets.
RUN git clone --depth 1 --branch v5.13.3 \
        https://github.com/microsoft/msphpsql.git \
        /tmp/msphpsql \
    && cp -a /tmp/msphpsql/source/shared \
        /tmp/msphpsql/source/sqlsrv/shared \
    && cp -a /tmp/msphpsql/source/shared \
        /tmp/msphpsql/source/pdo_sqlsrv/shared \
    \
    && cd /tmp/msphpsql/source/sqlsrv \
    && phpize \
    && ./configure \
    && make -j"$(nproc)" \
    && make install \
    \
    && cd /tmp/msphpsql/source/pdo_sqlsrv \
    && phpize \
    && ./configure \
    && make -j"$(nproc)" \
    && make install \
    \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && php -m | grep -E '^(sqlsrv|pdo_sqlsrv)$' \
    && rm -rf /tmp/msphpsql

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js 22
# Use npmmirror because registry.npmjs.org is unreachable on this network.
RUN curl -4 -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && node --version \
    && npm --version \
    && npm config set registry https://registry.npmmirror.com \
    && npm config set audit false \
    && npm config set fund false \
    && npm config set progress false \
    && npm config set fetch-retries 10 \
    && npm config set fetch-retry-factor 2 \
    && npm config set fetch-retry-mintimeout 20000 \
    && npm config set fetch-retry-maxtimeout 120000 \
    && npm config set fetch-timeout 600000 \
    && npm config set maxsockets 3 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy Laravel application
COPY . .

# Composer dependencies
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --no-dev \
    --optimize-autoloader

# Node dependencies
RUN npm ci \
    --no-audit \
    --no-fund \
    --loglevel=verbose

# Build React / Vite
RUN npm run build

# Laravel permissions
RUN chown -R www-data:www-data \
    storage \
    bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]