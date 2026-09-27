# PayFlow HR

A full-stack payroll and workforce management system. The repository is split into two clear applications: a React SPA in `frontend/` and a Laravel REST API in `backend/`. Docker builds and serves both as one deployable application.

## Project structure

```text
payflow-hr/
├── frontend/        React 19, Vite, and Tailwind CSS
├── backend/         Laravel 12 API, domain services, and tests
├── docker/          Apache and PHP container configuration
├── docs/            Deployment and operations guides
├── .github/         Continuous integration
├── compose.yaml     Production orchestration
└── Dockerfile       Multi-stage production build
```

The split keeps framework-specific files inside their application boundary while retaining a small, conventional repository root.

## API

All application endpoints are under `/api`. Login is rate limited; application
routes require Laravel Sanctum authentication, and sensitive payroll and loan
routes require an administrator:

- `GET|POST /api/employees`, `GET|PUT|PATCH|DELETE /api/employees/{employee}`
- `GET /api/attendance?month=YYYY-MM`, `POST /api/attendance/generate`, `POST /api/attendance/import`, `GET /api/attendance/export?month=YYYY-MM`, `PATCH /api/attendance/cell`, `PATCH /api/attendance/overtime`, `POST /api/attendance/lock`

## Admin deployment

The supported deployment is one Docker Compose application container on the
admin's Windows or macOS computer. The image builds the React SPA and serves it
with Laravel through Apache. Neon PostgreSQL remains external.

1. Put the securely supplied `.env` at `backend/.env`.
2. Open Docker Desktop.
3. In this folder, run `docker compose up -d`.
4. Confirm `http://127.0.0.1:8000/up` responds.
5. After installing and signing in to Tailscale, run
   `tailscale serve --bg http://127.0.0.1:8000`.

The application port is bound only to loopback. Other devices reach it through
the private Tailscale HTTPS address, not through the LAN or public internet.
See `docs/production-deployment.md` for preparation, handoff, verification,
updates, backups, and troubleshooting.

## Local development

1. Create an isolated Neon development branch and put its Direct connection
   string in `backend/.env` as `DB_URL`. Then, from `backend/`, run `composer install`,
   `php artisan key:generate`, and `php artisan migrate`.
2. Create a separate disposable Neon testing branch, copy
   `backend/.env.testing.example` to `backend/.env.testing`, and configure its Direct
   connection string as `TEST_DB_URL`. Tests refuse to start until
   `TEST_DB_ISOLATED=true`.
3. In `backend/`, run `php artisan serve` (port 8000).
4. In `frontend/`, copy `.env.example` to `.env` if required, run `npm install`, then `npm run dev` (port 5173).

During development the SPA calls `/api` on its own origin and Vite proxies those requests to `VITE_BACKEND_URL` (default `http://127.0.0.1:8000`). This avoids browser CORS and localhost/port mismatches. Set `VITE_API_URL` only when deploying the frontend and API on separate origins.
