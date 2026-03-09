<?php

function qrs_render_header($activePage, $pageTitle, $message, $error)
{
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo h($pageTitle); ?></title>
  <style>
    body { font-family: sans-serif; margin: 24px; line-height: 1.5; }
    .box { width: 100%; max-width: none; padding: 16px; border: 1px solid #ccc; border-radius: 6px; margin-bottom: 16px; box-sizing: border-box; }
    .ok { color: #0a7b34; }
    .error { color: #b00020; }
    label { display: block; margin-top: 10px; }
    input, select, textarea { width: 100%; max-width: 520px; padding: 6px; box-sizing: border-box; }
    button { margin-top: 10px; padding: 7px 11px; }
    code { background: #f5f5f5; padding: 2px 4px; }
    table { width: auto; max-width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
    .num { text-align: right; }
    .table-scroll { overflow-x: auto; }
    .table-scroll table { min-width: 1100px; }
    .param-table { width: 100%; table-layout: fixed; }
    .param-table th, .param-table td { overflow-wrap: anywhere; }
    .param-table input, .param-table select, .param-table textarea { max-width: none; width: 100%; box-sizing: border-box; }
    .param-table .preset-buttons { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .param-table .preset-buttons button { margin-top: 0; }
    .param-table .js-relative-wrap select,
    .param-table .js-relative-wrap input { margin-bottom: 6px; }
    .schedule-preset-list { display: grid; gap: 8px; margin: 10px 0; }
    .schedule-preset-item { display: flex; gap: 10px; align-items: flex-start; }
    .schedule-preset-item button { margin-top: 0; white-space: nowrap; }
    .inline-form { display: inline-block; margin-right: 8px; }
    .muted { color: #666; font-size: 12px; }
    .topbar { margin-bottom: 12px; }
    .tabs { margin-bottom: 16px; }
    .tabs a { display: inline-block; margin-right: 8px; padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; text-decoration: none; color: #333; }
    .tabs a.active { background: #f3f3f3; font-weight: bold; }
  </style>
</head>
<body>
  <h1><?php echo h(t('app_name', array())); ?></h1>

  <div class="topbar">
    <strong><?php echo h(t('language', array())); ?>:</strong>
    <a href="<?php echo h(qrs_lang_switch_url('en')); ?>"><?php echo h(t('lang_en', array())); ?></a>
    |
    <a href="<?php echo h(qrs_lang_switch_url('ja')); ?>"><?php echo h(t('lang_ja', array())); ?></a>
    |
    <strong><?php echo h(t('version_label', array())); ?>:</strong>
    <code><?php echo h(qrs_app_version()); ?></code>
  </div>

  <div class="tabs">
    <a class="<?php echo ($activePage === 'env') ? 'active' : ''; ?>" href="env.php"><?php echo h(t('tab_environment', array())); ?></a>
    <a class="<?php echo ($activePage === 'instances') ? 'active' : ''; ?>" href="instances.php"><?php echo h(t('tab_instances', array())); ?></a>
    <a class="<?php echo ($activePage === 'datasets') ? 'active' : ''; ?>" href="datasets.php"><?php echo h(t('tab_datasets', array())); ?></a>
    <a class="<?php echo ($activePage === 'variants') ? 'active' : ''; ?>" href="variants.php"><?php echo h(t('tab_variants', array())); ?></a>
    <a class="<?php echo ($activePage === 'buckets') ? 'active' : ''; ?>" href="buckets.php"><?php echo h(t('tab_buckets', array())); ?></a>
    <a class="<?php echo ($activePage === 'logs') ? 'active' : ''; ?>" href="logs.php"><?php echo h(t('tab_logs', array())); ?></a>
  </div>

  <?php if ($message !== ''): ?>
    <p class="ok"><?php echo h($message); ?></p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <p class="error"><?php echo h($error); ?></p>
  <?php endif; ?>
<?php
}

function qrs_render_footer()
{
?>
</body>
</html>
<?php
}
