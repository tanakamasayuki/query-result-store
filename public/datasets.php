<?php

require_once __DIR__ . '/_app.php';
require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/lib/Repository/DatasetRepository.php';
require_once dirname(__DIR__) . '/lib/RedashClient.php';

$message = '';
$error = '';
$datasets = array();
$enabledInstances = array();

if (!$runtimeOk) {
    $error = t('runtime_error', array('errors' => implode(' ', $runtimeErrors)));
}

$dbOk = false;
$dbError = '';
$pdo = null;
$isInitialized = false;
$curlAvailable = false;

if ($runtimeOk) {
    qrs_connect_db($config, $dbOk, $dbError, $pdo, $isInitialized, $curlAvailable);
}

function qrs_extract_query_id_from_url($url)
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '';
    }

    $matches = array();
    if (preg_match('#/queries/([0-9]+)#', $path, $matches)) {
        return $matches[1];
    }

    return '';
}

function qrs_normalize_url_base($url)
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host'])) {
        return '';
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    $path = isset($parts['path']) ? $parts['path'] : '';
    $path = rtrim($path, '/');

    $base = $scheme . '://' . $host;
    if ($port > 0) {
        $base .= ':' . $port;
    }
    if ($path !== '') {
        $base .= $path;
    }

    return $base;
}

function qrs_find_instance_by_query_url($queryUrl, $instances)
{
    $normalizedQueryUrl = qrs_normalize_url_base($queryUrl);
    if ($normalizedQueryUrl === '') {
        return null;
    }

    $selected = null;
    $bestLen = -1;
    foreach ($instances as $instance) {
        $instanceBase = isset($instance['base_url']) ? $instance['base_url'] : '';
        $normalizedInstanceBase = qrs_normalize_url_base($instanceBase);
        if ($normalizedInstanceBase === '') {
            continue;
        }

        if (strpos($normalizedQueryUrl, $normalizedInstanceBase) === 0) {
            $len = strlen($normalizedInstanceBase);
            if ($len > $bestLen) {
                $selected = $instance;
                $bestLen = $len;
            }
        }
    }

    return $selected;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $runtimeOk && $dbOk) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (!$isInitialized) {
        $error = t('schema_required_for_page', array());
    } else {
        $instanceRepo = new QrsInstanceRepository($pdo);
        $datasetRepo = new QrsDatasetRepository($pdo);
        $enabledInstances = $instanceRepo->findEnabled();

        if ($action === 'create_dataset_from_url') {
            $queryUrl = isset($_POST['query_url']) ? trim($_POST['query_url']) : '';
            $queryId = qrs_extract_query_id_from_url($queryUrl);

            if ($queryUrl === '') {
                $error = t('dataset_required_url', array());
            } elseif ($queryId === '') {
                $error = t('dataset_invalid_url', array());
            } else {
                $instance = qrs_find_instance_by_query_url($queryUrl, $enabledInstances);
                if ($instance === null) {
                    $error = t('dataset_instance_auto_detect_failed', array());
                } else {
                    $instanceId = $instance['instance_id'];
                    $existing = $datasetRepo->findByInstanceAndQuery($instanceId, $queryId);
                    if ($existing !== null) {
                        $error = t('dataset_already_exists', array('id' => $existing['dataset_id']));
                    } else {
                        try {
                            $client = new QrsRedashClient();
                            $test = $client->testQueryExists($instance['base_url'], $instance['api_key'], $queryId);
                            if (!$test['ok']) {
                                $error = t('dataset_query_test_failed', array('code' => $test['status_code'], 'message' => $test['message']));
                            } else {
                                $datasetId = $datasetRepo->create($instanceId, $queryId);
                                $message = t('dataset_created', array('id' => $datasetId));
                            }
                        } catch (Exception $e) {
                            $error = t('dataset_create_error', array('message' => $e->getMessage()));
                        }
                    }
                }
            }
        } elseif ($action === 'create_dataset_manual') {
            $instanceId = isset($_POST['instance_id']) ? trim($_POST['instance_id']) : '';
            $queryId = isset($_POST['query_id']) ? trim($_POST['query_id']) : '';

            if ($instanceId === '' || $queryId === '') {
                $error = t('dataset_required_manual', array());
            } elseif (!preg_match('/^[0-9]+$/', $queryId)) {
                $error = t('dataset_query_id_invalid', array());
            } else {
                $instance = $instanceRepo->findById($instanceId);
                if ($instance === null || (int)$instance['is_enabled'] !== 1) {
                    $error = t('dataset_instance_invalid', array());
                } else {
                    $existing = $datasetRepo->findByInstanceAndQuery($instanceId, $queryId);
                    if ($existing !== null) {
                        $error = t('dataset_already_exists', array('id' => $existing['dataset_id']));
                    } else {
                        try {
                            $client = new QrsRedashClient();
                            $test = $client->testQueryExists($instance['base_url'], $instance['api_key'], $queryId);
                            if (!$test['ok']) {
                                $error = t('dataset_query_test_failed', array('code' => $test['status_code'], 'message' => $test['message']));
                            } else {
                                $datasetId = $datasetRepo->create($instanceId, $queryId);
                                $message = t('dataset_created', array('id' => $datasetId));
                            }
                        } catch (Exception $e) {
                            $error = t('dataset_create_error', array('message' => $e->getMessage()));
                        }
                    }
                }
            }
        } elseif ($action === 'test_dataset') {
            $datasetId = isset($_POST['dataset_id']) ? trim($_POST['dataset_id']) : '';
            if ($datasetId === '') {
                $error = t('dataset_id_invalid', array());
            } else {
                $dataset = $datasetRepo->findById($datasetId);
                if ($dataset === null) {
                    $error = t('dataset_not_found', array());
                } else {
                    $instance = $instanceRepo->findById($dataset['instance_id']);
                    if ($instance === null || (int)$instance['is_enabled'] !== 1) {
                        $error = t('dataset_instance_invalid', array());
                    } else {
                        try {
                            $client = new QrsRedashClient();
                            $test = $client->testQueryExists($instance['base_url'], $instance['api_key'], $dataset['query_id']);
                            if ($test['ok']) {
                                $message = t('dataset_test_ok', array('id' => $datasetId, 'code' => $test['status_code']));
                            } else {
                                $error = t('dataset_test_ng', array('id' => $datasetId, 'code' => $test['status_code'], 'message' => $test['message']));
                            }
                        } catch (Exception $e) {
                            $error = t('dataset_test_error', array('message' => $e->getMessage()));
                        }
                    }
                }
            }
        } elseif ($action === 'delete_dataset') {
            $datasetId = isset($_POST['dataset_id']) ? trim($_POST['dataset_id']) : '';
            if ($datasetId === '') {
                $error = t('dataset_id_invalid', array());
            } else {
                $dataset = $datasetRepo->findById($datasetId);
                if ($dataset === null) {
                    $error = t('dataset_not_found', array());
                } else {
                    $refCount = $datasetRepo->countVariantReferences($datasetId);
                    if ($refCount > 0) {
                        $error = t('dataset_delete_blocked', array('count' => $refCount));
                    } else {
                        try {
                            if ($datasetRepo->deleteById($datasetId)) {
                                $message = t('dataset_deleted', array('id' => $datasetId));
                            } else {
                                $error = t('dataset_not_found', array());
                            }
                        } catch (Exception $e) {
                            $error = t('dataset_delete_error', array('message' => $e->getMessage()));
                        }
                    }
                }
            }
        }
    }
}

if ($runtimeOk && $dbOk && $isInitialized) {
    try {
        $instanceRepo = new QrsInstanceRepository($pdo);
        $datasetRepo = new QrsDatasetRepository($pdo);
        $enabledInstances = $instanceRepo->findEnabled();
        $datasets = $datasetRepo->findAllWithInstance();
    } catch (Exception $e) {
        $error = t('dataset_list_error', array('message' => $e->getMessage()));
    }
}

qrs_render_header('datasets', t('app_title', array()), $message, $error);
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
<?php elseif (count($enabledInstances) === 0): ?>
  <div class="box">
    <p class="error"><?php echo h(t('dataset_no_instance', array())); ?></p>
    <p><a href="<?php echo h(qrs_url('instances.php')); ?>"><?php echo h(t('go_instances', array())); ?></a></p>
  </div>
<?php else: ?>
  <div class="box">
    <h2><?php echo h(t('dataset_create_from_url', array())); ?></h2>
    <form method="post">
      <?php echo qrs_lang_input_html(); ?>
      <input type="hidden" name="action" value="create_dataset_from_url">

      <label><?php echo h(t('dataset_query_url', array())); ?></label>
      <input type="text" name="query_url" placeholder="https://redash.example.com/queries/123" required>

      <div style="margin-top:10px;">
        <button type="submit"><?php echo h(t('dataset_create_button', array())); ?></button>
      </div>
    </form>
  </div>

  <div class="box">
    <h2><?php echo h(t('dataset_create_manual', array())); ?></h2>
    <form method="post">
      <?php echo qrs_lang_input_html(); ?>
      <input type="hidden" name="action" value="create_dataset_manual">

      <label><?php echo h(t('dataset_instance', array())); ?></label>
      <select name="instance_id" required>
        <option value=""><?php echo h(t('select_option', array())); ?></option>
        <?php foreach ($enabledInstances as $instance): ?>
          <option value="<?php echo h($instance['instance_id']); ?>"><?php echo h($instance['instance_id'] . ' / ' . $instance['base_url']); ?></option>
        <?php endforeach; ?>
      </select>

      <label><?php echo h(t('dataset_query_id', array())); ?></label>
      <input type="text" name="query_id" placeholder="123" required>

      <div style="margin-top:10px;">
        <button type="submit"><?php echo h(t('dataset_create_button', array())); ?></button>
      </div>
    </form>
  </div>

  <div class="box">
    <h2><?php echo h(t('dataset_list', array())); ?></h2>
    <?php if (count($datasets) === 0): ?>
      <p><?php echo h(t('dataset_none', array())); ?></p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th><?php echo h(t('dataset_id', array())); ?></th>
            <th><?php echo h(t('dataset_instance_id', array())); ?></th>
            <th><?php echo h(t('dataset_query_id', array())); ?></th>
            <th><?php echo h(t('dataset_instance_base_url', array())); ?></th>
            <th><?php echo h(t('col_updated_at', array())); ?></th>
            <th><?php echo h(t('col_actions', array())); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($datasets as $dataset): ?>
          <tr>
            <td><code><?php echo h($dataset['dataset_id']); ?></code></td>
            <td><code><?php echo h($dataset['instance_id']); ?></code></td>
            <td><code><?php echo h($dataset['query_id']); ?></code></td>
            <td><?php echo h(isset($dataset['instance_base_url']) ? $dataset['instance_base_url'] : ''); ?></td>
            <td><?php echo h($dataset['updated_at']); ?></td>
            <td>
              <div><a href="<?php echo h(qrs_url_with_params('variants.php', array('dataset_id' => $dataset['dataset_id']))); ?>"><?php echo h(t('manage_variants', array())); ?></a></div>
              <form class="inline-form" method="post">
                <?php echo qrs_lang_input_html(); ?>
                <input type="hidden" name="action" value="test_dataset">
                <input type="hidden" name="dataset_id" value="<?php echo h($dataset['dataset_id']); ?>">
                <button type="submit"><?php echo h(t('dataset_test_button', array())); ?></button>
              </form>
              <form class="inline-form" method="post" onsubmit="return confirm('<?php echo h(t('dataset_delete_confirm', array())); ?>');">
                <?php echo qrs_lang_input_html(); ?>
                <input type="hidden" name="action" value="delete_dataset">
                <input type="hidden" name="dataset_id" value="<?php echo h($dataset['dataset_id']); ?>">
                <button type="submit"><?php echo h(t('dataset_delete_button', array())); ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php qrs_render_footer();
