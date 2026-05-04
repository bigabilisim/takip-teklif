<?php

return [
    'app' => [
        'name' => 'Ürün ve Hizmet Yenileme Takibi',
        'url' => 'http://127.0.0.1:8000',
        'timezone' => 'Europe/Istanbul',
        'debug' => true,
    ],

    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'renewal_tracker',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],

    'mail' => [
        // "log" gelistirme icin storage/logs/mail.log dosyasina yazar.
        // "mail" PHP'nin mail() fonksiyonunu kullanir.
        'transport' => 'log',
        'from_email' => 'yenileme@example.com',
        'from_name' => 'Yenileme Takip Sistemi',
        'log_path' => dirname(__DIR__) . '/storage/logs/mail.log',
    ],

    'reminders' => [
        'default_days_before' => 30,
    ],

    'auth' => [
        'session_lifetime' => 86400,
        'max_active_sessions' => 2,
    ],

    'parasut' => [
        'base_url' => 'https://api.parasut.com',
        'client_id' => getenv('PARASUT_CLIENT_ID') ?: '',
        'client_secret' => getenv('PARASUT_CLIENT_SECRET') ?: '',
        'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
        'token_path' => dirname(__DIR__) . '/storage/parasut_token.json',
        'contact_cache_path' => dirname(__DIR__) . '/storage/parasut_contacts_cache.json',
        'contact_cache_ttl' => 21600,
        'contact_cache_pages' => 50,
    ],
];
