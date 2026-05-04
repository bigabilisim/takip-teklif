<?php

declare(strict_types=1);

$configName = getenv('APP_CONFIG') ?: 'config.php';
if (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $configName)) {
    $configName = 'config.php';
}

$config = require dirname(__DIR__) . '/config/' . $configName;
$db = $config['database'];
$charset = $db['charset'] ?? 'utf8mb4';

$dsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    $db['host'],
    (int) $db['port'],
    $charset
);

$pdo = new PDO($dsn, $db['username'], $db['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$database = str_replace('`', '``', $db['database']);
$pdo->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    $database
));
$pdo->exec(sprintf('USE `%s`', $database));

run_sql_file($pdo, dirname(__DIR__) . '/database/schema.sql');

$exists = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($exists === 0) {
    run_sql_file($pdo, dirname(__DIR__) . '/database/seed.sql');
}

echo "Veritabani hazir: {$db['database']}\n";
echo $exists === 0 ? "Demo veriler yuklendi.\n" : "Mevcut veriler korundu, seed atlandi.\n";

function run_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("SQL dosyasi okunamadi: {$path}");
    }

    foreach (split_sql($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($quote !== null) {
            $buffer .= $char;
            if ($char === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === '-' && $next === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            $buffer .= "\n";
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}
