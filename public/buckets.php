<?php

require_once __DIR__ . '/_app.php';
require_once __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/lib/Repository/DatasetRepository.php';
require_once dirname(__DIR__) . '/lib/Repository/VariantRepository.php';
require_once dirname(__DIR__) . '/lib/Repository/BucketRepository.php';

$message = '';
$error = '';
$datasets = array();
$variants = array();
$rows = array();
$statusOptions = array();

$filterDatasetId = isset($_GET['dataset_id']) ? trim((string)$_GET['dataset_id']) : '';
$filterVariantId = isset($_GET['variant_id']) ? trim((string)$_GET['variant_id']) : '';
$filterStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit <= 0) {
    $limit = 200;
}

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

function qrs_format_seconds($value)
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric($value)) {
        return (string)$value;
    }
    return number_format((float)$value, 3, '.', '');
}

function qrs_format_int_group($value)
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric($value)) {
        return (string)$value;
    }
    return number_format((float)$value, 0, '.', ',');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $runtimeOk && $dbOk) {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    $variantId = isset($_POST['variant_id']) ? trim((string)$_POST['variant_id']) : '';
    $bucketAt = isset($_POST['bucket_at']) ? trim((string)$_POST['bucket_at']) : '';

    if (!$isInitialized) {
        $error = t('schema_required_for_page', array());
    } else {
        $bucketRepo = new QrsBucketRepository($pdo);
        if ($variantId === '' || $bucketAt === '') {
            $error = t('bucket_id_invalid', array());
        } elseif ($action === 'requeue_bucket') {
            try {
                if ($bucketRepo->requeueManual($variantId, $bucketAt)) {
                    $message = t('bucket_requeue_ok', array('variant_id' => $variantId, 'bucket_at' => $bucketAt));
                } else {
                    $error = t('bucket_not_found', array());
                }
            } catch (Exception $e) {
                $error = t('bucket_requeue_error', array('message' => $e->getMessage()));
            }
        } elseif ($action === 'delete_bucket') {
            try {
                $deleteResult = $bucketRepo->deleteBucketAndDataByKey($variantId, $bucketAt);
                if (isset($deleteResult['deleted_bucket']) && $deleteResult['deleted_bucket']) {
                    $deletedRows = isset($deleteResult['deleted_rows']) ? (int)$deleteResult['deleted_rows'] : 0;
                    $message = t('bucket_delete_ok_with_rows', array(
                        'variant_id' => $variantId,
                        'bucket_at' => $bucketAt,
                        'rows' => $deletedRows,
                    ));
                } else {
                    $error = t('bucket_not_found', array());
                }
            } catch (Exception $e) {
                $error = t('bucket_delete_error', array('message' => $e->getMessage()));
            }
        }
    }
}

if ($runtimeOk && $dbOk && $isInitialized) {
    try {
        $datasetRepo = new QrsDatasetRepository($pdo);
        $variantRepo = new QrsVariantRepository($pdo);
        $bucketRepo = new QrsBucketRepository($pdo);

        $datasets = $datasetRepo->findAllWithInstance();
        $variants = $variantRepo->findAllWithDataset('');
        $statusOptions = $bucketRepo->findDistinctStatuses();
        $rows = $bucketRepo->findAll(array(
            'dataset_id' => $filterDatasetId,
            'variant_id' => $filterVariantId,
            'status' => $filterStatus,
        ), $limit);
    } catch (Exception $e) {
        $error = t('bucket_list_error', array('message' => $e->getMessage()));
    }
}

qrs_render_header('buckets', t('app_title', array()), $message, $error);
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
<?php else: ?>
  <div class="box">
    <h2><?php echo h(t('tab_buckets', array())); ?></h2>
    <form method="get">
      <label><?php echo h(t('dataset_id', array())); ?></label>
      <select name="dataset_id">
        <option value=""><?php echo h(t('filter_all', array())); ?></option>
        <?php foreach ($datasets as $dataset): ?>
          <?php $datasetId = isset($dataset['dataset_id']) ? (string)$dataset['dataset_id'] : ''; ?>
          <option value="<?php echo h($datasetId); ?>"<?php echo ($filterDatasetId === $datasetId) ? ' selected' : ''; ?>>
            <?php echo h($datasetId); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label><?php echo h(t('variant_id', array())); ?></label>
      <select name="variant_id">
        <option value=""><?php echo h(t('filter_all', array())); ?></option>
        <?php foreach ($variants as $variant): ?>
          <?php $variantId = isset($variant['variant_id']) ? (string)$variant['variant_id'] : ''; ?>
          <?php $variantDatasetId = isset($variant['dataset_id']) ? (string)$variant['dataset_id'] : ''; ?>
          <?php if ($filterDatasetId !== '' && $variantDatasetId !== $filterDatasetId) { continue; } ?>
          <option value="<?php echo h($variantId); ?>"<?php echo ($filterVariantId === $variantId) ? ' selected' : ''; ?>>
            <?php echo h($variantId . ' / ' . $variantDatasetId); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label><?php echo h(t('bucket_status', array())); ?></label>
      <select name="status">
        <option value=""><?php echo h(t('filter_all', array())); ?></option>
        <?php foreach ($statusOptions as $status): ?>
          <option value="<?php echo h($status); ?>"<?php echo ($filterStatus === $status) ? ' selected' : ''; ?>><?php echo h($status); ?></option>
        <?php endforeach; ?>
      </select>

      <label><?php echo h(t('filter_limit', array())); ?></label>
      <input type="text" name="limit" value="<?php echo h((string)$limit); ?>">

      <div style="margin-top:10px;">
        <button type="submit"><?php echo h(t('filter_apply', array())); ?></button>
      </div>
    </form>
  </div>

  <div class="box">
    <h2><?php echo h(t('bucket_list', array())); ?></h2>
    <?php if (count($rows) === 0): ?>
      <p><?php echo h(t('bucket_none', array())); ?></p>
    <?php else: ?>
      <div class="table-scroll">
      <table>
        <thead>
          <tr>
            <th><?php echo h(t('variant_id', array())); ?></th>
            <th><?php echo h(t('dataset_id', array())); ?></th>
            <th>bucket_at</th>
            <th><?php echo h(t('bucket_status', array())); ?></th>
            <th>execute_after</th>
            <th class="num">attempt_count</th>
            <th class="num">last_row_count</th>
            <th class="num">last_fetch_seconds</th>
            <th><?php echo h(t('col_updated_at', array())); ?></th>
            <th><?php echo h(t('col_actions', array())); ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <?php $variantId = isset($row['variant_id']) ? (string)$row['variant_id'] : ''; ?>
          <?php $bucketAt = isset($row['bucket_at']) ? (string)$row['bucket_at'] : ''; ?>
          <tr>
            <td><code><?php echo h($variantId); ?></code></td>
            <td><code><?php echo h(isset($row['dataset_id']) ? $row['dataset_id'] : ''); ?></code></td>
            <td><code><?php echo h($bucketAt); ?></code></td>
            <td><code><?php echo h(isset($row['status']) ? $row['status'] : ''); ?></code></td>
            <td><code><?php echo h(isset($row['execute_after']) ? $row['execute_after'] : ''); ?></code></td>
            <td class="num"><?php echo h(isset($row['attempt_count']) ? (string)$row['attempt_count'] : ''); ?></td>
            <td class="num"><?php echo h(isset($row['last_row_count']) ? qrs_format_int_group($row['last_row_count']) : ''); ?></td>
            <td class="num"><?php echo h(isset($row['last_fetch_seconds']) ? qrs_format_seconds($row['last_fetch_seconds']) : ''); ?></td>
            <td><code><?php echo h(isset($row['updated_at']) ? $row['updated_at'] : ''); ?></code></td>
            <td>
              <form class="inline-form" method="post">
                <?php echo qrs_lang_input_html(); ?>
                <input type="hidden" name="action" value="requeue_bucket">
                <input type="hidden" name="variant_id" value="<?php echo h($variantId); ?>">
                <input type="hidden" name="bucket_at" value="<?php echo h($bucketAt); ?>">
                <button type="submit"><?php echo h(t('bucket_requeue_button', array())); ?></button>
              </form>
              <form class="inline-form" method="post" onsubmit="return confirm('<?php echo h(t('bucket_delete_confirm', array())); ?>');">
                <?php echo qrs_lang_input_html(); ?>
                <input type="hidden" name="action" value="delete_bucket">
                <input type="hidden" name="variant_id" value="<?php echo h($variantId); ?>">
                <input type="hidden" name="bucket_at" value="<?php echo h($bucketAt); ?>">
                <button type="submit"><?php echo h(t('bucket_delete_button', array())); ?></button>
              </form>
              <a href="<?php echo h(qrs_url_with_params('logs.php', array('variant_id' => $variantId, 'bucket_at' => $bucketAt))); ?>"><?php echo h(t('bucket_open_logs', array())); ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php qrs_render_footer();
