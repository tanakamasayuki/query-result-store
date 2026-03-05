<?php

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Repository/InstanceRepository.php';
require_once dirname(__DIR__) . '/lib/RedashClient.php';

$rootDir = dirname(__DIR__);
$workerPath = $rootDir . '/bin/worker.php';
$workerLockPath = $rootDir . '/var/qrs-worker.lock';
$config = QrsConfig::load($rootDir);
$dbConfigExplicit = QrsConfig::hasExplicitDbConfig($rootDir);
QrsConfig::applyTimezone($config);
$locale = QrsI18n::init($rootDir, $_GET, $_COOKIE, $_SERVER);

if (isset($_GET['lang']) && ($_GET['lang'] === 'en' || $_GET['lang'] === 'ja')) {
    setcookie('qrs_lang', $_GET['lang'], time() + 31536000, '/');
}

$runtimeErrors = QrsRuntime::validateRequiredExtensions();
$runtimeOk = (count($runtimeErrors) === 0);
$jsonAvailable = function_exists('json_decode') && function_exists('json_encode');
$curlRuntimeAvailable = function_exists('curl_init');

$currentPage = basename(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');
if (!$dbConfigExplicit && $currentPage !== 'env.php') {
    $target = 'env.php';
    if (isset($_GET['lang']) && ($_GET['lang'] === 'ja' || $_GET['lang'] === 'en')) {
        $target .= '?lang=' . $_GET['lang'];
    }
    header('Location: ' . $target);
    exit;
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function t($key, $params)
{
    return QrsI18n::t($key, $params);
}

function qrs_lang()
{
    $lang = isset($_GET['lang']) ? $_GET['lang'] : QrsI18n::locale();
    if ($lang !== 'ja') {
        $lang = 'en';
    }
    return $lang;
}

function qrs_url($path)
{
    return $path;
}

function qrs_url_with_params($path, $params)
{
    if (!is_array($params)) {
        $params = array();
    }
    if (count($params) === 0) {
        return $path;
    }
    return $path . '?' . http_build_query($params);
}

function qrs_lang_switch_url($lang)
{
    $page = basename($_SERVER['PHP_SELF']);
    return $page . '?lang=' . $lang;
}

function qrs_lang_input_html()
{
    return '<input type="hidden" name="lang" value="' . h(qrs_lang()) . '">';
}

function maskApiKey($apiKey)
{
    $apiKey = (string)$apiKey;
    $len = strlen($apiKey);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($apiKey, 0, 4) . str_repeat('*', $len - 8) . substr($apiKey, -4);
}

function qrs_connect_db($config, &$dbOk, &$dbError, &$pdo, &$isInitialized, &$curlAvailable)
{
    global $dbConfigExplicit, $curlRuntimeAvailable;

    $dbOk = false;
    $dbError = '';
    $pdo = null;
    $isInitialized = false;
    $curlAvailable = $curlRuntimeAvailable;

    if (!$dbConfigExplicit) {
        $dbError = t('db_config_missing', array());
        return;
    }

    try {
        $pdo = QrsDb::connect($config);
        $dbOk = true;
        $isInitialized = QrsDb::isInitialized($pdo);
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}
