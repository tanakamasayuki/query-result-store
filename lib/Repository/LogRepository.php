<?php

class QrsLogRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function findAll($filters, $limit)
    {
        $sql = 'SELECT l.log_id, l.variant_id, v.dataset_id, l.bucket_at, l.status, l.row_count, l.fetch_seconds, '
            . 'l.level, l.message, l.context_json, l.created_at '
            . 'FROM qrs_sys_logs l '
            . 'LEFT JOIN qrs_sys_variants v ON v.variant_id = l.variant_id ';
        $where = array();
        $params = array();

        $datasetId = isset($filters['dataset_id']) ? trim((string)$filters['dataset_id']) : '';
        $variantId = isset($filters['variant_id']) ? trim((string)$filters['variant_id']) : '';
        $bucketAt = isset($filters['bucket_at']) ? trim((string)$filters['bucket_at']) : '';
        $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
        $level = isset($filters['level']) ? trim((string)$filters['level']) : '';

        if ($datasetId !== '') {
            $where[] = 'v.dataset_id = :dataset_id';
            $params[':dataset_id'] = $datasetId;
        }
        if ($variantId !== '') {
            $where[] = 'l.variant_id = :variant_id';
            $params[':variant_id'] = $variantId;
        }
        if ($bucketAt !== '') {
            $where[] = 'l.bucket_at = :bucket_at';
            $params[':bucket_at'] = $bucketAt;
        }
        if ($status !== '') {
            $where[] = 'l.status = :status';
            $params[':status'] = $status;
        }
        if ($level !== '') {
            $where[] = 'l.level = :level';
            $params[':level'] = $level;
        }

        if (count($where) > 0) {
            $sql .= 'WHERE ' . implode(' AND ', $where) . ' ';
        }

        $limit = (int)$limit;
        if ($limit <= 0) {
            $limit = 200;
        }
        if ($limit > 2000) {
            $limit = 2000;
        }

        $sql .= 'ORDER BY l.created_at DESC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findDistinctStatuses()
    {
        $stmt = $this->pdo->query('SELECT DISTINCT status FROM qrs_sys_logs WHERE status IS NOT NULL AND status <> \'\' ORDER BY status ASC');
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

    public function findDistinctLevels()
    {
        $stmt = $this->pdo->query('SELECT DISTINCT level FROM qrs_sys_logs WHERE level IS NOT NULL AND level <> \'\' ORDER BY level ASC');
        $rows = $stmt->fetchAll();
        $result = array();
        foreach ($rows as $row) {
            if (!isset($row['level'])) {
                continue;
            }
            $v = trim((string)$row['level']);
            if ($v !== '') {
                $result[] = $v;
            }
        }
        return $result;
    }
}
