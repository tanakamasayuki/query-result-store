<?php

class QrsDispatchPlanner
{
    private static function queuePriority($queueStatus)
    {
        $s = trim((string)$queueStatus);
        if ($s === 'queued_scheduled') {
            return 400;
        }
        if ($s === 'queued_retry') {
            return 300;
        }
        if ($s === 'queued_manual') {
            return 200;
        }
        if ($s === 'queued_backfill') {
            return 100;
        }
        return 0;
    }

    public static function resolveParameterValues($parameterJson, $nowTs, $bucketAtText)
    {
        $parameterMap = self::decodeJsonObject($parameterJson);
        $bucketTs = null;
        if ($bucketAtText !== null && trim((string)$bucketAtText) !== '') {
            $parsed = strtotime((string)$bucketAtText);
            if ($parsed !== false) {
                $bucketTs = $parsed;
            }
        }

        $rows = self::buildParameterPreviewRows($parameterMap, $nowTs, $bucketTs);
        $result = array();
        $i = 0;
        while ($i < count($rows)) {
            $row = $rows[$i];
            if (isset($row['name'])) {
                $result[(string)$row['name']] = isset($row['value']) ? (string)$row['value'] : '';
            }
            $i++;
        }
        return $result;
    }

    public static function buildPreview($mode, $parameterJson, $scheduleJson, $nowTs, $maxFuture, $maxLookback)
    {
        $mode = trim((string)$mode);
        if ($mode === '') {
            throw new Exception('mode is required.');
        }

        $parameterMap = self::decodeJsonObject($parameterJson);
        $schedule = self::decodeJsonObject($scheduleJson);

        $preview = array(
            'mode' => $mode,
            'now' => date('Y-m-d H:i:s', $nowTs),
            'next_runs' => array(),
            'lookback_targets' => array(),
            'parameter_preview' => array(),
        );

        if ($mode === 'oneshot') {
            $preview['next_runs'][] = array(
                'execute_at' => date('Y-m-d H:i:s', $nowTs),
                'bucket_at' => '',
                'priority' => self::queuePriority('queued_manual'),
                'parameter_values' => self::buildParameterPreviewRows($parameterMap, $nowTs, null),
            );
            $preview['parameter_preview'] = self::buildParameterPreviewRows($parameterMap, $nowTs, null);
            return $preview;
        }

        if (!isset($schedule['interval'])) {
            throw new Exception('schedule.interval is required.');
        }

        $interval = self::parseIntervalSpec($schedule['interval']);
        $lagSec = 0;
        if ($mode === 'snapshot') {
            if (!isset($schedule['lag']) || !isset($schedule['lookback'])) {
                throw new Exception('snapshot requires lag and lookback.');
            }
            $lagSec = self::parseDurationSeconds($schedule['lag']);
        }

        if ($mode === 'latest') {
            $preview['next_runs'] = self::buildNextRunsForLatest($interval, $nowTs, $maxFuture);
            $idx = 0;
            while ($idx < count($preview['next_runs'])) {
                $preview['next_runs'][$idx]['priority'] = self::queuePriority('queued_scheduled');
                $preview['next_runs'][$idx]['parameter_values'] = self::buildParameterPreviewRows($parameterMap, $nowTs, null);
                $idx++;
            }
            $preview['parameter_preview'] = self::buildParameterPreviewRows($parameterMap, $nowTs, null);
            return $preview;
        }

        $startAtTs = null;
        if (isset($schedule['start_at']) && trim((string)$schedule['start_at']) !== '') {
            $startAtTs = strtotime((string)$schedule['start_at']);
            if ($startAtTs === false) {
                throw new Exception('Invalid schedule.start_at.');
            }
        }

        $dueBaseTs = $nowTs - $lagSec;
        $latestBucketTs = self::floorBucketStart($interval, $dueBaseTs, $startAtTs);
        if ($latestBucketTs === null) {
            return $preview;
        }
        if ($startAtTs !== null && $latestBucketTs < $startAtTs) {
            return $preview;
        }

        $lookbackCount = self::parseLookbackCount($schedule['lookback'], $interval);
        if ($maxLookback > 0 && $lookbackCount > $maxLookback) {
            $lookbackCount = $maxLookback;
        }

        $targets = array();
        $i = 0;
        while ($i < $lookbackCount) {
            $bucketTs = self::stepBucket($interval, $latestBucketTs, -$i);
            if ($startAtTs !== null && $bucketTs < $startAtTs) {
                break;
            }
            $targets[] = array(
                'bucket_at' => date('Y-m-d H:i:s', $bucketTs),
                'execute_after' => date('Y-m-d H:i:s', $bucketTs + $lagSec),
                'priority' => ($i === 0) ? self::queuePriority('queued_scheduled') : self::queuePriority('queued_backfill'),
                'parameter_values' => self::buildParameterPreviewRows($parameterMap, $nowTs, $bucketTs),
            );
            $i++;
        }
        $preview['lookback_targets'] = $targets;

        $future = array();
        $j = 0;
        while ($j < $maxFuture) {
            $bucketTs = self::stepBucket($interval, $latestBucketTs, $j + 1);
            $future[] = array(
                'execute_at' => date('Y-m-d H:i:s', $bucketTs + $lagSec),
                'bucket_at' => date('Y-m-d H:i:s', $bucketTs),
                'priority' => self::queuePriority('queued_scheduled'),
                'parameter_values' => self::buildParameterPreviewRows($parameterMap, $nowTs, $bucketTs),
            );
            $j++;
        }
        $preview['next_runs'] = $future;
        $preview['parameter_preview'] = self::buildParameterPreviewRows($parameterMap, $nowTs, $latestBucketTs);
        return $preview;
    }

    public static function buildDispatchTargets($variantId, $mode, $scheduleJson, $nowTs, $maxLookback)
    {
        $schedule = self::decodeJsonObject($scheduleJson);
        $result = array();

        if ($mode === 'latest') {
            if (!isset($schedule['interval'])) {
                return $result;
            }

            $interval = self::parseIntervalSpec($schedule['interval']);
            $bucketTs = self::floorBucketStart($interval, $nowTs, null);
            if ($bucketTs === null) {
                return $result;
            }

            $result[] = array(
                'variant_id' => $variantId,
                'bucket_at' => date('Y-m-d H:i:s', $bucketTs),
                'execute_after' => date('Y-m-d H:i:s', $bucketTs),
                'priority' => 400,
                'queue_status' => 'queued_scheduled',
            );
            return $result;
        }

        if ($mode !== 'snapshot') {
            return $result;
        }

        if (!isset($schedule['interval']) || !isset($schedule['lag']) || !isset($schedule['lookback'])) {
            return $result;
        }

        $interval = self::parseIntervalSpec($schedule['interval']);
        $lagSec = self::parseDurationSeconds($schedule['lag']);
        $startAtTs = null;
        if (isset($schedule['start_at']) && trim((string)$schedule['start_at']) !== '') {
            $startAtTs = strtotime((string)$schedule['start_at']);
            if ($startAtTs === false) {
                throw new Exception('Invalid schedule.start_at.');
            }
        }

        $latestBucketTs = self::floorBucketStart($interval, $nowTs - $lagSec, $startAtTs);
        if ($latestBucketTs === null) {
            return $result;
        }
        if ($startAtTs !== null && $latestBucketTs < $startAtTs) {
            return $result;
        }

        $lookbackCount = self::parseLookbackCount($schedule['lookback'], $interval);
        if ($maxLookback > 0 && $lookbackCount > $maxLookback) {
            $lookbackCount = $maxLookback;
        }

        $i = 0;
        while ($i < $lookbackCount) {
            $bucketTs = self::stepBucket($interval, $latestBucketTs, -$i);
            if ($startAtTs !== null && $bucketTs < $startAtTs) {
                break;
            }
            $result[] = array(
                'variant_id' => $variantId,
                'bucket_at' => date('Y-m-d H:i:s', $bucketTs),
                'execute_after' => date('Y-m-d H:i:s', $bucketTs + $lagSec),
                'queue_status' => ($i === 0) ? 'queued_scheduled' : 'queued_backfill',
                'priority' => ($i === 0)
                    ? self::queuePriority('queued_scheduled')
                    : self::queuePriority('queued_backfill'),
            );
            $i++;
        }

        return $result;
    }

    private static function decodeJsonObject($jsonText)
    {
        $data = json_decode((string)$jsonText, true);
        if (!is_array($data)) {
            return array();
        }
        return $data;
    }

    private static function parseIntervalSpec($text)
    {
        $spec = self::parseDurationSpec($text);
        if ($spec['seconds'] <= 0 && $spec['months'] <= 0) {
            throw new Exception('Invalid interval: ' . $text);
        }
        if ($spec['seconds'] > 0 && $spec['months'] > 0) {
            throw new Exception('Mixed calendar/seconds interval is not supported: ' . $text);
        }
        return $spec;
    }

    private static function parseLookbackCount($lookbackText, $interval)
    {
        $text = trim(strtolower((string)$lookbackText));
        if (preg_match('/^([0-9]+)\s*intervals?$/', $text, $m)) {
            $c = (int)$m[1];
            return ($c > 0) ? $c : 1;
        }

        $duration = self::parseDurationSpec($lookbackText);
        if ($duration['months'] > 0 && $interval['months'] > 0) {
            $c = (int)floor($duration['months'] / $interval['months']) + 1;
            return ($c > 0) ? $c : 1;
        }
        if ($duration['months'] > 0 && $interval['seconds'] > 0) {
            throw new Exception('lookback with month/year is not supported for sub-month interval.');
        }
        if ($interval['months'] > 0) {
            throw new Exception('Use "N intervals" for month/year interval lookback.');
        }
        if ($interval['seconds'] <= 0) {
            return 1;
        }

        $c = (int)floor($duration['seconds'] / $interval['seconds']) + 1;
        return ($c > 0) ? $c : 1;
    }

    private static function floorBucketStart($interval, $targetTs, $startAtTs)
    {
        if ($interval['seconds'] > 0) {
            $baseTs = ($startAtTs === null) ? 0 : (int)$startAtTs;
            if ($targetTs < $baseTs) {
                return null;
            }
            $n = (int)floor(($targetTs - $baseTs) / $interval['seconds']);
            return $baseTs + ($n * $interval['seconds']);
        }

        $baseTs = ($startAtTs === null) ? mktime(0, 0, 0, 1, 1, 1970) : (int)$startAtTs;
        $stepMonths = (int)$interval['months'];
        if ($stepMonths <= 0) {
            return null;
        }

        $baseY = (int)date('Y', $baseTs);
        $baseM = (int)date('n', $baseTs);
        $targetY = (int)date('Y', $targetTs);
        $targetM = (int)date('n', $targetTs);
        $diff = (($targetY - $baseY) * 12) + ($targetM - $baseM);
        $i = (int)floor($diff / $stepMonths);

        $bucket = self::addMonths($baseTs, $i * $stepMonths);
        while ($bucket > $targetTs) {
            $i--;
            $bucket = self::addMonths($baseTs, $i * $stepMonths);
        }
        while (self::addMonths($baseTs, ($i + 1) * $stepMonths) <= $targetTs) {
            $i++;
            $bucket = self::addMonths($baseTs, $i * $stepMonths);
        }
        return $bucket;
    }

    private static function stepBucket($interval, $baseBucketTs, $delta)
    {
        if ($interval['seconds'] > 0) {
            return $baseBucketTs + ($interval['seconds'] * $delta);
        }
        return self::addMonths($baseBucketTs, $interval['months'] * $delta);
    }

    private static function buildNextRunsForLatest($interval, $nowTs, $maxFuture)
    {
        $rows = array();
        $base = self::floorBucketStart($interval, $nowTs, null);
        if ($base === null) {
            return $rows;
        }

        $i = 0;
        while ($i < $maxFuture) {
            $next = self::stepBucket($interval, $base, $i + 1);
            $rows[] = array(
                'execute_at' => date('Y-m-d H:i:s', $next),
                'bucket_at' => '',
            );
            $i++;
        }
        return $rows;
    }

    private static function parseDurationSeconds($text)
    {
        $spec = self::parseDurationSpec($text);
        if ($spec['months'] > 0) {
            throw new Exception('month/year duration is not supported here: ' . $text);
        }
        return $spec['seconds'];
    }

    private static function parseDurationSpec($text)
    {
        $text = trim(strtolower((string)$text));
        if ($text === '') {
            throw new Exception('Duration is required.');
        }

        $words = preg_split('/\s+/', $text);
        $i = 0;
        $seconds = 0;
        $months = 0;

        while ($i < count($words)) {
            $nText = $words[$i];
            if (!preg_match('/^-?[0-9]+$/', $nText)) {
                throw new Exception('Invalid duration number: ' . $nText);
            }
            $n = (int)$nText;
            $i++;
            if ($i >= count($words)) {
                throw new Exception('Duration unit is missing.');
            }
            $unit = $words[$i];
            $i++;

            if ($unit === 'minute' || $unit === 'minutes' || $unit === 'min' || $unit === 'mins' || $unit === 'm') {
                $seconds += ($n * 60);
            } elseif ($unit === 'hour' || $unit === 'hours' || $unit === 'h') {
                $seconds += ($n * 3600);
            } elseif ($unit === 'day' || $unit === 'days' || $unit === 'd') {
                $seconds += ($n * 86400);
            } elseif ($unit === 'month' || $unit === 'months' || $unit === 'mo') {
                $months += $n;
            } elseif ($unit === 'year' || $unit === 'years' || $unit === 'y') {
                $months += ($n * 12);
            } elseif ($unit === 'interval' || $unit === 'intervals') {
                throw new Exception('"interval(s)" is only allowed for lookback.');
            } else {
                throw new Exception('Unsupported duration unit: ' . $unit);
            }
        }

        return array('seconds' => $seconds, 'months' => $months);
    }

    private static function addMonths($baseTs, $months)
    {
        $y = (int)date('Y', $baseTs);
        $m = (int)date('n', $baseTs);
        $d = (int)date('j', $baseTs);
        $h = (int)date('G', $baseTs);
        $i = (int)date('i', $baseTs);
        $s = (int)date('s', $baseTs);
        return mktime($h, $i, $s, $m + $months, $d, $y);
    }

    private static function buildParameterPreviewRows($parameterMap, $nowTs, $bucketTs)
    {
        $rows = array();
        foreach ($parameterMap as $name => $rule) {
            if (!is_array($rule) || !isset($rule['source'])) {
                continue;
            }
            $value = self::resolveRuleValue($rule, $nowTs, $bucketTs);
            $rows[] = array(
                'name' => (string)$name,
                'source' => (string)$rule['source'],
                'value' => $value,
            );
        }
        return $rows;
    }

    private static function resolveRuleValue($rule, $nowTs, $bucketTs)
    {
        $source = (string)$rule['source'];
        $format = isset($rule['format']) ? (string)$rule['format'] : 'YYYY-MM-DD HH:mm:ss';

        if ($source === 'fixed') {
            return isset($rule['value']) ? (string)$rule['value'] : '';
        }

        if ($source === 'now') {
            return self::formatTimestamp($nowTs, $format);
        }

        if ($source === 'bucket_at') {
            if ($bucketTs === null) {
                return '';
            }
            return self::formatTimestamp($bucketTs, $format);
        }

        if ($source === 'relative' && isset($rule['relative']) && is_array($rule['relative'])) {
            $baseTs = self::resolveRelativeAnchor($rule['relative'], $nowTs, $bucketTs);
            $offset = isset($rule['relative']['offset']) && is_array($rule['relative']['offset']) ? $rule['relative']['offset'] : array();
            $offsetValue = isset($offset['value']) ? (int)$offset['value'] : 0;
            $offsetUnit = isset($offset['unit']) ? (string)$offset['unit'] : 'day';
            $baseTs = self::applyOffset($baseTs, $offsetValue, $offsetUnit);
            $round = isset($rule['relative']['round']) ? (string)$rule['relative']['round'] : 'none';
            $baseTs = self::applyRound($baseTs, $round);
            return self::formatTimestamp($baseTs, $format);
        }

        return '';
    }

    private static function resolveRelativeAnchor($relative, $nowTs, $bucketTs)
    {
        $anchor = isset($relative['anchor']) ? (string)$relative['anchor'] : 'now';
        if ($anchor === 'bucket_at') {
            return ($bucketTs === null) ? $nowTs : $bucketTs;
        }
        if ($anchor === 'today_start') {
            return mktime(0, 0, 0, (int)date('n', $nowTs), (int)date('j', $nowTs), (int)date('Y', $nowTs));
        }
        if ($anchor === 'this_month_start') {
            return mktime(0, 0, 0, (int)date('n', $nowTs), 1, (int)date('Y', $nowTs));
        }
        if ($anchor === 'this_year_start') {
            return mktime(0, 0, 0, 1, 1, (int)date('Y', $nowTs));
        }
        return $nowTs;
    }

    private static function applyOffset($ts, $value, $unit)
    {
        if ($unit === 'minute') {
            return $ts + ($value * 60);
        }
        if ($unit === 'hour') {
            return $ts + ($value * 3600);
        }
        if ($unit === 'day') {
            return $ts + ($value * 86400);
        }
        if ($unit === 'month') {
            return self::addMonths($ts, $value);
        }
        if ($unit === 'year') {
            return self::addMonths($ts, $value * 12);
        }
        return $ts;
    }

    private static function applyRound($ts, $round)
    {
        if ($round === 'day_start') {
            return mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
        }
        if ($round === 'day_end') {
            return mktime(23, 59, 59, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
        }
        if ($round === 'month_start') {
            return mktime(0, 0, 0, (int)date('n', $ts), 1, (int)date('Y', $ts));
        }
        if ($round === 'month_end') {
            return mktime(23, 59, 59, (int)date('n', $ts) + 1, 0, (int)date('Y', $ts));
        }
        if ($round === 'year_start') {
            return mktime(0, 0, 0, 1, 1, (int)date('Y', $ts));
        }
        if ($round === 'year_end') {
            return mktime(23, 59, 59, 12, 31, (int)date('Y', $ts));
        }
        return $ts;
    }

    private static function formatTimestamp($ts, $format)
    {
        if ($format === 'UNIX_EPOCH') {
            return (string)$ts;
        }
        if ($format === 'YYYY-MM-DDTHH:mm:ssZ') {
            return gmdate('Y-m-d\TH:i:s\Z', $ts);
        }

        $php = self::toPhpDateFormat($format);
        return date($php, $ts);
    }

    private static function toPhpDateFormat($format)
    {
        if ($format === 'YYYY-MM-DD') {
            return 'Y-m-d';
        }
        if ($format === 'YYYYMM') {
            return 'Ym';
        }
        if ($format === 'YYYYMMDD') {
            return 'Ymd';
        }
        if ($format === 'YYYY-MM-DD HH:mm:ss') {
            return 'Y-m-d H:i:s';
        }
        $php = str_replace(
            array('YYYY', 'MM', 'DD', 'HH', 'mm', 'ss'),
            array('Y', 'm', 'd', 'H', 'i', 's'),
            (string)$format
        );
        return $php;
    }
}
