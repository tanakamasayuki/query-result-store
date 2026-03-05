<?php

require_once __DIR__ . '/_app.php';
require_once __DIR__ . '/_layout.php';

$message = '';
$error = '';

qrs_render_header('logs', t('app_title', array()), $message, $error);
?>

<div class="box">
  <h2><?php echo h(t('tab_logs', array())); ?></h2>
  <p><?php echo h(t('page_placeholder', array())); ?></p>
</div>

<?php qrs_render_footer();
