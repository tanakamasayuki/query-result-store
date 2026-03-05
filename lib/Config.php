<?php

class QrsConfig
{
    public static function load($rootDir)
    {
        $configPath = $rootDir . '/config.php';
        $envPath = $rootDir . '/.env';

        QrsEnv::loadFile($envPath);

        $config = array(
            'app' => array(
                'timezone_id' => QrsEnv::get('QRS_TIMEZONE', 'UTC'),
            ),
            'db' => array(
                'driver' => QrsEnv::get('QRS_DB_DRIVER', 'sqlite'),
                'sqlite_path' => QrsEnv::get('QRS_DB_SQLITE_PATH', $rootDir . '/var/qrs.sqlite3'),
                'host' => QrsEnv::get('QRS_DB_HOST', '127.0.0.1'),
                'port' => QrsEnv::get('QRS_DB_PORT', ''),
                'name' => QrsEnv::get('QRS_DB_NAME', 'qrs'),
                'user' => QrsEnv::get('QRS_DB_USER', ''),
                'password' => QrsEnv::get('QRS_DB_PASSWORD', ''),
                'charset' => QrsEnv::get('QRS_DB_CHARSET', 'utf8'),
            ),
        );

        if (is_file($configPath)) {
            $fileConfig = include $configPath;
            if (is_array($fileConfig)) {
                $config = self::merge($config, $fileConfig);
            }
        }

        if (!isset($config['app']) || !is_array($config['app'])) {
            $config['app'] = array();
        }
        if (!isset($config['app']['timezone_id']) || trim((string)$config['app']['timezone_id']) === '') {
            $config['app']['timezone_id'] = 'UTC';
        }

        return $config;
    }

    public static function applyTimezone($config)
    {
        $timezoneId = 'UTC';
        if (is_array($config) && isset($config['app']) && is_array($config['app']) &&
            isset($config['app']['timezone_id']) && trim((string)$config['app']['timezone_id']) !== '') {
            $timezoneId = trim((string)$config['app']['timezone_id']);
        }

        if (@date_default_timezone_set($timezoneId) === false) {
            date_default_timezone_set('UTC');
            error_log('[qrs] Invalid timezone_id "' . $timezoneId . '". Fallback to UTC.');
            return 'UTC';
        }

        return $timezoneId;
    }

    public static function timezoneStatus($rootDir, $config)
    {
        $status = array(
            'has_explicit' => false,
            'source' => 'default',
            'configured_value' => isset($config['app']['timezone_id']) ? (string)$config['app']['timezone_id'] : '',
            'effective_value' => date_default_timezone_get(),
        );

        $envValue = getenv('QRS_TIMEZONE');
        if ($envValue !== false && trim((string)$envValue) !== '') {
            $status['has_explicit'] = true;
            $status['source'] = 'env';
            $status['configured_value'] = trim((string)$envValue);
            return $status;
        }

        $configPath = $rootDir . '/config.php';
        if (is_file($configPath)) {
            $fileConfig = include $configPath;
            if (is_array($fileConfig) && isset($fileConfig['app']) && is_array($fileConfig['app']) &&
                isset($fileConfig['app']['timezone_id']) && trim((string)$fileConfig['app']['timezone_id']) !== '') {
                $status['has_explicit'] = true;
                $status['source'] = 'config';
                $status['configured_value'] = trim((string)$fileConfig['app']['timezone_id']);
                return $status;
            }
        }

        return $status;
    }

    public static function hasExplicitDbConfig($rootDir)
    {
        $envKeys = array(
            'QRS_DB_DRIVER',
            'QRS_DB_SQLITE_PATH',
            'QRS_DB_HOST',
            'QRS_DB_PORT',
            'QRS_DB_NAME',
            'QRS_DB_USER',
            'QRS_DB_PASSWORD',
            'QRS_DB_CHARSET',
        );
        foreach ($envKeys as $k) {
            $v = getenv($k);
            if ($v !== false && trim((string)$v) !== '') {
                return true;
            }
        }

        $configPath = $rootDir . '/config.php';
        if (!is_file($configPath)) {
            return false;
        }

        $fileConfig = include $configPath;
        if (!is_array($fileConfig) || !isset($fileConfig['db']) || !is_array($fileConfig['db'])) {
            return false;
        }

        $db = $fileConfig['db'];
        if (isset($db['driver']) && trim((string)$db['driver']) !== '') {
            return true;
        }
        if (isset($db['sqlite_path']) && trim((string)$db['sqlite_path']) !== '') {
            return true;
        }
        if (isset($db['host']) && trim((string)$db['host']) !== '') {
            return true;
        }
        if (isset($db['name']) && trim((string)$db['name']) !== '') {
            return true;
        }

        return false;
    }

    public static function merge($base, $override)
    {
        $result = $base;
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = self::merge($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
