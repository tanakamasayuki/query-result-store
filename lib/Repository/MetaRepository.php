<?php

class QrsMetaRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function get($key, $defaultValue)
    {
        $sql = 'SELECT meta_value FROM qrs_sys_meta WHERE meta_key = :meta_key';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':meta_key' => $key));
        $row = $stmt->fetch();
        if (!$row || !isset($row['meta_value'])) {
            return $defaultValue;
        }
        return (string)$row['meta_value'];
    }

    public function set($key, $value)
    {
        $now = date('Y-m-d H:i:s');
        $updateSql = 'UPDATE qrs_sys_meta SET meta_value = :meta_value, updated_at = :updated_at WHERE meta_key = :meta_key';
        $updateStmt = $this->pdo->prepare($updateSql);
        $updateStmt->execute(array(
            ':meta_key' => $key,
            ':meta_value' => (string)$value,
            ':updated_at' => $now,
        ));
        if ($updateStmt->rowCount() > 0) {
            return true;
        }

        $insertSql = 'INSERT INTO qrs_sys_meta (meta_key, meta_value, updated_at) VALUES (:meta_key, :meta_value, :updated_at)';
        $insertStmt = $this->pdo->prepare($insertSql);
        $insertStmt->execute(array(
            ':meta_key' => $key,
            ':meta_value' => (string)$value,
            ':updated_at' => $now,
        ));
        return true;
    }
}
