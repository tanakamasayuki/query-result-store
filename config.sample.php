<?php

return array(
    'app' => array(
        'timezone_id' => 'Asia/Tokyo',
    ),
    'db' => array(
        'driver' => 'sqlite', // sqlite | mysql | pgsql
        'sqlite_path' => __DIR__ . '/var/qrs.sqlite3',
        'host' => '127.0.0.1',
        'port' => '',
        'name' => 'qrs',
        'user' => '',
        'password' => '',
        'charset' => 'utf8',
    ),
);
