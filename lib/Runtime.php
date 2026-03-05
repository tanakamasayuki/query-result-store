<?php

class QrsRuntime
{
    public static function pdoDriverStatus()
    {
        $available = array();
        if (class_exists('PDO')) {
            $drivers = PDO::getAvailableDrivers();
            if (is_array($drivers)) {
                foreach ($drivers as $d) {
                    $available[strtolower((string)$d)] = true;
                }
            }
        }

        $status = array(
            'sqlite' => isset($available['sqlite']),
            'mysql' => isset($available['mysql']),
            'pgsql' => isset($available['pgsql']),
        );
        $status['any'] = ($status['sqlite'] || $status['mysql'] || $status['pgsql']);
        return $status;
    }

    public static function validateRequiredExtensions()
    {
        $errors = array();

        if (!function_exists('json_decode') || !function_exists('json_encode')) {
            $errors[] = 'json extension is required.';
        }

        if (!class_exists('PDO')) {
            $errors[] = 'PDO extension is required.';
        }
        if (!function_exists('curl_init')) {
            $errors[] = 'cURL extension is required.';
        }

        $driverStatus = self::pdoDriverStatus();
        if (!$driverStatus['any']) {
            $errors[] = 'At least one PDO driver is required (pdo_sqlite / pdo_mysql / pdo_pgsql).';
        }

        return $errors;
    }

    public static function isOk()
    {
        $errors = self::validateRequiredExtensions();
        return count($errors) === 0;
    }
}
