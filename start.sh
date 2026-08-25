#!/bin/sh

cd /var/www/html

# FIRST: Inject env vars into .env file
if [ -n "$GEMINI_API_KEY" ]; then
    echo "GEMINI_API_KEY=$GEMINI_API_KEY" >> .env
fi

if [ -n "$OPENAI_API_KEY" ]; then
    echo "OPENAI_API_KEY=$OPENAI_API_KEY" >> .env
fi

# Export to current shell so PHP can access via getenv()
export GEMINI_API_KEY="$GEMINI_API_KEY"
export OPENAI_API_KEY="$OPENAI_API_KEY"

# Now run Laravel commands
php artisan config:clear
php artisan cache:clear
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force

# Start server with env vars exported
exec php artisan serve --host=0.0.0.0 --port=8080
