<?php

declare(strict_types=1);

use App\Core\Mailer;
use App\Core\MailTemplate;
use App\Core\DatabaseBackup;
use App\Core\WebPush;
use App\Models\RenewalRepository;
use App\Models\SettingsRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

$settings = (new SettingsRepository())->all();
$force = in_array('--force', $argv ?? [], true);

if (!$force && !notification_window_is_open((string) ($settings['notifications.send_time'] ?? '09:00'))) {
    echo sprintf(
        "Bildirim saati bekleniyor. Ayar: %s, simdi: %s\n",
        notification_send_time((string) ($settings['notifications.send_time'] ?? '09:00')),
        date('H:i')
    );
    exit(0);
}

$repo = new RenewalRepository();
$rows = $repo->dueForReminder();
$sent = 0;
$failed = 0;
$supplierSent = 0;
$supplierFailed = 0;
$pushSent = 0;
$pushFailed = 0;
$backupMessage = 'yedek kontrol edilmedi';

foreach ($rows as $row) {
    $recipients = $repo->notificationRecipients((int) $row['customer_id']);
    if ($recipients === []) {
        $failed++;
        $repo->logMail(
            (int) $row['id'],
            'missing-approved-recipient',
            'Eksik bilgilendirme yetkilisi',
            'Bilgilendirme kutusu onayli ve e-posta adresi dolu yetkili bulunamadi.',
            'failed',
            'missing-approved-recipient'
        );
        continue;
    }

    $days = days_until($row['renewal_date']);
    $recordType = $row['kind'] === 'product' ? 'lisans' : 'üyelik / hizmet';

    if ($days !== null && $days < 0) {
        $subject = sprintf('%s süresi doldu: %s', ucfirst($recordType), $row['title']);
        $statusLine = sprintf('Bu %s %d gün önce sona erdi.', $recordType, abs($days));
    } elseif ($days === 0) {
        $subject = sprintf('Bugün sona eriyor: %s', $row['title']);
        $statusLine = sprintf('Bu %s bugün sona eriyor.', $recordType);
    } else {
        $subject = sprintf('Yenileme hatırlatması: %s', $row['title']);
        $statusLine = sprintf('Kalan süre: %d gün.', max(0, (int) $days));
    }

    foreach ($recipients as $recipient) {
        $delivery = $repo->createNotificationDelivery((int) $row['id'], $recipient);
        $recipientForMail = array_merge($recipient, ['read_ack_url' => $delivery['read_url']]);
        $mail = MailTemplate::renderRenewal($settings, $row, $recipientForMail, $statusLine, $days);
        $body = (string) $mail['body'];
        $ok = Mailer::send($recipient['email'], $subject, $body, (bool) $mail['is_html'], $mail['inline_attachments'] ?? []);
        $mailLogId = $repo->logMail((int) $row['id'], $recipient['email'], $subject, $body, $ok ? 'sent' : 'failed', $ok ? null : 'transport-failed');
        $repo->updateNotificationDeliveryStatus((int) $delivery['id'], $mailLogId, $ok ? 'sent' : 'failed', $ok ? null : 'transport-failed');

        $ok ? $sent++ : $failed++;
    }
}

$priceRequestRows = $repo->dueForSupplierPriceRequests();
foreach ($priceRequestRows as $row) {
    $recipients = $repo->supplierNotificationRecipients(
        empty($row['supplier_id']) ? null : (int) $row['supplier_id'],
        empty($row['supplier_group_id']) ? null : (int) $row['supplier_group_id']
    );

    if ($recipients === []) {
        $supplierFailed++;
        $repo->logMail(
            (int) $row['id'],
            'missing-approved-supplier-recipient',
            'Eksik tedarikci fiyat talebi yetkilisi',
            'Tedarikci fiyat talebi icin bilgilendirme kutusu onayli ve e-posta adresi dolu yetkili bulunamadi.',
            'failed',
            'missing-approved-supplier-recipient',
            false
        );
        continue;
    }

    $days = days_until($row['renewal_date']);
    $subject = sprintf('Lisans fiyat talebi: %s', $row['title']);
    $statusLine = $days !== null && $days < 0
        ? sprintf('Yenileme tarihi %d gun once gecti.', abs($days))
        : sprintf('Yenilemeye kalan sure: %d gun.', max(0, (int) $days));
    $anySent = false;

    foreach ($recipients as $recipient) {
        $body = implode("\n", [
            'Merhaba ' . ($recipient['name'] ?: ''),
            '',
            sprintf('%s icin asagidaki lisans yenilemesi yaklasiyor. Guncel yenileme fiyatini ve varsa yenileme kosullarini iletebilir misiniz?', $row['company_name']),
            '',
            'Urun: ' . $row['title'],
            'Marka: ' . (string) ($row['brand'] ?: '-'),
            'Lisans / referans no: ' . (string) ($row['license_key'] ?: '-'),
            'Yenileme tarihi: ' . date('d.m.Y', strtotime($row['renewal_date'])),
            $statusLine,
            '',
            'Notlar:',
            (string) ($row['notes'] ?: '-'),
            '',
            'Tesekkurler,',
            'Yenileme Takip Sistemi',
        ]);

        $ok = Mailer::send($recipient['email'], $subject, $body);
        $repo->logMail((int) $row['id'], $recipient['email'], $subject, $body, $ok ? 'sent' : 'failed', $ok ? null : 'transport-failed', false);

        if ($ok) {
            $supplierSent++;
            $anySent = true;
        } else {
            $supplierFailed++;
        }
    }

    if ($anySent) {
        $repo->markSupplierPriceRequested((int) $row['id']);
    }
}

$pushNeeded = $rows !== [] || $priceRequestRows !== [];
if ($pushNeeded) {
    try {
        $pushBodyParts = [];
        if ($rows !== []) {
            $pushBodyParts[] = count($rows) . ' yenileme hatırlatması';
        }
        if ($priceRequestRows !== []) {
            $pushBodyParts[] = count($priceRequestRows) . ' tedarikçi fiyat talebi';
        }

        $pushResult = WebPush::sendToAll([
            'title' => 'Yenileme bildirimi',
            'body' => implode(', ', $pushBodyParts) . ' için işlem var.',
            'url' => '/renewals',
        ]);
        $pushSent = (int) $pushResult['sent'];
        $pushFailed = (int) $pushResult['failed'];
    } catch (Throwable $e) {
        $pushFailed++;
    }
}

try {
    $backupResult = DatabaseBackup::runDaily(new SettingsRepository(), false);
    $backupMessage = !empty($backupResult['skipped'])
        ? (string) ($backupResult['message'] ?? 'yedek atlandi')
        : 'yedek alindi';
} catch (Throwable $e) {
    $backupMessage = 'yedek hatasi: ' . $e->getMessage();
}

echo sprintf(
    "Hatirlatma tamamlandi. Musteri gonderilen: %d, musteri basarisiz: %d, tedarikci fiyat talebi gonderilen: %d, tedarikci basarisiz: %d, web push gonderilen: %d, web push basarisiz: %d, yedek: %s\n",
    $sent,
    $failed,
    $supplierSent,
    $supplierFailed,
    $pushSent,
    $pushFailed,
    $backupMessage
);

function notification_send_time(string $time): string
{
    $time = trim($time);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches) !== 1) {
        return '09:00';
    }

    return $matches[1] . ':' . $matches[2];
}

function notification_window_is_open(string $time): bool
{
    [$hour, $minute] = array_map('intval', explode(':', notification_send_time($time)));
    $targetMinute = ($hour * 60) + $minute;
    $currentMinute = ((int) date('G') * 60) + (int) date('i');

    return $currentMinute >= $targetMinute && $currentMinute < $targetMinute + 60;
}
