#!/bin/sh

# Inject runtime environment variables into .env
echo "GEMINI_API_KEY=${GEMINI_API_KEY}" >> /var/www/html/.env
echo "OPENAI_API_KEY=${OPENAI_API_KEY}" >> /var/www/html/.env

# Clear any cached config
php artisan config:clear

# Run migrations and seed
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force

# Start server
exec php artisan serve --host=0.0.0.0 --port=8080
