<?php

$lang = isset($_GET['lang']) ? $_GET['lang'] : '';
if ($lang === 'ja' || $lang === 'en') {
    header('Location: env.php?lang=' . $lang);
} else {
    header('Location: env.php');
}
exit;
