<?php

class QrsDatasetRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function findAllWithInstance()
    {
        $sql = 'SELECT d.dataset_id, d.instance_id, d.query_id, d.created_at, d.updated_at, i.base_url AS instance_base_url, i.api_key AS instance_api_key, i.is_enabled AS instance_enabled '
            . 'FROM qrs_sys_datasets d '
            . 'LEFT JOIN qrs_sys_instances i ON i.instance_id = d.instance_id '
            . 'ORDER BY d.created_at DESC';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function findByInstanceAndQuery($instanceId, $queryId)
    {
        $sql = 'SELECT dataset_id, instance_id, query_id, created_at, updated_at FROM qrs_sys_datasets WHERE instance_id = :instance_id AND query_id = :query_id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':instance_id' => $instanceId,
            ':query_id' => $queryId,
        ));
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $row;
    }

    public function findById($datasetId)
    {
        $sql = 'SELECT dataset_id, instance_id, query_id, created_at, updated_at FROM qrs_sys_datasets WHERE dataset_id = :dataset_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':dataset_id' => $datasetId));
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $row;
    }

    public function create($instanceId, $queryId)
    {
        $datasetId = $this->nextDatasetId();
        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO qrs_sys_datasets (dataset_id, instance_id, query_id, created_at, updated_at) VALUES (:dataset_id, :instance_id, :query_id, :created_at, :updated_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':dataset_id' => $datasetId,
            ':instance_id' => $instanceId,
            ':query_id' => $queryId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ));

        return $datasetId;
    }

    private function nextDatasetId()
    {
        $sql = 'SELECT dataset_id FROM qrs_sys_datasets';
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();

        $max = 0;
        foreach ($rows as $row) {
            if (!isset($row['dataset_id'])) {
                continue;
            }
            $id = (string)$row['dataset_id'];
            if (preg_match('/^ds_([0-9]+)$/', $id, $m)) {
                $n = (int)$m[1];
                if ($n > $max) {
                    $max = $n;
                }
            }
        }

        return 'ds_' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function countVariantReferences($datasetId)
    {
        $sql = 'SELECT COUNT(*) AS c FROM qrs_sys_variants WHERE dataset_id = :dataset_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':dataset_id' => $datasetId));
        $row = $stmt->fetch();
        if (!$row) {
            return 0;
        }
        return (int)$row['c'];
    }

    public function deleteById($datasetId)
    {
        $sql = 'DELETE FROM qrs_sys_datasets WHERE dataset_id = :dataset_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':dataset_id' => $datasetId));
        return ($stmt->rowCount() > 0);
    }
}
