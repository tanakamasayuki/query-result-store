<?php

require_once __DIR__ . '/_app.php';
require_once __DIR__ . '/_layout.php';

$message = '';
$error = '';
$instances = array();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $runtimeOk && $dbOk) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if (!$isInitialized) {
        $error = t('schema_required_for_page', array());
    } elseif (!$curlAvailable) {
        $error = t('instance_feature_disabled', array());
    } else {
        $repo = new QrsInstanceRepository($pdo);

        if ($action === 'create_instance') {
            $baseUrl = isset($_POST['base_url']) ? trim($_POST['base_url']) : '';
            $apiKey = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';
            $isEnabled = isset($_POST['is_enabled']) && $_POST['is_enabled'] === '1';

            if ($baseUrl === '' || $apiKey === '') {
                $error = t('instance_required', array());
            } else {
                try {
                    $client = new QrsRedashClient();
                    $testResult = $client->testConnection($baseUrl, $apiKey);
                    if (!$testResult['ok']) {
                        $error = t('instance_test_blocked', array('code' => $testResult['status_code'], 'message' => $testResult['message']));
                    } else {
                        $instanceId = $repo->create($baseUrl, $apiKey, $isEnabled);
                        $message = t('instance_created', array('id' => $instanceId));
                    }
                } catch (Exception $e) {
                    $error = t('instance_create_error', array('message' => $e->getMessage()));
                }
            }
        } elseif ($action === 'toggle_instance') {
            $instanceId = isset($_POST['instance_id']) ? trim($_POST['instance_id']) : '';
            $nextEnabled = isset($_POST['next_enabled']) && $_POST['next_enabled'] === '1';

            if ($instanceId === '') {
                $error = t('instance_id_invalid', array());
            } else {
                try {
                    if ($repo->setEnabled($instanceId, $nextEnabled)) {
                        $message = t('instance_toggle_ok', array());
                    } else {
                        $error = t('instance_not_found', array());
                    }
                } catch (Exception $e) {
                    $error = t('instance_toggle_error', array('message' => $e->getMessage()));
                }
            }
        } elseif ($action === 'test_instance') {
            $instanceId = isset($_POST['instance_id']) ? trim($_POST['instance_id']) : '';

            if ($instanceId === '') {
                $error = t('instance_id_invalid', array());
            } else {
                try {
                    $instance = $repo->findById($instanceId);
                    if ($instance === null) {
                        $error = t('instance_not_found', array());
                    } else {
                        $client = new QrsRedashClient();
                        $result = $client->testConnection($instance['base_url'], $instance['api_key']);
                        if ($result['ok']) {
                            $message = t('instance_test_ok', array('id' => $instanceId, 'code' => $result['status_code']));
                        } else {
                            $error = t('instance_test_ng', array('id' => $instanceId, 'code' => $result['status_code'], 'message' => $result['message']));
                        }
                    }
                } catch (Exception $e) {
                    $error = t('instance_test_error', array('message' => $e->getMessage()));
                }
            }
        }
    }
}

if ($runtimeOk && $dbOk && $isInitialized) {
    try {
        $repo = new QrsInstanceRepository($pdo);
        $instances = $repo->findAll();
    } catch (Exception $e) {
        $error = t('instance_test_error', array('message' => $e->getMessage()));
    }
}

qrs_render_header('instances', t('app_title', array()), $message, $error);
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
<?php elseif (!$curlAvailable): ?>
  <div class="box">
    <p class="error"><?php echo h(t('instance_feature_disabled', array())); ?></p>
    <p><a href="<?php echo h(qrs_url('env.php')); ?>"><?php echo h(t('go_environment', array())); ?></a></p>
  </div>
<?php else: ?>
  <div class="box">
    <h2><?php echo h(t('instance_box', array())); ?></h2>
    <form method="post">
      <?php echo qrs_lang_input_html(); ?>
      <input type="hidden" name="action" value="create_instance">

      <label><?php echo h(t('base_url', array())); ?></label>
      <input type="text" name="base_url" placeholder="https://redash.example.com" required>

      <label><?php echo h(t('api_key', array())); ?></label>
      <input type="password" name="api_key" required>

      <label><?php echo h(t('is_enabled', array())); ?></label>
      <select name="is_enabled">
        <option value="1"><?php echo h(t('enabled', array())); ?></option>
        <option value="0"><?php echo h(t('disabled', array())); ?></option>
      </select>

      <div style="margin-top:10px;">
        <button type="submit"><?php echo h(t('register_button', array())); ?></button>
      </div>
    </form>
    <p class="muted"><?php echo h(t('instance_post_hint', array())); ?></p>
  </div>

  <div class="box">
    <h2><?php echo h(t('instance_list', array())); ?></h2>
    <?php if (count($instances) === 0): ?>
      <p><?php echo h(t('instance_none', array())); ?></p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th><?php echo h(t('col_instance_id', array())); ?></th>
            <th><?php echo h(t('col_base_url', array())); ?></th>
            <th><?php echo h(t('col_api_key', array())); ?></th>
            <th><?php echo h(t('col_enabled', array())); ?></th>
            <th><?php echo h(t('col_updated_at', array())); ?></th>
            <th><?php echo h(t('col_actions', array())); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($instances as $instance): ?>
          <tr>
            <td><code><?php echo h($instance['instance_id']); ?></code></td>
            <td><?php echo h($instance['base_url']); ?></td>
            <td><code><?php echo h(maskApiKey($instance['api_key'])); ?></code></td>
            <td><?php echo ((int)$instance['is_enabled'] === 1) ? h(t('yes', array())) : h(t('no', array())); ?></td>
            <td><?php echo h($instance['updated_at']); ?></td>
            <td>
              <form class="inline-form" method="post">
                <?php echo qrs_lang_input_html(); ?>
                <input type="hidden" name="action" value="toggle_instance">
                <input type="hidden" name="instance_id" value="<?php echo h($instance['instance_id']); ?>">
                <input type="hidden" name="next_enabled" value="<?php echo ((int)$instance['is_enabled'] === 1) ? '0' : '1'; ?>">
                <button type="submit"><?php echo ((int)$instance['is_enabled'] === 1) ? h(t('disable_button', array())) : h(t('enable_button', array())); ?></button>
              </form>

              <form class="inline-form" method="post">
                <?php echo qrs_lang_input_html(); ?>
                <input type="hidden" name="action" value="test_instance">
                <input type="hidden" name="instance_id" value="<?php echo h($instance['instance_id']); ?>">
                <button type="submit"><?php echo h(t('connection_test', array())); ?></button>
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
