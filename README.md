# bt4103-team31-app
NUS BT4103 Capstone Project — SenseiGigs

CodeIgniter 4 monolith serving both the API and the server-rendered frontend, backed by a MariaDB database hosted on Layerbase.

## Project structure

```
app/
  Config/       framework config (routes, database, paths, filters, ...)
  Controllers/  request handlers
  Models/       database access
  Views/        page templates (layout/, partials/, per-page files)
  Database/     migrations and seeders
public/         web docroot (only folder exposed to the browser)
writable/       runtime output (logs, cache, sessions)
tests/          PHPUnit tests
spark           CI4 CLI tool
.env            local config + secrets (gitignored)
.env.example    template for teammates
```

## Prerequisites

- PHP 8.2+ with `intl`, `mbstring`, `mysqlnd` extensions
- Composer

## First-time setup

```bash
composer install
cp .env.example .env      # then fill in Layerbase credentials
php spark key:generate    # writes encryption.key into .env
php spark serve           # http://localhost:8080
```

## Verify the DB connection

Once the Layerbase host/user/password/database are filled into `.env`:

```bash
php spark db:table --show
```

Lists the tables in the connected database. If credentials are wrong, the MySQLi error points at the failing field.

## Adding a page

1. Create the view under `app/Views/`, extending the shared layout:
   ```php
   <?= $this->extend('layout/main') ?>
   <?= $this->section('content') ?>
   <h1 class="text-2xl font-bold">Hello</h1>
   <?= $this->endSection() ?>
   ```
2. Add a controller method in `app/Controllers/` returning `view('yourview')`.
3. Register the route in `app/Config/Routes.php`.

## Notes

- `.env` is gitignored — every teammate keeps their own copy. Only `.env.example` is committed.
- Tailwind is loaded via the Play CDN (compiles in the browser). Fine for development; swap for a compiled build before shipping.
