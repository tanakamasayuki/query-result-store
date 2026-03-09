# Query Result Store (QRS)

QRS (Query Result Store) is a lightweight PHP application that periodically executes Redash queries and stores the results into your own database for downstream BI/analytics usage.

Japanese README: [README.ja.md](README.ja.md)

## What QRS does

- Registers multiple Redash instances
- Registers Datasets (`instance_id + query_id`)
- Defines Variants (execution mode, parameters, schedule)
- Dispatches and executes bucket-based jobs via worker
- Stores execution state/logs in system tables
- Stores query result rows in per-variant data tables

## What QRS does not do

- Build dashboards/visualizations
- Edit Redash SQL
- Provide schema migration tooling

## Tech stack

- PHP (designed to stay compatible with older OSS-friendly style)
- PDO databases:
  - SQLite
  - MySQL
  - PostgreSQL
- Redash API via cURL

## Requirements

- PHP with extensions:
  - `PDO` (required)
  - `json` (required)
  - `curl` (required)
- PDO drivers:
  - at least one of `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`

## Project structure

- Web UI: `public/`
- Worker: `bin/worker.php`
- Core libraries: `lib/`
- Language files: `lang/`
- Runtime files (sqlite/raw payload/logs): `var/`

## Quick start (local PHP)

1. Create config file:

   ```bash
   cp config.sample.php config.php
   ```

2. Edit DB/timezone in `config.php` (or use environment variables below).

3. Start web UI:

   ```bash
   php -S 127.0.0.1:8080 -t public
   ```

4. Open:

   ```text
   http://127.0.0.1:8080
   ```

5. In Environment page:

- confirm runtime checks
- initialize schema
- save runtime settings if needed

## Quick start (Web server deployment)

For production-style deployment, expose only `public/` to the web server.

1. Recommended: point `DocumentRoot` to `public/`

- Example: `/var/www/qrs/public`
- Prevents direct exposure of `lib/`, `bin/`, `config.php`, `var/`, etc.

2. If you cannot change existing `DocumentRoot`: map `public/` with Alias/location

- Use Apache `Alias` or Nginx `location` to map `/qrs` to `public/`
- Practical when integrating under an existing site

3. Reference (less recommended): keep root-exposed layout with deny rules

- You must explicitly block direct access to `lib/`, `bin/`, `var/`, `config.php`
- Higher risk of misconfiguration than 1 or 2

## Worker execution

Run once:

```bash
php bin/worker.php
```

Cron example (host):

```cron
* * * * * flock -n /var/lock/qrs-worker.lock php /path/to/query-result-store/bin/worker.php
```

## Docker quick start

### Start services

```bash
docker compose up -d --build
```

- Web UI: `http://127.0.0.1:8080`
- Worker service is included (`worker`) and runs in a loop

### Worker loop interval

Default: `15` seconds (`WORKER_LOOP_SECONDS=15`)

Override at startup:

```bash
WORKER_LOOP_SECONDS=5 docker compose up -d worker
```

### Permission note

If you get write-permission errors for `config.php` or `var/`:

```bash
UID=$(id -u) GID=$(id -g) docker compose up -d --build
```

## Docker mount mapping

From current `docker-compose.yml`:

- Host: project directory (where `docker-compose.yml` exists)
- Container: `/var/www/html`

So, for example:

- Host `./var/qrs.sqlite3`
- Container `/var/www/html/var/qrs.sqlite3`

Apache document root is `/var/www/html/public`.

## Configuration

QRS supports both `config.php` and environment variables.

For containerized deployment, environment variables are recommended.

### Environment variables

- App
  - `QRS_TIMEZONE`
- DB
  - `QRS_DB_DRIVER` (`sqlite` | `mysql` | `pgsql`)
  - `QRS_DB_SQLITE_PATH`
  - `QRS_DB_HOST`
  - `QRS_DB_PORT`
  - `QRS_DB_NAME`
  - `QRS_DB_USER`
  - `QRS_DB_PASSWORD`
  - `QRS_DB_CHARSET` (MySQL)

### Runtime settings (stored in `qrs_sys_meta`)

Configured from Environment UI:

- `worker.global_concurrency`
- `worker.max_run_seconds`
- `worker.max_jobs_per_run`
- `worker.poll_timeout_seconds`
- `worker.poll_interval_millis`
- `worker.running_stale_seconds`
- `worker.retry_max_count`
- `worker.retry_backoff_seconds`
- `runtime.store_raw_redash_payload`
- `runtime.raw_redash_payload_dir`

## UI pages

- `env.php`: environment/runtime/db settings
- `instances.php`: Redash instances
- `datasets.php`: datasets
- `variants.php`: variants (execution definitions)
- `buckets.php`: bucket states
- `logs.php`: worker logs

## Operational notes

- Worker has startup stale-running recovery.
- If `running` rows remain after recovery, worker aborts for safety.
- Retry jobs are processed after non-retry classes.
- Retry uses max count + exponential backoff.

## Internationalization

- Languages: English / Japanese
- Fallback order: selected locale -> `en` -> key id
- Strict mode: `QRS_APP_ENV=development` or `QRS_I18N_STRICT=1`
- Missing-key log: `QRS_I18N_LOG_MISSING=1`

## Related docs

- Japanese requirements: `qrs_requirements_spec.ja.md`
- English requirements: `qrs_requirements_spec.md`
- Japanese implementation plan: `qrs_implementation_plan.ja.md`
- English implementation plan: `qrs_implementation_plan.md`

## License

MIT. See [LICENSE](LICENSE).
