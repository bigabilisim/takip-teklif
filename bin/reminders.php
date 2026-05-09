<?php

declare(strict_types=1);

use App\Core\Mailer;
use App\Core\MailTemplate;
use App\Core\DatabaseBackup;
use App\Core\WebPush;
use App\Models\PaymentRequestRepository;
use App\Models\RenewalRepository;
use App\Models\SettingsRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

$settings = (new SettingsRepository())->all();
$force = in_array('--force', $argv ?? [], true);
$renewalWindowOpen = notification_window_is_open((string) ($settings['notifications.send_time'] ?? '09:00'));
$paymentRequestRepo = new PaymentRequestRepository();
$paymentReminderRows = $paymentRequestRepo->dueForDailyReminders($force);

if (!$force && !$renewalWindowOpen && $paymentReminderRows === []) {
    echo sprintf(
        "Bildirim saati bekleniyor. Ayar: %s, simdi: %s\n",
        notification_send_time((string) ($settings['notifications.send_time'] ?? '09:00')),
        date('H:i')
    );
    exit(0);
}

$repo = new RenewalRepository();
$rows = ($force || $renewalWindowOpen) ? $repo->dueForReminder() : [];
$sent = 0;
$failed = 0;
$supplierSent = 0;
$supplierFailed = 0;
$paymentReminderSent = 0;
$paymentReminderFailed = 0;
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

$priceRequestRows = ($force || $renewalWindowOpen) ? $repo->dueForSupplierPriceRequests() : [];
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
        ? sprintf('Yenileme tarihi %d gün önce geçti.', abs($days))
        : sprintf('Yenilemeye kalan süre: %d gün.', max(0, (int) $days));
    $anySent = false;

    foreach ($recipients as $recipient) {
        $message = implode("\n", [
            'Merhaba ' . ($recipient['name'] ?: ''),
            '',
            sprintf('%s için aşağıdaki lisans yenilemesi yaklaşıyor. Güncel yenileme fiyatını ve varsa yenileme koşullarını iletebilir misiniz?', $row['company_name']),
            '',
            'Ürün: ' . $row['title'],
            'Marka: ' . (string) ($row['brand'] ?: '-'),
            'Lisans / referans no: ' . (string) ($row['license_key'] ?: '-'),
            'Yenileme tarihi: ' . date('d.m.Y', strtotime($row['renewal_date'])),
            $statusLine,
            '',
            'Notlar:',
            (string) ($row['notes'] ?: '-'),
            '',
            'Teşekkürler,',
            'Yenileme Takip Sistemi',
        ]);
        $quoteRequest = $repo->createSupplierQuoteRequest((int) $row['id'], $recipient, $subject, $message, 'mail');
        $body = implode("\n", [
            $message,
            '',
            'Teklif formu: ' . (string) ($quoteRequest['url'] ?? ''),
            'Tedarik listesinden çık: ' . (string) ($quoteRequest['unsubscribe_url'] ?? ''),
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

foreach ($paymentReminderRows as $paymentRequest) {
    $requestId = (int) ($paymentRequest['id'] ?? 0);
    $recipients = payment_request_reminder_recipients($paymentRequest);
    $subject = mb_substr('Ödeme hatırlatması: ' . (string) ($paymentRequest['title'] ?? 'Manuel ödeme talebi'), 0, 240);
    $paymentUrl = manual_payment_reminder_url($paymentRequest);

    if ($requestId < 1 || $recipients === []) {
        if ($requestId > 0) {
            $paymentRequestRepo->logDelivery(
                $requestId,
                'reminder-mail',
                'missing-recipient',
                $subject,
                'Otomatik ödeme hatırlatması için geçerli e-posta alıcısı bulunamadı.',
                'failed',
                'missing-recipient'
            );
            $paymentRequestRepo->markReminderAttempted($requestId);
        }
        $paymentReminderFailed++;
        continue;
    }

    foreach ($recipients as $recipient) {
        $message = manual_payment_reminder_message($paymentRequest, $recipient);
        $body = manual_payment_reminder_body($paymentRequest, $message, $paymentUrl);
        $result = Mailer::sendWithResult((string) $recipient['email'], $subject, $body, true);
        $ok = !empty($result['ok']);
        $paymentRequestRepo->logDelivery(
            $requestId,
            'reminder-mail',
            (string) $recipient['email'],
            $subject,
            (string) ($result['body'] ?? $body),
            $ok ? 'sent' : 'failed',
            $ok ? null : (string) ($result['error'] ?? 'transport-failed')
        );

        $ok ? $paymentReminderSent++ : $paymentReminderFailed++;
    }

    $paymentRequestRepo->markReminderAttempted($requestId);
}

$pushNeeded = $rows !== [] || $priceRequestRows !== [] || $paymentReminderRows !== [];
if ($pushNeeded) {
    try {
        $pushBodyParts = [];
        if ($rows !== []) {
            $pushBodyParts[] = count($rows) . ' yenileme hatırlatması';
        }
        if ($priceRequestRows !== []) {
            $pushBodyParts[] = count($priceRequestRows) . ' tedarikçi fiyat talebi';
        }
        if ($paymentReminderRows !== []) {
            $pushBodyParts[] = count($paymentReminderRows) . ' ödeme talebi hatırlatması';
        }

        $pushResult = WebPush::sendToAll([
            'title' => 'Sistem bildirimi',
            'body' => implode(', ', $pushBodyParts) . ' için işlem var.',
            'url' => ($paymentReminderRows !== [] && $rows === [] && $priceRequestRows === []) ? '/payment-requests' : '/renewals',
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
    "Hatirlatma tamamlandi. Musteri gonderilen: %d, musteri basarisiz: %d, tedarikci fiyat talebi gonderilen: %d, tedarikci basarisiz: %d, odeme talebi hatirlatma gonderilen: %d, odeme talebi basarisiz: %d, web push gonderilen: %d, web push basarisiz: %d, yedek: %s\n",
    $sent,
    $failed,
    $supplierSent,
    $supplierFailed,
    $paymentReminderSent,
    $paymentReminderFailed,
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

function payment_request_reminder_recipients(array $row): array
{
    $recipients = [];
    $seen = [];
    $decoded = json_decode((string) ($row['recipients_json'] ?? ''), true);
    if (is_array($decoded)) {
        foreach ($decoded as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }
            $email = trim(mb_strtolower((string) ($recipient['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $recipients[] = [
                'name' => trim((string) ($recipient['name'] ?? '')),
                'email' => $email,
            ];
        }
    }

    $fallbackEmail = trim(mb_strtolower((string) ($row['customer_email'] ?? '')));
    if ($fallbackEmail !== '' && filter_var($fallbackEmail, FILTER_VALIDATE_EMAIL) && !isset($seen[$fallbackEmail])) {
        $recipients[] = [
            'name' => trim((string) ($row['customer_name'] ?? '')),
            'email' => $fallbackEmail,
        ];
    }

    return $recipients;
}

function manual_payment_reminder_url(array $row): string
{
    return url('/pay/' . rawurlencode((string) ($row['public_token'] ?? '')));
}

function manual_payment_reminder_number(array $row): string
{
    $id = max(0, (int) ($row['id'] ?? 0));
    $createdAt = !empty($row['created_at']) ? strtotime((string) $row['created_at']) : time();

    return 'MT-' . date('Y', $createdAt ?: time()) . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

function manual_payment_reminder_message(array $row, array $recipient): string
{
    $name = trim((string) ($recipient['name'] ?? ''));
    $customer = trim((string) ($row['customer_name'] ?? ''));
    $greetingName = $name !== '' ? $name : $customer;
    $greeting = $greetingName !== '' ? 'Merhaba ' . $greetingName . ',' : 'Merhaba,';
    $title = trim((string) ($row['title'] ?? 'Ödeme talebi'));
    $description = trim((string) ($row['description'] ?? ''));
    $total = money_format_local($row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'));
    $dueDate = manual_payment_reminder_due_date($row);

    $lines = [
        $greeting,
        '',
        $title . ' için oluşturulan ödeme talebiniz halen beklemede görünüyor.',
    ];

    if ($description !== '') {
        $lines[] = 'Açıklama: ' . $description;
    }

    if ($dueDate !== '') {
        $lines[] = 'Ödeme günü: ' . $dueDate;
    }

    $lines[] = 'Toplam tutar: ' . $total;
    $lines[] = '';
    $lines[] = 'Aşağıdaki güvenli bağlantıdan ödemenizi tamamlayabilirsiniz.';

    return implode("\n", $lines);
}

function manual_payment_reminder_body(array $row, string $message, string $paymentUrl): string
{
    $total = money_format_local($row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'));
    $dueDate = manual_payment_reminder_due_date($row);
    $dueDateRow = $dueDate !== ''
        ? '<tr><td style="padding:12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;">Ödeme günü</td><td style="padding:12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:800;">' . h($dueDate) . '</td></tr>'
        : '';

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Ödeme hatırlatması</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">' . h((string) ($row['title'] ?? 'Manuel ödeme talebi')) . '</h1>'
        . '<div style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . nl2br(h($message), false) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="padding:12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;">Talep no</td><td style="padding:12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:800;">' . h(manual_payment_reminder_number($row)) . '</td></tr>'
        . '<tr><td style="padding:12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;">Müşteri</td><td style="padding:12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:800;">' . h((string) (($row['customer_name'] ?? '') ?: '-')) . '</td></tr>'
        . $dueDateRow
        . '<tr><td style="padding:12px;color:#607069;font-weight:700;">Toplam</td><td style="padding:12px;color:#17201c;font-weight:800;">' . h($total) . '</td></tr>'
        . '</table>'
        . '<p style="margin:20px 0 0;"><a href="' . h($paymentUrl) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:14px 20px;font-weight:700;">Ödeme ekranını aç</a></p>'
        . '<p style="margin:18px 0 0;color:#61726c;font-size:13px;line-height:1.5;">Ödeme tamamlandığında bu hatırlatma otomatik olarak durur.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function manual_payment_reminder_due_date(array $row): string
{
    $date = trim((string) ($row['payment_due_date'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return '';
    }

    return date('d.m.Y', strtotime($date));
}
