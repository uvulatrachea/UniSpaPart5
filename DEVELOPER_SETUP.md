# UniSpa Developer Setup Guide

Welcome to the **UniSpa** development repository! This guide will walk you through setting up the project on your local machine using Docker Desktop.

---

## 💻 System Requirements
Before starting, make sure you have the following installed:
*   [Git](https://git-scm.com/)
*   [Docker Desktop](https://www.docker.com/products/docker-desktop/) (ensure it is running)
*   [Node.js](https://nodejs.org/) (optional, if you want to run builds on the host)

---

## 🚀 Setup Steps (A-Z)

Follow these steps in your terminal (PowerShell, Bash, or Command Prompt):

### 1. Clone the Repository
Clone the project code to your local workspace:
```bash
git clone <your-repository-url>
cd UniSpa2
```

### 2. Configure Environment Variables
Copy the example environment template file to create your active configuration:
```bash
cp .env.example .env
```
*(Open `.env` in your editor to verify that `DB_CONNECTION=pgsql` and database credentials match the postgres service in `docker-compose.yml`.)*

### 3. Spin Up Docker Containers
Start the application services in the background using Docker Compose:
```bash
docker compose up -d
```
This builds and starts four services:
*   `unispa-nginx`: Web server routing requests (Port `8080`)
*   `unispa-php`: Core PHP 8.4 runtime (Port `8000`)
*   `unispa-pgsql`: PostgreSQL 16 Database (Port `5432`)
*   `unispa-pgadmin`: Database management panel (Port `5050`)

### 4. Install Dependencies
Install the required PHP and Node modules inside the active PHP container:
```bash
# Install PHP Composer dependencies
docker compose exec php composer install

# Install Javascript Node packages
docker compose exec php npm install
```

### 5. Generate Application Key
Initialize Laravel's encryption key in the `.env` file:
```bash
docker compose exec php php artisan key:generate
```

### 6. Run Database Migrations & Seeding
Create the database tables and seed them with initial data (services, staff roles, and admins):
```bash
docker compose exec php php artisan migrate:refresh --seed
```

### 7. Run Vite Development Server
To support real-time frontend compiling and hot-module reloading:
```bash
docker compose exec php npm run dev
```

---

## 🌐 Accessing the Application
Once all containers and services are up, open your browser and navigate to:
*   **Customer/Guest Website:** [http://localhost:8080](http://localhost:8080)
*   **Admin Panel:** [http://localhost:8080/admin/login](http://localhost:8080/admin/login)
*   **Staff Portal:** [http://localhost:8080/staff/login](http://localhost:8080/staff/login)
*   **PgAdmin Database Panel:** [http://localhost:5050](http://localhost:5050)

---

## 🛠 Useful Docker Commands
*   **Stop services:** `docker compose down`
*   **Rebuild containers:** `docker compose up -d --build`
*   **Check logs:** `docker compose logs -f`
*   **Access the PHP container shell:** `docker compose exec php bash`
