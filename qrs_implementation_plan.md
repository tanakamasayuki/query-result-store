# QRS Implementation Plan (PHP / Minimal Dependencies)

## 1. Purpose

This document defines the implementation approach (How) for realizing `qrs_requirements_spec.ja.md`.
Within the scope of the requirements (What), it prioritizes maintainability, portability, and operational simplicity.

------------------------------------------------------------------------

## 2. Core Principles

- Separate UI and Batch components
- Implement with plain PHP
- Minimize external library dependencies
- Prefer coding style that runs on older PHP versions
- Support SQLite / MySQL / PostgreSQL

------------------------------------------------------------------------

## 3. Execution Architecture

### 3.1 UI

- Role: configuration management, listing, and manual execution request registration
- Do not execute jobs directly via Web requests
- Limit UI execution actions to registering requests only

### 3.2 Batch

- Role: ingest requests, create runs, execute runs, handle retries, and catch-up
- Periodically invoke a single CLI script from cron
- Do not implement duplicate-process control inside scripts
- Assume operational-side duplicate prevention via `flock`
- Start with a single-process model; keep the structure open for future internal process split

Example:

    * * * * * flock -n /var/lock/qrs-worker.lock php /path/bin/worker.php

------------------------------------------------------------------------

## 4. Recommended Directory Layout

    public/index.php
    bin/worker.php
    lib/Config.php
    lib/Db.php
    lib/RedashClient.php
    lib/SchemaGuard.php
    lib/StorageAdapter/
    lib/Repository/
    lib/Service/

------------------------------------------------------------------------

## 5. PHP Policy

- Prioritize compatibility (target style: compatible with PHP 5.3+)
- Use `PDO` as the common DB interface
- Keep required extensions minimal (`pdo_*`, `curl`, `json`)
- Ensure it can run without Composer

------------------------------------------------------------------------

## 6. DB Support Policy

- Isolate DB-specific differences in the Adapter layer
- Start implementation with an SQLite PoC, then add MySQL / PostgreSQL in stages
- Difference targets:
  - DDL
  - identifier quoting
  - metadata retrieval
  - transaction behavior
- Prepare a shortened naming rule (with hash) for logical table names to avoid length limits

------------------------------------------------------------------------

## 7. Execution Control Policy

- Strictly enforce state transitions: `queued -> running -> success|failed`
- Run `snapshot` DELETE/INSERT in a single transaction
- Implement retry limit, priority, and catch-up exactly as defined in requirements

------------------------------------------------------------------------

## 8. Non-Goals

- Do not assume framework adoption
- Do not introduce advanced job infrastructure (e.g., message queues) in the initial phase
- Do not implement automatic schema migration features

------------------------------------------------------------------------

## 9. Relationship to Requirements

This document is a supplemental implementation guide.
The source of truth for requirements is `qrs_requirements_spec.ja.md`.

------------------------------------------------------------------------

# End of Implementation Plan
