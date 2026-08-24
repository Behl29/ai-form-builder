FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libzip-dev \
    libfreetype6-dev libjpeg62-turbo-dev sqlite3 libsqlite3-dev nodejs npm \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy everything first
COPY . .

# Install dependencies and build
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && npm install \
    && npm run build

# Setup Laravel directories and permissions
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs \
    && mkdir -p database \
    && touch database/database.sqlite \
    && cp .env.production .env \
    && chmod -R 775 storage bootstrap/cache database

EXPOSE 8080

CMD sh -c "echo OPENAI_API_KEY=$OPENAI_API_KEY >> .env && php artisan key:generate --force && php artisan route:cache && php artisan view:cache && php artisan migrate --force && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=8080"
