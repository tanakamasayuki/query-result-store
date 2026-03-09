<?php

class QrsDb
{
    public static function connect($config)
    {
        if (!isset($config['db']) || !is_array($config['db'])) {
            throw new Exception('Database configuration is missing.');
        }

        $db = $config['db'];
        $driver = isset($db['driver']) ? strtolower(trim($db['driver'])) : 'sqlite';

        if ($driver === 'sqlite') {
            $path = isset($db['sqlite_path']) ? $db['sqlite_path'] : '';
            if ($path === '') {
                throw new Exception('sqlite_path is required for sqlite driver.');
            }
            $dir = dirname($path);
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0775, true)) {
                    throw new Exception('Failed to create sqlite directory: ' . $dir);
                }
            }
            $dsn = 'sqlite:' . $path;
            $pdo = new PDO($dsn);
        } elseif ($driver === 'mysql') {
            $host = self::value($db, 'host', '127.0.0.1');
            $name = self::value($db, 'name', '');
            $port = self::value($db, 'port', '3306');
            $charset = self::value($db, 'charset', 'utf8');
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=' . $charset;
            $pdo = new PDO($dsn, self::value($db, 'user', ''), self::value($db, 'password', ''));
        } elseif ($driver === 'pgsql' || $driver === 'postgres' || $driver === 'postgresql') {
            $host = self::value($db, 'host', '127.0.0.1');
            $name = self::value($db, 'name', '');
            $port = self::value($db, 'port', '5432');
            $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name;
            $pdo = new PDO($dsn, self::value($db, 'user', ''), self::value($db, 'password', ''));
        } else {
            throw new Exception('Unsupported db driver: ' . $driver);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    public static function isInitialized($pdo)
    {
        try {
            $stmt = $pdo->query('SELECT 1 FROM qrs_sys_instances LIMIT 1');
            if ($stmt) {
                return true;
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }

    public static function initializeSchema($pdo)
    {
        $queries = array(
            "CREATE TABLE IF NOT EXISTS qrs_sys_instances (
                instance_id TEXT PRIMARY KEY,
                base_url TEXT NOT NULL,
                api_key TEXT NOT NULL,
                is_enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS qrs_sys_datasets (
                dataset_id TEXT PRIMARY KEY,
                instance_id TEXT NOT NULL,
                query_id TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS qrs_sys_variants (
                variant_id TEXT PRIMARY KEY,
                dataset_id TEXT NOT NULL,
                mode TEXT NOT NULL,
                parameter_json TEXT NOT NULL,
                schedule_json TEXT NOT NULL,
                column_type_overrides_json TEXT NOT NULL,
                is_enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS qrs_sys_buckets (
                variant_id TEXT NOT NULL,
                bucket_at TEXT NOT NULL,
                status TEXT NOT NULL,
                priority INTEGER NOT NULL DEFAULT 0,
                execute_after TEXT NOT NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                locked_by TEXT,
                locked_at TEXT,
                started_at TEXT,
                finished_at TEXT,
                last_row_count INTEGER,
                last_fetch_seconds REAL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (variant_id, bucket_at)
            )",
            "CREATE TABLE IF NOT EXISTS qrs_sys_logs (
                log_id TEXT PRIMARY KEY,
                variant_id TEXT NOT NULL,
                bucket_at TEXT,
                status TEXT,
                row_count INTEGER,
                fetch_seconds REAL,
                level TEXT NOT NULL,
                message TEXT NOT NULL,
                context_json TEXT,
                created_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS qrs_sys_schema (
                variant_id TEXT PRIMARY KEY,
                storage_table TEXT NOT NULL,
                locked_columns_json TEXT NOT NULL,
                locked_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS qrs_sys_meta (
                meta_key TEXT PRIMARY KEY,
                meta_value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )"
        );

        $pdo->beginTransaction();
        try {
            foreach ($queries as $sql) {
                $pdo->exec($sql);
            }

            $metaStmt = $pdo->prepare('SELECT meta_value FROM qrs_sys_meta WHERE meta_key = :meta_key');
            $metaStmt->execute(array(':meta_key' => 'schema_version'));
            $metaRow = $metaStmt->fetch();
            if (!$metaRow) {
                $now = date('Y-m-d H:i:s');
                $insertMetaStmt = $pdo->prepare(
                    'INSERT INTO qrs_sys_meta (meta_key, meta_value, updated_at) VALUES (:meta_key, :meta_value, :updated_at)'
                );
                $insertMetaStmt->execute(array(
                    ':meta_key' => 'schema_version',
                    ':meta_value' => '1',
                    ':updated_at' => $now,
                ));
            }

            $defaultMetaValues = array(
                'runtime.store_raw_redash_payload' => '0',
                'runtime.raw_redash_payload_dir' => 'var/redash_raw',
                'worker.global_concurrency' => '1',
                'worker.max_run_seconds' => '150',
                'worker.max_jobs_per_run' => '20',
                'worker.poll_timeout_seconds' => '300',
                'worker.poll_interval_millis' => '1000',
                'worker.running_stale_seconds' => '900',
                'worker.retry_max_count' => '3',
                'worker.retry_backoff_seconds' => '60',
            );
            $upsertMetaStmt = $pdo->prepare(
                'INSERT OR IGNORE INTO qrs_sys_meta (meta_key, meta_value, updated_at) VALUES (:meta_key, :meta_value, :updated_at)'
            );
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $upsertMetaStmt = $pdo->prepare(
                    'INSERT IGNORE INTO qrs_sys_meta (meta_key, meta_value, updated_at) VALUES (:meta_key, :meta_value, :updated_at)'
                );
            } elseif ($driver === 'pgsql') {
                $upsertMetaStmt = $pdo->prepare(
                    'INSERT INTO qrs_sys_meta (meta_key, meta_value, updated_at) VALUES (:meta_key, :meta_value, :updated_at) '
                    . 'ON CONFLICT (meta_key) DO NOTHING'
                );
            }
            $now = date('Y-m-d H:i:s');
            foreach ($defaultMetaValues as $metaKey => $metaValue) {
                $upsertMetaStmt->execute(array(
                    ':meta_key' => $metaKey,
                    ':meta_value' => $metaValue,
                    ':updated_at' => $now,
                ));
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function value($arr, $key, $default)
    {
        if (!isset($arr[$key]) || $arr[$key] === '') {
            return $default;
        }
        return $arr[$key];
    }
}
