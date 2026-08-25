#!/bin/sh

# Debug: Print env vars
echo "DEBUG: GEMINI_API_KEY length = ${#GEMINI_API_KEY}"

# Inject runtime environment variables into .env
if [ -n "$GEMINI_API_KEY" ]; then
    echo "GEMINI_API_KEY=$GEMINI_API_KEY" >> /var/www/html/.env
    echo "Injected GEMINI_API_KEY"
else
    echo "WARNING: GEMINI_API_KEY is empty!"
fi

if [ -n "$OPENAI_API_KEY" ]; then
    echo "OPENAI_API_KEY=$OPENAI_API_KEY" >> /var/www/html/.env
    echo "Injected OPENAI_API_KEY"
fi

# Show .env contents (masked)
echo "DEBUG: .env file contents:"
cat /var/www/html/.env | grep -E "^(AI_|GEMINI_|OPENAI_)" | sed 's/=.*/=***MASKED***/'

# Clear any cached config
php artisan config:clear

# Run migrations and seed
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force

# Start server
exec php artisan serve --host=0.0.0.0 --port=8080
