<?php

require_once __DIR__ . '/_app.php';
require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/lib/Repository/DatasetRepository.php';
require_once dirname(__DIR__) . '/lib/Repository/VariantRepository.php';
require_once dirname(__DIR__) . '/lib/RedashClient.php';

$message = '';
$error = '';
$datasets = array();
$variants = array();
$filterDatasetId = isset($_GET['dataset_id']) ? trim($_GET['dataset_id']) : '';
$selectedDataset = null;
$selectedInstanceBaseUrl = '';
$queryParamDefs = array();
$queryMetaOk = false;
$queryColumnTypes = array();
$createPreview = null;
$typeMapDebug = array();
$editVariantId = isset($_GET['edit_variant_id']) ? trim($_GET['edit_variant_id']) : '';
$copyVariantId = isset($_GET['copy_variant_id']) ? trim($_GET['copy_variant_id']) : '';
$editingVariant = null;
$isEditMode = false;
$copyingVariant = null;
$isCopyMode = false;

if (!$runtimeOk) {
    $error = t('runtime_error', array('errors' => implode(' ', $runtimeErrors)));
}

$dbOk = false;
$dbError = '';
$pdo = null;
$isInitialized = false;
$curlAvailable = false;
$dbDriverName = '';

if ($runtimeOk) {
    qrs_connect_db($config, $dbOk, $dbError, $pdo, $isInitialized, $curlAvailable);
    if ($dbOk && $pdo instanceof PDO) {
        try {
            $dbDriverName = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Exception $e) {
            $dbDriverName = '';
        }
    }
}

function qrs_is_valid_variant_mode($mode)
{
    return in_array($mode, array('snapshot', 'latest', 'oneshot'), true);
}

function qrs_extract_query_params($queryData)
{
    $result = array();
    if (!is_array($queryData)) {
        return $result;
    }

    if (!isset($queryData['options']) || !is_array($queryData['options'])) {
        return $result;
    }
    if (!isset($queryData['options']['parameters']) || !is_array($queryData['options']['parameters'])) {
        return $result;
    }

    foreach ($queryData['options']['parameters'] as $param) {
        if (!is_array($param) || !isset($param['name'])) {
            continue;
        }
        $name = trim((string)$param['name']);
        if ($name === '') {
            continue;
        }

        $title = isset($param['title']) ? trim((string)$param['title']) : $name;
        if ($title === '') {
            $title = $name;
        }

        $defaultValue = '';
        if (isset($param['value']) && (is_string($param['value']) || is_numeric($param['value']))) {
            $defaultValue = (string)$param['value'];
        }

        $result[] = array(
            'name' => $name,
            'title' => $title,
            'default' => $defaultValue,
        );
    }

    return $result;
}

function qrs_extract_query_column_types($queryData)
{
    $columns = array();
    if (!is_array($queryData)) {
        return $columns;
    }

    $candidates = array();
    if (isset($queryData['latest_query_data']) && is_array($queryData['latest_query_data']) &&
        isset($queryData['latest_query_data']['data']) && is_array($queryData['latest_query_data']['data']) &&
        isset($queryData['latest_query_data']['data']['columns']) && is_array($queryData['latest_query_data']['data']['columns'])) {
        $candidates = $queryData['latest_query_data']['data']['columns'];
    } elseif (isset($queryData['query_result']) && is_array($queryData['query_result']) &&
        isset($queryData['query_result']['data']) && is_array($queryData['query_result']['data']) &&
        isset($queryData['query_result']['data']['columns']) && is_array($queryData['query_result']['data']['columns'])) {
        $candidates = $queryData['query_result']['data']['columns'];
    }

    foreach ($candidates as $col) {
        if (!is_array($col)) {
            continue;
        }
        $name = isset($col['name']) ? trim((string)$col['name']) : '';
        if ($name === '') {
            continue;
        }
        $type = isset($col['type']) ? trim((string)$col['type']) : 'unknown';
        if ($type === '') {
            $type = 'unknown';
        }
        $columns[] = array('name' => $name, 'type' => $type);
    }

    return $columns;
}

function qrs_extract_columns_from_query_result($queryResult)
{
    $columns = array();
    if (!is_array($queryResult) || !isset($queryResult['data']) || !is_array($queryResult['data'])) {
        return $columns;
    }
    if (!isset($queryResult['data']['columns']) || !is_array($queryResult['data']['columns'])) {
        return $columns;
    }
    foreach ($queryResult['data']['columns'] as $col) {
        if (!is_array($col)) {
            continue;
        }
        $name = isset($col['name']) ? trim((string)$col['name']) : '';
        if ($name === '') {
            continue;
        }
        $type = isset($col['type']) ? trim((string)$col['type']) : 'unknown';
        if ($type === '') {
            $type = 'unknown';
        }
        $columns[] = array('name' => $name, 'type' => $type);
    }
    return $columns;
}

function qrs_build_default_query_parameters($paramDefs)
{
    $params = array();
    if (!is_array($paramDefs)) {
        return $params;
    }
    foreach ($paramDefs as $paramDef) {
        if (!is_array($paramDef) || !isset($paramDef['name'])) {
            continue;
        }
        $name = trim((string)$paramDef['name']);
        if ($name === '') {
            continue;
        }
        $params[$name] = isset($paramDef['default']) ? (string)$paramDef['default'] : '';
    }
    return $params;
}

function qrs_build_parameter_json($paramDefs, $post)
{
    $mapping = array();
    $allowedRelativeAnchor = array('now', 'bucket_at', 'today_start', 'this_month_start', 'this_year_start');
    $allowedRelativeUnit = array('minute', 'hour', 'day', 'month', 'year');
    $allowedRelativeRound = array('none', 'day_start', 'day_end', 'month_start', 'year_start', 'month_end', 'year_end');

    if (!is_array($paramDefs) || count($paramDefs) === 0) {
        return array('ok' => true, 'value' => '{}', 'message' => '');
    }

    foreach ($paramDefs as $paramDef) {
        $name = $paramDef['name'];
        $source = '';
        if (isset($post['param_source']) && is_array($post['param_source']) && isset($post['param_source'][$name])) {
            $source = trim((string)$post['param_source'][$name]);
        }

        if (!in_array($source, array('fixed', 'bucket_at', 'now', 'relative'), true)) {
            return array('ok' => false, 'value' => '', 'message' => t('variant_param_source_invalid', array('name' => $name)));
        }

        $format = 'YYYY-MM-DD HH:mm:ss';
        if (isset($post['param_format']) && is_array($post['param_format']) && isset($post['param_format'][$name])) {
            $format = trim((string)$post['param_format'][$name]);
        }
        if ($format === '') {
            return array('ok' => false, 'value' => '', 'message' => t('variant_param_format_required', array('name' => $name)));
        }

        if ($source === 'fixed') {
            $fixed = '';
            if (isset($post['param_fixed']) && is_array($post['param_fixed']) && isset($post['param_fixed'][$name])) {
                $fixed = (string)$post['param_fixed'][$name];
            }
            $mapping[$name] = array('source' => 'fixed', 'value' => $fixed, 'format' => $format);
        } elseif ($source === 'bucket_at') {
            $mapping[$name] = array('source' => 'bucket_at', 'format' => $format);
        } elseif ($source === 'now') {
            $mapping[$name] = array('source' => 'now', 'format' => $format);
        } else {
            $anchor = '';
            $offsetValue = '';
            $offsetUnit = '';
            $round = '';

            if (isset($post['param_relative_anchor']) && is_array($post['param_relative_anchor']) && isset($post['param_relative_anchor'][$name])) {
                $anchor = trim((string)$post['param_relative_anchor'][$name]);
            }
            if (isset($post['param_relative_offset_value']) && is_array($post['param_relative_offset_value']) && isset($post['param_relative_offset_value'][$name])) {
                $offsetValue = trim((string)$post['param_relative_offset_value'][$name]);
            }
            if (isset($post['param_relative_offset_unit']) && is_array($post['param_relative_offset_unit']) && isset($post['param_relative_offset_unit'][$name])) {
                $offsetUnit = trim((string)$post['param_relative_offset_unit'][$name]);
            }
            if (isset($post['param_relative_round']) && is_array($post['param_relative_round']) && isset($post['param_relative_round'][$name])) {
                $round = trim((string)$post['param_relative_round'][$name]);
            }

            if (!in_array($anchor, $allowedRelativeAnchor, true)) {
                return array('ok' => false, 'value' => '', 'message' => t('variant_param_relative_invalid', array('name' => $name)));
            }
            if ($offsetValue === '' || !preg_match('/^-?[0-9]+$/', $offsetValue)) {
                return array('ok' => false, 'value' => '', 'message' => t('variant_param_relative_invalid', array('name' => $name)));
            }
            if (!in_array($offsetUnit, $allowedRelativeUnit, true)) {
                return array('ok' => false, 'value' => '', 'message' => t('variant_param_relative_invalid', array('name' => $name)));
            }
            if (!in_array($round, $allowedRelativeRound, true)) {
                return array('ok' => false, 'value' => '', 'message' => t('variant_param_relative_invalid', array('name' => $name)));
            }

            $mapping[$name] = array(
                'source' => 'relative',
                'format' => $format,
                'relative' => array(
                    'anchor' => $anchor,
                    'offset' => array(
                        'value' => (int)$offsetValue,
                        'unit' => $offsetUnit,
                    ),
                    'round' => $round,
                ),
            );
        }
    }

    return array('ok' => true, 'value' => json_encode($mapping), 'message' => '');
}

function qrs_build_schedule_json($mode, $post)
{
    if ($mode === 'oneshot') {
        return array('ok' => true, 'value' => '{}', 'message' => '');
    }

    if ($mode === 'latest') {
        $interval = isset($post['schedule_interval']) ? trim((string)$post['schedule_interval']) : '';
        if ($interval === '') {
            return array('ok' => false, 'value' => '', 'message' => t('variant_schedule_interval_required', array()));
        }
        $schedule = array('interval' => $interval);
        return array('ok' => true, 'value' => json_encode($schedule), 'message' => '');
    }

    $interval = isset($post['schedule_interval']) ? trim((string)$post['schedule_interval']) : '';
    $lag = isset($post['schedule_lag']) ? trim((string)$post['schedule_lag']) : '';
    $lookback = isset($post['schedule_lookback']) ? trim((string)$post['schedule_lookback']) : '';
    $startAt = isset($post['schedule_start_at']) ? trim((string)$post['schedule_start_at']) : '';

    if ($interval === '' || $lag === '' || $lookback === '') {
        return array('ok' => false, 'value' => '', 'message' => t('variant_schedule_snapshot_required', array()));
    }

    $schedule = array(
        'interval' => $interval,
        'lag' => $lag,
        'lookback' => $lookback,
    );
    if ($startAt !== '') {
        $schedule['start_at'] = $startAt;
    }

    return array('ok' => true, 'value' => json_encode($schedule), 'message' => '');
}

function qrs_allowed_column_override_types()
{
    return QrsColumnTypeMapper::overrideTypes();
}

function qrs_auto_override_label($dbDriverName, $redashType)
{
    $resolved = QrsColumnTypeMapper::resolveStorageType($dbDriverName, $redashType, 'AUTO');
    return t('variant_type_override_auto_resolved', array('type' => $resolved));
}

function qrs_build_column_type_overrides_json($queryColumnTypes, $post)
{
    $map = array();
    $allowed = qrs_allowed_column_override_types();
    if (!is_array($queryColumnTypes) || count($queryColumnTypes) === 0) {
        return array('ok' => true, 'value' => '{}', 'message' => '');
    }

    $input = array();
    if (isset($post['column_type_override']) && is_array($post['column_type_override'])) {
        $input = $post['column_type_override'];
    }

    $i = 0;
    while ($i < count($queryColumnTypes)) {
        $col = $queryColumnTypes[$i];
        $name = isset($col['name']) ? trim((string)$col['name']) : '';
        if ($name === '') {
            $i++;
            continue;
        }
        $v = isset($input[$name]) ? strtoupper(trim((string)$input[$name])) : 'AUTO';
        if ($v === '' || $v === 'AUTO') {
            $i++;
            continue;
        }
        if (!in_array($v, $allowed, true)) {
            return array('ok' => false, 'value' => '', 'message' => t('variant_override_invalid_type', array('name' => $name, 'type' => $v)));
        }
        $map[$name] = $v;
        $i++;
    }

    return array('ok' => true, 'value' => json_encode($map), 'message' => '');
}

function qrs_decode_json_assoc($jsonText)
{
    if (!function_exists('json_decode')) {
        return array();
    }
    $data = json_decode((string)$jsonText, true);
    if (!is_array($data)) {
        return array();
    }
    return $data;
}

function qrs_param_value_for_form($paramName, $paramDefs, $variantParamData)
{
    if (isset($_POST['param_fixed']) && is_array($_POST['param_fixed']) && isset($_POST['param_fixed'][$paramName])) {
        return (string)$_POST['param_fixed'][$paramName];
    }
    if (isset($variantParamData[$paramName]) && is_array($variantParamData[$paramName]) && isset($variantParamData[$paramName]['value'])) {
        return (string)$variantParamData[$paramName]['value'];
    }
    foreach ($paramDefs as $d) {
        if ($d['name'] === $paramName) {
            return isset($d['default']) ? (string)$d['default'] : '';
        }
    }
    return '';
}

function qrs_param_source_for_form($paramName, $variantParamData)
{
    if (isset($_POST['param_source']) && is_array($_POST['param_source']) && isset($_POST['param_source'][$paramName])) {
        return (string)$_POST['param_source'][$paramName];
    }
    if (isset($variantParamData[$paramName]) && is_array($variantParamData[$paramName]) && isset($variantParamData[$paramName]['source'])) {
        return (string)$variantParamData[$paramName]['source'];
    }
    return 'fixed';
}

function qrs_param_format_for_form($paramName, $variantParamData)
{
    if (isset($_POST['param_format']) && is_array($_POST['param_format']) && isset($_POST['param_format'][$paramName])) {
        $v = trim((string)$_POST['param_format'][$paramName]);
        if ($v !== '') {
            return $v;
        }
    }
    if (isset($variantParamData[$paramName]) && is_array($variantParamData[$paramName]) && isset($variantParamData[$paramName]['format'])) {
        $v = trim((string)$variantParamData[$paramName]['format']);
        if ($v !== '') {
            return $v;
        }
    }
    return 'YYYY-MM-DD HH:mm:ss';
}

function qrs_param_relative_anchor_for_form($paramName, $variantParamData)
{
    if (isset($_POST['param_relative_anchor']) && is_array($_POST['param_relative_anchor']) && isset($_POST['param_relative_anchor'][$paramName])) {
        return (string)$_POST['param_relative_anchor'][$paramName];
    }
    if (isset($variantParamData[$paramName]['relative']['anchor'])) {
        return (string)$variantParamData[$paramName]['relative']['anchor'];
    }
    return 'now';
}

function qrs_param_relative_offset_value_for_form($paramName, $variantParamData)
{
    if (isset($_POST['param_relative_offset_value']) && is_array($_POST['param_relative_offset_value']) && isset($_POST['param_relative_offset_value'][$paramName])) {
        return (string)$_POST['param_relative_offset_value'][$paramName];
    }
    if (isset($variantParamData[$paramName]['relative']['offset']['value'])) {
        return (string)$variantParamData[$paramName]['relative']['offset']['value'];
    }
    return '0';
}

function qrs_param_relative_offset_unit_for_form($paramName, $variantParamData)
{
    if (isset($_POST['param_relative_offset_unit']) && is_array($_POST['param_relative_offset_unit']) && isset($_POST['param_relative_offset_unit'][$paramName])) {
        return (string)$_POST['param_relative_offset_unit'][$paramName];
    }
    if (isset($variantParamData[$paramName]['relative']['offset']['unit'])) {
        return (string)$variantParamData[$paramName]['relative']['offset']['unit'];
    }
    return 'day';
}

function qrs_param_relative_round_for_form($paramName, $variantParamData)
{
    if (isset($_POST['param_relative_round']) && is_array($_POST['param_relative_round']) && isset($_POST['param_relative_round'][$paramName])) {
        return (string)$_POST['param_relative_round'][$paramName];
    }
    if (isset($variantParamData[$paramName]['relative']['round'])) {
        return (string)$variantParamData[$paramName]['relative']['round'];
    }
    return 'none';
}

function qrs_post_text($name, $defaultValue)
{
    if (isset($_POST[$name]) && is_string($_POST[$name])) {
        return trim((string)$_POST[$name]);
    }
    return $defaultValue;
}

function qrs_column_override_for_form($columnName, $baseOverridesData)
{
    if (isset($_POST['column_type_override']) && is_array($_POST['column_type_override']) && isset($_POST['column_type_override'][$columnName])) {
        $v = strtoupper(trim((string)$_POST['column_type_override'][$columnName]));
        return ($v === '') ? 'AUTO' : $v;
    }
    if (isset($baseOverridesData[$columnName])) {
        $v = strtoupper(trim((string)$baseOverridesData[$columnName]));
        return ($v === '') ? 'AUTO' : $v;
    }
    return 'AUTO';
}

function qrs_build_redash_preview_urls($baseUrl, $queryId, $parameterPreview)
{
    $result = array(
        'query_page_url' => '',
        'query_page_with_params_url' => '',
    );

    $baseUrl = rtrim(trim((string)$baseUrl), '/');
    $queryId = trim((string)$queryId);
    if ($baseUrl === '' || $queryId === '' || !preg_match('/^[0-9]+$/', $queryId)) {
        return $result;
    }

    $queryPath = '/queries/' . $queryId;
    $result['query_page_url'] = $baseUrl . $queryPath;

    $params = array();
    if (is_array($parameterPreview)) {
        foreach ($parameterPreview as $row) {
            if (!is_array($row) || !isset($row['name'])) {
                continue;
            }
            $name = trim((string)$row['name']);
            if ($name === '') {
                continue;
            }
            $params['p_' . $name] = isset($row['value']) ? (string)$row['value'] : '';
        }
    }

    if (count($params) === 0) {
        $result['query_page_with_params_url'] = $result['query_page_url'];
    } else {
        $result['query_page_with_params_url'] = $result['query_page_url'] . '?' . http_build_query($params);
    }

    return $result;
}

function qrs_build_type_map_debug($baseUrl, $queryId, $params)
{
    $result = array();
    $base = rtrim(trim((string)$baseUrl), '/');
    $qid = trim((string)$queryId);
    if ($base === '' || $qid === '') {
        return $result;
    }
    $payload = array(
        'parameters' => is_array($params) ? $params : array(),
        'max_age' => 0,
    );
    $json = json_encode($payload);
    if (!is_string($json)) {
        $json = '{}';
    }

    $result[] = 'GET ' . $base . '/api/queries/' . $qid . '/results';
    $result[] = 'POST ' . $base . '/api/queries/' . $qid . '/results (fallback)';
    $result[] = 'payload: ' . $json . ' (for POST fallback)';
    $result[] = 'GET ' . $base . '/api/queries/' . $qid;
    return $result;
}

function qrs_variant_parameter_lines($parameterJson)
{
    $lines = array();
    $data = qrs_decode_json_assoc($parameterJson);
    if (!is_array($data) || count($data) === 0) {
        return $lines;
    }

    foreach ($data as $name => $rule) {
        if (!is_array($rule)) {
            $lines[] = (string)$name . ': (invalid)';
            continue;
        }
        $source = isset($rule['source']) ? (string)$rule['source'] : 'unknown';
        $format = isset($rule['format']) ? (string)$rule['format'] : '';

        if ($source === 'fixed') {
            $value = isset($rule['value']) ? (string)$rule['value'] : '';
            $lines[] = (string)$name . ': fixed="' . $value . '"' . ($format !== '' ? ' format=' . $format : '');
            continue;
        }
        if ($source === 'relative') {
            $anchor = isset($rule['relative']['anchor']) ? (string)$rule['relative']['anchor'] : 'now';
            $offsetValue = isset($rule['relative']['offset']['value']) ? (string)$rule['relative']['offset']['value'] : '0';
            $offsetUnit = isset($rule['relative']['offset']['unit']) ? (string)$rule['relative']['offset']['unit'] : 'day';
            $round = isset($rule['relative']['round']) ? (string)$rule['relative']['round'] : 'none';
            $lines[] = (string)$name . ': relative(anchor=' . $anchor . ', offset=' . $offsetValue . ' ' . $offsetUnit . ', round=' . $round . ')' . ($format !== '' ? ' format=' . $format : '');
            continue;
        }

        $lines[] = (string)$name . ': ' . $source . ($format !== '' ? ' format=' . $format : '');
    }

    return $lines;
}

function qrs_variant_schedule_lines($mode, $scheduleJson)
{
    $lines = array();
    $schedule = qrs_decode_json_assoc($scheduleJson);

    if ($mode === 'oneshot') {
        $lines[] = 'oneshot (no schedule)';
        return $lines;
    }

    if (!is_array($schedule) || count($schedule) === 0) {
        return $lines;
    }

    if (isset($schedule['interval']) && trim((string)$schedule['interval']) !== '') {
        $lines[] = 'interval: ' . (string)$schedule['interval'];
    }
    if (isset($schedule['lag']) && trim((string)$schedule['lag']) !== '') {
        $lines[] = 'lag: ' . (string)$schedule['lag'];
    }
    if (isset($schedule['lookback']) && trim((string)$schedule['lookback']) !== '') {
        $lines[] = 'lookback: ' . (string)$schedule['lookback'];
    }
    if (isset($schedule['start_at']) && trim((string)$schedule['start_at']) !== '') {
        $lines[] = 'start_at: ' . (string)$schedule['start_at'];
    }

    return $lines;
}

function qrs_variant_override_lines($overrideJson)
{
    $lines = array();
    $data = qrs_decode_json_assoc($overrideJson);
    if (!is_array($data) || count($data) === 0) {
        return $lines;
    }
    foreach ($data as $name => $type) {
        $col = trim((string)$name);
        $t = strtoupper(trim((string)$type));
        if ($col === '' || $t === '') {
            continue;
        }
        $lines[] = $col . ': ' . $t;
    }
    return $lines;
}

if ($runtimeOk && $dbOk && $isInitialized) {
    try {
        $datasetRepo = new QrsDatasetRepository($pdo);
        $variantRepo = new QrsVariantRepository($pdo);
        $instanceRepo = new QrsInstanceRepository($pdo);
        $client = new QrsRedashClient();

        $datasets = $datasetRepo->findAllWithInstance();

        if ($filterDatasetId !== '') {
            $selectedDataset = $datasetRepo->findById($filterDatasetId);
            if ($selectedDataset === null) {
                $error = t('variant_dataset_invalid', array());
                $filterDatasetId = '';
            } else {
                $instance = $instanceRepo->findById($selectedDataset['instance_id']);
                if ($instance === null || (int)$instance['is_enabled'] !== 1) {
                    $error = t('variant_dataset_instance_invalid', array());
                } else {
                    $selectedInstanceBaseUrl = (string)$instance['base_url'];
                    $queryInfo = $client->getQueryDetails($instance['base_url'], $instance['api_key'], $selectedDataset['query_id']);
                    if (!$queryInfo['ok']) {
                        $error = t('variant_query_fetch_failed', array('code' => $queryInfo['status_code'], 'message' => $queryInfo['message']));
                    } else {
                        $queryMetaOk = true;
                        $queryParamDefs = qrs_extract_query_params($queryInfo['data']);
                        $queryColumnTypes = qrs_extract_query_column_types($queryInfo['data']);
                        if (count($queryColumnTypes) === 0) {
                            $resultsInfo = $client->getQueryResults($instance['base_url'], $instance['api_key'], $selectedDataset['query_id']);
                            if ($resultsInfo['ok']) {
                                $resultsColumns = qrs_extract_query_column_types($resultsInfo['data']);
                                if (count($resultsColumns) > 0) {
                                    $queryColumnTypes = $resultsColumns;
                                }
                            }
                        }
                        if (count($queryColumnTypes) === 0) {
                            $defaultParams = qrs_build_default_query_parameters($queryParamDefs);
                            $previewExec = $client->executeQuery($instance['base_url'], $instance['api_key'], $selectedDataset['query_id'], $defaultParams, 30);
                            if ($previewExec['ok']) {
                                $resultsInfoAfterExec = $client->getQueryResults($instance['base_url'], $instance['api_key'], $selectedDataset['query_id']);
                                if ($resultsInfoAfterExec['ok']) {
                                    $resultsColumnsAfterExec = qrs_extract_query_column_types($resultsInfoAfterExec['data']);
                                    if (count($resultsColumnsAfterExec) > 0) {
                                        $queryColumnTypes = $resultsColumnsAfterExec;
                                    }
                                }
                                if (count($queryColumnTypes) === 0 && isset($previewExec['query_result']) && is_array($previewExec['query_result'])) {
                                    $liveColumns = qrs_extract_columns_from_query_result($previewExec['query_result']);
                                    if (count($liveColumns) > 0) {
                                        $queryColumnTypes = $liveColumns;
                                    }
                                }
                            }
                        }
                    }
                }

                $variants = $variantRepo->findAllWithDataset($filterDatasetId);
                if ($editVariantId !== '') {
                    $candidate = $variantRepo->findById($editVariantId);
                    if ($candidate === null || $candidate['dataset_id'] !== $filterDatasetId) {
                        $error = t('variant_not_found', array());
                    } else {
                        $editingVariant = $candidate;
                        $isEditMode = true;
                    }
                } elseif ($copyVariantId !== '') {
                    $candidate = $variantRepo->findById($copyVariantId);
                    if ($candidate === null || $candidate['dataset_id'] !== $filterDatasetId) {
                        $error = t('variant_not_found', array());
                    } else {
                        $copyingVariant = $candidate;
                        $isCopyMode = true;
                    }
                }
            }
        }
    } catch (Exception $e) {
        $error = t('variant_list_error', array('message' => $e->getMessage()));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $runtimeOk && $dbOk) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (!$isInitialized) {
        $error = t('schema_required_for_page', array());
    } else {
        $datasetRepo = new QrsDatasetRepository($pdo);
        $variantRepo = new QrsVariantRepository($pdo);

        if ($action === 'fetch_type_map') {
            $datasetId = isset($_POST['dataset_id']) ? trim($_POST['dataset_id']) : '';
            if ($datasetId === '' || $datasetId !== $filterDatasetId) {
                $error = t('variant_dataset_select_first', array());
            } else {
                try {
                    $instanceRepo = new QrsInstanceRepository($pdo);
                    $client = new QrsRedashClient();
                    $selectedDataset = $datasetRepo->findById($datasetId);
                    if ($selectedDataset === null) {
                        $error = t('variant_dataset_invalid', array());
                    } else {
                        $instance = $instanceRepo->findById($selectedDataset['instance_id']);
                        if ($instance === null || (int)$instance['is_enabled'] !== 1) {
                            $error = t('variant_dataset_instance_invalid', array());
                        } else {
                            $execParams = qrs_build_default_query_parameters($queryParamDefs);
                            $typeMapDebug = qrs_build_type_map_debug($instance['base_url'], $selectedDataset['query_id'], $execParams);
                            $resultsInfo = $client->getQueryResults($instance['base_url'], $instance['api_key'], $selectedDataset['query_id']);
                            if ($resultsInfo['ok']) {
                                $resultsColumns = qrs_extract_query_column_types($resultsInfo['data']);
                                if (count($resultsColumns) > 0) {
                                    $queryColumnTypes = $resultsColumns;
                                    $message = t('variant_type_map_fetch_ok', array('count' => count($resultsColumns)));
                                }
                            }
                            if (count($queryColumnTypes) === 0) {
                                $exec = $client->executeQuery($instance['base_url'], $instance['api_key'], $selectedDataset['query_id'], $execParams, 15);
                                if (!$exec['ok']) {
                                    $error = t('variant_type_map_fetch_error', array('message' => $exec['message']));
                                } else {
                                    $resultsInfoAfterExec = $client->getQueryResults($instance['base_url'], $instance['api_key'], $selectedDataset['query_id']);
                                    if ($resultsInfoAfterExec['ok']) {
                                        $resultsColumnsAfterExec = qrs_extract_query_column_types($resultsInfoAfterExec['data']);
                                        if (count($resultsColumnsAfterExec) > 0) {
                                            $queryColumnTypes = $resultsColumnsAfterExec;
                                            $message = t('variant_type_map_fetch_ok', array('count' => count($resultsColumnsAfterExec)));
                                        }
                                    }
                                }
                            }
                            if ($error === '' && count($queryColumnTypes) === 0) {
                                $error = t('variant_preview_type_map_none', array());
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error = t('variant_type_map_fetch_error', array('message' => $e->getMessage()));
                }
            }
        } elseif ($action === 'create_variant' || $action === 'validate_variant_create' || $action === 'update_variant_from_editor' || $action === 'validate_variant_update') {
            $datasetId = isset($_POST['dataset_id']) ? trim($_POST['dataset_id']) : '';
            $mode = isset($_POST['mode']) ? trim($_POST['mode']) : '';
            $isEnabled = isset($_POST['is_enabled']) && $_POST['is_enabled'] === '1';
            $targetVariantId = isset($_POST['variant_id']) ? trim($_POST['variant_id']) : '';
            $isUpdateAction = ($action === 'update_variant_from_editor' || $action === 'validate_variant_update');

            if ($datasetId === '' || $mode === '') {
                $error = t('variant_required', array());
            } elseif ($isUpdateAction && $targetVariantId === '') {
                $error = t('variant_id_invalid', array());
            } elseif (!qrs_is_valid_variant_mode($mode)) {
                $error = t('variant_mode_invalid', array());
            } elseif ($datasetId !== $filterDatasetId || !$queryMetaOk) {
                $error = t('variant_dataset_select_first', array());
            } else {
                $paramBuild = qrs_build_parameter_json($queryParamDefs, $_POST);
                $scheduleBuild = qrs_build_schedule_json($mode, $_POST);
                $overrideBuild = array('ok' => true, 'value' => '{}', 'message' => '');
                if (!$isUpdateAction) {
                    $overrideBuild = qrs_build_column_type_overrides_json($queryColumnTypes, $_POST);
                }

                if (!$paramBuild['ok']) {
                    $error = $paramBuild['message'];
                } elseif (!$scheduleBuild['ok']) {
                    $error = $scheduleBuild['message'];
                } elseif (!$overrideBuild['ok']) {
                    $error = $overrideBuild['message'];
                } else {
                    if ($action === 'validate_variant_create' || $action === 'validate_variant_update') {
                        try {
                            $createPreview = QrsDispatchPlanner::buildPreview(
                                $mode,
                                $paramBuild['value'],
                                $scheduleBuild['value'],
                                time(),
                                5,
                                0
                            );
                            $message = t('variant_validate_ok', array());
                        } catch (Exception $e) {
                            $error = t('variant_validate_error', array('message' => $e->getMessage()));
                        }
                    } else {
                        try {
                            if ($isUpdateAction) {
                                if ($variantRepo->updateSettings($targetVariantId, $mode, $paramBuild['value'], $scheduleBuild['value'])) {
                                    $variantRepo->setEnabled($targetVariantId, $isEnabled);
                                    $message = t('variant_updated', array('id' => $targetVariantId));
                                } else {
                                    $error = t('variant_not_found', array());
                                }
                            } else {
                                $variantId = $variantRepo->create($datasetId, $mode, $paramBuild['value'], $scheduleBuild['value'], $isEnabled, $overrideBuild['value']);
                                $message = t('variant_created', array('id' => $variantId));
                            }
                            $variants = $variantRepo->findAllWithDataset($filterDatasetId);
                        } catch (Exception $e) {
                            $error = $isUpdateAction
                                ? t('variant_update_error', array('message' => $e->getMessage()))
                                : t('variant_create_error', array('message' => $e->getMessage()));
                        }
                    }
                }
            }
        } elseif ($action === 'update_variant') {
            $variantId = isset($_POST['variant_id']) ? trim($_POST['variant_id']) : '';
            $datasetId = isset($_POST['dataset_id']) ? trim($_POST['dataset_id']) : '';
            $mode = isset($_POST['mode']) ? trim($_POST['mode']) : '';

            if ($variantId === '' || $datasetId === '' || $mode === '') {
                $error = t('variant_update_required', array());
            } elseif (!qrs_is_valid_variant_mode($mode)) {
                $error = t('variant_mode_invalid', array());
            } elseif ($datasetId !== $filterDatasetId || !$queryMetaOk) {
                $error = t('variant_dataset_select_first', array());
            } else {
                $paramBuild = qrs_build_parameter_json($queryParamDefs, $_POST);
                $scheduleBuild = qrs_build_schedule_json($mode, $_POST);

                if (!$paramBuild['ok']) {
                    $error = $paramBuild['message'];
                } elseif (!$scheduleBuild['ok']) {
                    $error = $scheduleBuild['message'];
                } else {
                    try {
                        if ($variantRepo->updateSettings($variantId, $mode, $paramBuild['value'], $scheduleBuild['value'])) {
                            $message = t('variant_updated', array('id' => $variantId));
                        } else {
                            $error = t('variant_not_found', array());
                        }
                        $variants = $variantRepo->findAllWithDataset($filterDatasetId);
                    } catch (Exception $e) {
                        $error = t('variant_update_error', array('message' => $e->getMessage()));
                    }
                }
            }
        } elseif ($action === 'toggle_variant') {
            $variantId = isset($_POST['variant_id']) ? trim($_POST['variant_id']) : '';
            $datasetId = isset($_POST['dataset_id']) ? trim($_POST['dataset_id']) : '';
            $nextEnabled = isset($_POST['next_enabled']) && $_POST['next_enabled'] === '1';

            if ($variantId === '' || $datasetId === '') {
                $error = t('variant_id_invalid', array());
            } else {
                try {
                    if ($variantRepo->setEnabled($variantId, $nextEnabled)) {
                        $message = t('variant_toggle_ok', array());
                    } else {
                        $error = t('variant_not_found', array());
                    }
                    $variants = $variantRepo->findAllWithDataset($filterDatasetId);
                } catch (Exception $e) {
                    $error = t('variant_toggle_error', array('message' => $e->getMessage()));
                }
            }
        }
    }
}

qrs_render_header('variants', t('app_title', array()), $message, $error);
?>

<?php if (!$runtimeOk): ?>
  <div class="box">
    <p class="error"><?php echo h(t('runtime_missing', array())); ?></p>
  </div>
<?php elseif (!$dbOk): ?>
  <div class="box">
    <p class="error"><?php echo h(t('db_failed', array('message' => $dbError))); ?></p>
    <p><a href="<?php echo h(qrs_url('env.php')); ?>"><?php echo h(t('go_environment', array())); ?></a></p>
  </div>
<?php elseif (!$isInitialized): ?>
  <div class="box">
    <p class="error"><?php echo h(t('schema_required_for_page', array())); ?></p>
    <p><a href="<?php echo h(qrs_url('env.php')); ?>"><?php echo h(t('go_environment', array())); ?></a></p>
  </div>
<?php elseif (count($datasets) === 0): ?>
  <div class="box">
    <p class="error"><?php echo h(t('variant_no_dataset', array())); ?></p>
    <p><a href="<?php echo h(qrs_url('datasets.php')); ?>"><?php echo h(t('go_datasets', array())); ?></a></p>
  </div>
<?php else: ?>
  <div class="box">
    <h2><?php echo h(t('variant_dataset_select_title', array())); ?></h2>
    <form method="get" action="variants.php">
      <label><?php echo h(t('variant_dataset', array())); ?></label>
      <select name="dataset_id" required>
        <option value=""><?php echo h(t('select_option', array())); ?></option>
        <?php foreach ($datasets as $dataset): ?>
          <?php $selected = ($filterDatasetId !== '' && $filterDatasetId === $dataset['dataset_id']) ? ' selected' : ''; ?>
          <option value="<?php echo h($dataset['dataset_id']); ?>"<?php echo $selected; ?>><?php echo h($dataset['dataset_id'] . ' / q=' . $dataset['query_id']); ?></option>
        <?php endforeach; ?>
      </select>
      <div style="margin-top:10px;">
        <button type="submit"><?php echo h(t('variant_dataset_select_button', array())); ?></button>
      </div>
    </form>
  </div>

  <?php if ($filterDatasetId !== '' && $selectedDataset !== null): ?>
    <div class="box">
      <h2><?php echo h($isEditMode ? t('variant_edit_title', array()) : t('variant_create', array())); ?></h2>
      <p><code><?php echo h($selectedDataset['dataset_id']); ?></code> / query_id=<code><?php echo h($selectedDataset['query_id']); ?></code></p>
      <?php if ($isEditMode): ?>
        <div style="margin:12px 0; padding:10px 12px; border:1px solid #d97706; background:#fff7ed; border-radius:4px;">
          <p style="margin:0 0 6px 0; font-weight:bold; color:#9a3412;"><?php echo h(t('variant_history_notice_title', array())); ?></p>
          <p style="margin:0 0 4px 0; color:#7c2d12;"><?php echo h(t('variant_history_notice_line1', array())); ?></p>
          <p style="margin:0 0 4px 0; color:#7c2d12;"><?php echo h(t('variant_history_notice_line2', array())); ?></p>
          <p style="margin:0; color:#7c2d12;"><?php echo h(t('variant_history_notice_line3', array())); ?></p>
        </div>
      <?php endif; ?>
      <?php if ($isEditMode): ?>
        <p class="muted"><?php echo h(t('variant_editing_id', array('id' => $editingVariant['variant_id']))); ?> <a href="<?php echo h(qrs_url_with_params('variants.php', array('dataset_id' => $filterDatasetId))); ?>"><?php echo h(t('variant_edit_cancel', array())); ?></a></p>
      <?php elseif ($isCopyMode): ?>
        <p class="muted"><?php echo h(t('variant_copying_id', array('id' => $copyingVariant['variant_id']))); ?> <a href="<?php echo h(qrs_url_with_params('variants.php', array('dataset_id' => $filterDatasetId))); ?>"><?php echo h(t('variant_copy_cancel', array())); ?></a></p>
      <?php endif; ?>
      <?php
        $sourceVariant = null;
        if ($isEditMode) {
            $sourceVariant = $editingVariant;
        } elseif ($isCopyMode) {
            $sourceVariant = $copyingVariant;
        }
        $editorParamData = ($sourceVariant !== null) ? qrs_decode_json_assoc($sourceVariant['parameter_json']) : array();
        $editorScheduleData = ($sourceVariant !== null) ? qrs_decode_json_assoc($sourceVariant['schedule_json']) : array();
        $editorOverrideData = ($sourceVariant !== null && isset($sourceVariant['column_type_overrides_json'])) ? qrs_decode_json_assoc($sourceVariant['column_type_overrides_json']) : array();
        $createMode = qrs_post_text('mode', ($sourceVariant !== null) ? $sourceVariant['mode'] : 'snapshot');
        $createScheduleInterval = qrs_post_text('schedule_interval', isset($editorScheduleData['interval']) ? (string)$editorScheduleData['interval'] : '1 hour');
        $createScheduleLag = qrs_post_text('schedule_lag', isset($editorScheduleData['lag']) ? (string)$editorScheduleData['lag'] : '5 minutes');
        $createScheduleLookback = qrs_post_text('schedule_lookback', isset($editorScheduleData['lookback']) ? (string)$editorScheduleData['lookback'] : '1 interval');
        $createScheduleStartAt = qrs_post_text('schedule_start_at', isset($editorScheduleData['start_at']) ? (string)$editorScheduleData['start_at'] : '');
        $createIsEnabled = qrs_post_text('is_enabled', ($sourceVariant !== null) ? ((((int)$sourceVariant['is_enabled'] === 1) ? '1' : '0')) : '1');
        $validateAction = $isEditMode ? 'validate_variant_update' : 'validate_variant_create';
        $saveAction = $isEditMode ? 'update_variant_from_editor' : 'create_variant';
        $saveButtonLabel = $isEditMode ? t('variant_update_button', array()) : t('variant_create_button', array());
      ?>

      <?php if (!$queryMetaOk): ?>
        <p class="error"><?php echo h(t('variant_query_fetch_required', array())); ?></p>
      <?php else: ?>
        <form method="post">
          <?php echo qrs_lang_input_html(); ?>
          <input type="hidden" name="dataset_id" value="<?php echo h($selectedDataset['dataset_id']); ?>">
          <?php if ($isEditMode): ?>
            <input type="hidden" name="variant_id" value="<?php echo h($editingVariant['variant_id']); ?>">
          <?php endif; ?>

          <label><?php echo h(t('variant_mode', array())); ?></label>
          <select name="mode" class="js-mode-selector" data-target-prefix="create" required>
            <option value="snapshot"<?php echo ($createMode === 'snapshot') ? ' selected' : ''; ?>>snapshot</option>
            <option value="latest"<?php echo ($createMode === 'latest') ? ' selected' : ''; ?>>latest</option>
            <option value="oneshot"<?php echo ($createMode === 'oneshot') ? ' selected' : ''; ?>>oneshot</option>
          </select>

          <h3><?php echo h(t('variant_parameters', array())); ?></h3>
          <?php if (count($queryParamDefs) === 0): ?>
            <p class="muted"><?php echo h(t('variant_parameters_none', array())); ?></p>
          <?php else: ?>
            <table class="param-table">
              <colgroup>
                <col style="width:14%;">
                <col style="width:14%;">
                <col style="width:18%;">
                <col style="width:30%;">
                <col style="width:24%;">
              </colgroup>
              <thead>
                <tr>
                  <th><?php echo h(t('variant_param_name', array())); ?></th>
                  <th><?php echo h(t('variant_param_source', array())); ?></th>
                  <th><?php echo h(t('variant_param_fixed_value', array())); ?></th>
                  <th><?php echo h(t('variant_param_relative', array())); ?></th>
                  <th><?php echo h(t('variant_param_format', array())); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($queryParamDefs as $p): ?>
                <?php
                  $paramName = $p['name'];
                  $formatInputId = 'create-format-' . $paramName;
                  $relativeWrapId = 'create-relative-' . $paramName;
                  $paramSource = qrs_param_source_for_form($paramName, $editorParamData);
                  $paramValue = qrs_param_value_for_form($paramName, $queryParamDefs, $editorParamData);
                  $paramFormat = qrs_param_format_for_form($paramName, $editorParamData);
                  $relativeAnchor = qrs_param_relative_anchor_for_form($paramName, $editorParamData);
                  $relativeOffsetValue = qrs_param_relative_offset_value_for_form($paramName, $editorParamData);
                  $relativeOffsetUnit = qrs_param_relative_offset_unit_for_form($paramName, $editorParamData);
                  $relativeRound = qrs_param_relative_round_for_form($paramName, $editorParamData);
                  $relativeDisplay = ($paramSource === 'relative') ? '' : 'display:none;';
                ?>
                <tr>
                  <td><code><?php echo h($paramName); ?></code></td>
                  <td>
                    <select name="param_source[<?php echo h($paramName); ?>]" class="js-param-source" data-fixed-id="create-fixed-<?php echo h($paramName); ?>" data-relative-id="<?php echo h($relativeWrapId); ?>">
                      <option value="fixed"<?php echo ($paramSource === 'fixed') ? ' selected' : ''; ?>>Fixed</option>
                      <option value="bucket_at"<?php echo ($paramSource === 'bucket_at') ? ' selected' : ''; ?>>BucketAt</option>
                      <option value="now"<?php echo ($paramSource === 'now') ? ' selected' : ''; ?>>Now</option>
                      <option value="relative"<?php echo ($paramSource === 'relative') ? ' selected' : ''; ?>>Relative</option>
                    </select>
                  </td>
                  <td>
                    <input id="create-fixed-<?php echo h($paramName); ?>" type="text" name="param_fixed[<?php echo h($paramName); ?>]" value="<?php echo h($paramValue); ?>">
                  </td>
                  <td>
                    <div id="<?php echo h($relativeWrapId); ?>" class="js-relative-wrap" style="<?php echo h($relativeDisplay); ?>">
                      <select name="param_relative_anchor[<?php echo h($paramName); ?>]">
                        <option value="now"<?php echo ($relativeAnchor === 'now') ? ' selected' : ''; ?>>now</option>
                        <option value="bucket_at"<?php echo ($relativeAnchor === 'bucket_at') ? ' selected' : ''; ?>>bucket_at</option>
                        <option value="today_start"<?php echo ($relativeAnchor === 'today_start') ? ' selected' : ''; ?>>today_start</option>
                        <option value="this_month_start"<?php echo ($relativeAnchor === 'this_month_start') ? ' selected' : ''; ?>>this_month_start</option>
                        <option value="this_year_start"<?php echo ($relativeAnchor === 'this_year_start') ? ' selected' : ''; ?>>this_year_start</option>
                      </select>
                      <input type="text" name="param_relative_offset_value[<?php echo h($paramName); ?>]" value="<?php echo h($relativeOffsetValue); ?>" style="max-width:80px;">
                      <select name="param_relative_offset_unit[<?php echo h($paramName); ?>]">
                        <option value="minute"<?php echo ($relativeOffsetUnit === 'minute') ? ' selected' : ''; ?>>minute</option>
                        <option value="hour"<?php echo ($relativeOffsetUnit === 'hour') ? ' selected' : ''; ?>>hour</option>
                        <option value="day"<?php echo ($relativeOffsetUnit === 'day') ? ' selected' : ''; ?>>day</option>
                        <option value="month"<?php echo ($relativeOffsetUnit === 'month') ? ' selected' : ''; ?>>month</option>
                        <option value="year"<?php echo ($relativeOffsetUnit === 'year') ? ' selected' : ''; ?>>year</option>
                      </select>
                      <select name="param_relative_round[<?php echo h($paramName); ?>]">
                        <option value="none"<?php echo ($relativeRound === 'none') ? ' selected' : ''; ?>>none</option>
                        <option value="day_start"<?php echo ($relativeRound === 'day_start') ? ' selected' : ''; ?>>day_start</option>
                        <option value="day_end"<?php echo ($relativeRound === 'day_end') ? ' selected' : ''; ?>>day_end</option>
                        <option value="month_start"<?php echo ($relativeRound === 'month_start') ? ' selected' : ''; ?>>month_start</option>
                        <option value="year_start"<?php echo ($relativeRound === 'year_start') ? ' selected' : ''; ?>>year_start</option>
                        <option value="month_end"<?php echo ($relativeRound === 'month_end') ? ' selected' : ''; ?>>month_end</option>
                        <option value="year_end"<?php echo ($relativeRound === 'year_end') ? ' selected' : ''; ?>>year_end</option>
                      </select>
                      <div class="preset-buttons">
                        <button type="button" class="js-relative-preset" data-anchor-name="param_relative_anchor[<?php echo h($paramName); ?>]" data-offset-name="param_relative_offset_value[<?php echo h($paramName); ?>]" data-unit-name="param_relative_offset_unit[<?php echo h($paramName); ?>]" data-round-name="param_relative_round[<?php echo h($paramName); ?>]" data-anchor="now" data-offset="-1" data-unit="day" data-round="day_start"><?php echo h(t('variant_relative_yesterday', array())); ?></button>
                        <button type="button" class="js-relative-preset" data-anchor-name="param_relative_anchor[<?php echo h($paramName); ?>]" data-offset-name="param_relative_offset_value[<?php echo h($paramName); ?>]" data-unit-name="param_relative_offset_unit[<?php echo h($paramName); ?>]" data-round-name="param_relative_round[<?php echo h($paramName); ?>]" data-anchor="now" data-offset="-1" data-unit="day" data-round="day_end"><?php echo h(t('variant_relative_yesterday_end', array())); ?></button>
                        <button type="button" class="js-relative-preset" data-anchor-name="param_relative_anchor[<?php echo h($paramName); ?>]" data-offset-name="param_relative_offset_value[<?php echo h($paramName); ?>]" data-unit-name="param_relative_offset_unit[<?php echo h($paramName); ?>]" data-round-name="param_relative_round[<?php echo h($paramName); ?>]" data-anchor="now" data-offset="0" data-unit="month" data-round="month_start"><?php echo h(t('variant_relative_this_month_first', array())); ?></button>
                        <button type="button" class="js-relative-preset" data-anchor-name="param_relative_anchor[<?php echo h($paramName); ?>]" data-offset-name="param_relative_offset_value[<?php echo h($paramName); ?>]" data-unit-name="param_relative_offset_unit[<?php echo h($paramName); ?>]" data-round-name="param_relative_round[<?php echo h($paramName); ?>]" data-anchor="now" data-offset="-1" data-unit="month" data-round="month_start"><?php echo h(t('variant_relative_last_month_first', array())); ?></button>
                        <button type="button" class="js-relative-preset" data-anchor-name="param_relative_anchor[<?php echo h($paramName); ?>]" data-offset-name="param_relative_offset_value[<?php echo h($paramName); ?>]" data-unit-name="param_relative_offset_unit[<?php echo h($paramName); ?>]" data-round-name="param_relative_round[<?php echo h($paramName); ?>]" data-anchor="now" data-offset="-1" data-unit="month" data-round="month_end"><?php echo h(t('variant_relative_last_month_end', array())); ?></button>
                        <button type="button" class="js-relative-preset" data-anchor-name="param_relative_anchor[<?php echo h($paramName); ?>]" data-offset-name="param_relative_offset_value[<?php echo h($paramName); ?>]" data-unit-name="param_relative_offset_unit[<?php echo h($paramName); ?>]" data-round-name="param_relative_round[<?php echo h($paramName); ?>]" data-anchor="now" data-offset="-1" data-unit="year" data-round="year_start"><?php echo h(t('variant_relative_last_year', array())); ?></button>
                        <button type="button" class="js-relative-preset" data-anchor-name="param_relative_anchor[<?php echo h($paramName); ?>]" data-offset-name="param_relative_offset_value[<?php echo h($paramName); ?>]" data-unit-name="param_relative_offset_unit[<?php echo h($paramName); ?>]" data-round-name="param_relative_round[<?php echo h($paramName); ?>]" data-anchor="now" data-offset="-1" data-unit="year" data-round="year_end"><?php echo h(t('variant_relative_last_year_end', array())); ?></button>
                      </div>
                    </div>
                  </td>
                  <td>
                    <input id="<?php echo h($formatInputId); ?>" type="text" name="param_format[<?php echo h($paramName); ?>]" value="<?php echo h($paramFormat); ?>" placeholder="YYYY-MM-DD HH:mm:ss">
                    <div class="preset-buttons">
                      <button type="button" class="js-preset-btn" data-target-id="<?php echo h($formatInputId); ?>" data-value="YYYY-MM-DD">YYYY-MM-DD</button>
                      <button type="button" class="js-preset-btn" data-target-id="<?php echo h($formatInputId); ?>" data-value="YYYYMM">YYYYMM</button>
                      <button type="button" class="js-preset-btn" data-target-id="<?php echo h($formatInputId); ?>" data-value="YYYYMMDD">YYYYMMDD</button>
                      <button type="button" class="js-preset-btn" data-target-id="<?php echo h($formatInputId); ?>" data-value="YYYY-MM-DD HH:mm:ss">DATETIME</button>
                      <button type="button" class="js-preset-btn" data-target-id="<?php echo h($formatInputId); ?>" data-value="YYYY-MM-DDTHH:mm:ssZ">ISO8601_Z</button>
                      <button type="button" class="js-preset-btn" data-target-id="<?php echo h($formatInputId); ?>" data-value="UNIX_EPOCH">UNIX_EPOCH</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <h3><?php echo h(t('variant_schedule', array())); ?></h3>
          <div class="schedule-preset-list">
            <div class="schedule-preset-item">
              <button type="button" class="js-schedule-preset" data-target-prefix="create" data-mode="snapshot" data-interval="1 month" data-lag="5 days" data-lookback="1 interval"><?php echo h(t('variant_schedule_preset_monthly_5d', array())); ?></button>
              <span class="muted"><?php echo h(t('variant_schedule_preset_desc_monthly_5d', array())); ?></span>
            </div>
            <div class="schedule-preset-item">
              <button type="button" class="js-schedule-preset" data-target-prefix="create" data-mode="snapshot" data-interval="1 day" data-lag="1 hour 15 minutes" data-lookback="1 interval"><?php echo h(t('variant_schedule_preset_daily_1h15', array())); ?></button>
              <span class="muted"><?php echo h(t('variant_schedule_preset_desc_daily_1h15', array())); ?></span>
            </div>
            <div class="schedule-preset-item">
              <button type="button" class="js-schedule-preset" data-target-prefix="create" data-mode="snapshot" data-interval="1 hour" data-lag="15 minutes" data-lookback="1 interval"><?php echo h(t('variant_schedule_preset_hourly_15m', array())); ?></button>
              <span class="muted"><?php echo h(t('variant_schedule_preset_desc_hourly_15m', array())); ?></span>
            </div>
            <div class="schedule-preset-item">
              <button type="button" class="js-schedule-preset" data-target-prefix="create" data-mode="snapshot" data-interval="5 minutes" data-lag="2 minutes" data-lookback="1 interval"><?php echo h(t('variant_schedule_preset_5m_2m', array())); ?></button>
              <span class="muted"><?php echo h(t('variant_schedule_preset_desc_5m_2m', array())); ?></span>
            </div>
          </div>
          <div class="js-schedule js-schedule-snapshot" data-mode-prefix="create"<?php echo ($createMode === 'snapshot') ? '' : ' style="display:none;"'; ?>>
            <label><?php echo h(t('variant_schedule_interval', array())); ?></label>
            <p class="muted"><?php echo h(t('variant_schedule_help_interval', array())); ?></p>
            <input id="create-snapshot-interval" type="text" name="schedule_interval" value="<?php echo h($createScheduleInterval); ?>" placeholder="1 hour">
            <div class="preset-buttons">
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-interval" data-value="5 minutes">5m</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-interval" data-value="15 minutes">15m</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-interval" data-value="1 hour">1h</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-interval" data-value="1 day">1d</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-interval" data-value="1 month">1mo</button>
            </div>
            <label><?php echo h(t('variant_schedule_lag', array())); ?></label>
            <p class="muted"><?php echo h(t('variant_schedule_help_lag', array())); ?></p>
            <input id="create-snapshot-lag" type="text" name="schedule_lag" value="<?php echo h($createScheduleLag); ?>" placeholder="5 minutes">
            <div class="preset-buttons">
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-lag" data-value="0 minutes">0m</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-lag" data-value="5 minutes">5m</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-lag" data-value="15 minutes">15m</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-lag" data-value="1 hour">1h</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-lag" data-value="5 days">5d</button>
            </div>
            <label><?php echo h(t('variant_schedule_lookback', array())); ?></label>
            <p class="muted"><?php echo h(t('variant_schedule_help_lookback', array())); ?></p>
            <input id="create-snapshot-lookback" type="text" name="schedule_lookback" value="<?php echo h($createScheduleLookback); ?>" placeholder="1 interval">
            <div class="preset-buttons">
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-lookback" data-value="1 interval">1 interval</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-lookback" data-value="24 intervals">24 intervals</button>
              <button type="button" class="js-preset-btn" data-target-id="create-snapshot-lookback" data-value="7 days">7 days</button>
            </div>
            <label><?php echo h(t('variant_schedule_start_at', array())); ?></label>
            <p class="muted"><?php echo h(t('variant_schedule_help_start_at', array())); ?></p>
            <input type="text" name="schedule_start_at" value="<?php echo h($createScheduleStartAt); ?>" placeholder="2026-01-01 00:00:00 (optional)">
          </div>
          <div class="js-schedule js-schedule-latest" data-mode-prefix="create"<?php echo ($createMode === 'latest') ? '' : ' style="display:none;"'; ?>>
            <label><?php echo h(t('variant_schedule_interval', array())); ?></label>
            <p class="muted"><?php echo h(t('variant_schedule_help_interval', array())); ?></p>
            <input id="create-latest-interval" type="text" name="schedule_interval" value="<?php echo h($createScheduleInterval); ?>" placeholder="1 hour">
            <div class="preset-buttons">
              <button type="button" class="js-preset-btn" data-target-id="create-latest-interval" data-value="5 minutes">5m</button>
              <button type="button" class="js-preset-btn" data-target-id="create-latest-interval" data-value="15 minutes">15m</button>
              <button type="button" class="js-preset-btn" data-target-id="create-latest-interval" data-value="1 hour">1h</button>
              <button type="button" class="js-preset-btn" data-target-id="create-latest-interval" data-value="1 day">1d</button>
              <button type="button" class="js-preset-btn" data-target-id="create-latest-interval" data-value="1 month">1mo</button>
            </div>
          </div>
          <div class="js-schedule js-schedule-oneshot" data-mode-prefix="create"<?php echo ($createMode === 'oneshot') ? '' : ' style="display:none;"'; ?>>
            <p class="muted"><?php echo h(t('variant_schedule_oneshot_hint', array())); ?></p>
          </div>

          <h3><?php echo h(t('variant_type_override', array())); ?></h3>
          <?php if (count($queryColumnTypes) === 0): ?>
            <p class="muted"><?php echo h(t('variant_preview_type_map_none', array())); ?></p>
            <div style="margin-top:10px;">
              <button type="submit" name="action" value="fetch_type_map"><?php echo h(t('variant_type_map_fetch_button', array())); ?></button>
            </div>
            <?php if (count($typeMapDebug) > 0): ?>
              <p class="muted"><?php echo h(t('variant_type_map_debug_title', array())); ?></p>
              <?php foreach ($typeMapDebug as $line): ?>
                <div><code><?php echo h($line); ?></code></div>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php else: ?>
            <?php if ($isEditMode): ?>
              <p class="muted"><?php echo h(t('variant_type_override_locked_note', array())); ?></p>
            <?php endif; ?>
            <table>
              <thead><tr><th><?php echo h(t('variant_type_override_column', array())); ?></th><th><?php echo h(t('variant_type_override_detected', array())); ?></th><th><?php echo h(t('variant_type_override_setting', array())); ?></th></tr></thead>
              <tbody>
              <?php foreach ($queryColumnTypes as $col): ?>
                <?php
                  $colName = isset($col['name']) ? (string)$col['name'] : '';
                  $detectedType = isset($col['type']) ? (string)$col['type'] : 'unknown';
                  $overrideType = qrs_column_override_for_form($colName, $editorOverrideData);
                ?>
                <tr>
                  <td><code><?php echo h($colName); ?></code></td>
                  <td><?php echo h($detectedType); ?></td>
                  <td>
                    <?php if ($isEditMode): ?>
                      <code><?php echo h($overrideType); ?></code>
                    <?php else: ?>
                      <select name="column_type_override[<?php echo h($colName); ?>]">
                        <option value="AUTO"<?php echo ($overrideType === 'AUTO') ? ' selected' : ''; ?>><?php echo h(qrs_auto_override_label($dbDriverName, $detectedType)); ?></option>
                        <?php foreach (qrs_allowed_column_override_types() as $ot): ?>
                          <option value="<?php echo h($ot); ?>"<?php echo ($overrideType === $ot) ? ' selected' : ''; ?>><?php echo h($ot); ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <label><?php echo h(t('is_enabled', array())); ?></label>
          <select name="is_enabled">
            <option value="1"<?php echo ($createIsEnabled === '1') ? ' selected' : ''; ?>><?php echo h(t('enabled', array())); ?></option>
            <option value="0"<?php echo ($createIsEnabled === '0') ? ' selected' : ''; ?>><?php echo h(t('disabled', array())); ?></option>
          </select>

          <div style="margin-top:10px;">
            <button type="submit" name="action" value="<?php echo h($validateAction); ?>"><?php echo h(t('variant_validate_button', array())); ?></button>
          </div>

          <?php if ($createPreview !== null): ?>
          <?php $previewUrls = qrs_build_redash_preview_urls($selectedInstanceBaseUrl, $selectedDataset['query_id'], $createPreview['parameter_preview']); ?>
          <hr>
          <h3><?php echo h(t('variant_preview_title', array())); ?></h3>
          <p class="muted"><?php echo h(t('variant_preview_now', array('now' => $createPreview['now']))); ?></p>

          <h4><?php echo h(t('variant_preview_fetch_url', array())); ?></h4>
          <p class="muted"><?php echo h(t('variant_preview_fetch_url_note', array())); ?></p>
          <?php if ($previewUrls['query_page_url'] === ''): ?>
            <p class="muted"><?php echo h(t('variant_preview_none', array())); ?></p>
          <?php else: ?>
            <div><a href="<?php echo h($previewUrls['query_page_url']); ?>" target="_blank" rel="noopener"><?php echo h($previewUrls['query_page_url']); ?></a></div>
            <div><a href="<?php echo h($previewUrls['query_page_with_params_url']); ?>" target="_blank" rel="noopener"><?php echo h($previewUrls['query_page_with_params_url']); ?></a></div>
          <?php endif; ?>

          <h4><?php echo h(t('variant_preview_next_runs', array())); ?></h4>
          <?php if (count($createPreview['next_runs']) === 0): ?>
            <p class="muted"><?php echo h(t('variant_preview_none', array())); ?></p>
          <?php else: ?>
            <table>
              <thead><tr><th>bucket_at</th><th>execute_at</th><th>parameters</th></tr></thead>
              <tbody>
              <?php foreach ($createPreview['next_runs'] as $row): ?>
                <tr>
                  <td><code><?php echo h($row['bucket_at']); ?></code></td>
                  <td><code><?php echo h($row['execute_at']); ?></code></td>
                  <td>
                    <?php if (!isset($row['parameter_values']) || !is_array($row['parameter_values']) || count($row['parameter_values']) === 0): ?>
                      <span class="muted">-</span>
                    <?php else: ?>
                      <?php foreach ($row['parameter_values'] as $pv): ?>
                        <div><code><?php echo h($pv['name']); ?></code>=<code><?php echo h($pv['value']); ?></code> <span class="muted">(<?php echo h($pv['source']); ?>)</span></div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <h4><?php echo h(t('variant_preview_lookback_targets', array())); ?></h4>
          <?php if (count($createPreview['lookback_targets']) === 0): ?>
            <p class="muted"><?php echo h(t('variant_preview_none', array())); ?></p>
          <?php else: ?>
            <table>
              <thead><tr><th>bucket_at</th><th>execute_after</th><th>parameters</th></tr></thead>
              <tbody>
              <?php foreach ($createPreview['lookback_targets'] as $row): ?>
                <tr>
                  <td><code><?php echo h($row['bucket_at']); ?></code></td>
                  <td><code><?php echo h($row['execute_after']); ?></code></td>
                  <td>
                    <?php if (!isset($row['parameter_values']) || !is_array($row['parameter_values']) || count($row['parameter_values']) === 0): ?>
                      <span class="muted">-</span>
                    <?php else: ?>
                      <?php foreach ($row['parameter_values'] as $pv): ?>
                        <div><code><?php echo h($pv['name']); ?></code>=<code><?php echo h($pv['value']); ?></code> <span class="muted">(<?php echo h($pv['source']); ?>)</span></div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

            <div style="margin-top:12px;">
              <button type="submit" name="action" value="<?php echo h($saveAction); ?>"><?php echo h($saveButtonLabel); ?></button>
            </div>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div>

    <div class="box">
      <h2><?php echo h(t('variant_list', array())); ?></h2>
      <?php if (count($variants) === 0): ?>
        <p><?php echo h(t('variant_none', array())); ?></p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th><?php echo h(t('variant_id', array())); ?></th>
              <th><?php echo h(t('dataset_id', array())); ?></th>
              <th><?php echo h(t('variant_mode', array())); ?></th>
              <th><?php echo h(t('variant_parameters', array())); ?></th>
              <th><?php echo h(t('variant_schedule', array())); ?></th>
              <th><?php echo h(t('variant_type_override', array())); ?></th>
              <th><?php echo h(t('col_enabled', array())); ?></th>
              <th><?php echo h(t('col_actions', array())); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($variants as $variant): ?>
            <tr>
              <td><code><?php echo h($variant['variant_id']); ?></code></td>
              <td><code><?php echo h($variant['dataset_id']); ?></code></td>
              <td><?php echo h($variant['mode']); ?></td>
              <td>
                <?php $parameterLines = qrs_variant_parameter_lines($variant['parameter_json']); ?>
                <?php if (count($parameterLines) === 0): ?>
                  <span class="muted">-</span>
                <?php else: ?>
                  <?php foreach ($parameterLines as $line): ?>
                    <div><code><?php echo h($line); ?></code></div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td>
                <?php $scheduleLines = qrs_variant_schedule_lines($variant['mode'], $variant['schedule_json']); ?>
                <?php if (count($scheduleLines) === 0): ?>
                  <span class="muted">-</span>
                <?php else: ?>
                  <?php foreach ($scheduleLines as $line): ?>
                    <div><code><?php echo h($line); ?></code></div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td>
                <?php $overrideLines = qrs_variant_override_lines(isset($variant['column_type_overrides_json']) ? $variant['column_type_overrides_json'] : '{}'); ?>
                <?php if (count($overrideLines) === 0): ?>
                  <span class="muted"><?php echo h(t('variant_type_override_auto', array())); ?></span>
                <?php else: ?>
                  <?php foreach ($overrideLines as $line): ?>
                    <div><code><?php echo h($line); ?></code></div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td><?php echo ((int)$variant['is_enabled'] === 1) ? h(t('yes', array())) : h(t('no', array())); ?></td>
              <td>
                <a href="<?php echo h(qrs_url_with_params('variants.php', array('dataset_id' => $variant['dataset_id'], 'edit_variant_id' => $variant['variant_id']))); ?>"><?php echo h(t('variant_edit_open', array())); ?></a>
                <br>
                <a href="<?php echo h(qrs_url_with_params('variants.php', array('dataset_id' => $variant['dataset_id'], 'copy_variant_id' => $variant['variant_id']))); ?>"><?php echo h(t('variant_copy_open', array())); ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
(function () {
  function toggleSchedule(prefix, mode) {
    var snapshot = document.querySelector('.js-schedule-snapshot[data-mode-prefix="' + prefix + '"]');
    var latest = document.querySelector('.js-schedule-latest[data-mode-prefix="' + prefix + '"]');
    var oneshot = document.querySelector('.js-schedule-oneshot[data-mode-prefix="' + prefix + '"]');

    function setSectionState(el, visible) {
      if (!el) return;
      el.style.display = visible ? '' : 'none';
      var controls = el.querySelectorAll('input, textarea, select');
      for (var i = 0; i < controls.length; i++) {
        controls[i].disabled = !visible;
      }
    }

    setSectionState(snapshot, mode === 'snapshot');
    setSectionState(latest, mode === 'latest');
    setSectionState(oneshot, mode === 'oneshot');
  }

  function toggleFixedInput(selectEl) {
    var fixedId = selectEl.getAttribute('data-fixed-id');
    var relativeId = selectEl.getAttribute('data-relative-id');
    var input = document.getElementById(fixedId);
    var relativeWrap = document.getElementById(relativeId);
    if (input) {
      input.disabled = (selectEl.value !== 'fixed');
    }
    if (relativeWrap) {
      var showRelative = (selectEl.value === 'relative');
      relativeWrap.style.display = showRelative ? '' : 'none';
      var controls = relativeWrap.querySelectorAll('input, select, textarea, button');
      for (var i = 0; i < controls.length; i++) {
        controls[i].disabled = !showRelative;
      }
    }
  }

  var modeSelectors = document.querySelectorAll('.js-mode-selector');
  for (var i = 0; i < modeSelectors.length; i++) {
    (function (sel) {
      var prefix = sel.getAttribute('data-target-prefix');
      toggleSchedule(prefix, sel.value);
      sel.addEventListener('change', function () {
        toggleSchedule(prefix, sel.value);
      });
    })(modeSelectors[i]);
  }

  var paramSources = document.querySelectorAll('.js-param-source');
  for (var j = 0; j < paramSources.length; j++) {
    toggleFixedInput(paramSources[j]);
    paramSources[j].addEventListener('change', function () {
      toggleFixedInput(this);
    });
  }

  var presetButtons = document.querySelectorAll('.js-preset-btn');
  for (var k = 0; k < presetButtons.length; k++) {
    presetButtons[k].addEventListener('click', function (e) {
      e.preventDefault();
      var targetId = this.getAttribute('data-target-id');
      var value = this.getAttribute('data-value');
      var input = document.getElementById(targetId);
      if (!input) return;
      input.value = value;
      if (typeof input.dispatchEvent === 'function') {
        try {
          input.dispatchEvent(new Event('input', { bubbles: true }));
        } catch (err) {
        }
      }
      input.focus();
    });
  }

  var relativePresets = document.querySelectorAll('.js-relative-preset');
  for (var r = 0; r < relativePresets.length; r++) {
    relativePresets[r].addEventListener('click', function (e) {
      e.preventDefault();
      var form = this;
      while (form && form.tagName && form.tagName.toLowerCase() !== 'form') {
        form = form.parentNode;
      }
      if (!form) return;

      function setByName(name, value) {
        var el = form.querySelector('[name=\"' + name.replace(/\"/g, '\\\\\"') + '\"]');
        if (el) {
          el.value = value;
        }
      }

      setByName(this.getAttribute('data-anchor-name'), this.getAttribute('data-anchor'));
      setByName(this.getAttribute('data-offset-name'), this.getAttribute('data-offset'));
      setByName(this.getAttribute('data-unit-name'), this.getAttribute('data-unit'));
      setByName(this.getAttribute('data-round-name'), this.getAttribute('data-round'));
    });
  }

  var schedulePresets = document.querySelectorAll('.js-schedule-preset');
  for (var sp = 0; sp < schedulePresets.length; sp++) {
    schedulePresets[sp].addEventListener('click', function (e) {
      e.preventDefault();
      var prefix = this.getAttribute('data-target-prefix');
      var mode = this.getAttribute('data-mode');
      var interval = this.getAttribute('data-interval');
      var lag = this.getAttribute('data-lag');
      var lookback = this.getAttribute('data-lookback');

      var modeSel = document.querySelector('.js-mode-selector[data-target-prefix=\"' + prefix + '\"]');
      if (modeSel) {
        modeSel.value = mode;
        toggleSchedule(prefix, mode);
      }

      var setInput = function (id, value) {
        var el = document.getElementById(id);
        if (el && !el.disabled) {
          el.value = value;
        }
      };

      setInput(prefix + '-snapshot-interval', interval);
      setInput(prefix + '-snapshot-lag', lag);
      setInput(prefix + '-snapshot-lookback', lookback);
      setInput(prefix + '-latest-interval', interval);
    });
  }
})();
</script>

<?php qrs_render_footer();
