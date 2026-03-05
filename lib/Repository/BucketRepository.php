<?php

class QrsBucketRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function findAll($filters, $limit)
    {
        $sql = 'SELECT b.variant_id, v.dataset_id, b.bucket_at, b.status, b.priority, b.execute_after, b.attempt_count, '
            . 'b.last_error, b.last_row_count, b.last_fetch_seconds, b.locked_by, b.locked_at, b.started_at, b.finished_at, b.updated_at '
            . 'FROM qrs_sys_buckets b '
            . 'LEFT JOIN qrs_sys_variants v ON v.variant_id = b.variant_id ';
        $where = array();
        $params = array();

        $datasetId = isset($filters['dataset_id']) ? trim((string)$filters['dataset_id']) : '';
        $variantId = isset($filters['variant_id']) ? trim((string)$filters['variant_id']) : '';
        $status = isset($filters['status']) ? trim((string)$filters['status']) : '';

        if ($datasetId !== '') {
            $where[] = 'v.dataset_id = :dataset_id';
            $params[':dataset_id'] = $datasetId;
        }
        if ($variantId !== '') {
            $where[] = 'b.variant_id = :variant_id';
            $params[':variant_id'] = $variantId;
        }
        if ($status !== '') {
            $where[] = 'b.status = :status';
            $params[':status'] = $status;
        }

        if (count($where) > 0) {
            $sql .= 'WHERE ' . implode(' AND ', $where) . ' ';
        }

        $limit = (int)$limit;
        if ($limit <= 0) {
            $limit = 200;
        }
        if ($limit > 1000) {
            $limit = 1000;
        }

        $sql .= 'ORDER BY b.execute_after ASC, b.bucket_at ASC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findDistinctStatuses()
    {
        $stmt = $this->pdo->query('SELECT DISTINCT status FROM qrs_sys_buckets ORDER BY status ASC');
        $rows = $stmt->fetchAll();
        $result = array();
        foreach ($rows as $row) {
            if (!isset($row['status'])) {
                continue;
            }
            $v = trim((string)$row['status']);
            if ($v !== '') {
                $result[] = $v;
            }
        }
        return $result;
    }

    public function requeueManual($variantId, $bucketAt)
    {
        $sql = 'UPDATE qrs_sys_buckets '
            . 'SET status = :status, execute_after = :execute_after, attempt_count = 0, last_error = NULL, '
            . 'locked_by = NULL, locked_at = NULL, started_at = NULL, finished_at = NULL, updated_at = :updated_at '
            . 'WHERE variant_id = :variant_id AND bucket_at = :bucket_at';
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':status' => 'queued_manual',
            ':execute_after' => $now,
            ':updated_at' => $now,
            ':variant_id' => $variantId,
            ':bucket_at' => $bucketAt,
        ));
        return ($stmt->rowCount() > 0);
    }

    public function deleteByKey($variantId, $bucketAt)
    {
        $sql = 'DELETE FROM qrs_sys_buckets WHERE variant_id = :variant_id AND bucket_at = :bucket_at';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':variant_id' => $variantId,
            ':bucket_at' => $bucketAt,
        ));
        return ($stmt->rowCount() > 0);
    }
}
