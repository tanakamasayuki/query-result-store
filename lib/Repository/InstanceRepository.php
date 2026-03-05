<?php

class QrsInstanceRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function findAll()
    {
        $sql = 'SELECT instance_id, base_url, api_key, is_enabled, created_at, updated_at FROM qrs_sys_instances ORDER BY created_at DESC';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function findEnabled()
    {
        $sql = 'SELECT instance_id, base_url, api_key, is_enabled, created_at, updated_at FROM qrs_sys_instances WHERE is_enabled = 1 ORDER BY created_at DESC';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll();
    }

    public function findById($instanceId)
    {
        $sql = 'SELECT instance_id, base_url, api_key, is_enabled, created_at, updated_at FROM qrs_sys_instances WHERE instance_id = :instance_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':instance_id' => $instanceId));
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $row;
    }

    public function create($baseUrl, $apiKey, $isEnabled)
    {
        $instanceId = $this->nextInstanceId();
        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO qrs_sys_instances (instance_id, base_url, api_key, is_enabled, created_at, updated_at) VALUES (:instance_id, :base_url, :api_key, :is_enabled, :created_at, :updated_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':instance_id' => $instanceId,
            ':base_url' => $baseUrl,
            ':api_key' => $apiKey,
            ':is_enabled' => $isEnabled ? 1 : 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ));

        return $instanceId;
    }

    private function nextInstanceId()
    {
        $sql = 'SELECT instance_id FROM qrs_sys_instances';
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();

        $max = 0;
        foreach ($rows as $row) {
            if (!isset($row['instance_id'])) {
                continue;
            }
            $id = (string)$row['instance_id'];
            if (preg_match('/^ins_([0-9]+)$/', $id, $m)) {
                $n = (int)$m[1];
                if ($n > $max) {
                    $max = $n;
                }
            }
        }

        return 'ins_' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function setEnabled($instanceId, $isEnabled)
    {
        $sql = 'UPDATE qrs_sys_instances SET is_enabled = :is_enabled, updated_at = :updated_at WHERE instance_id = :instance_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':is_enabled' => $isEnabled ? 1 : 0,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':instance_id' => $instanceId,
        ));

        return ($stmt->rowCount() > 0);
    }
}
