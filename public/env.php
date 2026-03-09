<?php

require_once __DIR__ . '/_app.php';
require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/lib/Repository/MetaRepository.php';

$message = '';
$error = '';

if (!$runtimeOk) {
    $error = t('runtime_error', array('errors' => implode(' ', $runtimeErrors)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save_config') {
        $newConfig = array(
            'db' => array(
                'driver' => isset($_POST['driver']) ? trim($_POST['driver']) : 'sqlite',
                'sqlite_path' => isset($_POST['sqlite_path']) ? trim($_POST['sqlite_path']) : ($rootDir . '/var/qrs.sqlite3'),
                'host' => isset($_POST['host']) ? trim($_POST['host']) : '127.0.0.1',
                'port' => isset($_POST['port']) ? trim($_POST['port']) : '',
                'name' => isset($_POST['name']) ? trim($_POST['name']) : 'qrs',
                'user' => isset($_POST['user']) ? trim($_POST['user']) : '',
                'password' => isset($_POST['password']) ? trim($_POST['password']) : '',
                'charset' => isset($_POST['charset']) ? trim($_POST['charset']) : 'utf8',
            ),
        );

        $configPath = $rootDir . '/config.php';
        $fileConfig = array();
        if (is_file($configPath)) {
            $loaded = include $configPath;
            if (is_array($loaded)) {
                $fileConfig = $loaded;
            }
        }
        $mergedConfig = QrsConfig::merge($fileConfig, $newConfig);
        $content = "<?php\n\nreturn " . var_export($mergedConfig, true) . ";\n";

        if (@file_put_contents($configPath, $content) === false) {
            $error = t('config_save_error', array());
        } else {
            $message = t('config_save_ok', array());
            $config = QrsConfig::merge($config, $newConfig);
            QrsConfig::applyTimezone($config);
            $dbConfigExplicit = true;
            $GLOBALS['dbConfigExplicit'] = QrsConfig::hasExplicitDbConfig($rootDir);
            if (!$GLOBALS['dbConfigExplicit']) {
                $GLOBALS['dbConfigExplicit'] = true;
            }
        }
    } elseif ($action === 'save_timezone_config') {
        $timezoneId = isset($_POST['timezone_id']) ? trim((string)$_POST['timezone_id']) : '';
        $timezoneCustom = isset($_POST['timezone_id_custom']) ? trim((string)$_POST['timezone_id_custom']) : '';
        if ($timezoneId === '__custom__') {
            $timezoneId = $timezoneCustom;
        }
        if ($timezoneId === '') {
            $error = t('timezone_invalid', array('message' => 'timezone_id is required.'));
        } elseif (!in_array($timezoneId, timezone_identifiers_list(), true)) {
            $error = t('timezone_invalid', array('message' => 'unknown timezone_id.'));
        } else {
            $newConfig = array(
                'app' => array(
                    'timezone_id' => $timezoneId,
                ),
            );
            $configPath = $rootDir . '/config.php';
            $fileConfig = array();
            if (is_file($configPath)) {
                $loaded = include $configPath;
                if (is_array($loaded)) {
                    $fileConfig = $loaded;
                }
            }
            $mergedConfig = QrsConfig::merge($fileConfig, $newConfig);
            $content = "<?php\n\nreturn " . var_export($mergedConfig, true) . ";\n";

            if (@file_put_contents($configPath, $content) === false) {
                $error = t('timezone_save_error', array());
            } else {
                $config = QrsConfig::merge($config, $newConfig);
                QrsConfig::applyTimezone($config);
                $GLOBALS['dbConfigExplicit'] = QrsConfig::hasExplicitDbConfig($rootDir);
                $message = t('timezone_save_ok', array('timezone' => $timezoneId));
            }
        }
    }
}

$dbOk = false;
$dbError = '';
$pdo = null;
$isInitialized = false;
$curlAvailable = false;

if ($runtimeOk) {
    qrs_connect_db($config, $dbOk, $dbError, $pdo, $isInitialized, $curlAvailable);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $runtimeOk && $dbOk) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'init_schema') {
        try {
            QrsDb::initializeSchema($pdo);
            $isInitialized = true;
            $message = t('schema_init_ok', array());
        } catch (Exception $e) {
            $error = t('schema_init_error', array('message' => $e->getMessage()));
        }
    } elseif ($action === 'save_runtime_settings') {
        if (!$isInitialized) {
            $error = t('schema_required_for_page', array());
        } else {
            try {
                $metaRepo = new QrsMetaRepository($pdo);
                $storeRaw = isset($_POST['store_raw_redash_payload']) ? trim((string)$_POST['store_raw_redash_payload']) : '0';
                if ($storeRaw !== '1') {
                    $storeRaw = '0';
                }
                $rawDir = isset($_POST['raw_redash_payload_dir']) ? trim((string)$_POST['raw_redash_payload_dir']) : 'var/redash_raw';
                if ($rawDir === '') {
                    $rawDir = 'var/redash_raw';
                }
                $workerGlobalConcurrency = isset($_POST['worker_global_concurrency']) ? (int)$_POST['worker_global_concurrency'] : 1;
                if ($workerGlobalConcurrency < 1) {
                    $workerGlobalConcurrency = 1;
                }
                $workerMaxRunSeconds = isset($_POST['worker_max_run_seconds']) ? (int)$_POST['worker_max_run_seconds'] : 150;
                if ($workerMaxRunSeconds < 1) {
                    $workerMaxRunSeconds = 1;
                }
                $workerMaxJobsPerRun = isset($_POST['worker_max_jobs_per_run']) ? (int)$_POST['worker_max_jobs_per_run'] : 20;
                if ($workerMaxJobsPerRun < 1) {
                    $workerMaxJobsPerRun = 1;
                }
                $workerPollTimeoutSeconds = isset($_POST['worker_poll_timeout_seconds']) ? (int)$_POST['worker_poll_timeout_seconds'] : 300;
                if ($workerPollTimeoutSeconds < 1) {
                    $workerPollTimeoutSeconds = 1;
                }
                $workerPollIntervalMillis = isset($_POST['worker_poll_interval_millis']) ? (int)$_POST['worker_poll_interval_millis'] : 1000;
                if ($workerPollIntervalMillis < 100) {
                    $workerPollIntervalMillis = 100;
                }
                $workerRunningStaleSeconds = isset($_POST['worker_running_stale_seconds']) ? (int)$_POST['worker_running_stale_seconds'] : 900;
                if ($workerRunningStaleSeconds < 1) {
                    $workerRunningStaleSeconds = 1;
                }
                $workerRetryMaxCount = isset($_POST['worker_retry_max_count']) ? (int)$_POST['worker_retry_max_count'] : 3;
                if ($workerRetryMaxCount < 0) {
                    $workerRetryMaxCount = 0;
                }
                $workerRetryBackoffSeconds = isset($_POST['worker_retry_backoff_seconds']) ? (int)$_POST['worker_retry_backoff_seconds'] : 60;
                if ($workerRetryBackoffSeconds < 1) {
                    $workerRetryBackoffSeconds = 1;
                }
                $metaRepo->set('runtime.store_raw_redash_payload', $storeRaw);
                $metaRepo->set('runtime.raw_redash_payload_dir', $rawDir);
                $metaRepo->set('worker.global_concurrency', (string)$workerGlobalConcurrency);
                $metaRepo->set('worker.max_run_seconds', (string)$workerMaxRunSeconds);
                $metaRepo->set('worker.max_jobs_per_run', (string)$workerMaxJobsPerRun);
                $metaRepo->set('worker.poll_timeout_seconds', (string)$workerPollTimeoutSeconds);
                $metaRepo->set('worker.poll_interval_millis', (string)$workerPollIntervalMillis);
                $metaRepo->set('worker.running_stale_seconds', (string)$workerRunningStaleSeconds);
                $metaRepo->set('worker.retry_max_count', (string)$workerRetryMaxCount);
                $metaRepo->set('worker.retry_backoff_seconds', (string)$workerRetryBackoffSeconds);
                foreach ($_POST as $postKey => $postValue) {
                    if (strpos($postKey, 'meta_') !== 0) {
                        continue;
                    }
                    $metaKey = substr($postKey, 5);
                    if (!preg_match('/^[A-Za-z0-9._-]+$/', $metaKey)) {
                        continue;
                    }
                    if (is_array($postValue) || is_object($postValue)) {
                        continue;
                    }
                    $metaRepo->set($metaKey, trim((string)$postValue));
                }
                $message = t('runtime_settings_saved', array());
            } catch (Exception $e) {
                $error = t('runtime_settings_save_error', array('message' => $e->getMessage()));
            }
        }
    }
}

$db = isset($config['db']) ? $config['db'] : array();
$timezoneStatus = QrsConfig::timezoneStatus($rootDir, $config);
$timezoneCurrent = date_default_timezone_get();
$timezoneNow = date('Y-m-d H:i:s');
$timezoneIni = (string)ini_get('date.timezone');
$timezoneEnvTz = getenv('TZ');
if ($timezoneEnvTz === false) {
    $timezoneEnvTz = '';
}
$timezoneCommonChoices = array(
    'UTC',
    'Asia/Tokyo',
    'Asia/Seoul',
    'Asia/Singapore',
    'Asia/Shanghai',
    'America/Los_Angeles',
    'America/New_York',
    'Europe/London',
    'Europe/Berlin',
    'Australia/Sydney',
);
$timezoneFormSelected = 'UTC';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_timezone_config') {
    $timezoneFormSelected = isset($_POST['timezone_id']) ? trim((string)$_POST['timezone_id']) : 'UTC';
}
$timezoneFormCustom = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_timezone_config') {
    $timezoneFormCustom = isset($_POST['timezone_id_custom']) ? trim((string)$_POST['timezone_id_custom']) : '';
}
$pdoDriverStatus = QrsRuntime::pdoDriverStatus();
$runtimeStoreRaw = '0';
$runtimeRawDir = 'var/redash_raw';
$runtimeWorkerGlobalConcurrency = '1';
$runtimeWorkerMaxRunSeconds = '150';
$runtimeWorkerMaxJobsPerRun = '20';
$runtimeWorkerPollTimeoutSeconds = '300';
$runtimeWorkerPollIntervalMillis = '1000';
$runtimeWorkerRunningStaleSeconds = '900';
$runtimeWorkerRetryMaxCount = '3';
$runtimeWorkerRetryBackoffSeconds = '60';
if ($dbOk && $isInitialized) {
    try {
        $metaRepo = new QrsMetaRepository($pdo);
        $runtimeStoreRaw = $metaRepo->get('runtime.store_raw_redash_payload', '0');
        if ($runtimeStoreRaw !== '1') {
            $runtimeStoreRaw = '0';
        }
        $runtimeRawDir = trim($metaRepo->get('runtime.raw_redash_payload_dir', 'var/redash_raw'));
        if ($runtimeRawDir === '') {
            $runtimeRawDir = 'var/redash_raw';
        }
        $runtimeWorkerGlobalConcurrency = trim($metaRepo->get('worker.global_concurrency', '1'));
        if ($runtimeWorkerGlobalConcurrency === '') {
            $runtimeWorkerGlobalConcurrency = '1';
        }
        $runtimeWorkerMaxRunSeconds = trim($metaRepo->get('worker.max_run_seconds', '150'));
        if ($runtimeWorkerMaxRunSeconds === '') {
            $runtimeWorkerMaxRunSeconds = '150';
        }
        $runtimeWorkerMaxJobsPerRun = trim($metaRepo->get('worker.max_jobs_per_run', '20'));
        if ($runtimeWorkerMaxJobsPerRun === '') {
            $runtimeWorkerMaxJobsPerRun = '20';
        }
        $runtimeWorkerPollTimeoutSeconds = trim($metaRepo->get('worker.poll_timeout_seconds', '300'));
        if ($runtimeWorkerPollTimeoutSeconds === '') {
            $runtimeWorkerPollTimeoutSeconds = '300';
        }
        $runtimeWorkerPollIntervalMillis = trim($metaRepo->get('worker.poll_interval_millis', '1000'));
        if ($runtimeWorkerPollIntervalMillis === '') {
            $runtimeWorkerPollIntervalMillis = '1000';
        }
        $runtimeWorkerRunningStaleSeconds = trim($metaRepo->get('worker.running_stale_seconds', '900'));
        if ($runtimeWorkerRunningStaleSeconds === '') {
            $runtimeWorkerRunningStaleSeconds = '900';
        }
        $runtimeWorkerRetryMaxCount = trim($metaRepo->get('worker.retry_max_count', '3'));
        if ($runtimeWorkerRetryMaxCount === '') {
            $runtimeWorkerRetryMaxCount = '3';
        }
        $runtimeWorkerRetryBackoffSeconds = trim($metaRepo->get('worker.retry_backoff_seconds', '60'));
        if ($runtimeWorkerRetryBackoffSeconds === '') {
            $runtimeWorkerRetryBackoffSeconds = '60';
        }
    } catch (Exception $e) {
        $error = t('runtime_settings_load_error', array('message' => $e->getMessage()));
    }
}
$runtimeRawDirResolved = $runtimeRawDir;
if ($runtimeRawDirResolved !== '' && !preg_match('/^(\/|[A-Za-z]:[\\\\\/])/', $runtimeRawDirResolved)) {
    $runtimeRawDirResolved = rtrim($rootDir, '/\\') . '/' . ltrim($runtimeRawDirResolved, '/\\');
}
qrs_render_header('env', t('app_title', array()), $message, $error);
?>

<?php if ($runtimeOk): ?>
  <div class="box">
    <h2><?php echo h(t('runtime_status', array())); ?></h2>
    <?php if ($jsonAvailable): ?>
      <p class="ok"><?php echo h(t('json_ok', array())); ?></p>
    <?php else: ?>
      <p class="error"><?php echo h(t('json_ng', array())); ?></p>
    <?php endif; ?>
    <?php if ($curlRuntimeAvailable): ?>
      <p class="ok"><?php echo h(t('curl_ok', array())); ?></p>
    <?php else: ?>
      <p class="error"><?php echo h(t('curl_ng', array())); ?></p>
      <p><?php echo h(t('curl_hint', array())); ?></p>
    <?php endif; ?>
    <p><?php echo h(t('pdo_driver_status_title', array())); ?></p>
    <p><?php echo $pdoDriverStatus['sqlite'] ? '<span class="ok">' . h(t('pdo_driver_sqlite_ok', array())) . '</span>' : '<span class="error">' . h(t('pdo_driver_sqlite_ng', array())) . '</span>'; ?></p>
    <p><?php echo $pdoDriverStatus['mysql'] ? '<span class="ok">' . h(t('pdo_driver_mysql_ok', array())) . '</span>' : '<span class="error">' . h(t('pdo_driver_mysql_ng', array())) . '</span>'; ?></p>
    <p><?php echo $pdoDriverStatus['pgsql'] ? '<span class="ok">' . h(t('pdo_driver_pgsql_ok', array())) . '</span>' : '<span class="error">' . h(t('pdo_driver_pgsql_ng', array())) . '</span>'; ?></p>
  </div>
<?php endif; ?>

<?php if (!$timezoneStatus['has_explicit']): ?>
  <div class="box">
    <h2><?php echo h(t('timezone_box', array())); ?></h2>
    <p class="error"><?php echo h(t('timezone_unset_warning', array('timezone' => $timezoneCurrent))); ?></p>
    <p class="muted"><?php echo h(t('timezone_reference', array('php' => $timezoneCurrent, 'ini' => ($timezoneIni === '' ? '-' : $timezoneIni), 'tz' => ($timezoneEnvTz === '' ? '-' : $timezoneEnvTz)))); ?> <span id="browser-timezone" data-label="<?php echo h(t('timezone_reference_browser', array())); ?>"></span></p>
    <form method="post">
      <?php echo qrs_lang_input_html(); ?>
      <input type="hidden" name="action" value="save_timezone_config">
      <label><?php echo h(t('timezone_field', array())); ?></label>
      <select id="timezone-id-select" name="timezone_id">
        <?php foreach ($timezoneCommonChoices as $tz): ?>
          <option value="<?php echo h($tz); ?>"<?php echo ($timezoneFormSelected === $tz) ? ' selected' : ''; ?>><?php echo h($tz); ?></option>
        <?php endforeach; ?>
        <option value="__custom__"<?php echo ($timezoneFormSelected === '__custom__') ? ' selected' : ''; ?>><?php echo h(t('timezone_custom_option', array())); ?></option>
      </select>
      <?php $timezoneCustomStyle = ($timezoneFormSelected === '__custom__') ? '' : 'display:none;'; ?>
      <div id="timezone-custom-wrap" style="<?php echo h($timezoneCustomStyle); ?>">
        <label><?php echo h(t('timezone_custom_field', array())); ?></label>
        <input type="text" name="timezone_id_custom" value="<?php echo h($timezoneFormCustom); ?>" placeholder="America/Chicago">
      </div>
      <p class="muted"><?php echo h(t('timezone_help_link_label', array())); ?> <a href="https://www.php.net/manual/timezones.php" target="_blank" rel="noopener">https://www.php.net/manual/timezones.php</a></p>
      <div style="margin-top:10px;">
        <button type="submit"><?php echo h(t('timezone_save_button', array())); ?></button>
      </div>
    </form>
  </div>
<?php else: ?>
  <div class="box">
    <h2><?php echo h(t('timezone_box', array())); ?></h2>
    <p class="ok"><?php echo h(t('timezone_configured', array('timezone' => $timezoneStatus['configured_value'], 'source' => $timezoneStatus['source']))); ?></p>
    <p><?php echo h(t('timezone_current', array('timezone' => $timezoneCurrent))); ?></p>
    <p><?php echo h(t('timezone_now', array('now' => $timezoneNow))); ?></p>
    <p class="muted"><?php echo h(t('timezone_reference', array('php' => $timezoneCurrent, 'ini' => ($timezoneIni === '' ? '-' : $timezoneIni), 'tz' => ($timezoneEnvTz === '' ? '-' : $timezoneEnvTz)))); ?> <span id="browser-timezone" data-label="<?php echo h(t('timezone_reference_browser', array())); ?>"></span></p>
  </div>
<?php endif; ?>

<div class="box">
  <h2><?php echo h(t('db_status', array())); ?></h2>
  <?php if (!$runtimeOk): ?>
    <p class="error"><?php echo h(t('runtime_missing', array())); ?></p>
  <?php elseif ($dbOk): ?>
    <p class="ok"><?php echo h(t('db_connected', array())); ?></p>
    <p><?php echo h(t('driver_label', array())); ?>: <code><?php echo h(isset($db['driver']) ? $db['driver'] : ''); ?></code></p>
    <?php if ($isInitialized): ?>
      <p class="ok"><?php echo h(t('schema_ready', array())); ?></p>
    <?php else: ?>
      <p><?php echo h(t('schema_missing', array())); ?></p>
      <form method="post">
        <?php echo qrs_lang_input_html(); ?>
        <input type="hidden" name="action" value="init_schema">
        <button type="submit"><?php echo h(t('schema_init_button', array())); ?></button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p class="error"><?php echo h(t('db_failed', array('message' => $dbError))); ?></p>
    <p><?php echo h(t('db_retry_hint', array())); ?></p>
  <?php endif; ?>
</div>

<?php if ($dbOk && $isInitialized): ?>
  <div class="box">
    <h2><?php echo h(t('runtime_settings_box', array())); ?></h2>
    <form method="post">
      <?php echo qrs_lang_input_html(); ?>
      <input type="hidden" name="action" value="save_runtime_settings">

      <label><?php echo h(t('runtime_setting_store_raw', array())); ?></label>
      <select name="store_raw_redash_payload">
        <option value="0"<?php echo ($runtimeStoreRaw === '0') ? ' selected' : ''; ?>><?php echo h(t('no', array())); ?></option>
        <option value="1"<?php echo ($runtimeStoreRaw === '1') ? ' selected' : ''; ?>><?php echo h(t('yes', array())); ?></option>
      </select>

      <label><?php echo h(t('runtime_setting_raw_dir', array())); ?></label>
      <input type="text" name="raw_redash_payload_dir" value="<?php echo h($runtimeRawDir); ?>">
      <p class="muted"><?php echo h(t('runtime_setting_raw_dir_resolved', array('path' => $runtimeRawDirResolved))); ?></p>
      <p class="muted"><?php echo h(t('runtime_setting_raw_note', array())); ?></p>

      <label><?php echo h(t('runtime_setting_worker_global_concurrency', array())); ?></label>
      <input type="text" name="worker_global_concurrency" value="<?php echo h($runtimeWorkerGlobalConcurrency); ?>">

      <label><?php echo h(t('runtime_setting_worker_max_run_seconds', array())); ?></label>
      <input type="text" name="worker_max_run_seconds" value="<?php echo h($runtimeWorkerMaxRunSeconds); ?>">

      <label><?php echo h(t('runtime_setting_worker_max_jobs_per_run', array())); ?></label>
      <input type="text" name="worker_max_jobs_per_run" value="<?php echo h($runtimeWorkerMaxJobsPerRun); ?>">

      <label><?php echo h(t('runtime_setting_worker_poll_timeout_seconds', array())); ?></label>
      <input type="text" name="worker_poll_timeout_seconds" value="<?php echo h($runtimeWorkerPollTimeoutSeconds); ?>">

      <label><?php echo h(t('runtime_setting_worker_poll_interval_millis', array())); ?></label>
      <input type="text" name="worker_poll_interval_millis" value="<?php echo h($runtimeWorkerPollIntervalMillis); ?>">

      <label><?php echo h(t('runtime_setting_worker_running_stale_seconds', array())); ?></label>
      <input type="text" name="worker_running_stale_seconds" value="<?php echo h($runtimeWorkerRunningStaleSeconds); ?>">

      <label><?php echo h(t('runtime_setting_worker_retry_max_count', array())); ?></label>
      <input type="text" name="worker_retry_max_count" value="<?php echo h($runtimeWorkerRetryMaxCount); ?>">

      <label><?php echo h(t('runtime_setting_worker_retry_backoff_seconds', array())); ?></label>
      <input type="text" name="worker_retry_backoff_seconds" value="<?php echo h($runtimeWorkerRetryBackoffSeconds); ?>">

      <div style="margin-top:10px;">
        <button type="submit"><?php echo h(t('runtime_settings_save_button', array())); ?></button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php if (!$dbOk): ?>
  <div class="box">
    <h2><?php echo h(t('config_box', array())); ?></h2>
    <form method="post">
      <?php echo qrs_lang_input_html(); ?>
      <input type="hidden" name="action" value="save_config">

      <label><?php echo h(t('driver_label', array())); ?></label>
      <select name="driver">
        <?php
          $drivers = array('sqlite', 'mysql', 'pgsql');
          $currentDriver = isset($db['driver']) ? $db['driver'] : 'sqlite';
          foreach ($drivers as $d) {
              $selected = ($currentDriver === $d) ? ' selected' : '';
              echo '<option value="' . h($d) . '"' . $selected . '>' . h($d) . '</option>';
          }
        ?>
      </select>

      <label><?php echo h(t('field_sqlite_path', array())); ?></label>
      <input type="text" name="sqlite_path" value="<?php echo h(isset($db['sqlite_path']) ? $db['sqlite_path'] : ($rootDir . '/var/qrs.sqlite3')); ?>">

      <label><?php echo h(t('field_host', array())); ?></label>
      <input type="text" name="host" value="<?php echo h(isset($db['host']) ? $db['host'] : '127.0.0.1'); ?>">

      <label><?php echo h(t('field_port', array())); ?></label>
      <input type="text" name="port" value="<?php echo h(isset($db['port']) ? $db['port'] : ''); ?>">

      <label><?php echo h(t('field_name', array())); ?></label>
      <input type="text" name="name" value="<?php echo h(isset($db['name']) ? $db['name'] : 'qrs'); ?>">

      <label><?php echo h(t('field_user', array())); ?></label>
      <input type="text" name="user" value="<?php echo h(isset($db['user']) ? $db['user'] : ''); ?>">

      <label><?php echo h(t('field_password', array())); ?></label>
      <input type="password" name="password" value="<?php echo h(isset($db['password']) ? $db['password'] : ''); ?>">

      <label><?php echo h(t('field_charset', array())); ?></label>
      <input type="text" name="charset" value="<?php echo h(isset($db['charset']) ? $db['charset'] : 'utf8'); ?>">

      <div style="margin-top:10px;">
        <button type="submit"><?php echo h(t('config_save_button', array())); ?></button>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="box">
  <h2><?php echo h(t('batch_example', array())); ?></h2>
  <pre>* * * * * flock -n <?php echo h($workerLockPath); ?> php <?php echo h($workerPath); ?></pre>
</div>

<script>
(function () {
  var timezoneSelect = document.getElementById('timezone-id-select');
  var timezoneCustomWrap = document.getElementById('timezone-custom-wrap');
  function toggleTimezoneCustom() {
    if (!timezoneSelect || !timezoneCustomWrap) { return; }
    timezoneCustomWrap.style.display = (timezoneSelect.value === '__custom__') ? '' : 'none';
  }
  if (timezoneSelect) {
    timezoneSelect.addEventListener('change', toggleTimezoneCustom);
    toggleTimezoneCustom();
  }

  var el = document.getElementById('browser-timezone');
  if (!el) { return; }
  var label = el.getAttribute('data-label') || 'Browser timezone';
  var guessed = '';
  try {
    if (window.Intl && Intl.DateTimeFormat) {
      guessed = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    }
  } catch (e) {}
  if (guessed !== '') {
    el.textContent = ' / ' + label + ': ' + guessed;
  }
})();
</script>

<?php qrs_render_footer();
