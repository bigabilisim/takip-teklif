<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SettingsRepository;
use PDO;
use RuntimeException;
use ZipArchive;

final class DatabaseBackup
{
    public static function runDaily(SettingsRepository $settingsRepo, bool $force = false): array
    {
        $settings = $settingsRepo->all();
        if (!$force && (string) ($settings['backup.enabled'] ?? '1') !== '1') {
            return ['ok' => true, 'skipped' => true, 'message' => 'Yedekleme kapali.'];
        }

        $today = date('Y-m-d');
        if (!$force && (string) ($settings['backup.last_run_date'] ?? '') === $today) {
            return ['ok' => true, 'skipped' => true, 'message' => 'Bugunun yedegi zaten alinmis.'];
        }

        if (!$force && !self::timeReached((string) ($settings['backup.time'] ?? '02:00'))) {
            return ['ok' => true, 'skipped' => true, 'message' => 'Yedekleme saati bekleniyor.'];
        }

        $archive = self::createArchive();
        self::pruneOld((int) ($settings['backup.keep_days'] ?? 14));

        $recipient = trim((string) (($settings['backup.email'] ?? '') ?: ($settings['mail.from_email'] ?? '')));
        $mailResult = ['ok' => false, 'error' => 'Yedek mail alicisi tanimli degil.'];
        if ($recipient !== '') {
            $mailResult = Mailer::sendWithAttachments(
                $recipient,
                'Yenileme Takibi DB yedegi - ' . date('d.m.Y'),
                implode("\n", [
                    'Günlük veritabanı yedeği ektedir.',
                    '',
                    'Veritabanı: ' . $archive['database'],
                    'Tablo sayısı: ' . $archive['table_count'],
                    'Kayıt sayısı: ' . $archive['row_count'],
                    'Dosya boyutu: ' . self::humanSize((int) $archive['size']),
                    '',
                    'ZIP içinde backup.sql geri yükleme dosyası ve her tablo için CSV çıktısı bulunur.',
                ]),
                [[
                    'path' => $archive['zip_path'],
                    'name' => basename((string) $archive['zip_path']),
                    'content_type' => 'application/zip',
                ]]
            );
        }

        $settingsRepo->setMany([
            'backup.last_run_date' => $today,
            'backup.last_run_at' => date('Y-m-d H:i:s'),
            'backup.last_file' => (string) $archive['zip_path'],
            'backup.last_size' => (string) $archive['size'],
            'backup.last_mail_status' => $mailResult['ok'] ? 'sent' : 'failed',
            'backup.last_error' => $mailResult['ok'] ? '' : (string) ($mailResult['error'] ?? ''),
        ]);

        return [
            'ok' => true,
            'skipped' => false,
            'archive' => $archive,
            'mail' => $mailResult,
        ];
    }

    public static function createArchive(string $prefix = 'db'): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive PHP eklentisi aktif degil.');
        }

        $db = Database::connection();
        $database = (string) app_config('database.database', 'database');
        $timestamp = date('Ymd-His');
        $backupDir = ROOT_PATH . '/storage/backups/database';
        $tempDir = ROOT_PATH . '/storage/backups/tmp/' . $prefix . '-' . $timestamp . '-' . bin2hex(random_bytes(4));

        self::ensureDirectory($backupDir);
        self::ensureDirectory($tempDir . '/csv');

        $tables = self::tables($db);
        $rowCounts = [];
        $sqlPath = $tempDir . '/backup.sql';
        self::writeSqlDump($db, $tables, $sqlPath);

        foreach ($tables as $table) {
            $rowCounts[$table] = self::writeCsv($db, $table, $tempDir . '/csv/' . self::safeName($table) . '.csv');
        }

        $manifest = [
            'created_at' => date(DATE_ATOM),
            'database' => $database,
            'table_count' => count($tables),
            'row_count' => array_sum($rowCounts),
            'tables' => $rowCounts,
            'restore_command' => 'php bin/restore-backup.php ' . basename($prefix . '-' . $timestamp . '.zip') . ' --yes',
        ];
        file_put_contents($tempDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $zipPath = $backupDir . '/' . $prefix . '-' . $timestamp . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::removeDirectory($tempDir);
            throw new RuntimeException('Yedek zip dosyasi olusturulamadi.');
        }

        self::addDirectoryToZip($zip, $tempDir, '');
        $zip->close();
        self::removeDirectory($tempDir);

        return [
            'zip_path' => $zipPath,
            'database' => $database,
            'table_count' => count($tables),
            'row_count' => array_sum($rowCounts),
            'size' => is_file($zipPath) ? filesize($zipPath) : 0,
        ];
    }

    public static function restoreArchive(string $zipPath): array
    {
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new RuntimeException('Yedek dosyasi okunamadi: ' . $zipPath);
        }

        $extractDir = ROOT_PATH . '/storage/backups/restore-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        self::ensureDirectory($extractDir);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Yedek zip dosyasi acilamadi.');
        }
        $zip->extractTo($extractDir);
        $zip->close();

        $sqlPath = $extractDir . '/backup.sql';
        if (!is_file($sqlPath)) {
            self::removeDirectory($extractDir);
            throw new RuntimeException('Yedek icinde backup.sql bulunamadi.');
        }

        $sql = (string) file_get_contents($sqlPath);
        $db = Database::connection();
        $statements = self::splitSql($sql);
        foreach ($statements as $statement) {
            $db->exec($statement);
        }

        self::removeDirectory($extractDir);

        return ['ok' => true, 'statements' => count($statements)];
    }

    public static function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return number_format($bytes, $unit === 'B' ? 0 : 2, ',', '.') . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return (string) $bytes . ' B';
    }

    private static function writeSqlDump(PDO $db, array $tables, string $path): void
    {
        $handle = fopen($path, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException('SQL yedek dosyasi olusturulamadi.');
        }

        fwrite($handle, "-- Yenileme Takibi veritabani yedegi\n");
        fwrite($handle, '-- Tarih: ' . date(DATE_ATOM) . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $quotedTable = self::quoteIdentifier($table);
            $create = $db->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_ASSOC);
            $createSql = (string) ($create['Create Table'] ?? array_values($create ?: [''])[1] ?? '');
            fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n");
            fwrite($handle, $createSql . ";\n\n");

            $columns = self::columns($db, $table);
            $columnSql = implode(', ', array_map([self::class, 'quoteIdentifier'], $columns));
            $stmt = $db->query('SELECT * FROM ' . $quotedTable);
            $batch = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = self::sqlValue($db, $row[$column] ?? null);
                }
                $batch[] = '(' . implode(', ', $values) . ')';

                if (count($batch) >= 100) {
                    fwrite($handle, 'INSERT INTO ' . $quotedTable . ' (' . $columnSql . ') VALUES' . "\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }

            if ($batch !== []) {
                fwrite($handle, 'INSERT INTO ' . $quotedTable . ' (' . $columnSql . ') VALUES' . "\n" . implode(",\n", $batch) . ";\n");
            }

            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    private static function writeCsv(PDO $db, string $table, string $path): int
    {
        $handle = fopen($path, 'wb');
        if (!is_resource($handle)) {
            throw new RuntimeException('CSV yedek dosyasi olusturulamadi: ' . $table);
        }

        fwrite($handle, "\xEF\xBB\xBF");
        $columns = self::columns($db, $table);
        fputcsv($handle, $columns, ',', '"', '\\');

        $count = 0;
        $stmt = $db->query('SELECT * FROM ' . self::quoteIdentifier($table));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($handle, array_map(static fn (string $column): mixed => $row[$column] ?? null, $columns), ',', '"', '\\');
            $count++;
        }

        fclose($handle);

        return $count;
    }

    private static function tables(PDO $db): array
    {
        $rows = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

        return array_values(array_map(static fn (array $row): string => (string) $row[0], $rows));
    }

    private static function columns(PDO $db, string $table): array
    {
        $rows = $db->query('SHOW COLUMNS FROM ' . self::quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['Field'], $rows);
    }

    private static function sqlValue(PDO $db, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return (string) $db->quote((string) $value);
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'table';
    }

    private static function timeReached(string $time): bool
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($time), $matches) !== 1) {
            $matches = [null, '02', '00'];
        }

        $target = ((int) $matches[1] * 60) + (int) $matches[2];
        $current = ((int) date('G') * 60) + (int) date('i');

        return $current >= $target;
    }

    private static function pruneOld(int $keepDays): void
    {
        $keepDays = max(1, $keepDays);
        $cutoff = time() - ($keepDays * 86400);
        foreach (glob(ROOT_PATH . '/storage/backups/database/*.zip') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }

    private static function addDirectoryToZip(ZipArchive $zip, string $dir, string $base): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            $local = ltrim($base . '/' . $item, '/');
            if (is_dir($path)) {
                $zip->addEmptyDir($local);
                self::addDirectoryToZip($zip, $path, $local);
            } else {
                $zip->addFile($path, $local);
            }
        }
    }

    private static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Dizin olusturulamadi: ' . $path);
        }
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;
            is_dir($child) ? self::removeDirectory($child) : @unlink($child);
        }

        @rmdir($path);
    }

    private static function splitSql(string $sql): array
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
}
