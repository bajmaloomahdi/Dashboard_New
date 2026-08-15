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

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --no-dev \
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