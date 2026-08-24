FROM php:8.2-fpm

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libzip-dev libpq-dev \
    libfreetype6-dev libjpeg62-turbo-dev supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd zip \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN groupadd -g ${GROUP_ID} appgroup || true \
    && useradd -u ${USER_ID} -g appgroup -m appuser || true

WORKDIR /var/www/html

RUN chown -R ${USER_ID}:${GROUP_ID} /var/www/html

USER appuser

EXPOSE 9000
CMD ["php-fpm"]
