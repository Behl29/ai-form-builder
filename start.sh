#!/bin/sh

cd /var/www/html

# Inject runtime environment variables into .env FIRST
if [ -n "$GEMINI_API_KEY" ]; then
    echo "GEMINI_API_KEY=$GEMINI_API_KEY" >> .env
    echo "Injected GEMINI_API_KEY (length: ${#GEMINI_API_KEY})"
fi

if [ -n "$OPENAI_API_KEY" ]; then
    echo "OPENAI_API_KEY=$OPENAI_API_KEY" >> .env
fi

# Now run Laravel commands (they will read updated .env)
php artisan config:clear
php artisan cache:clear
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force

# Start server
exec php artisan serve --host=0.0.0.0 --port=8080
