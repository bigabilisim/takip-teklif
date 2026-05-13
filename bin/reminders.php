<?php

declare(strict_types=1);

use App\Core\Mailer;
use App\Core\MailTemplate;
use App\Core\PaymentLink;
use App\Core\DatabaseBackup;
use App\Core\InternalNotifier;
use App\Core\WebPush;
use App\Models\CustomerInfoRequestRepository;
use App\Models\PaymentRequestRepository;
use App\Models\RenewalRepository;
use App\Models\SettingsRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

$settings = (new SettingsRepository())->all();
$force = in_array('--force', $argv ?? [], true);
$renewalWindowOpen = notification_window_is_open((string) ($settings['notifications.send_time'] ?? '09:00'));
$repo = new RenewalRepository();
$paymentRequestRepo = new PaymentRequestRepository();
$paymentReminderRows = $paymentRequestRepo->dueForDailyReminders($force);
$customerInfoRequestRepo = new CustomerInfoRequestRepository();
$customerInfoReminderRows = $customerInfoRequestRepo->dueForReminders(3, 50, $force);
$renewalPaymentNotificationRows = $repo->paidCardPaymentsPendingInternalNotifications(50);
$manualPaymentNotificationRows = $paymentRequestRepo->paidCardPaymentsPendingInternalNotifications(50);

if (!$force && !$renewalWindowOpen && $paymentReminderRows === [] && $customerInfoReminderRows === [] && $renewalPaymentNotificationRows === [] && $manualPaymentNotificationRows === []) {
    echo sprintf(
        "Bildirim saati bekleniyor. Ayar: %s, simdi: %s\n",
        notification_send_time((string) ($settings['notifications.send_time'] ?? '09:00')),
        date('H:i')
    );
    exit(0);
}

$rows = ($force || $renewalWindowOpen) ? $repo->dueForReminder() : [];
$sent = 0;
$failed = 0;
$supplierSent = 0;
$supplierFailed = 0;
$paymentReminderSent = 0;
$paymentReminderFailed = 0;
$customerInfoReminderSent = 0;
$customerInfoReminderFailed = 0;
$paymentNotificationPushSent = 0;
$paymentNotificationPushFailed = 0;
$paymentNotificationMailSent = 0;
$paymentNotificationMailFailed = 0;
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
        $recipientForMail = array_merge($recipient, [
            'read_ack_url' => $delivery['read_url'],
            'summary_url' => renewal_summary_url_for_reminder((int) $row['id'], (string) ($delivery['token'] ?? ''), (string) ($recipient['email'] ?? '')),
        ]);
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

foreach ($customerInfoReminderRows as $request) {
    $requestId = (int) ($request['id'] ?? 0);
    $email = trim(mb_strtolower((string) ($request['recipient_email'] ?? '')));
    $publicToken = trim((string) ($request['public_token'] ?? ''));
    $subject = 'Cari bilgi hatırlatması';

    if ($requestId < 1 || $publicToken === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if ($requestId > 0) {
            customer_info_reminder_log_mail(
                $email !== '' ? $email : 'missing-recipient',
                $subject,
                'Otomatik cari bilgi hatırlatması için geçerli e-posta alıcısı veya bağlantı bulunamadı.',
                'failed',
                'missing-recipient'
            );
            $customerInfoRequestRepo->markReminderAttempted($requestId);
        }
        $customerInfoReminderFailed++;
        continue;
    }

    $link = url('/cari-bilgi/' . rawurlencode($publicToken));
    $stopReminderLink = url('/cari-bilgi/' . rawurlencode($publicToken) . '/hatirlatma-kapat');
    $mail = MailTemplate::renderCustomerInfoRequest($settings, $request, $link, $stopReminderLink, true);
    $result = Mailer::sendWithResult(
        $email,
        $subject,
        (string) $mail['body'],
        (bool) $mail['is_html'],
        $mail['inline_attachments'] ?? []
    );
    $ok = !empty($result['ok']);
    customer_info_reminder_log_mail(
        $email,
        $subject,
        (string) ($result['body'] ?? $mail['body']),
        $ok ? 'sent' : 'failed',
        $ok ? null : (string) ($result['error'] ?? 'transport-failed')
    );
    $customerInfoRequestRepo->markReminderAttempted($requestId);

    $ok ? $customerInfoReminderSent++ : $customerInfoReminderFailed++;
}

foreach ($renewalPaymentNotificationRows as $payment) {
    $result = send_pending_payment_internal_notification($payment, 'renewal');
    $repo->markPaymentInternalNotification(
        (int) ($payment['id'] ?? 0),
        (int) ($result['push_sent'] ?? 0) > 0,
        !empty($result['mail_ok']),
        payment_internal_notification_error_text($result)
    );
    (int) ($result['push_sent'] ?? 0) > 0 ? $paymentNotificationPushSent++ : $paymentNotificationPushFailed++;
    !empty($result['mail_ok']) ? $paymentNotificationMailSent++ : $paymentNotificationMailFailed++;
}

foreach ($manualPaymentNotificationRows as $payment) {
    $result = send_pending_payment_internal_notification($payment, 'manual');
    $paymentRequestRepo->markPaymentInternalNotification(
        (int) ($payment['id'] ?? 0),
        (int) ($result['push_sent'] ?? 0) > 0,
        !empty($result['mail_ok']),
        payment_internal_notification_error_text($result)
    );
    (int) ($result['push_sent'] ?? 0) > 0 ? $paymentNotificationPushSent++ : $paymentNotificationPushFailed++;
    !empty($result['mail_ok']) ? $paymentNotificationMailSent++ : $paymentNotificationMailFailed++;
}

$pushNeeded = $rows !== [] || $priceRequestRows !== [] || $paymentReminderRows !== [] || $customerInfoReminderRows !== [];
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
        if ($customerInfoReminderRows !== []) {
            $pushBodyParts[] = count($customerInfoReminderRows) . ' cari bilgi hatırlatması';
        }

        $pushResult = WebPush::sendToAll([
            'title' => 'Sistem bildirimi',
            'body' => implode(', ', $pushBodyParts) . ' için işlem var.',
            'url' => ($paymentReminderRows !== [] && $rows === [] && $priceRequestRows === [] && $customerInfoReminderRows === []) ? '/payment-requests' : '/',
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
    "Hatirlatma tamamlandi. Musteri gonderilen: %d, musteri basarisiz: %d, tedarikci fiyat talebi gonderilen: %d, tedarikci basarisiz: %d, odeme talebi hatirlatma gonderilen: %d, odeme talebi basarisiz: %d, cari bilgi hatirlatma gonderilen: %d, cari bilgi hatirlatma basarisiz: %d, odeme ic bildirim push gonderilen: %d, odeme ic bildirim push basarisiz: %d, odeme ic bildirim mail gonderilen: %d, odeme ic bildirim mail basarisiz: %d, web push gonderilen: %d, web push basarisiz: %d, yedek: %s\n",
    $sent,
    $failed,
    $supplierSent,
    $supplierFailed,
    $paymentReminderSent,
    $paymentReminderFailed,
    $customerInfoReminderSent,
    $customerInfoReminderFailed,
    $paymentNotificationPushSent,
    $paymentNotificationPushFailed,
    $paymentNotificationMailSent,
    $paymentNotificationMailFailed,
    $pushSent,
    $pushFailed,
    $backupMessage
);

function send_pending_payment_internal_notification(array $payment, string $source): array
{
    $context = payment_internal_notification_context($payment, $source);
    $result = [
        'push_sent' => 0,
        'push_failed' => 0,
        'push_total' => 0,
        'push_error' => '',
        'mail_ok' => false,
        'mail_error' => '',
    ];

    if (empty($payment['internal_push_sent_at'])) {
        $pushResult = InternalNotifier::push(
            'Ödeme geldi',
            $context['customer'] . ' · ' . $context['amount'],
            $context['url'],
            'payment-received-' . $context['source'] . '-' . $context['record_id'] . '-' . hash('sha1', (string) ($context['payment_id'] ?? '') . '|' . (string) ($payment['id'] ?? ''))
        );
        $result['push_sent'] = (int) ($pushResult['sent'] ?? 0);
        $result['push_failed'] = (int) ($pushResult['failed'] ?? 0);
        $result['push_total'] = (int) ($pushResult['total'] ?? 0);
        $result['push_error'] = (string) ($pushResult['last_error'] ?? '');
    } else {
        $result['push_sent'] = 1;
    }

    if (empty($payment['internal_mail_sent_at'])) {
        $mailResult = InternalNotifier::mail(
            'Ödeme geldi: ' . $context['title'],
            payment_internal_notification_mail_body($context)
        );
        $result['mail_ok'] = !empty($mailResult['ok']);
        $result['mail_error'] = (string) ($mailResult['error'] ?? '');
    } else {
        $result['mail_ok'] = true;
    }

    return $result;
}

function payment_internal_notification_context(array $payment, string $source): array
{
    $isManual = $source === 'manual';
    $recordId = (int) ($isManual ? ($payment['request_id'] ?? 0) : ($payment['renewal_id'] ?? 0));
    $title = trim((string) ($payment['title'] ?? ($isManual ? 'Manuel ödeme talebi' : 'Yenileme')));
    $customer = trim((string) (
        $isManual
            ? (($payment['customer_name'] ?? '') ?: ($payment['customer_email'] ?? ''))
            : ($payment['company_name'] ?? '')
    ));
    $currency = strtoupper(trim((string) ($payment['currency'] ?? 'TRY')));
    if (!in_array($currency, ['TRY', 'USD', 'EUR'], true)) {
        $currency = 'TRY';
    }

    return [
        'source' => $isManual ? 'manual' : 'renewal',
        'source_label' => $isManual ? 'Manuel ödeme talebi' : 'Yenileme kaydı',
        'record_id' => $recordId,
        'title' => $title !== '' ? $title : ($isManual ? 'Manuel ödeme talebi' : 'Yenileme'),
        'customer' => $customer !== '' ? $customer : 'Müşteri bilgisi yok',
        'amount' => money_format_local($payment['amount'] ?? null, $currency),
        'payment_id' => trim((string) ($payment['payment_id'] ?? '')) ?: '-',
        'conversation_id' => trim((string) ($payment['conversation_id'] ?? '')) ?: '-',
        'url' => $isManual ? '/payment-requests?created=' . $recordId : '/renewals/' . $recordId . '/edit#card-payment',
    ];
}

function payment_internal_notification_mail_body(array $context): string
{
    $rows = [
        'Kaynak' => $context['source_label'],
        'Müşteri' => $context['customer'],
        'Kayıt' => $context['title'],
        'Tutar' => $context['amount'],
        'iyzico ödeme no' => $context['payment_id'],
        'Conversation ID' => $context['conversation_id'],
    ];

    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;">' . h($label) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:800;">' . h((string) $value) . '</td>'
            . '</tr>';
    }

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;color:#17201c;font-family:Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="680" cellpadding="0" cellspacing="0" style="max-width:680px;width:100%;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="padding:28px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:13px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;">Ödeme bildirimi</p>'
        . '<h1 style="margin:0 0 12px;font-size:30px;line-height:1.1;color:#17201c;">Ödeme geldi.</h1>'
        . '<p style="margin:0 0 20px;color:#607069;font-size:16px;line-height:1.5;">Sistemde başarılı kredi kartı ödemesi kaydedildi. Bu bildirim, önceki denemede eksik kaldığı için otomatik kontrol tarafından gönderildi.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 22px;">' . $htmlRows . '</table>'
        . '<a href="' . h(url((string) $context['url'])) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:8px;">Panelde görüntüle</a>'
        . '</td></tr></table>'
        . '</td></tr></table></body></html>';
}

function payment_internal_notification_error_text(array $result): string
{
    $errors = [];
    if ((int) ($result['push_sent'] ?? 0) < 1) {
        $errors[] = 'push: ' . ((string) ($result['push_error'] ?? '') ?: (((int) ($result['push_total'] ?? 0) < 1) ? 'aktif abonelik yok' : 'gönderilemedi'));
    }
    if (empty($result['mail_ok'])) {
        $errors[] = 'mail: ' . ((string) ($result['mail_error'] ?? '') ?: 'gönderilemedi');
    }

    return mb_substr(implode(' | ', $errors), 0, 1000);
}

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

function renewal_summary_url_for_reminder(int $renewalId, string $trackingToken = '', string $recipientEmail = ''): string
{
    $signedPaymentUrl = PaymentLink::urlForRenewal($renewalId, 60, $recipientEmail);
    $query = parse_url($signedPaymentUrl, PHP_URL_QUERY);
    $url = url('/renewals/' . $renewalId . '/summary') . ($query ? '?' . $query : '');
    if (preg_match('/^[a-f0-9]{64}$/i', $trackingToken) !== 1) {
        return $url;
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . 'track=' . rawurlencode($trackingToken);
}

function customer_info_reminder_log_mail(string $email, string $subject, string $body, string $status, ?string $error = null): void
{
    try {
        (new RenewalRepository())->logMail(
            null,
            $email,
            $subject,
            $body,
            $status === 'sent' ? 'sent' : 'failed',
            $error,
            false
        );
    } catch (Throwable $e) {
        error_log('Cari bilgi hatırlatma mail logu yazılamadı: ' . $e->getMessage());
    }
}
