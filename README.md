# Query Result Store (QRS)

Minimal implementation skeleton for the QRS requirements.

## Quick start

1. Create config (either one):
   - Copy `config.sample.php` to `config.php`, or
   - Copy `.env.example` to `.env`
   - Set application timezone (`QRS_TIMEZONE`, e.g. `Asia/Tokyo`)
2. Start UI:

   ```bash
   php -S 127.0.0.1:8080 -t public
   ```

3. Open `http://127.0.0.1:8080`.
   - If DB connection fails, setup form is shown.
   - When connection succeeds, run schema initialization.
   - After schema setup, you can register Redash instances from the UI.
   - Default UI language is English; Japanese is auto-selected for Japanese browser locales and can be switched with `?lang=en` or `?lang=ja`.
   - UI pages are split as:
     - `env.php` (Environment)
     - `instances.php` (Redash Instances)
     - `datasets.php` (Datasets)
     - `variants.php` (Variants)
     - `logs.php` (Logs, placeholder)

4. Run batch worker:

   ```bash
   php bin/worker.php
   ```

   Cron example with `flock`:

   ```bash
   * * * * * flock -n /var/lock/qrs-worker.lock php /home/mt/dev/query-result-store/bin/worker.php
   ```

## Docker quick start

1. Start container:

   ```bash
   docker compose up -d --build
   ```

   If you get file write permission errors for `config.php` or `var/`, run with your host UID/GID:

   ```bash
   UID=$(id -u) GID=$(id -g) docker compose up -d --build
   ```

2. Open `http://127.0.0.1:8080`.

3. Run worker manually in container:

   ```bash
   docker compose exec web php bin/worker.php
   ```

4. Configure DB by environment variables (`docker-compose.yml`):
   - `QRS_DB_DRIVER`: `sqlite` / `mysql` / `pgsql`
   - `QRS_DB_SQLITE_PATH`: sqlite file path in container (example: `/var/www/html/var/qrs.sqlite3`)
   - `QRS_DB_HOST`, `QRS_DB_PORT`, `QRS_DB_NAME`, `QRS_DB_USER`, `QRS_DB_PASSWORD`, `QRS_DB_CHARSET`

   Examples:

   ```yaml
   # sqlite
   QRS_DB_DRIVER: sqlite
   QRS_DB_SQLITE_PATH: /var/www/html/var/qrs.sqlite3

   # mysql
   QRS_DB_DRIVER: mysql
   QRS_DB_HOST: mysql
   QRS_DB_PORT: "3306"
   QRS_DB_NAME: qrs
   QRS_DB_USER: qrs
   QRS_DB_PASSWORD: qrs
   QRS_DB_CHARSET: utf8mb4

   # pgsql
   QRS_DB_DRIVER: pgsql
   QRS_DB_HOST: postgres
   QRS_DB_PORT: "5432"
   QRS_DB_NAME: qrs
   QRS_DB_USER: qrs
   QRS_DB_PASSWORD: qrs
   ```

## Notes

- Single worker entrypoint: `bin/worker.php`
- Current state is a scaffold; dispatch/execute internals are TODO.
- DB drivers supported by config: `sqlite`, `mysql`, `pgsql`
- Timezone source: config/env (`app.timezone_id` or `QRS_TIMEZONE`), applied on process bootstrap
- Required PHP extensions: `PDO`, `json` (`curl` is required for Redash instance features)
- i18n fallback order: selected locale -> `en` -> key id
- i18n strict mode (throws on missing key): set `QRS_APP_ENV=development` or `QRS_I18N_STRICT=1`
- i18n missing-key log to web server error log: set `QRS_I18N_LOG_MISSING=1`
