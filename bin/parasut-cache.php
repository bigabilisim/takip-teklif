<?php

declare(strict_types=1);

use App\Core\ParasutClient;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $result = (new ParasutClient())->refreshContactCache();
} catch (Throwable $e) {
    fwrite(STDERR, 'Parasut cari cache yenilenemedi: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo sprintf(
    "Parasut cari cache yenilendi. Firma: %s, cari sayisi: %d, tarih: %s\n",
    $result['company_id'],
    $result['count'],
    date('Y-m-d H:i:s', $result['generated_at'])
);
