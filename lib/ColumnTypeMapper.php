<?php

class QrsColumnTypeMapper
{
    public static function overrideTypes()
    {
        return array('TEXT', 'INTEGER', 'REAL', 'NUMERIC', 'DATE', 'DATETIME', 'BOOLEAN');
    }

    public static function resolveStorageType($driverName, $redashType, $overrideType)
    {
        $driver = self::normalizeDriverName($driverName);
        $override = strtoupper(trim((string)$overrideType));
        if ($override !== '' && $override !== 'AUTO') {
            return self::sqlTypeFromLogical($driver, $override);
        }

        $logical = self::logicalTypeFromRedashType($redashType);
        return self::sqlTypeFromLogical($driver, $logical);
    }

    public static function logicalTypeFromRedashType($redashType)
    {
        $t = self::normalizeRedashType($redashType);

        if (in_array($t, array('integer', 'int', 'bigint', 'smallint', 'long', 'int32', 'int64'), true)) {
            return 'INTEGER';
        }
        if (in_array($t, array('float', 'double', 'real'), true)) {
            return 'REAL';
        }
        if (in_array($t, array('decimal', 'numeric', 'number'), true)) {
            return 'NUMERIC';
        }
        if (in_array($t, array('boolean', 'bool'), true)) {
            return 'BOOLEAN';
        }
        if ($t === 'date') {
            return 'DATE';
        }
        if (in_array($t, array('datetime', 'timestamp', 'timestamptz'), true)) {
            return 'DATETIME';
        }
        if ($t === 'time') {
            return 'TIME';
        }
        if (in_array($t, array('json', 'object', 'array'), true)) {
            return 'JSON';
        }
        return 'TEXT';
    }

    public static function normalizeRedashType($redashType)
    {
        $t = strtolower(trim((string)$redashType));
        if ($t === '') {
            return 'unknown';
        }
        return $t;
    }

    public static function normalizeDriverName($driverName)
    {
        $driver = strtolower(trim((string)$driverName));
        if ($driver === 'postgres' || $driver === 'postgresql') {
            return 'pgsql';
        }
        if (!in_array($driver, array('sqlite', 'mysql', 'pgsql'), true)) {
            return '';
        }
        return $driver;
    }

    public static function sqlTypeFromLogical($driverName, $logicalType)
    {
        $driver = self::normalizeDriverName($driverName);
        $type = strtoupper(trim((string)$logicalType));

        if ($type === 'INTEGER') {
            if ($driver === 'sqlite') {
                return 'INTEGER';
            }
            return 'BIGINT';
        }
        if ($type === 'REAL') {
            if ($driver === 'sqlite') {
                return 'REAL';
            }
            if ($driver === 'pgsql') {
                return 'DOUBLE PRECISION';
            }
            return 'DOUBLE';
        }
        if ($type === 'NUMERIC') {
            if ($driver === 'mysql') {
                return 'DECIMAL(38,10)';
            }
            return 'NUMERIC';
        }
        if ($type === 'DATE') {
            if ($driver === 'sqlite') {
                return 'TEXT';
            }
            return 'DATE';
        }
        if ($type === 'DATETIME') {
            if ($driver === 'sqlite') {
                return 'TEXT';
            }
            if ($driver === 'pgsql') {
                return 'TIMESTAMP';
            }
            return 'DATETIME(6)';
        }
        if ($type === 'BOOLEAN') {
            if ($driver === 'sqlite') {
                return 'INTEGER';
            }
            if ($driver === 'mysql') {
                return 'TINYINT(1)';
            }
            return 'BOOLEAN';
        }
        if ($type === 'TIME') {
            if ($driver === 'sqlite') {
                return 'TEXT';
            }
            return 'TIME';
        }
        if ($type === 'JSON') {
            if ($driver === 'pgsql') {
                return 'JSONB';
            }
            if ($driver === 'mysql') {
                return 'JSON';
            }
            return 'TEXT';
        }
        return 'TEXT';
    }
}
