<?php

declare(strict_types=1);

use App\Core\DatabaseBackup;
use App\Models\SettingsRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

$force = in_array('--force', $argv ?? [], true);
$result = DatabaseBackup::runDaily(new SettingsRepository(), $force);

if (!empty($result['skipped'])) {
    echo $result['message'] . "\n";
    exit(0);
}

$archive = $result['archive'] ?? [];
$mail = $result['mail'] ?? [];
echo sprintf(
    "Yedek tamamlandi. Dosya: %s, boyut: %s, mail: %s\n",
    (string) ($archive['zip_path'] ?? '-'),
    DatabaseBackup::humanSize((int) ($archive['size'] ?? 0)),
    !empty($mail['ok']) ? 'gonderildi' : ('gonderilemedi - ' . (string) ($mail['error'] ?? 'bilinmiyor'))
);
