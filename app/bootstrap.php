<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

error_reporting(E_ALL);

$configName = getenv('APP_CONFIG') ?: 'config.php';
if (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $configName)) {
    $configName = 'config.php';
}

$configFile = ROOT_PATH . '/config/' . $configName;
$appConfig = require is_file($configFile)
    ? $configFile
    : ROOT_PATH . '/config/config.example.php';

$databaseOverrideFile = ROOT_PATH . '/storage/config/database.php';
if (is_file($databaseOverrideFile)) {
    $databaseOverride = require $databaseOverrideFile;
    if (is_array($databaseOverride)) {
        $appConfig['database'] = array_merge($appConfig['database'] ?? [], $databaseOverride);
    }
}

$debug = (bool) ($appConfig['app']['debug'] ?? true);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

$logDir = ROOT_PATH . '/storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/app-error.log');
if (!is_file($logDir . '/app-error.log')) {
    touch($logDir . '/app-error.log');
}

date_default_timezone_set($appConfig['app']['timezone'] ?? 'Europe/Istanbul');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = ROOT_PATH . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require ROOT_PATH . '/app/Core/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = max(3600, (int) ($appConfig['auth']['session_lifetime'] ?? 86400));
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    ini_set('session.cookie_lifetime', (string) $sessionLifetime);
    $httpsSignals = [
        strtolower((string) ($_SERVER['HTTPS'] ?? '')),
        strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')),
        strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')),
    ];
    $isHttps = in_array('on', $httpsSignals, true)
        || in_array('https', $httpsSignals, true)
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
