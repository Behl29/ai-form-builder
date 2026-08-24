# AI Form Builder

An AI-powered form builder application built with Laravel and React.

## Technology Stack

- **Backend:** PHP 8.2+, Laravel 12
- **Frontend:** React 18, TypeScript, Tailwind CSS 4
- **Database:** MySQL 8
- **Cache/Queue:** Redis
- **Queue Worker:** Laravel Horizon
- **Build Tool:** Vite

## Frontend Libraries

The form builder uses the following open-source libraries:

- **[@dnd-kit/core](https://dndkit.com/)** - Modern drag and drop toolkit for React
- **[@dnd-kit/sortable](https://dndkit.com/)** - Sortable preset for dnd-kit
- **[@tanstack/react-query](https://tanstack.com/query)** - Data fetching and caching
- **[lucide-react](https://lucide.dev/)** - Beautiful icons
- **[clsx](https://github.com/lukeed/clsx)** - Utility for constructing className strings
- **[axios](https://axios-http.com/)** - HTTP client

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

## Form Builder

The form builder provides a drag-and-drop interface for creating forms.

### Features

- **Field Palette:** 14 field types (text, textarea, number, email, phone, date, select, radio, checkbox group, checkbox, file, heading, rating, URL)
- **Drag & Drop:** Add fields by dragging from palette or clicking
- **Reordering:** Drag to reorder fields and sections
- **Configuration Panel:** Type-specific field properties
- **Preview Modes:** Desktop and mobile preview
- **Autosave:** Debounced automatic saving with status indicator
- **Keyboard Accessible:** Full keyboard navigation support

### Field Types

| Type | Description |
|------|-------------|
| text | Single-line text input |
| textarea | Multi-line text input |
| number | Numeric input with min/max/step |
| email | Email address input |
| phone | Phone number input |
| date | Date picker |
| select | Dropdown selection |
| radio | Radio button group |
| checkbox_group | Multiple checkboxes |
| checkbox | Single checkbox |
| file | File upload |
| heading | Section heading (presentational) |
| rating | Star rating |
| url | URL input |

### Schema

Forms are stored as versioned JSON schemas. See [docs/SCHEMA.md](docs/SCHEMA.md) for the complete schema specification.

## API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/register | Register new user with tenant |
| POST | /api/login | Login and get token |
| POST | /api/logout | Logout (requires auth) |
| GET | /api/user | Get current user profile |
| POST | /api/tenants/{id}/switch | Switch active tenant |

### Forms

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/forms | List forms (search, filter, paginate) |
| POST | /api/forms | Create form |
| GET | /api/forms/{id} | Get form details |
| PUT | /api/forms/{id} | Update form metadata |
| DELETE | /api/forms/{id} | Delete form |
| PUT | /api/forms/{id}/schema | Update form schema |
| POST | /api/forms/{id}/publish | Publish form |
| POST | /api/forms/{id}/unpublish | Unpublish form |
| POST | /api/forms/{id}/archive | Archive form |
| POST | /api/forms/{id}/restore | Restore archived form |
| POST | /api/forms/{id}/duplicate | Duplicate form |

### Form Versions

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/forms/{id}/versions | List form versions |
| GET | /api/forms/{id}/versions/{versionId} | Get specific version |
| POST | /api/forms/{id}/versions/{versionId}/restore | Restore version |

## Testing

### Backend Tests
```bash
# Docker
docker-compose exec app php artisan test

# Local
php artisan test
```

### Frontend Tests
```bash
npm run test
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

## Demo Account

After running seeders, a demo account is available:
- Email: `demo@example.com` (configurable via `DEMO_USER_EMAIL`)
- Password: `password` (configurable via `DEMO_USER_PASSWORD`)

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
