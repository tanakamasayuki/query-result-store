<?php

class QrsI18n
{
    private static $locale = 'en';
    private static $messages = array();
    private static $fallbackMessages = array();
    private static $strictMode = false;
    private static $logMissing = false;

    public static function init($rootDir, $query, $cookie, $server)
    {
        $locale = self::resolveLocale($query, $cookie, $server);
        self::$locale = $locale;
        self::$messages = self::loadMessages($rootDir, $locale);
        self::$fallbackMessages = self::loadMessages($rootDir, 'en');
        self::$strictMode = self::resolveStrictMode();
        self::$logMissing = self::resolveLogMissing();

        return $locale;
    }

    public static function locale()
    {
        return self::$locale;
    }

    public static function t($key, $params)
    {
        if (isset(self::$messages[$key])) {
            $text = self::$messages[$key];
        } elseif (isset(self::$fallbackMessages[$key])) {
            $text = self::$fallbackMessages[$key];
        } else {
            $msg = 'Missing i18n key: ' . $key . ' (locale=' . self::$locale . ')';
            if (self::$logMissing) {
                error_log('[qrs-i18n] ' . $msg);
            }
            if (self::$strictMode) {
                throw new Exception($msg);
            }
            $text = $key;
        }

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }

        return $text;
    }

    private static function resolveLocale($query, $cookie, $server)
    {
        $supported = array('en', 'ja');

        if (isset($query['lang'])) {
            $lang = strtolower(trim($query['lang']));
            if (in_array($lang, $supported, true)) {
                return $lang;
            }
        }

        if (isset($cookie['qrs_lang'])) {
            $lang = strtolower(trim($cookie['qrs_lang']));
            if (in_array($lang, $supported, true)) {
                return $lang;
            }
        }

        $accept = isset($server['HTTP_ACCEPT_LANGUAGE']) ? strtolower($server['HTTP_ACCEPT_LANGUAGE']) : '';
        if (strpos($accept, 'ja') === 0 || strpos($accept, ',ja') !== false) {
            return 'ja';
        }

        return 'en';
    }

    private static function loadMessages($rootDir, $locale)
    {
        $path = $rootDir . '/lang/' . $locale . '.php';
        if (!is_file($path)) {
            $path = $rootDir . '/lang/en.php';
        }

        $messages = include $path;
        if (!is_array($messages)) {
            return array();
        }

        return $messages;
    }

    private static function resolveStrictMode()
    {
        $strict = getenv('QRS_I18N_STRICT');
        if ($strict !== false) {
            $strict = strtolower(trim((string)$strict));
            return in_array($strict, array('1', 'true', 'yes', 'on'), true);
        }

        $env = getenv('QRS_APP_ENV');
        $env = ($env === false) ? 'production' : strtolower(trim((string)$env));
        return in_array($env, array('dev', 'development', 'local', 'test', 'testing'), true);
    }

    private static function resolveLogMissing()
    {
        $log = getenv('QRS_I18N_LOG_MISSING');
        if ($log !== false) {
            $log = strtolower(trim((string)$log));
            return in_array($log, array('1', 'true', 'yes', 'on'), true);
        }

        return self::$strictMode;
    }
}
