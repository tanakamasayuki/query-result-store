# QRS (Query Result Store) -- Requirements Specification (Pre‑Implementation)

## 1. Purpose

QRS (Query Result Store) is a system that periodically executes queries
defined in Redash and stores the results so they can be analyzed later
using BI tools such as Redash itself.

QRS focuses on: - reliable scheduled execution - storing query results
as datasets - enabling time‑series accumulation of Redash results

QRS **does not** provide visualization or BI features.

------------------------------------------------------------------------

# 2. Scope

QRS responsibilities:

-   Execute Redash queries via API
-   Store query results in a database
-   Maintain execution history
-   Support snapshot and latest style result storage
-   Provide deterministic scheduling and retry behavior

Out of scope:

-   Visualization or dashboards
-   SQL editing or query authoring
-   Query parameter validation logic
-   Data quality validation
-   Schema migration automation

------------------------------------------------------------------------

# 3. Redash Integration

## 3.1 Redash Instance

QRS supports multiple Redash instances.

Each instance contains:

-   `instance_id`
-   `base_url`
-   `api_key` (user/service account)
-   enabled/disabled flag

### Connection Test

Connection tests can be executed immediately (GUI-triggered).

Purpose: - validate API connectivity - validate authentication - confirm
instance availability

------------------------------------------------------------------------

# 4. Dataset

A **Dataset** represents a single Redash query.

Dataset contains:

-   `dataset_id`
-   `instance_id`
-   `query_id`

Dataset creation methods:

1.  Redash Query URL
2.  Instance selection + Query ID

Example URL:

    http://redash.example.com/queries/123

QRS extracts `query_id` automatically.

------------------------------------------------------------------------

# 5. Variant

A **Variant** defines how a Dataset is executed and stored.

Characteristics:

-   immutable `variant_id`
-   parameter mapping
-   optional column type override settings
-   storage modes
-   scheduling settings

Variants can be enabled or disabled.

Column type override operating rules:

-   overrides can be configured **only when creating a new Variant**
-   Variant edit screen is read-only for overrides
-   to change overrides, copy an existing Variant and create a new one
-   overrides are applied when the storage table is created on first successful execution, then treated as schema-locked

------------------------------------------------------------------------

# 6. Execution Modes

Variants support the following modes:

### snapshot

Time‑series storage using time buckets.

### latest

Stores only the latest dataset version.

### oneshot

Single execution with no automatic updates.

------------------------------------------------------------------------

# 7. Bucket Strategy (snapshot mode)

Snapshot variants define:

Required:

-   `interval`
-   `lag`
-   `lookback`

Optional:

-   `start_at`

### Catch‑up Behavior

If execution is delayed, QRS **must catch up** by executing missing
buckets until current time.

------------------------------------------------------------------------

# 8. Parameter Assignment

Parameters are assigned per variant.

Supported value sources:

-   Fixed value
-   BucketAt
-   Now
-   Future extensible expressions

### Default Values

When creating a dataset, parameters from the query URL are used as
default **Fixed** values.

Example:

    ...?country=JP

→ `country = Fixed("JP")`

------------------------------------------------------------------------

# 9. Execution Model

GUI operations do not directly run jobs.

Instead they create/update **bucket state records** (`qrs_sys_buckets`)
processed by a background dispatcher.

Dispatcher runs periodically (example: every minute).

### Initial execution behavior

-   concurrency = 1
-   sequential execution

Future implementations may support configurable concurrency.

## 9.1 Worker Runtime Controls (Operational Settings)

Worker behavior is controlled by operational settings stored in `qrs_sys_meta`.

Primary settings:

-   `worker.global_concurrency`
    -   maximum concurrent executions (used when parallel execution is enabled)
    -   recommended initial value: `1`
-   `worker.max_run_seconds`
    -   maximum seconds to keep claiming new jobs in a single worker run
    -   recommended initial value: `150` (assuming every-minute cron + flock)
-   `worker.max_jobs_per_run`
    -   maximum number of jobs processed in a single worker run
    -   recommended initial value: `20`
-   `worker.poll_timeout_seconds`
    -   timeout for waiting Redash job completion
    -   recommended initial value: `300`
-   `worker.poll_interval_millis`
    -   polling interval for Redash job status (milliseconds)
    -   recommended initial value: `1000`
-   `worker.running_stale_seconds`
    -   seconds before reclaiming tasks left in `running` state as `queued_retry`
    -   recommended initial value: `900`
-   `worker.retry_max_count`
    -   maximum automatic retry count (how many times to requeue after first failure)
    -   recommended initial value: `3`
-   `worker.retry_backoff_seconds`
    -   base wait seconds for automatic retry (used in exponential backoff)
    -   recommended initial value: `60`

Termination behavior:

-   after `max_run_seconds`, Worker **stops claiming new jobs**
-   jobs already moved to `running` are completed before process exit

`running` recovery:

-   on worker startup, records in `running` with `locked_at` older than `running_stale_seconds` are moved to `queued_retry`
-   clear `locked_by`, `locked_at`, `started_at`, and record an automatic recovery reason in `last_error`
-   if any `running` rows still remain after recovery, Worker exits with error and does not start dispatch/execute

Default rationale:

-   with `poll_timeout_seconds=300`, `running_stale_seconds=900` (3x) is a safe default to avoid false recovery
-   if faster recovery is preferred, `600` (2x) is also a practical option

Constraints for parallel execution:

-   do not run the same `variant_id` concurrently (`per_variant_concurrency=1`)
-   do not run the same `dataset_id` (same storage table) concurrently (`per_dataset_concurrency=1`)

Note:

-   `worker.dispatch_target_limit_per_variant` is not adopted for now (lookback target visibility is handled in Variant UI preview)

------------------------------------------------------------------------

# 10. Execution Priority

Worker processes tasks in the following order:

1.  Manual single executions
2.  Scheduled runs
3.  Backfill executions
4.  Retry of failed runs (always last)

Priorities are managed with fixed numeric values (higher number = higher priority):

-   `queued_scheduled`: `400`
-   `queued_manual`: `200`
-   `queued_backfill` (including lookback): `100`
-   `queued_retry`: `50` (fixed as the last class)

Execution order within the same priority:

-   ascending `execute_at` / `execute_after` (older time first)
-   if candidates still tie on both priority and time, final ordering is defined by Worker implementation

Notes:

-   implementation compares queue class order first (`manual/scheduled/backfill/retry`), then numeric `priority`
-   therefore `queued_retry` does not run before non-retry tasks even if its numeric priority is higher

------------------------------------------------------------------------

# 11. Retry Policy

Failed runs:

-   are retried automatically
-   have a configurable retry limit
-   stop retrying once the limit is reached
-   set delayed `execute_after` before retrying

Retry wait uses exponential backoff:

-   next wait seconds = `retry_backoff_seconds * 2^(retry_attempt - 1)`
-   example with `60`: `60s, 120s, 240s ...`

Users may manually trigger re-execution.

------------------------------------------------------------------------

# 11.1 Time Zone and Timestamp Policy

QRS uses **local time in the configured system time zone (not UTC)** for internal timestamps.

-   configure `timezone_id` at setup (example: `Asia/Tokyo`)
-   `timezone_id` is immutable after go-live (except maintenance procedure)
-   scheduling calculations (`bucket_at`, `execute_at`, `execute_after`) use `timezone_id`
-   audit/metadata timestamps (`created_at`, `updated_at`, `start_time`, `end_time`, `qrs_ingested_at`, `qrs_bucket_at`) are stored using `timezone_id`

Notes:

-   changing time zone affects bucket boundaries and duplicate detection, so it is prohibited in normal operations
-   when using DST-enabled zones, Worker implementation must handle duplicated/missing boundary times

------------------------------------------------------------------------

# 12. Storage Behavior

## latest / oneshot

Storage strategy:

    DELETE all rows
    INSERT new rows

## snapshot

Storage strategy:

    DELETE rows WHERE qrs_bucket_at = bucket_at
    INSERT new rows

Snapshot result sets may contain **0..N rows**.

------------------------------------------------------------------------

# 13. Metadata Columns

QRS adds internal metadata columns using the `qrs_` prefix.

Examples:

    qrs_bucket_at
    qrs_ingested_at
    qrs_run_id

Prefix prevents collision with user query columns.

------------------------------------------------------------------------

# 14. Schema Handling

### Column Source

Column names come from:

    columns.name

Redash `friendly_name` is ignored for schema purposes.

### Schema Lock

The schema is fixed at the **first successful dataset execution**.

### Allowed changes

-   column order changes

### Disallowed changes (error)

-   column addition
-   column removal
-   column rename

If schema changes occur, execution fails and is recorded in Run logs.

------------------------------------------------------------------------

# 15. Column Types

Redash column types are **not trusted as authoritative types**.

QRS:

-   reads Redash column types
-   but storage typing is implementation dependent

Example: - SQLite may store everything dynamically.

## 15.1 AUTO Type Mapping Policy (Draft)

When `AUTO` is selected, QRS normalizes Redash column type strings and maps them as follows:

| Redash type (normalized) | SQLite | MySQL | PostgreSQL |
|---|---|---|---|
| `integer` / `bigint` / `long` | `INTEGER` | `BIGINT` | `BIGINT` |
| `float` / `double` / `real` | `REAL` | `DOUBLE` | `DOUBLE PRECISION` |
| `decimal` / `numeric` | `NUMERIC` | `DECIMAL(38,10)` | `NUMERIC` |
| `boolean` / `bool` | `INTEGER` (0/1) | `TINYINT(1)` | `BOOLEAN` |
| `date` | `TEXT` (`YYYY-MM-DD`) | `DATE` | `DATE` |
| `datetime` / `timestamp` | `TEXT` (`YYYY-MM-DD HH:MM:SS`) | `DATETIME(6)` | `TIMESTAMP` |
| `time` | `TEXT` (`HH:MM:SS`) | `TIME` | `TIME` |
| `json` / `object` / `array` | `TEXT` (JSON string) | `JSON` (or `LONGTEXT` if unavailable) | `JSONB` |
| `string` / `text` / `unknown` / others | `TEXT` | `TEXT` | `TEXT` |

Notes:

-   Unknown/unrecognized types should safely fall back to `TEXT`
-   Actual storage types are finalized when the table is first created on a successful run, then schema-locked
-   This is a target spec for future AUTO expansion; current implementation may still treat `AUTO` as `TEXT`

------------------------------------------------------------------------

# 16. Execution History (Run Log)

Each execution stores:

-   run_id
-   variant_id
-   bucket_at (snapshot only)
-   status
-   start_time
-   end_time
-   row_count (optional)
-   error message (if failed)

Purpose:

-   auditing
-   retry logic
-   catch‑up detection

------------------------------------------------------------------------

# 17. Immediate Execution Exceptions

The following operations may execute immediately (GUI triggered):

-   Redash connection test
-   Dataset query verification (creation step)

These executions do not enter the regular bucket-state scheduling flow.

------------------------------------------------------------------------

# 18. Table Naming

System table naming convention (QRS internal metadata):

    qrs_sys_instances
    qrs_sys_datasets
    qrs_sys_variants
    qrs_sys_buckets
    qrs_sys_logs
    qrs_sys_schema

Core columns (minimum):

-   `qrs_sys_buckets`
    - `variant_id`, `bucket_at` (composite primary key)
    - `status`, `priority`, `execute_after`
    - `attempt_count`, `last_error`
    - `last_row_count`, `last_fetch_seconds` (to show latest row count / fetch seconds in list views)
    - `started_at`, `finished_at`, `created_at`, `updated_at`
-   `qrs_sys_logs`
    - `log_id`
    - `variant_id`, `bucket_at`
    - `status`, `row_count`, `fetch_seconds`
    - `level`, `message`, `context_json`, `created_at`
-   `qrs_sys_schema`
    - `variant_id`
    - `storage_table`
    - `locked_columns_json`, `locked_at`, `updated_at`

Stored data table naming convention (for BI access):

    qrs_d_<dataset_id>_<variant_id>

Notes:

-   `qrs_sys_` is a fixed prefix to simplify privilege separation and operations.
-   `qrs_d_` keeps data table names short.
-   One Variant uses only one stored-data table (mode differences are expressed by storage behavior).

Physical schema implementation is environment dependent.

------------------------------------------------------------------------

# 19. Backfill

Backfill allows re-executing historical buckets.

Characteristics:

-   lowest priority
-   large operations may generate multiple bucket executions

------------------------------------------------------------------------

# 20. Non‑Goals

QRS intentionally does **not** implement:

-   BI dashboards
-   visualization tools
-   query builders
-   SQL rewriting
-   automatic schema migrations
-   Redash query discovery UI

------------------------------------------------------------------------

# 21. Expected Usage Pattern

1.  Create Redash query
2.  Create Dataset in QRS
3.  Configure Variant
4.  Schedule execution
5.  Use resulting tables in BI tools

------------------------------------------------------------------------

# End of Requirements

See `qrs_implementation_plan.md` for implementation guidance.
