# bt4103-team31-app
NUS BT4103 Capstone Project

## Project structure

```
server/   CodeIgniter 4 app — routing, controllers, API, database
web/      Frontend — view templates and static assets
```

## Running it

One server serves both, since CodeIgniter renders the frontend views:

```bash
cd server
php spark serve      # http://localhost:8080
```

No separate frontend dev server, no build step, no npm. Edit a file under
`web/views/` and refresh.

---

## Backend setup (`server/`)

CodeIgniter 4 API backed by a MariaDB database hosted on Layerbase.

### Prerequisites
- PHP 8.2+ with `intl`, `mbstring`, `mysqlnd` extensions
- Composer

### First-time setup
```bash
cd server
composer install
cp .env.example .env        
php spark key:generate      # writes encryption.key into .env
php spark serve             # http://localhost:8080
```

### Verify the DB connection
Once the Layerbase host/user/password/database are filled into `server/.env`:
```bash
cd server
php spark db:table --show
```
This lists the tables in the connected database. If the credentials are wrong, the error message from MySQLi will point at the failing field.

### Notes
- `server/.env` is gitignored — every teammate keeps their own copy.
- Only `server/.env.example` is committed as the shared template.

---

## Frontend (`web/`)

Server-rendered CodeIgniter views styled with Tailwind.

```
web/
  views/
    layout/main.php   base HTML layout, loads Tailwind
    home.php          page content, extends the layout
  assets/             static assets
```

### Adding a page

1. Create the view in `web/views/`, extending `layout/main`:
   ```php
   <?= $this->extend('layout/main') ?>
   <?= $this->section('content') ?>
   <h1 class="text-2xl font-bold">Hello</h1>
   <?= $this->endSection() ?>
   ```
2. Add a controller method in `server/app/Controllers/` returning `view('yourview')`.
3. Register the route in `server/app/Config/Routes.php`.

### Notes
- Tailwind comes from the Play CDN, which compiles in the browser. Fine for
  development; swap for a compiled build before shipping.
- `web/assets/` is not web-accessible yet — only `server/public/` is served, so
  files there need a symlink or copy step once there are any.
