<?php

class QrsVariantRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function findAllWithDataset($datasetId)
    {
        $params = array();
        $sql = 'SELECT v.variant_id, v.dataset_id, v.mode, v.parameter_json, v.schedule_json, v.column_type_overrides_json, v.is_enabled, v.created_at, v.updated_at, d.instance_id, d.query_id '
            . 'FROM qrs_sys_variants v '
            . 'LEFT JOIN qrs_sys_datasets d ON d.dataset_id = v.dataset_id ';

        if ($datasetId !== '') {
            $sql .= 'WHERE v.dataset_id = :dataset_id ';
            $params[':dataset_id'] = $datasetId;
        }

        $sql .= 'ORDER BY v.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById($variantId)
    {
        $sql = 'SELECT variant_id, dataset_id, mode, parameter_json, schedule_json, column_type_overrides_json, is_enabled, created_at, updated_at FROM qrs_sys_variants WHERE variant_id = :variant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':variant_id' => $variantId));
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $row;
    }

    public function create($datasetId, $mode, $parameterJson, $scheduleJson, $isEnabled, $columnTypeOverridesJson)
    {
        $variantId = $this->nextVariantId();
        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO qrs_sys_variants (variant_id, dataset_id, mode, parameter_json, schedule_json, column_type_overrides_json, is_enabled, created_at, updated_at) VALUES (:variant_id, :dataset_id, :mode, :parameter_json, :schedule_json, :column_type_overrides_json, :is_enabled, :created_at, :updated_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':variant_id' => $variantId,
            ':dataset_id' => $datasetId,
            ':mode' => $mode,
            ':parameter_json' => $parameterJson,
            ':schedule_json' => $scheduleJson,
            ':column_type_overrides_json' => $columnTypeOverridesJson,
            ':is_enabled' => $isEnabled ? 1 : 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ));

        return $variantId;
    }

    private function nextVariantId()
    {
        $sql = 'SELECT variant_id FROM qrs_sys_variants';
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();

        $max = 0;
        foreach ($rows as $row) {
            if (!isset($row['variant_id'])) {
                continue;
            }
            $id = (string)$row['variant_id'];
            if (preg_match('/^vr_([0-9]+)$/', $id, $m)) {
                $n = (int)$m[1];
                if ($n > $max) {
                    $max = $n;
                }
            }
        }

        return 'vr_' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function updateSettings($variantId, $mode, $parameterJson, $scheduleJson)
    {
        $sql = 'UPDATE qrs_sys_variants SET mode = :mode, parameter_json = :parameter_json, schedule_json = :schedule_json, updated_at = :updated_at WHERE variant_id = :variant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':mode' => $mode,
            ':parameter_json' => $parameterJson,
            ':schedule_json' => $scheduleJson,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':variant_id' => $variantId,
        ));

        return ($stmt->rowCount() > 0);
    }

    public function setEnabled($variantId, $isEnabled)
    {
        $sql = 'UPDATE qrs_sys_variants SET is_enabled = :is_enabled, updated_at = :updated_at WHERE variant_id = :variant_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':is_enabled' => $isEnabled ? 1 : 0,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':variant_id' => $variantId,
        ));

        return ($stmt->rowCount() > 0);
    }
}
