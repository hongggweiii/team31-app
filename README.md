# bt4103-team31-app
NUS BT4103 Capstone Project

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
