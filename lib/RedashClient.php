<?php

class QrsRedashClient
{
    public function testConnection($baseUrl, $apiKey)
    {
        $result = $this->request($baseUrl, $apiKey, '/api/queries?page=1&page_size=1');
        $statusCode = $result['status_code'];

        if ($statusCode >= 200 && $statusCode < 300) {
            return array('ok' => true, 'status_code' => $statusCode, 'message' => 'Connection succeeded.');
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Authentication failed (invalid API key or insufficient permission).');
        }

        return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Unexpected HTTP status from Redash API.');
    }

    public function testQueryExists($baseUrl, $apiKey, $queryId)
    {
        $queryId = trim((string)$queryId);
        if ($queryId === '' || !preg_match('/^[0-9]+$/', $queryId)) {
            return array('ok' => false, 'status_code' => 0, 'message' => 'Invalid query_id.');
        }

        $result = $this->request($baseUrl, $apiKey, '/api/queries/' . $queryId);
        $statusCode = $result['status_code'];

        if ($statusCode >= 200 && $statusCode < 300) {
            return array('ok' => true, 'status_code' => $statusCode, 'message' => 'Query exists.');
        }

        if ($statusCode === 404) {
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Query not found.');
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Authentication failed (invalid API key or insufficient permission).');
        }

        return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Unexpected HTTP status from Redash API.');
    }

    public function getQueryDetails($baseUrl, $apiKey, $queryId)
    {
        $queryId = trim((string)$queryId);
        if ($queryId === '' || !preg_match('/^[0-9]+$/', $queryId)) {
            return array('ok' => false, 'status_code' => 0, 'message' => 'Invalid query_id.');
        }

        $result = $this->request($baseUrl, $apiKey, '/api/queries/' . $queryId);
        $statusCode = $result['status_code'];

        if ($statusCode < 200 || $statusCode >= 300) {
            if ($statusCode === 404) {
                return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Query not found.');
            }
            if ($statusCode === 401 || $statusCode === 403) {
                return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Authentication failed (invalid API key or insufficient permission).');
            }
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Unexpected HTTP status from Redash API.');
        }

        if (!function_exists('json_decode')) {
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'json extension is required.');
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Invalid JSON response from Redash API.');
        }

        return array('ok' => true, 'status_code' => $statusCode, 'message' => 'Query details fetched.', 'data' => $data);
    }

    public function executeQuery($baseUrl, $apiKey, $queryId, $parameters, $maxWaitSeconds)
    {
        $queryId = trim((string)$queryId);
        if ($queryId === '' || !preg_match('/^[0-9]+$/', $queryId)) {
            return array('ok' => false, 'status_code' => 0, 'message' => 'Invalid query_id.');
        }
        if (!is_array($parameters)) {
            $parameters = array();
        }
        $maxWait = (int)$maxWaitSeconds;
        if ($maxWait <= 0) {
            $maxWait = 30;
        }

        $payload = array(
            'parameters' => $parameters,
            'max_age' => 0,
        );
        $payloadJson = json_encode($payload);
        if (!is_string($payloadJson)) {
            return array('ok' => false, 'status_code' => 0, 'message' => 'Failed to encode request payload.');
        }

        $postResult = $this->request($baseUrl, $apiKey, '/api/queries/' . $queryId . '/results', 'POST', $payloadJson, array('Content-Type: application/json'));
        $statusCode = $postResult['status_code'];
        if ($statusCode < 200 || $statusCode >= 300) {
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Failed to execute query.');
        }

        if (!function_exists('json_decode')) {
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'json extension is required.');
        }

        $postData = json_decode($postResult['body'], true);
        if (!is_array($postData)) {
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Invalid JSON response from Redash execution API.');
        }

        if (isset($postData['query_result']) && is_array($postData['query_result'])) {
            return array(
                'ok' => true,
                'status_code' => $statusCode,
                'message' => 'Query executed.',
                'query_result' => $postData['query_result'],
                'raw_json' => $postResult['body'],
                'raw_source' => 'query_results_post'
            );
        }

        if (!isset($postData['job']) || !is_array($postData['job']) || !isset($postData['job']['id'])) {
            return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Execution job response is missing.');
        }

        $jobId = (string)$postData['job']['id'];
        $started = time();

        while (true) {
            if ((time() - $started) > $maxWait) {
                return array('ok' => false, 'status_code' => $statusCode, 'message' => 'Execution timed out.');
            }

            $jobResult = $this->request($baseUrl, $apiKey, '/api/jobs/' . $jobId);
            if ($jobResult['status_code'] < 200 || $jobResult['status_code'] >= 300) {
                return array('ok' => false, 'status_code' => $jobResult['status_code'], 'message' => 'Failed to fetch execution job status.');
            }
            $jobData = json_decode($jobResult['body'], true);
            if (!is_array($jobData) || !isset($jobData['job']) || !is_array($jobData['job'])) {
                return array('ok' => false, 'status_code' => $jobResult['status_code'], 'message' => 'Invalid job status payload.');
            }

            $job = $jobData['job'];
            $jobStatus = isset($job['status']) ? (int)$job['status'] : 0;
            if ($jobStatus === 3) {
                if (!isset($job['query_result_id'])) {
                    return array('ok' => false, 'status_code' => $jobResult['status_code'], 'message' => 'query_result_id is missing.');
                }
                $qrId = trim((string)$job['query_result_id']);
                if ($qrId === '') {
                    return array('ok' => false, 'status_code' => $jobResult['status_code'], 'message' => 'query_result_id is empty.');
                }
                $qrResult = $this->request($baseUrl, $apiKey, '/api/query_results/' . $qrId);
                if ($qrResult['status_code'] < 200 || $qrResult['status_code'] >= 300) {
                    return array('ok' => false, 'status_code' => $qrResult['status_code'], 'message' => 'Failed to fetch query result.');
                }
                $qrData = json_decode($qrResult['body'], true);
                if (!is_array($qrData) || !isset($qrData['query_result']) || !is_array($qrData['query_result'])) {
                    return array('ok' => false, 'status_code' => $qrResult['status_code'], 'message' => 'Invalid query result payload.');
                }
                return array(
                    'ok' => true,
                    'status_code' => $qrResult['status_code'],
                    'message' => 'Query executed.',
                    'query_result' => $qrData['query_result'],
                    'raw_json' => $qrResult['body'],
                    'raw_source' => 'query_results_fetch'
                );
            }

            if ($jobStatus === 4 || $jobStatus === 5) {
                return array('ok' => false, 'status_code' => $jobResult['status_code'], 'message' => 'Execution job failed.');
            }

            usleep(500000);
        }
    }

    private function request($baseUrl, $apiKey, $path, $method = 'GET', $body = null, $extraHeaders = null)
    {
        if (!function_exists('curl_init')) {
            throw new Exception('cURL extension is required for Redash API calls.');
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            throw new Exception('base_url is required.');
        }
        if ($apiKey === '') {
            throw new Exception('api_key is required.');
        }

        $url = $baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception('Failed to initialize cURL.');
        }

        $headers = array(
            'Authorization: Key ' . $apiKey,
            'Accept: application/json',
        );
        if (is_array($extraHeaders)) {
            foreach ($extraHeaders as $h) {
                if (is_string($h) && $h !== '') {
                    $headers[] = $h;
                }
            }
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $method = strtoupper((string)$method);
        if ($method === '') {
            $method = 'GET';
        }
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Connection error: ' . $err);
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array(
            'status_code' => $statusCode,
            'body' => $body,
        );
    }
}
