#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Repository/InstanceRepository.php';
require_once dirname(__DIR__) . '/lib/Repository/DatasetRepository.php';
require_once dirname(__DIR__) . '/lib/Repository/VariantRepository.php';
require_once dirname(__DIR__) . '/lib/RedashClient.php';

function qrs_worker_log_event($pdo, $variantId, $bucketAt, $level, $message, $status, $rowCount, $fetchSeconds, $context)
{
    $logId = uniqid('log_', true);
    $ctx = '';
    if (is_array($context)) {
        $json = json_encode($context);
        if (is_string($json)) {
            $ctx = $json;
        }
    }

    $fetchSecondsValue = $fetchSeconds;
    if ($fetchSecondsValue !== null && is_numeric($fetchSecondsValue)) {
        $fetchSecondsValue = (float)$fetchSecondsValue;
        if ($fetchSecondsValue < 0) {
            $fetchSecondsValue = 0.0;
        }
    }

    $sql = 'INSERT INTO qrs_sys_logs (log_id, variant_id, bucket_at, status, row_count, fetch_seconds, level, message, context_json, created_at) '
        . 'VALUES (:log_id, :variant_id, :bucket_at, :status, :row_count, :fetch_seconds, :level, :message, :context_json, :created_at)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':log_id' => $logId,
        ':variant_id' => $variantId,
        ':bucket_at' => $bucketAt,
        ':status' => $status,
        ':row_count' => $rowCount,
        ':fetch_seconds' => $fetchSecondsValue,
        ':level' => $level,
        ':message' => $message,
        ':context_json' => $ctx,
        ':created_at' => date('Y-m-d H:i:s'),
    ));
}

function qrs_worker_quote_identifier($pdo, $name)
{
    $driver = '';
    try {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Exception $e) {
        $driver = '';
    }
    if ($driver === 'mysql') {
        return '`' . str_replace('`', '``', (string)$name) . '`';
    }
    return '"' . str_replace('"', '""', (string)$name) . '"';
}

function qrs_worker_driver_name($pdo)
{
    try {
        return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Exception $e) {
        return '';
    }
}

function qrs_worker_storage_table_name($datasetId, $variantId)
{
    $base = 'qrs_d_' . (string)$datasetId . '_' . (string)$variantId;
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $base);
    if (!is_string($safe) || $safe === '') {
        $safe = 'qrs_d_' . substr(sha1($base), 0, 16);
    }
    if (strlen($safe) > 60) {
        $safe = substr($safe, 0, 44) . '_' . substr(sha1($safe), 0, 15);
    }
    return $safe;
}

function qrs_worker_extract_columns_rows($queryResult)
{
    $columns = array();
    $rows = array();
    if (!is_array($queryResult) || !isset($queryResult['data']) || !is_array($queryResult['data'])) {
        return array($columns, $rows);
    }

    $data = $queryResult['data'];
    if (isset($data['columns']) && is_array($data['columns'])) {
        $i = 0;
        while ($i < count($data['columns'])) {
            $c = $data['columns'][$i];
            if (is_array($c) && isset($c['name'])) {
                $name = trim((string)$c['name']);
                if ($name !== '') {
                    $columns[] = $name;
                }
            }
            $i++;
        }
    }
    if (isset($data['rows']) && is_array($data['rows'])) {
        $rows = $data['rows'];
    }

    if (count($columns) === 0 && count($rows) > 0 && is_array($rows[0])) {
        foreach ($rows[0] as $k => $v) {
            $name = trim((string)$k);
            if ($name !== '') {
                $columns[] = $name;
            }
        }
    }

    $uniq = array();
    $normalized = array();
    $j = 0;
    while ($j < count($columns)) {
        $n = (string)$columns[$j];
        if (!isset($uniq[$n])) {
            $uniq[$n] = true;
            $normalized[] = $n;
        }
        $j++;
    }

    return array($normalized, $rows);
}

function qrs_worker_extract_column_type_map($queryResult)
{
    $map = array();
    if (!is_array($queryResult) || !isset($queryResult['data']) || !is_array($queryResult['data'])) {
        return $map;
    }
    $data = $queryResult['data'];
    if (!isset($data['columns']) || !is_array($data['columns'])) {
        return $map;
    }
    $i = 0;
    while ($i < count($data['columns'])) {
        $col = $data['columns'][$i];
        if (is_array($col) && isset($col['name'])) {
            $name = trim((string)$col['name']);
            if ($name !== '' && !isset($map[$name])) {
                $map[$name] = isset($col['type']) ? trim((string)$col['type']) : '';
            }
        }
        $i++;
    }
    return $map;
}

function qrs_worker_decode_column_overrides($jsonText)
{
    $result = array();
    if (!function_exists('json_decode')) {
        return $result;
    }
    $data = json_decode((string)$jsonText, true);
    if (!is_array($data)) {
        return $result;
    }
    $allowed = QrsColumnTypeMapper::overrideTypes();
    foreach ($data as $k => $v) {
        $name = trim((string)$k);
        $type = strtoupper(trim((string)$v));
        if ($name === '' || $type === '' || !in_array($type, $allowed, true)) {
            continue;
        }
        $result[$name] = $type;
    }
    return $result;
}

function qrs_worker_ensure_schema_and_table($pdo, $variantId, $tableName, $columns, $overrideMap, $detectedTypeMap)
{
    $selectStmt = $pdo->prepare('SELECT storage_table, locked_columns_json FROM qrs_sys_schema WHERE variant_id = :variant_id');
    $selectStmt->execute(array(':variant_id' => $variantId));
    $row = $selectStmt->fetch();

    $sortedCurrent = $columns;
    sort($sortedCurrent);

    if ($row) {
        $existingTable = isset($row['storage_table']) ? (string)$row['storage_table'] : '';
        $existingCols = json_decode((string)$row['locked_columns_json'], true);
        if (!is_array($existingCols)) {
            throw new Exception('Invalid schema lock definition.');
        }
        $sortedExisting = $existingCols;
        sort($sortedExisting);
        if (count($sortedExisting) !== count($sortedCurrent) || $sortedExisting !== $sortedCurrent) {
            throw new Exception('Schema mismatch detected for variant_id=' . $variantId);
        }
        if ($existingTable !== '') {
            $tableName = $existingTable;
        }
    } else {
        $insertStmt = $pdo->prepare(
            'INSERT INTO qrs_sys_schema (variant_id, storage_table, locked_columns_json, locked_at, updated_at) VALUES (:variant_id, :storage_table, :locked_columns_json, :locked_at, :updated_at)'
        );
        $now = date('Y-m-d H:i:s');
        $insertStmt->execute(array(
            ':variant_id' => $variantId,
            ':storage_table' => $tableName,
            ':locked_columns_json' => json_encode($columns),
            ':locked_at' => $now,
            ':updated_at' => $now,
        ));
    }

    $defs = array();
    $defs[] = qrs_worker_quote_identifier($pdo, 'qrs_run_id') . ' TEXT NOT NULL';
    $defs[] = qrs_worker_quote_identifier($pdo, 'qrs_bucket_at') . ' TEXT';
    $defs[] = qrs_worker_quote_identifier($pdo, 'qrs_ingested_at') . ' TEXT NOT NULL';
    $driver = qrs_worker_driver_name($pdo);
    $i = 0;
    while ($i < count($columns)) {
        $colName = $columns[$i];
        $overrideType = isset($overrideMap[$colName]) ? $overrideMap[$colName] : 'AUTO';
        $redashType = isset($detectedTypeMap[$colName]) ? $detectedTypeMap[$colName] : '';
        $sqlType = QrsColumnTypeMapper::resolveStorageType($driver, $redashType, $overrideType);
        $defs[] = qrs_worker_quote_identifier($pdo, $colName) . ' ' . $sqlType;
        $i++;
    }
    $sql = 'CREATE TABLE IF NOT EXISTS ' . qrs_worker_quote_identifier($pdo, $tableName) . ' (' . implode(', ', $defs) . ')';
    $pdo->exec($sql);

    return $tableName;
}

function qrs_worker_save_rows($pdo, $tableName, $mode, $bucketAt, $runId, $rows, $columns)
{
    $tableSql = qrs_worker_quote_identifier($pdo, $tableName);
    if ($mode === 'snapshot') {
        $deleteSql = 'DELETE FROM ' . $tableSql . ' WHERE ' . qrs_worker_quote_identifier($pdo, 'qrs_bucket_at') . ' = :bucket_at';
        $deleteStmt = $pdo->prepare($deleteSql);
        $deleteStmt->execute(array(':bucket_at' => $bucketAt));
    } else {
        $pdo->exec('DELETE FROM ' . $tableSql);
    }

    if (count($rows) === 0) {
        return 0;
    }

    $insertCols = array('qrs_run_id', 'qrs_bucket_at', 'qrs_ingested_at');
    $i = 0;
    while ($i < count($columns)) {
        $insertCols[] = $columns[$i];
        $i++;
    }

    $colSql = array();
    $paramSql = array();
    $j = 0;
    while ($j < count($insertCols)) {
        $colSql[] = qrs_worker_quote_identifier($pdo, $insertCols[$j]);
        $paramSql[] = '?';
        $j++;
    }

    $insertSql = 'INSERT INTO ' . $tableSql . ' (' . implode(', ', $colSql) . ') VALUES (' . implode(', ', $paramSql) . ')';
    $insertStmt = $pdo->prepare($insertSql);
    $ingestedAt = date('Y-m-d H:i:s');
    $count = 0;

    $r = 0;
    while ($r < count($rows)) {
        $row = $rows[$r];
        if (!is_array($row)) {
            $row = array();
        }
        $params = array();
        $params[] = $runId;
        $params[] = ($mode === 'snapshot') ? $bucketAt : null;
        $params[] = $ingestedAt;
        $c = 0;
        while ($c < count($columns)) {
            $colName = $columns[$c];
            $v = isset($row[$colName]) ? $row[$colName] : null;
            if (is_array($v) || is_object($v)) {
                $encoded = json_encode($v);
                $v = is_string($encoded) ? $encoded : '';
            } elseif ($v !== null && !is_scalar($v)) {
                $v = (string)$v;
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif ($v !== null) {
                $v = (string)$v;
            }
            $params[] = $v;
            $c++;
        }
        $insertStmt->execute($params);
        $count++;
        $r++;
    }

    return $count;
}

function qrs_worker_find_due_buckets($pdo, $limit)
{
    $n = (int)$limit;
    if ($n <= 0) {
        $n = 20;
    }
    $now = date('Y-m-d H:i:s');
    $sql = 'SELECT variant_id, bucket_at, status, priority, execute_after, attempt_count FROM qrs_sys_buckets '
        . 'WHERE status IN (\'queued_scheduled\', \'queued_retry\', \'queued_manual\', \'queued_backfill\') '
        . 'AND execute_after <= :now '
        . 'ORDER BY CASE status '
        . '  WHEN \'queued_manual\' THEN 1 '
        . '  WHEN \'queued_scheduled\' THEN 2 '
        . '  WHEN \'queued_backfill\' THEN 3 '
        . '  WHEN \'queued_retry\' THEN 4 '
        . '  ELSE 9 END ASC, '
        . 'priority DESC, execute_after ASC, bucket_at ASC '
        . 'LIMIT ' . $n;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':now' => $now));
    return $stmt->fetchAll();
}

function qrs_worker_find_due_buckets_claimable($pdo, $limit)
{
    $n = (int)$limit;
    if ($n <= 0) {
        $n = 20;
    }
    $now = date('Y-m-d H:i:s');
    $sql = 'SELECT b.variant_id, b.bucket_at, b.status, b.priority, b.execute_after, b.attempt_count, v.dataset_id '
        . 'FROM qrs_sys_buckets b '
        . 'INNER JOIN qrs_sys_variants v ON v.variant_id = b.variant_id '
        . 'WHERE b.status IN (\'queued_scheduled\', \'queued_retry\', \'queued_manual\', \'queued_backfill\') '
        . 'AND b.execute_after <= :now '
        . 'AND NOT EXISTS ('
        . '  SELECT 1 FROM qrs_sys_buckets rb '
        . '  INNER JOIN qrs_sys_variants rv ON rv.variant_id = rb.variant_id '
        . '  WHERE rb.status = \'running\' AND rv.dataset_id = v.dataset_id'
        . ') '
        . 'ORDER BY CASE b.status '
        . '  WHEN \'queued_manual\' THEN 1 '
        . '  WHEN \'queued_scheduled\' THEN 2 '
        . '  WHEN \'queued_backfill\' THEN 3 '
        . '  WHEN \'queued_retry\' THEN 4 '
        . '  ELSE 9 END ASC, '
        . 'b.priority DESC, b.execute_after ASC, b.bucket_at ASC '
        . 'LIMIT ' . $n;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':now' => $now));
    return $stmt->fetchAll();
}

function qrs_worker_mark_running($pdo, $variantId, $bucketAt, $workerId)
{
    $now = date('Y-m-d H:i:s');
    $sql = 'UPDATE qrs_sys_buckets SET status = :next_status, locked_by = :locked_by, locked_at = :locked_at, started_at = :started_at, '
        . 'attempt_count = attempt_count + 1, updated_at = :updated_at '
        . 'WHERE variant_id = :variant_id AND bucket_at = :bucket_at '
        . 'AND execute_after <= :now '
        . 'AND NOT EXISTS ('
        . '  SELECT 1 FROM qrs_sys_buckets rb '
        . '  INNER JOIN qrs_sys_variants rv ON rv.variant_id = rb.variant_id '
        . '  WHERE rb.status = \'running\' '
        . '    AND rv.dataset_id = (SELECT dataset_id FROM qrs_sys_variants WHERE variant_id = :variant_id_for_dataset)'
        . ') '
        . 'AND status IN (\'queued_scheduled\', \'queued_retry\', \'queued_manual\', \'queued_backfill\')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':next_status' => 'running',
        ':locked_by' => $workerId,
        ':locked_at' => $now,
        ':started_at' => $now,
        ':updated_at' => $now,
        ':variant_id' => $variantId,
        ':bucket_at' => $bucketAt,
        ':now' => $now,
        ':variant_id_for_dataset' => $variantId,
    ));
    return ($stmt->rowCount() > 0);
}

function qrs_worker_claim_one_due_bucket($pdo, $workerId, $limit)
{
    $candidates = qrs_worker_find_due_buckets_claimable($pdo, $limit);
    $i = 0;
    while ($i < count($candidates)) {
        $c = $candidates[$i];
        $variantId = isset($c['variant_id']) ? (string)$c['variant_id'] : '';
        $bucketAt = isset($c['bucket_at']) ? (string)$c['bucket_at'] : '';
        if ($variantId !== '' && $bucketAt !== '') {
            if (qrs_worker_mark_running($pdo, $variantId, $bucketAt, $workerId)) {
                return $c;
            }
        }
        $i++;
    }
    return null;
}

function qrs_worker_mark_success($pdo, $variantId, $bucketAt, $rowCount, $fetchSeconds)
{
    $fetchSecondsValue = $fetchSeconds;
    if (is_numeric($fetchSecondsValue)) {
        $fetchSecondsValue = (float)$fetchSecondsValue;
        if ($fetchSecondsValue < 0) {
            $fetchSecondsValue = 0.0;
        }
    } else {
        $fetchSecondsValue = 0.0;
    }

    $now = date('Y-m-d H:i:s');
    $sql = 'UPDATE qrs_sys_buckets SET status = :status, last_error = NULL, finished_at = :finished_at, '
        . 'last_row_count = :last_row_count, last_fetch_seconds = :last_fetch_seconds, updated_at = :updated_at '
        . 'WHERE variant_id = :variant_id AND bucket_at = :bucket_at';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':status' => 'success',
        ':finished_at' => $now,
        ':last_row_count' => $rowCount,
        ':last_fetch_seconds' => $fetchSecondsValue,
        ':updated_at' => $now,
        ':variant_id' => $variantId,
        ':bucket_at' => $bucketAt,
    ));
}

function qrs_worker_retry_delay_seconds($attemptCount, $baseSeconds)
{
    $attempt = (int)$attemptCount;
    if ($attempt < 1) {
        $attempt = 1;
    }
    $base = (int)$baseSeconds;
    if ($base < 1) {
        $base = 60;
    }
    $delay = (int)round($base * pow(2, $attempt - 1));
    if ($delay < 1) {
        $delay = 1;
    }
    if ($delay > 604800) {
        $delay = 604800;
    }
    return $delay;
}

function qrs_worker_mark_failed($pdo, $variantId, $bucketAt, $errorMessage, $retryMaxCount, $retryBackoffSeconds)
{
    $selectSql = 'SELECT attempt_count FROM qrs_sys_buckets WHERE variant_id = :variant_id AND bucket_at = :bucket_at';
    $selectStmt = $pdo->prepare($selectSql);
    $selectStmt->execute(array(
        ':variant_id' => $variantId,
        ':bucket_at' => $bucketAt,
    ));
    $row = $selectStmt->fetch();
    $attemptCount = ($row && isset($row['attempt_count'])) ? (int)$row['attempt_count'] : 1;

    $retryMax = (int)$retryMaxCount;
    if ($retryMax < 0) {
        $retryMax = 0;
    }

    $isRetry = ($attemptCount <= $retryMax);
    $now = date('Y-m-d H:i:s');
    $retryDelay = qrs_worker_retry_delay_seconds($attemptCount, $retryBackoffSeconds);
    $executeAfter = $isRetry ? date('Y-m-d H:i:s', time() + $retryDelay) : $now;
    $status = $isRetry ? 'queued_retry' : 'failed';

    $sql = 'UPDATE qrs_sys_buckets SET status = :status, execute_after = :execute_after, '
        . 'locked_by = NULL, locked_at = NULL, last_error = :last_error, finished_at = :finished_at, updated_at = :updated_at '
        . 'WHERE variant_id = :variant_id AND bucket_at = :bucket_at';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':status' => $status,
        ':execute_after' => $executeAfter,
        ':last_error' => $errorMessage,
        ':finished_at' => $now,
        ':updated_at' => $now,
        ':variant_id' => $variantId,
        ':bucket_at' => $bucketAt,
    ));
    return array(
        'status' => $status,
        'attempt_count' => $attemptCount,
        'retry_delay_seconds' => $isRetry ? $retryDelay : 0,
        'execute_after' => $executeAfter,
    );
}

function qrs_worker_recover_stale_running($pdo, $staleSeconds)
{
    $sec = (int)$staleSeconds;
    if ($sec <= 0) {
        $sec = 900;
    }

    $nowTs = time();
    $cutoffTs = $nowTs - $sec;
    $cutoff = date('Y-m-d H:i:s', $cutoffTs);
    $now = date('Y-m-d H:i:s', $nowTs);

    $selectSql = 'SELECT variant_id, bucket_at, locked_at FROM qrs_sys_buckets '
        . 'WHERE status = :status AND locked_at IS NOT NULL AND locked_at <= :cutoff';
    $selectStmt = $pdo->prepare($selectSql);
    $selectStmt->execute(array(
        ':status' => 'running',
        ':cutoff' => $cutoff,
    ));
    $rows = $selectStmt->fetchAll();

    if (!is_array($rows) || count($rows) === 0) {
        return 0;
    }

    $updateSql = 'UPDATE qrs_sys_buckets '
        . 'SET status = :status, execute_after = :execute_after, '
        . 'locked_by = NULL, locked_at = NULL, started_at = NULL, finished_at = NULL, '
        . 'last_error = :last_error, updated_at = :updated_at '
        . 'WHERE variant_id = :variant_id AND bucket_at = :bucket_at AND status = :running_status';
    $updateStmt = $pdo->prepare($updateSql);

    $count = 0;
    $i = 0;
    while ($i < count($rows)) {
        $row = $rows[$i];
        $variantId = isset($row['variant_id']) ? (string)$row['variant_id'] : '';
        $bucketAt = isset($row['bucket_at']) ? (string)$row['bucket_at'] : '';
        $lockedAt = isset($row['locked_at']) ? (string)$row['locked_at'] : '';
        if ($variantId !== '' && $bucketAt !== '') {
            $reason = 'auto recovered stale running task';
            if ($lockedAt !== '') {
                $reason .= ' (locked_at=' . $lockedAt . ')';
            }
            $updateStmt->execute(array(
                ':status' => 'queued_retry',
                ':execute_after' => $now,
                ':last_error' => $reason,
                ':updated_at' => $now,
                ':variant_id' => $variantId,
                ':bucket_at' => $bucketAt,
                ':running_status' => 'running',
            ));
            if ($updateStmt->rowCount() > 0) {
                $count++;
            }
        }
        $i++;
    }
    return $count;
}

function qrs_worker_count_running_buckets($pdo)
{
    $sql = 'SELECT COUNT(*) AS c FROM qrs_sys_buckets WHERE status = :status';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':status' => 'running'));
    $row = $stmt->fetch();
    if (!$row || !isset($row['c'])) {
        return 0;
    }
    return (int)$row['c'];
}

function qrs_worker_new_run_id()
{
    $rand = '';
    if (function_exists('random_bytes')) {
        try {
            $rand = bin2hex(random_bytes(3));
        } catch (Exception $e) {
            $rand = '';
        }
    }
    if ($rand === '') {
        $rand = substr(sha1(uniqid('', true)), 0, 6);
    }
    return 'run_' . date('Ymd_His') . '_' . $rand;
}

function qrs_worker_get_meta($pdo, $key, $defaultValue)
{
    $sql = 'SELECT meta_value FROM qrs_sys_meta WHERE meta_key = :meta_key';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':meta_key' => $key));
    $row = $stmt->fetch();
    if (!$row || !isset($row['meta_value'])) {
        return $defaultValue;
    }
    return (string)$row['meta_value'];
}

function qrs_worker_get_meta_int($pdo, $key, $defaultValue, $minValue, $maxValue)
{
    $raw = qrs_worker_get_meta($pdo, $key, (string)$defaultValue);
    $v = (int)$raw;
    $min = (int)$minValue;
    $max = (int)$maxValue;
    if ($v < $min) {
        $v = $min;
    }
    if ($max > 0 && $v > $max) {
        $v = $max;
    }
    return $v;
}

function qrs_worker_resolve_store_dir($rootDir, $dirSetting)
{
    $dir = trim((string)$dirSetting);
    if ($dir === '') {
        $dir = 'var/redash_raw';
    }
    if (preg_match('/^(\/|[A-Za-z]:[\\\\\/])/', $dir)) {
        return $dir;
    }
    return rtrim($rootDir, '/\\') . '/' . ltrim($dir, '/\\');
}

function qrs_worker_write_raw_payload($baseDir, $runId, $variantId, $bucketAt, $rawSource, $rawJson)
{
    $runPart = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$runId);
    if (!is_string($runPart) || $runPart === '') {
        $runPart = 'run_' . date('Ymd_His');
    }
    $variantPart = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$variantId);
    if (!is_string($variantPart) || $variantPart === '') {
        $variantPart = 'variant';
    }
    $bucketPart = preg_replace('/[^0-9]/', '', (string)$bucketAt);
    if (!is_string($bucketPart) || $bucketPart === '') {
        $bucketPart = date('YmdHis');
    }
    $sourcePart = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$rawSource);
    if (!is_string($sourcePart) || $sourcePart === '') {
        $sourcePart = 'raw';
    }

    $targetDir = rtrim($baseDir, '/\\') . '/' . $variantPart;
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0775, true)) {
            throw new Exception('Failed to create raw payload directory: ' . $targetDir);
        }
    }
    $name = $runPart . '_' . $variantPart . '_' . $bucketPart . '_' . $sourcePart . '.json';
    $path = $targetDir . '/' . $name;
    if (@file_put_contents($path, (string)$rawJson) === false) {
        throw new Exception('Failed to write raw payload file: ' . $path);
    }
    return $path;
}

function qrs_worker_enqueue_bucket($pdo, $target)
{
    $variantId = isset($target['variant_id']) ? (string)$target['variant_id'] : '';
    $bucketAt = isset($target['bucket_at']) ? (string)$target['bucket_at'] : '';
    $executeAfter = isset($target['execute_after']) ? (string)$target['execute_after'] : date('Y-m-d H:i:s');
    $priority = isset($target['priority']) ? (int)$target['priority'] : 0;
    $status = isset($target['queue_status']) ? (string)$target['queue_status'] : 'queued_scheduled';

    if ($variantId === '' || $bucketAt === '') {
        return array('inserted' => false, 'reason' => 'invalid_target');
    }

    $selectSql = 'SELECT status FROM qrs_sys_buckets WHERE variant_id = :variant_id AND bucket_at = :bucket_at';
    $selectStmt = $pdo->prepare($selectSql);
    $selectStmt->execute(array(':variant_id' => $variantId, ':bucket_at' => $bucketAt));
    $existing = $selectStmt->fetch();
    if ($existing) {
        return array('inserted' => false, 'reason' => 'already_exists');
    }

    $now = date('Y-m-d H:i:s');
    $insertSql = 'INSERT INTO qrs_sys_buckets (variant_id, bucket_at, status, priority, execute_after, attempt_count, created_at, updated_at) '
        . 'VALUES (:variant_id, :bucket_at, :status, :priority, :execute_after, 0, :created_at, :updated_at)';
    $insertStmt = $pdo->prepare($insertSql);
    $insertStmt->execute(array(
        ':variant_id' => $variantId,
        ':bucket_at' => $bucketAt,
        ':status' => $status,
        ':priority' => $priority,
        ':execute_after' => $executeAfter,
        ':created_at' => $now,
        ':updated_at' => $now,
    ));

    return array('inserted' => true, 'reason' => 'created');
}

function qrs_worker_execute_claimed_bucket($pdo, $variantRepo, $datasetRepo, $instanceRepo, $client, $variantId, $bucketAt, $storeRawPayload, $rawPayloadDir, $pollTimeoutSeconds, $pollIntervalMillis, $retryMaxCount, $retryBackoffSeconds)
{
    qrs_worker_log_event($pdo, $variantId, $bucketAt, 'info', 'bucket execution started', 'running', null, null, array());
    try {
        $variant = $variantRepo->findById($variantId);
        if ($variant === null) {
            throw new Exception('Variant not found.');
        }
        $dataset = $datasetRepo->findById($variant['dataset_id']);
        if ($dataset === null) {
            throw new Exception('Dataset not found.');
        }
        $instance = $instanceRepo->findById($dataset['instance_id']);
        if ($instance === null || (int)$instance['is_enabled'] !== 1) {
            throw new Exception('Instance not found or disabled.');
        }

        $nowTs = time();
        $params = QrsDispatchPlanner::resolveParameterValues($variant['parameter_json'], $nowTs, $bucketAt);

        $fetchStarted = microtime(true);
        $exec = $client->executeQuery(
            $instance['base_url'],
            $instance['api_key'],
            $dataset['query_id'],
            $params,
            $pollTimeoutSeconds,
            $pollIntervalMillis
        );
        $fetchSeconds = microtime(true) - $fetchStarted;
        if (!$exec['ok']) {
            throw new Exception($exec['message'] . ' (HTTP ' . (string)$exec['status_code'] . ')');
        }

        list($columns, $rows) = qrs_worker_extract_columns_rows($exec['query_result']);
        $detectedTypeMap = qrs_worker_extract_column_type_map($exec['query_result']);
        if (count($columns) === 0 && count($rows) > 0) {
            throw new Exception('No columns in query result.');
        }

        $tableName = qrs_worker_storage_table_name($dataset['dataset_id'], $variantId);
        $overrideMap = qrs_worker_decode_column_overrides(
            isset($variant['column_type_overrides_json']) ? $variant['column_type_overrides_json'] : '{}'
        );
        $tableName = qrs_worker_ensure_schema_and_table($pdo, $variantId, $tableName, $columns, $overrideMap, $detectedTypeMap);

        $runId = qrs_worker_new_run_id();
        $rawSavedPath = '';
        if ($storeRawPayload && isset($exec['raw_json'])) {
            $rawSavedPath = qrs_worker_write_raw_payload(
                $rawPayloadDir,
                $runId,
                $variantId,
                $bucketAt,
                isset($exec['raw_source']) ? (string)$exec['raw_source'] : 'raw',
                (string)$exec['raw_json']
            );
        }
        $pdo->beginTransaction();
        try {
            $rowCount = qrs_worker_save_rows($pdo, $tableName, $variant['mode'], $bucketAt, $runId, $rows, $columns);
            qrs_worker_mark_success($pdo, $variantId, $bucketAt, $rowCount, $fetchSeconds);
            $pdo->commit();
        } catch (Exception $saveEx) {
            $pdo->rollBack();
            throw $saveEx;
        }

        qrs_worker_log_event(
            $pdo,
            $variantId,
            $bucketAt,
            'info',
            'bucket execution succeeded',
            'success',
            $rowCount,
            $fetchSeconds,
            array(
                'storage_table' => $tableName,
                'raw_payload_path' => $rawSavedPath
            )
        );
        fwrite(STDOUT, '[qrs-worker] executed variant=' . $variantId . ' bucket_at=' . $bucketAt . ' rows=' . $rowCount . "\n");
        return true;
    } catch (Exception $e) {
        $failed = qrs_worker_mark_failed($pdo, $variantId, $bucketAt, $e->getMessage(), $retryMaxCount, $retryBackoffSeconds);
        $nextStatus = isset($failed['status']) ? (string)$failed['status'] : 'failed';
        $retryDelay = isset($failed['retry_delay_seconds']) ? (int)$failed['retry_delay_seconds'] : 0;
        $nextExecuteAfter = isset($failed['execute_after']) ? (string)$failed['execute_after'] : '';
        qrs_worker_log_event(
            $pdo,
            $variantId,
            $bucketAt,
            'error',
            'bucket execution failed: ' . $e->getMessage(),
            $nextStatus,
            null,
            null,
            array(
                'retry_delay_seconds' => $retryDelay,
                'next_execute_after' => $nextExecuteAfter,
            )
        );
        fwrite(
            STDERR,
            '[qrs-worker] execute failed variant=' . $variantId
            . ' bucket_at=' . $bucketAt
            . ' status=' . $nextStatus
            . ' retry_after=' . $nextExecuteAfter
            . ' error=' . $e->getMessage() . "\n"
        );
        return false;
    }
}

function qrs_worker_spawn_child($scriptPath)
{
    if (!function_exists('proc_open')) {
        return false;
    }
    $phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' --child';
    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return false;
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return array('proc' => $proc, 'pipes' => $pipes, 'stdout' => '', 'stderr' => '');
}

$rootDir = dirname(__DIR__);
$scriptPath = __FILE__;
$argvList = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : array();
$isChildMode = in_array('--child', $argvList, true);
$config = QrsConfig::load($rootDir);
QrsConfig::applyTimezone($config);
$dbConfigExplicit = QrsConfig::hasExplicitDbConfig($rootDir);
$runtimeErrors = QrsRuntime::validateRequiredExtensions();
if (!$dbConfigExplicit) {
    fwrite(STDERR, '[qrs-worker] DB config is not set. Create config.php or set .env values first.' . "\n");
    exit(1);
}
if (count($runtimeErrors) > 0) {
    fwrite(STDERR, '[qrs-worker] Runtime requirement error: ' . implode(' ', $runtimeErrors) . "\n");
    exit(1);
}

try {
    $pdo = QrsDb::connect($config);
} catch (Exception $e) {
    fwrite(STDERR, '[qrs-worker] DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!QrsDb::isInitialized($pdo)) {
    fwrite(STDERR, '[qrs-worker] Core schema is not initialized. Open UI and run schema setup first.' . "\n");
    exit(1);
}

$startedAt = date('Y-m-d H:i:s');
$storeRawPayload = false;
$rawPayloadDir = qrs_worker_resolve_store_dir($rootDir, 'var/redash_raw');
$workerGlobalConcurrency = 1;
$workerMaxRunSeconds = 150;
$workerMaxJobsPerRun = 20;
$workerPollTimeoutSeconds = 300;
$workerPollIntervalMillis = 1000;
$workerNoDueBackoffMillis = 1000;
$workerRunningStaleSeconds = 900;
$workerRetryMaxCount = 3;
$workerRetryBackoffSeconds = 60;
try {
    $storeRawPayload = (qrs_worker_get_meta($pdo, 'runtime.store_raw_redash_payload', '0') === '1');
    $rawPayloadDir = qrs_worker_resolve_store_dir($rootDir, qrs_worker_get_meta($pdo, 'runtime.raw_redash_payload_dir', 'var/redash_raw'));
    $workerGlobalConcurrency = qrs_worker_get_meta_int($pdo, 'worker.global_concurrency', 1, 1, 16);
    $workerMaxRunSeconds = qrs_worker_get_meta_int($pdo, 'worker.max_run_seconds', 150, 1, 86400);
    $workerMaxJobsPerRun = qrs_worker_get_meta_int($pdo, 'worker.max_jobs_per_run', 20, 1, 10000);
    $workerPollTimeoutSeconds = qrs_worker_get_meta_int($pdo, 'worker.poll_timeout_seconds', 300, 1, 86400);
    $workerPollIntervalMillis = qrs_worker_get_meta_int($pdo, 'worker.poll_interval_millis', 1000, 100, 60000);
    $workerRunningStaleSeconds = qrs_worker_get_meta_int($pdo, 'worker.running_stale_seconds', 900, 1, 86400);
    $workerRetryMaxCount = qrs_worker_get_meta_int($pdo, 'worker.retry_max_count', 3, 0, 1000);
    $workerRetryBackoffSeconds = qrs_worker_get_meta_int($pdo, 'worker.retry_backoff_seconds', 60, 1, 86400);
} catch (Exception $e) {
    fwrite(STDERR, '[qrs-worker] failed to load runtime meta settings: ' . $e->getMessage() . "\n");
}
$hadActivity = false;

try {
    $recoveredCount = qrs_worker_recover_stale_running($pdo, $workerRunningStaleSeconds);
    if ($recoveredCount > 0) {
        $hadActivity = true;
        fwrite(STDOUT, '[qrs-worker] recovered stale running buckets=' . $recoveredCount . "\n");
    }
} catch (Exception $e) {
    fwrite(STDERR, '[qrs-worker] stale recovery failed: ' . $e->getMessage() . "\n");
}

if (!$isChildMode) {
    try {
        $runningCount = qrs_worker_count_running_buckets($pdo);
        if ($runningCount > 0) {
            fwrite(STDERR, '[qrs-worker] running buckets still exist after stale recovery: ' . $runningCount . '. Abort to avoid overlapping execution.' . "\n");
            exit(1);
        }
    } catch (Exception $e) {
        fwrite(STDERR, '[qrs-worker] running bucket safety check failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

// Phase 1: dispatch-equivalent tasks.
if (!$isChildMode) {
    try {
        $variantRepo = new QrsVariantRepository($pdo);
        $allVariants = $variantRepo->findAllWithDataset('');
        $nowTs = time();
        $dispatchCount = 0;

        foreach ($allVariants as $variant) {
            if ((int)$variant['is_enabled'] !== 1) {
                continue;
            }

            $targets = QrsDispatchPlanner::buildDispatchTargets(
                $variant['variant_id'],
                $variant['mode'],
                $variant['schedule_json'],
                $nowTs,
                0
            );

            $i = 0;
            while ($i < count($targets)) {
                $t = $targets[$i];
                $enqueue = qrs_worker_enqueue_bucket($pdo, $t);
                if ($enqueue['inserted']) {
                    qrs_worker_log_event(
                        $pdo,
                        $t['variant_id'],
                        $t['bucket_at'],
                        'info',
                        'bucket dispatched',
                        isset($t['queue_status']) ? $t['queue_status'] : 'queued_scheduled',
                        null,
                        null,
                        array(
                            'priority' => isset($t['priority']) ? (int)$t['priority'] : 0,
                            'execute_after' => isset($t['execute_after']) ? (string)$t['execute_after'] : null,
                        )
                    );
                    fwrite(
                        STDOUT,
                        '[qrs-worker] dispatched variant=' . $t['variant_id']
                        . ' bucket_at=' . $t['bucket_at']
                        . ' priority=' . (isset($t['priority']) ? $t['priority'] : 0) . "\n"
                    );
                    $dispatchCount++;
                }
                $i++;
            }
        }

        if ($dispatchCount > 0) {
            $hadActivity = true;
            fwrite(STDOUT, '[qrs-worker] dispatch inserted=' . $dispatchCount . "\n");
        }
    } catch (Exception $e) {
        fwrite(STDERR, '[qrs-worker] dispatch phase failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

// Phase 2: execute-equivalent tasks.
try {
    $variantRepo = new QrsVariantRepository($pdo);
    $datasetRepo = new QrsDatasetRepository($pdo);
    $instanceRepo = new QrsInstanceRepository($pdo);
    $client = new QrsRedashClient();
    $workerId = gethostname() . ':' . getmypid();
    $runStartedTs = time();

    if ($isChildMode) {
        $claimed = qrs_worker_claim_one_due_bucket($pdo, $workerId, 20);
        if ($claimed === null) {
            exit(2);
        }
        $variantId = isset($claimed['variant_id']) ? (string)$claimed['variant_id'] : '';
        $bucketAt = isset($claimed['bucket_at']) ? (string)$claimed['bucket_at'] : '';
        if ($variantId === '' || $bucketAt === '') {
            exit(2);
        }
        qrs_worker_execute_claimed_bucket(
            $pdo,
            $variantRepo,
            $datasetRepo,
            $instanceRepo,
            $client,
            $variantId,
            $bucketAt,
            $storeRawPayload,
            $rawPayloadDir,
            $workerPollTimeoutSeconds,
            $workerPollIntervalMillis,
            $workerRetryMaxCount,
            $workerRetryBackoffSeconds
        );
        exit(0);
    }

    if ($workerGlobalConcurrency <= 1 || !function_exists('proc_open')) {
        $executeCount = 0;
        while (true) {
            if ($executeCount >= $workerMaxJobsPerRun) {
                break;
            }
            if ((time() - $runStartedTs) >= $workerMaxRunSeconds) {
                break;
            }

            $claimed = qrs_worker_claim_one_due_bucket($pdo, $workerId, 20);
            if ($claimed === null) {
                break;
            }
            $variantId = isset($claimed['variant_id']) ? (string)$claimed['variant_id'] : '';
            $bucketAt = isset($claimed['bucket_at']) ? (string)$claimed['bucket_at'] : '';
            if ($variantId === '' || $bucketAt === '') {
                break;
            }
            qrs_worker_execute_claimed_bucket(
                $pdo,
                $variantRepo,
                $datasetRepo,
                $instanceRepo,
                $client,
                $variantId,
                $bucketAt,
                $storeRawPayload,
                $rawPayloadDir,
                $workerPollTimeoutSeconds,
                $workerPollIntervalMillis,
                $workerRetryMaxCount,
                $workerRetryBackoffSeconds
            );
            $executeCount++;
        }
        if ($executeCount > 0) {
            $hadActivity = true;
            fwrite(STDOUT, '[qrs-worker] execute completed=' . $executeCount . "\n");
        }
    } else {
        $spawnedCount = 0;
        $claimedCount = 0;
        $claimedOrReservedCount = 0;
        $children = array();
        $spawnDisabled = false;
        $noDueObserved = false;
        $nextSpawnAt = 0.0;

        while (true) {
            // Reap finished children.
            $nextChildren = array();
            $i = 0;
            while ($i < count($children)) {
                $child = $children[$i];
                $proc = $child['proc'];
                $pipes = $child['pipes'];
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    $outChunk = stream_get_contents($pipes[1]);
                    if (is_string($outChunk) && $outChunk !== '') {
                        fwrite(STDOUT, $outChunk);
                    }
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    $errChunk = stream_get_contents($pipes[2]);
                    if (is_string($errChunk) && $errChunk !== '') {
                        fwrite(STDERR, $errChunk);
                    }
                }
                $status = proc_get_status($proc);
                if ($status['running']) {
                    $nextChildren[] = $child;
                } else {
                    if (isset($pipes[1]) && is_resource($pipes[1])) {
                        fclose($pipes[1]);
                    }
                    if (isset($pipes[2]) && is_resource($pipes[2])) {
                        fclose($pipes[2]);
                    }
                    $exitCode = proc_close($proc);
                    if ($exitCode === 2) {
                        if ($claimedOrReservedCount > 0) {
                            $claimedOrReservedCount--;
                        }
                        $noDueObserved = true;
                        $nextSpawnAt = microtime(true) + ((float)$workerNoDueBackoffMillis / 1000.0);
                    } else {
                        $claimedCount++;
                        $noDueObserved = false;
                    }
                }
                $i++;
            }
            $children = $nextChildren;

            $deadlineReached = ((time() - $runStartedTs) >= $workerMaxRunSeconds);
            $jobLimitReached = ($claimedOrReservedCount >= $workerMaxJobsPerRun);
            if (!$deadlineReached && !$jobLimitReached && !$spawnDisabled) {
                while (count($children) < $workerGlobalConcurrency && $claimedOrReservedCount < $workerMaxJobsPerRun && ((time() - $runStartedTs) < $workerMaxRunSeconds)) {
                    if (microtime(true) < $nextSpawnAt) {
                        break;
                    }
                    $spawned = qrs_worker_spawn_child($scriptPath);
                    if ($spawned === false) {
                        $spawnDisabled = true;
                        break;
                    }
                    $children[] = $spawned;
                    $spawnedCount++;
                    $claimedOrReservedCount++;
                    $noDueObserved = false;
                }
            }

            if (($deadlineReached || $jobLimitReached || $spawnDisabled) && count($children) === 0) {
                break;
            }
            if (!$deadlineReached && !$jobLimitReached && count($children) === 0 && $noDueObserved) {
                break;
            }
            usleep(200000);
        }

        if ($claimedCount > 0) {
            $hadActivity = true;
            fwrite(STDOUT, '[qrs-worker] execute completed=' . $claimedCount . ' spawned=' . $spawnedCount . ' claimed=' . $claimedCount . "\n");
        }
    }
} catch (Exception $e) {
    fwrite(STDERR, '[qrs-worker] execute phase failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$endedAt = date('Y-m-d H:i:s');
if ($hadActivity) {
    fwrite(STDOUT, '[qrs-worker] finished at ' . $endedAt . "\n");
}

exit(0);
