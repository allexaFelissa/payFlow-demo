# Docker Desktop and Tailscale Deployment

## Architecture

```text
Authorized laptop
        |
        | Private HTTPS (Tailscale Serve)
        v
Admin laptop: 127.0.0.1:8000
        |
        v
Docker Compose
        |
        +-- Apache + PHP 8.3 + Laravel 12
        +-- compiled React SPA
        +-- automatic non-destructive migrations
        |
        | TLS database connection
        v
Neon PostgreSQL production branch
```

This deployment does not use Render. The application is not published on a LAN
address or the public internet. Docker publishes it only on the host's loopback
address, and Tailscale Serve provides the private HTTPS endpoint.

The admin laptop must be powered on and awake, with Docker Desktop and Tailscale
running, for other users to reach the application.

## Owner preparation and secure handoff

Before giving the project to the admin:

1. Confirm the Neon production branch contains the intended production data,
   is protected, and has a current backup.
2. Copy `backend/.env.production.example` to `backend/.env`.
3. Set a persistent Laravel `APP_KEY`. Generate it with
   `php backend/artisan key:generate --show` on a trusted development machine if needed.
4. Set `DB_URL` to the Neon production pooled connection string.
5. Optionally set `DB_MIGRATION_URL` to the production direct connection string.
   Startup uses it only for migrations and uses `DB_URL` for web requests.
6. Keep `DB_SSLMODE=require`, `APP_DEBUG=false`, and
   `SESSION_SECURE_COOKIE=true`.
7. Confirm the production database already contains the correct Admin and HR
   accounts. Container startup migrates the schema but deliberately does not
   seed users or payroll data.

Never rotate `APP_KEY` casually. Existing encrypted values, cookies, and
sessions may depend on it.

The project can be delivered through a private Git repository or an archive.
Send the populated `.env` separately through a password manager, encrypted file
transfer, or another approved secure channel. Do not commit it, put it in a
normal GitHub upload, or send it through chat or email. The file must be named
exactly `.env`, not `.env.txt`, and placed inside the `backend/` directory.

## First installation on the admin laptop

### 1. Install Docker Desktop

Install Docker Desktop for Windows or macOS and launch it. Docker Desktop
includes Docker Engine and Docker Compose. Wait until it reports that the
engine is running. A restart may be requested by the installer.

### 2. Place the project and environment file

Put the project in a stable folder that will not be renamed or routinely
deleted. Put the securely received `.env` inside the backend directory:

```text
compose.yaml
Dockerfile
backend/.env
```

### 3. Build and start the application

Open PowerShell, Terminal, or the Docker Desktop terminal in the project folder:

```bash
docker compose up -d
```

This command builds one production application image, starts the container,
applies only pending Laravel migrations with `--force`, caches Laravel
configuration, and serves both Laravel and the compiled React SPA.

Check the result:

```bash
docker compose ps
docker compose logs --tail=100 app
```

The `app` service should show `running` and then `healthy`. On the admin laptop,
open:

```text
http://127.0.0.1:8000
http://127.0.0.1:8000/up
```

If migration or database connection fails, the container will not become
healthy. Use the log command above; do not run `migrate:fresh`, `db:wipe`, or
any destructive database command.

### 4. Install and sign in to Tailscale

Install Tailscale on the admin laptop and every authorized client device. Sign
in to the intended tailnet. Apply Tailscale access-control rules so only the
required users or devices can reach the admin laptop.

### 5. Configure private HTTPS

On the admin laptop, run the following command. On Windows, use a Terminal or
PowerShell window opened as Administrator if the normal terminal is denied:

```bash
tailscale serve --bg http://127.0.0.1:8000
```

Tailscale prints the private URL, similar to:

```text
https://admin-laptop.example-tailnet.ts.net
```

Open that URL from another signed-in, authorized Tailscale device. Verify the
configuration at any time:

```bash
tailscale serve status
```

The older command `tailscale serve https / http://localhost:8000` uses obsolete
CLI syntax. The `--bg` form persists across Tailscale and device restarts.
Tailscale may open a one-time consent page to enable HTTPS certificates for the
tailnet.

Use Tailscale **Serve**, not **Funnel**. Funnel would make the application
publicly accessible.

## First-use verification

- `docker compose ps` reports the `app` service as healthy.
- `/up` returns HTTP 200 locally and through the Tailscale HTTPS URL.
- The browser shows a valid HTTPS connection on the Tailscale URL.
- Login succeeds and invalid login attempts are rate limited.
- Admin-only payroll and loan pages reject HR staff accounts.
- Employee, attendance, overtime, payroll, recap, and salary-slip workflows
  work against the verified Neon production branch.
- Container logs contain no stack traces or database errors.
- The Neon dashboard shows connections using TLS.

Do not approve the system for live payroll use until the backup, user accounts,
permissions, and representative payroll totals have been checked.

## Routine operation

Docker Compose uses `restart: unless-stopped`, so the application returns when
Docker Desktop restarts. Configure Docker Desktop to launch when the admin signs
in. Tailscale Serve also resumes after restart when configured with `--bg`.

Useful commands:

```bash
# Status
docker compose ps

# Recent logs
docker compose logs --tail=100 app

# Follow logs
docker compose logs -f app

# Restart
docker compose restart app

# Stop and remove only the container/network
docker compose down

# Start again
docker compose up -d
```

`docker compose down` does not delete the external Neon database. Do not add
`--volumes` to operational instructions without reviewing future local storage
requirements.

## Application updates

Back up Neon before a release that contains migrations. Pull or copy the new
project files into the same folder without replacing the populated `.env`, then
run:

```bash
docker compose up -d --build
docker compose ps
docker compose logs --tail=100 app
```

The new container runs pending migrations before Apache starts. If the build
fails, the previous running container remains available. If startup fails after
replacement, inspect the logs and restore the previous project version. An
application rollback does not reverse a database migration; prefer a forward
corrective migration or a verified Neon restore.

## Security and operational notes

- `backend/.env` is excluded from both Git and the Docker build context. Compose injects
  it only when the container starts.
- Compose overrides unsafe local values so the container always uses
  `APP_ENV=production`, `APP_DEBUG=false`, PostgreSQL TLS, and secure cookies.
- Port `8000` is bound to `127.0.0.1`, preventing direct access from the LAN or
  tailnet. Tailscale Serve is the intended remote entry point.
- Laravel trusts the local reverse proxy so HTTPS detection and generated
  request URLs work behind Tailscale.
- The React SPA uses same-origin `/api`; there is no separately exposed
  frontend development server.
- Neon contains the durable application data. Deleting the local container does
  not delete Neon data.
- Generated exports and salary-slip archives are temporary. Add reviewed
  persistent or object storage before introducing permanent uploads.
- Protect the admin laptop with full-disk encryption, OS login security,
  automatic security updates, and a non-shared administrator account.
- Configure Neon backups and recovery independently of Docker.

## Resetting Tailscale Serve

To inspect or remove the private HTTPS proxy:

```bash
tailscale serve status
tailscale serve reset
```

Resetting Serve does not stop the Docker container. It only removes the
Tailscale proxy configuration.
