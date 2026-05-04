<?php

$configFile = is_file(__DIR__ . '/config.php')
    ? __DIR__ . '/config.php'
    : __DIR__ . '/config.example.php';

$config = require $configFile;

$config['app']['url'] = 'http://127.0.0.1:8001';
$config['database']['database'] = 'renewal_tracker_test';
$config['mail']['log_path'] = dirname(__DIR__) . '/storage/logs/mail-test.log';
$config['parasut']['token_path'] = dirname(__DIR__) . '/storage/parasut_token_test.json';
$config['parasut']['contact_cache_path'] = dirname(__DIR__) . '/storage/parasut_contacts_cache_test.json';

return $config;
