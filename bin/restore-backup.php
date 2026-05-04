<?php

declare(strict_types=1);

use App\Core\DatabaseBackup;

require dirname(__DIR__) . '/app/bootstrap.php';

$args = $argv ?? [];
$zipPath = $args[1] ?? '';
$confirmed = in_array('--yes', $args, true);

if ($zipPath === '') {
    echo "Kullanim: php bin/restore-backup.php /tam/yol/yedek.zip --yes\n";
    exit(1);
}

if (!str_starts_with($zipPath, '/')) {
    $zipPath = ROOT_PATH . '/storage/backups/database/' . $zipPath;
}

if (!$confirmed) {
    echo "Dikkat: Bu islem mevcut veritabanini yedekteki backup.sql ile geri yukler.\n";
    echo "Devam etmek icin komutu --yes parametresiyle calistirin.\n";
    exit(1);
}

echo "Geri yukleme oncesi guvenlik yedegi aliniyor...\n";
$preRestore = DatabaseBackup::createArchive('pre-restore');
echo "Guvenlik yedegi: " . $preRestore['zip_path'] . "\n";

$result = DatabaseBackup::restoreArchive($zipPath);
echo "Geri yukleme tamamlandi. Calisan SQL komutu: " . $result['statements'] . "\n";
