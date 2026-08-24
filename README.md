# AI Form Builder

An AI-powered form builder application built with Laravel and React.

## Technology Stack

- **Backend:** PHP 8.2+, Laravel 12
- **Frontend:** React 18, TypeScript, Tailwind CSS 4
- **Database:** MySQL 8
- **Cache/Queue:** Redis
- **Queue Worker:** Laravel Horizon
- **Build Tool:** Vite

## Prerequisites

- Docker & Docker Compose
- Git

For local development without Docker:
- PHP 8.2+
- Composer
- Node.js 20+
- MySQL 8
- Redis

## Docker Setup (Recommended)

1. Clone the repository:
```bash
git clone <repository-url>
cd ai-form-builder
```

2. Copy environment file:
```bash
cp .env.example .env
```

3. Start Docker containers:
```bash
docker-compose up -d --build
```

4. Install PHP dependencies:
```bash
docker-compose exec app composer install
```

5. Generate application key:
```bash
docker-compose exec app php artisan key:generate
```

6. Run database migrations:
```bash
docker-compose exec app php artisan migrate
```

7. Access the application:
- Application: http://localhost:8000
- Vite dev server: http://localhost:5173

## Local Setup (Without Docker)

1. Clone and install dependencies:
```bash
git clone <repository-url>
cd ai-form-builder
composer install
npm install
```

2. Configure environment:
```bash
cp .env.example .env
php artisan key:generate
```

3. Update `.env` with your local database and Redis settings.

4. Run migrations:
```bash
php artisan migrate
```

5. Start development servers:
```bash
# Terminal 1 - Laravel
php artisan serve

# Terminal 2 - Vite
npm run dev

# Terminal 3 - Queue worker (optional)
php artisan horizon
```

## Database

Run migrations:
```bash
# Docker
docker-compose exec app php artisan migrate

# Local
php artisan migrate
```

Run seeders:
```bash
# Docker
docker-compose exec app php artisan db:seed

# Local
php artisan db:seed
```

## Frontend Development

Build for production:
```bash
npm run build
```

Development with hot reload:
```bash
npm run dev
```

## Testing

Run test suite:
```bash
# Docker
docker-compose exec app php artisan test

# Local
php artisan test
```

## Queue / Horizon

Start Horizon (manages queue workers):
```bash
# Docker - runs automatically via horizon container

# Local
php artisan horizon
```

Access Horizon dashboard at `/horizon` (local environment only).

## Health Check

Verify the application is running:
```bash
curl http://localhost:8000/api/health
```

## Authentication

The application uses Laravel Sanctum for API authentication with multi-tenant support.

### Demo Account

After running seeders, a demo account is available:
- Email: `demo@example.com` (configurable via `DEMO_USER_EMAIL`)
- Password: `password` (configurable via `DEMO_USER_PASSWORD`)

### API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/register | Register new user with tenant |
| POST | /api/login | Login and get token |
| POST | /api/logout | Logout (requires auth) |
| GET | /api/user | Get current user profile |
| POST | /api/tenants/{id}/switch | Switch active tenant |

### Example: Login
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email": "demo@example.com", "password": "password"}'
```

### Example: Authenticated Request
```bash
curl http://localhost:8000/api/user \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Docker Services

| Service | Port | Description |
|---------|------|-------------|
| nginx | 8000 | Web server |
| app | 9000 | PHP-FPM |
| mysql | 3306 | Database |
| redis | 6379 | Cache/Queue |
| horizon | - | Queue worker |
| node | 5173 | Vite dev server |

## Useful Commands

```bash
# View logs
docker-compose logs -f app

# Enter app container
docker-compose exec app bash

# Clear caches
docker-compose exec app php artisan optimize:clear

# Stop all containers
docker-compose down

# Stop and remove volumes
docker-compose down -v
```
