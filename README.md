# UniSpa — Booking Management System

A full-stack spa booking and management system built with **Laravel 12**, **React (Inertia.js)**, and **PostgreSQL**, containerised with **Docker**.

---

## What You Need (Prerequisites)

Install **only these two things** — you do NOT need PHP, Node.js, Composer, or PostgreSQL on your machine:

| Tool | Version | Download |
|---|---|---|
| **Docker Desktop** | Latest | https://www.docker.com/products/docker-desktop |
| **Git** | Any | https://git-scm.com/downloads |

> **Windows users:** After installing Docker Desktop, make sure it is running (whale icon in the taskbar) before proceeding.

---

## Quick Setup (5 Steps)

### Step 1 — Clone the repository

```bash
git clone https://github.com/uvulatrachea/UniSpaPart5.git
cd UniSpaPart5
```

### Step 2 — Create your `.env` file

Copy the example file and rename it:

**Windows (Command Prompt):**
```cmd
copy .env.example .env
```

**Mac / Linux:**
```bash
cp .env.example .env
```

> You do **not** need to change anything in `.env` for basic setup. The default values are pre-configured for Docker.

### Step 3 — Generate the app key

```bash
docker compose run --rm php php artisan key:generate
```

> This fills in the `APP_KEY=` line in your `.env`. Only needed once.

### Step 4 — Build and start all containers

```bash
docker compose up -d --build
```

This will:
- Build the PHP container (installs all dependencies automatically)
- Start PostgreSQL, Nginx, PHP, and pgAdmin
- Takes **3–8 minutes** on first run (downloading images + building)

Wait until you see all containers as **running**:
```bash
docker compose ps
```

All four services (`unispa-nginx`, `unispa-php`, `unispa-pgsql`, `unispa-pgadmin`) should show `running`.

### Step 5 — Set up the database

Run these two commands **once** after starting:

```bash
docker exec unispa-php php artisan migrate --force
docker exec unispa-php php artisan db:seed --class=UniSpaSeeder --force
```

The first command creates all tables. The second seeds demo accounts, services, staff, and promotions.

### Done!

Open your browser and go to: **http://localhost:8080**

---

## Login Credentials

### Customer Accounts

| Name | Email | Password |
|---|---|---|
| Hasya (demo) | hasyadini15@gmail.com | Customer@123 |
| Nur Aina (UiTM member) | 2025179327@student.uitm.edu.my | Password!1 |
| Adam Lee (regular) | adam@example.com | Password!1 |

### Staff / Admin Accounts

| Role | Email | Password |
|---|---|---|
| **Admin** | dinihasya15@gmail.com | Admin12345! |
| Full-Time Staff | staff@unispa.com | Staff12345! |
| Part-Time Staff | student@unispa.com | Student12345! |

> If the admin email above doesn't work, check your `.env` — the seeder uses `ADMIN_EMAIL` and `ADMIN_PASSWORD` from there.

### Login Pages

| Page | URL |
|---|---|
| Customer Login | http://localhost:8080/login |
| Customer Register | http://localhost:8080/register |
| Staff / Admin Login | http://localhost:8080/staff/login |
| pgAdmin (DB viewer) | http://localhost:50432 |

**pgAdmin login:** Email `admin@admin.com` / Password `password`  
Connect to server: Host `pgsql`, Port `5432`, DB `unispa`, User `sail`, Password `password`

---

## Promo Codes (for testing)

Enter these in the Cart page:

| Code | Discount | Expiry |
|---|---|---|
| `SUMMER2026` | 15% off | 31 Aug 2026 |
| `WELCOME10` | 10% off | No expiry |
| `STUDENT10` | 10% off | Set by admin |

---

## Stopping and Restarting

```bash
# Stop containers (keeps your database data)
docker compose down

# Start again later (no need to rebuild)
docker compose up -d

# Full reset — removes ALL data and rebuilds from scratch
docker compose down -v
docker compose up -d --build
docker exec unispa-php php artisan migrate --force
docker exec unispa-php php artisan db:seed --class=UniSpaSeeder --force
```

---

## Common Problems & Fixes

### Website shows "502 Bad Gateway"
The PHP container is still starting up. Wait 30 seconds and refresh.

```bash
# Check if PHP is running
docker logs unispa-php --tail=20
```

If you see "Ready to handle connections", the server is up — refresh the browser.

### Website shows "504 Gateway Timeout"
PHP took too long. This usually happens on the first page load after starting.

```bash
# Restart just the PHP container
docker compose restart php
```

Wait 10 seconds then refresh.

### "SQLSTATE: relation does not exist" or database errors

Migrations haven't run yet. Run:

```bash
docker exec unispa-php php artisan migrate --force
docker exec unispa-php php artisan db:seed --class=UniSpaSeeder --force
```

### "Class UniSpaSeeder not found"

```bash
docker exec unispa-php php artisan config:clear
docker exec unispa-php php artisan db:seed --class=UniSpaSeeder --force
```

### Page loads but shows a white screen or JavaScript error

The frontend assets need to be rebuilt:

```bash
docker exec unispa-php npm run build
```

Wait for the build to finish (~30–60 seconds), then refresh.

### Storage / file upload not working (images not showing)

```bash
docker exec unispa-php php artisan storage:link
```

### "APP_KEY is missing" or decryption error

```bash
docker exec unispa-php php artisan key:generate
docker compose restart php
```

### Port 8080 is already in use

Another application is using port 8080. Either stop it, or change the port in `docker-compose.yml`:

```yaml
nginx:
  ports:
    - '9090:80'   # change 8080 to any free port, e.g. 9090
```

Then visit http://localhost:9090 instead.

### Port 5432 is already in use (PostgreSQL conflict)

You may have PostgreSQL installed locally. Change the port in `docker-compose.yml`:

```yaml
pgsql:
  ports:
    - '5433:5432'   # change left side only
```

### Docker build fails with "failed to solve" or "no space left"

Clean up Docker's cache and try again:

```bash
docker system prune -f
docker compose up -d --build
```

### Cannot login — "These credentials do not match"

The seeder may not have run, or ran with different credentials. Reset and re-seed:

```bash
docker exec unispa-php php artisan db:seed --class=UniSpaSeeder --force
```

If it still fails, do a full reset:

```bash
docker compose down -v
docker compose up -d --build
docker exec unispa-php php artisan migrate --force
docker exec unispa-php php artisan db:seed --class=UniSpaSeeder --force
```

---

## Useful Commands

```bash
# View live logs for the PHP app
docker logs unispa-php -f

# Run any artisan command
docker exec unispa-php php artisan <command>

# Open a shell inside the PHP container
docker exec -it unispa-php bash

# Rebuild frontend (after changing JSX/CSS files)
docker exec unispa-php npm run build

# Fresh database (drops all tables and re-migrates)
docker exec unispa-php php artisan migrate:fresh --seed --seeder=UniSpaSeeder

# Clear all Laravel caches
docker exec unispa-php php artisan optimize:clear
```

---

## Project Structure

```
UniSpaPart5/
├── app/
│   ├── Http/Controllers/       # Laravel controllers
│   │   ├── Auth/               # Customer + Admin auth
│   │   ├── Booking/            # Cart, Schedule, Payment
│   │   └── Admin/              # Admin dashboard, manage bookings
│   ├── Models/                 # Eloquent models
│   └── Support/                # BookingCalendar helper
├── database/
│   ├── migrations/             # 34 database migrations
│   └── seeders/                # Demo data seeders
├── resources/js/
│   └── Pages/                  # React (Inertia) pages
│       ├── Admin/              # ManageServices, ManageScheduling, etc.
│       ├── Booking/            # Services, Schedule, Cart, Payment
│       └── Appointments.jsx    # Customer reservations page
├── docker/nginx/               # Nginx config
├── docker-compose.yml          # Local development containers
├── DockerFile                  # PHP container definition
└── .env.example                # Environment template
```

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.4 |
| Frontend | React 18, Inertia.js v1, Tailwind CSS |
| Database | PostgreSQL 16 |
| Web Server | Nginx 1.25 (proxy) + PHP artisan serve |
| Containers | Docker Compose |
| Email | Gmail SMTP (optional) |
| File Storage | Laravel local disk (public storage) |

---

## Email Setup (Optional)

The system sends booking confirmation emails. To enable this on your machine:

1. Use a Gmail account and [create an App Password](https://myaccount.google.com/apppasswords) (requires 2FA)
2. Edit your `.env` file:

```env
MAIL_USERNAME=your_email@gmail.com
MAIL_PASSWORD="your_16_char_app_password"
MAIL_FROM_ADDRESS=your_email@gmail.com
```

3. Restart the PHP container:

```bash
docker compose restart php
```

If you skip this, the app works normally — emails just won't send.

---

## Test Accounts Summary

### What to demonstrate

| Feature | Where | Login as |
|---|---|---|
| Browse & book a service | /booking/services | Customer |
| Apply promo code | /booking/cart | Customer |
| Upload QR payment | /booking/payment | Customer |
| View & cancel reservations | /reservations | Customer |
| Write a review | /reviews | Customer (after completed booking) |
| Manage bookings (approve QR) | Admin → Manage Bookings | Admin |
| Manage staff schedules | Admin → Manage Scheduling | Admin |
| Manage services & promotions | Admin → Manage Services | Admin |
| Staff submit availability | Staff portal | Part-Time Staff |

---

> Built for CSC600 — UniSpa Booking Management System (UBMS)
