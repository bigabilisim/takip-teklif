<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Core\DatabaseBackup;
use App\Core\ExchangeRates;
use App\Core\IyzicoClient;
use App\Core\Mailer;
use App\Core\MailTemplate;
use App\Core\ParasutClient;
use App\Core\PaymentLink;
use App\Core\TaxCertificateAnalyzer;
use App\Core\WebPush;
use App\Models\CustomerInfoRequestRepository;
use App\Models\RenewalRepository;
use App\Models\SettingsRepository;
use App\Models\UserRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

$path = route_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/login') {
    handle_login($method);
    exit;
}

if ($path === '/forgot-password') {
    handle_forgot_password($method);
    exit;
}

if (preg_match('#^/cari-bilgi/([a-f0-9]{64})$#', $path, $matches)) {
    handle_customer_info_public($method, $matches[1]);
    exit;
}

if (preg_match('#^/tedarikci-teklif/([a-f0-9]{64})$#', $path, $matches)) {
    handle_supplier_quote_public($method, $matches[1]);
    exit;
}

if (preg_match('#^/musteri-teklif/([a-f0-9]{64})$#', $path, $matches)) {
    handle_customer_offer_public($method, $matches[1]);
    exit;
}

if (preg_match('#^/renewals/(\d+)/payment$#', $path, $matches)) {
    handle_public_renewal_payment($method, (int) $matches[1]);
    exit;
}

if (preg_match('#^/renewals/(\d+)/summary$#', $path, $matches)) {
    handle_public_renewal_summary((int) $matches[1]);
    exit;
}

if (preg_match('#^/renewals/(\d+)/read$#', $path, $matches)) {
    handle_public_renewal_read((int) $matches[1]);
    exit;
}

if (preg_match('#^/renewals/(\d+)/payment/thanks$#', $path, $matches)) {
    handle_public_renewal_payment_thanks((int) $matches[1]);
    exit;
}

if ($path === '/payments/iyzico/callback') {
    handle_iyzico_callback($method);
    exit;
}

if ($path === '/logout' && $method === 'POST') {
    verify_csrf();
    Auth::logout();
    flash('success', 'Oturum kapatildi.');
    redirect('/login');
}

Auth::requireLogin();

$repo = new RenewalRepository();
maybe_run_scheduled_backup($path, $method);

try {
    if ($path === '/') {
        if (!Auth::can('dashboard.view')) {
            $firstPath = first_allowed_path();
            if ($firstPath !== null && $firstPath !== '/') {
                redirect($firstPath);
            }
        }
        require_permission('dashboard.view');
        render_dashboard($repo);
    } elseif ($path === '/api/parasut/contacts' && $method === 'GET') {
        handle_parasut_contacts_api();
    } elseif ($path === '/api/push/public-key' && $method === 'GET') {
        handle_push_public_key();
    } elseif ($path === '/api/push/subscribe' && $method === 'POST') {
        handle_push_subscribe();
    } elseif ($path === '/api/push/unsubscribe' && $method === 'POST') {
        handle_push_unsubscribe();
    } elseif ($path === '/api/push/test' && $method === 'POST') {
        handle_push_test();
    } elseif ($path === '/renewals') {
        require_permission('renewals.view');
        if (Auth::can('dashboard.view')) {
            redirect('/');
        }
        render_renewals($repo);
    } elseif ($path === '/renewals/create') {
        require_permission('renewals.manage');
        handle_renewal_form($repo, $method);
    } elseif (preg_match('#^/renewals/(\d+)/edit$#', $path, $matches)) {
        require_permission('renewals.manage');
        handle_renewal_form($repo, $method, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/delete$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.delete');
        verify_csrf();
        $repo->delete((int) $matches[1]);
        flash('success', 'Yenileme kaydi silindi.');
        redirect('/renewals');
    } elseif (preg_match('#^/renewals/(\d+)/renew$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        verify_csrf();
        $nextDate = (string) ($_POST['next_date'] ?? '');
        $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            flash('error', 'Yeni dönem fatura numarası zorunlu.');
            redirect('/renewals/' . (int) $matches[1] . '/edit');
        }
        $repo->markRenewed((int) $matches[1], $invoiceNumber, $nextDate !== '' ? $nextDate : null);
        flash('success', 'Kayit yeni yenileme tarihiyle guncellendi.');
        redirect('/renewals');
    } elseif (preg_match('#^/renewals/(\d+)/payments/iyzico/create$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_iyzico_payment_create($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/notify$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_manual_renewal_notification($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/mail$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_manual_renewal_mail($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/supplier-price-mail$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_supplier_price_request_mail($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/supplier-price-link$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_supplier_price_request_link($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/supplier-price-whatsapp$#', $path, $matches) && $method === 'GET') {
        require_permission('renewals.manage');
        handle_supplier_price_request_whatsapp($repo, (int) $matches[1]);
    } elseif (preg_match('#^/supplier-quotes/(\d+)/select$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_supplier_quote_select($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/customer-offer/send$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_customer_offer_send($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/decision$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_renewal_decision($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/ack$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.view');
        verify_csrf();
        $repo->acknowledgeReminder((int) $matches[1]);
        flash('success', 'Bugunku bildirim okundu olarak isaretlendi.');
        redirect(safe_return_path($_POST['return_to'] ?? '/renewals'));
    } elseif ($path === '/customer-info/request' && $method === 'POST') {
        require_permission('customers.manage');
        handle_customer_info_request_submit();
    } elseif ($path === '/customers') {
        require_permission('customers.view');
        handle_customers($repo, $method);
    } elseif ($path === '/customers/deleted') {
        require_permission('customers.delete');
        render_deleted_customers($repo);
    } elseif (preg_match('#^/customers/(\d+)/edit$#', $path, $matches)) {
        require_permission('customers.manage');
        handle_customer_edit($repo, $method, (int) $matches[1]);
    } elseif (preg_match('#^/customers/(\d+)/delete$#', $path, $matches) && $method === 'POST') {
        require_permission('customers.delete');
        verify_csrf();
        $repo->deleteCustomer((int) $matches[1]);
        flash('success', 'Musteri silinenler havuzuna tasindi.');
        redirect('/customers');
    } elseif (preg_match('#^/customers/(\d+)/restore$#', $path, $matches) && $method === 'POST') {
        require_permission('customers.delete');
        verify_csrf();
        $repo->restoreCustomer((int) $matches[1]);
        flash('success', 'Musteri havuzdan geri alindi.');
        redirect('/customers/deleted');
    } elseif ($path === '/suppliers') {
        require_permission('suppliers.view');
        handle_suppliers($repo, $method);
    } elseif ($path === '/suppliers/deleted') {
        require_permission('suppliers.delete');
        render_deleted_suppliers($repo);
    } elseif (preg_match('#^/suppliers/(\d+)/edit$#', $path, $matches)) {
        require_permission('suppliers.manage');
        handle_supplier_edit($repo, $method, (int) $matches[1]);
    } elseif (preg_match('#^/suppliers/(\d+)/delete$#', $path, $matches) && $method === 'POST') {
        require_permission('suppliers.delete');
        verify_csrf();
        $repo->deleteSupplier((int) $matches[1]);
        flash('success', 'Tedarikci silinenler havuzuna tasindi.');
        redirect('/suppliers');
    } elseif (preg_match('#^/suppliers/(\d+)/restore$#', $path, $matches) && $method === 'POST') {
        require_permission('suppliers.delete');
        verify_csrf();
        $repo->restoreSupplier((int) $matches[1]);
        flash('success', 'Tedarikci havuzdan geri alindi.');
        redirect('/suppliers/deleted');
    } elseif ($path === '/settings/users/deleted') {
        require_permission('users.manage');
        render_deleted_users();
    } elseif (preg_match('#^/settings/users/(\d+)/delete$#', $path, $matches) && $method === 'POST') {
        require_permission('users.manage');
        handle_user_delete((int) $matches[1]);
    } elseif (preg_match('#^/settings/users/(\d+)/restore$#', $path, $matches) && $method === 'POST') {
        require_permission('users.manage');
        handle_user_restore((int) $matches[1]);
    } elseif ($path === '/settings/users') {
        require_permission('users.manage');
        handle_users($method);
    } elseif (preg_match('#^/settings/users/(\d+)/edit$#', $path, $matches)) {
        require_permission('users.manage');
        handle_users($method, (int) $matches[1]);
    } elseif ($path === '/logs') {
        require_permission('logs.view');
        render_logs();
    } elseif ($path === '/settings/definitions') {
        require_permission('definitions.manage');
        handle_definitions($repo, $method);
    } elseif ($path === '/settings/grapesjs') {
        require_permission('settings.manage');
        handle_grapesjs_template($method);
    } elseif ($path === '/settings') {
        require_permission('settings.manage');
        handle_settings($method);
    } elseif ($path === '/settings/microsoft/start') {
        require_permission('settings.manage');
        handle_microsoft_start();
    } elseif ($path === '/settings/microsoft/callback') {
        require_permission('settings.manage');
        handle_microsoft_callback();
    } elseif ($path === '/integrations/parasut') {
        require_permission('settings.manage');
        handle_parasut_integration($method);
    } else {
        http_response_code(404);
        render_layout('Sayfa bulunamadi', static function (): void {
            echo '<div class="empty">Aradiginiz sayfa bulunamadi.</div>';
        });
    }
} catch (Throwable $e) {
    http_response_code(500);
    render_error($e);
}

function require_permission(string $permission): void
{
    if (Auth::can($permission)) {
        return;
    }

    http_response_code(403);
    render_layout('Yetki yok', static function (): void {
        echo '<div class="alert error">Bu islem icin yetkiniz yok.</div>';
    });
    exit;
}

function require_json_permission(string $permission): void
{
    if (Auth::can($permission)) {
        return;
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'message' => 'Bu islem icin yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function handle_parasut_contacts_api(): void
{
    $type = (string) ($_GET['type'] ?? 'customer');
    if ($type === 'supplier') {
        require_json_permission('suppliers.manage');
    } elseif ($type === 'all') {
        require_json_any_permission(['customers.manage', 'suppliers.manage']);
    } else {
        require_json_permission('customers.manage');
    }

    header('Content-Type: application/json; charset=UTF-8');

    try {
        $client = new ParasutClient();
        echo json_encode([
            'ok' => true,
            'data' => $client->searchContacts(
                (string) ($_GET['q'] ?? ''),
                10,
                $type
            ),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

function require_json_any_permission(array $permissions): void
{
    if (Auth::canAny($permissions)) {
        return;
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'message' => 'Bu islem icin yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function wants_json_response(): bool
{
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_is_valid(): bool
{
    $posted = $_POST['_token'] ?? '';
    $session = $_SESSION['_csrf_token'] ?? '';

    return is_string($posted) && is_string($session) && hash_equals($session, $posted);
}

function handle_iyzico_payment_create(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    $renewal = $repo->find($renewalId);
    if (!$renewal) {
        flash('error', 'Yenileme kaydi bulunamadi.');
        redirect('/renewals');
    }

    $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
    $currency = normalize_allowed_currency($_POST['currency'] ?? 'TRY');
    if ($amount <= 0) {
        flash('error', 'iyzico odemesi icin tutar girin.');
        redirect('/renewals/' . $renewalId . '/edit#iyzico-payment');
    }

    $client = new IyzicoClient(new SettingsRepository());
    if (!$client->isEnabled() || !$client->isConfigured()) {
        flash('error', 'iyzico ayarlari aktif ve eksiksiz olmali.');
        redirect('/renewals/' . $renewalId . '/edit#iyzico-payment');
    }

    $conversationId = 'renewal-' . $renewalId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

    try {
        $result = $client->initializeCheckout($renewal, $amount, $currency, $conversationId);
        $response = $result['response'];
        $paymentPageUrl = trim((string) ($response['paymentPageUrl'] ?? ''));
        $token = trim((string) ($response['token'] ?? ''));
        $ok = (string) ($response['status'] ?? '') === 'success' && $paymentPageUrl !== '' && $token !== '';

        $repo->createIyzicoPayment([
            'renewal_id' => $renewalId,
            'conversation_id' => $conversationId,
            'token' => $token,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $ok ? 'pending' : 'failed',
            'payment_page_url' => $paymentPageUrl,
            'error_message' => $ok ? '' : ((string) ($response['errorMessage'] ?? 'iyzico odeme linki olusturulamadi.')),
            'raw_request' => $result['request'],
            'raw_response' => $response,
            'created_by' => (int) ($_SESSION['user_id'] ?? 0),
        ]);

        if (!$ok) {
            flash('error', (string) ($response['errorMessage'] ?? 'iyzico odeme linki olusturulamadi.'));
            redirect('/renewals/' . $renewalId . '/edit#iyzico-payment');
        }

        redirect($paymentPageUrl);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/renewals/' . $renewalId . '/edit#iyzico-payment');
    }
}

function handle_manual_renewal_notification(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $result = send_manual_renewal_notification($repo, $renewalId);
        $sent = (int) $result['sent'];
        $failed = (int) $result['failed'];

        if ($sent > 0 && $failed < 1) {
            flash('success', 'Bilgilendirme maili gonderildi. Alici sayisi: ' . $sent);
        } elseif ($sent > 0) {
            flash('error', 'Bilgilendirme kismen gonderildi. Basarili: ' . $sent . ', basarisiz: ' . $failed);
        } else {
            flash('error', (string) ($result['message'] ?? 'Bilgilendirme gonderilemedi.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function handle_manual_renewal_mail(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydi bulunamadi.');
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($subject === '') {
            $subject = 'Yenileme bilgilendirmesi: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? ''));
        }
        $subject = mb_substr($subject, 0, 240);

        if ($message === '') {
            throw new RuntimeException('Mail metni bos olamaz.');
        }

        $recipients = renewal_mail_recipients_from_request($repo, $row);
        if ($recipients === []) {
            throw new RuntimeException('Mail gonderilecek en az bir alici secin veya manuel e-posta yazin.');
        }

        $body = manual_renewal_mail_body($row, $message);
        $sent = 0;
        $failed = 0;
        $lastError = '';

        foreach ($recipients as $recipient) {
            $result = Mailer::sendWithResult((string) $recipient['email'], $subject, $body, true);
            $ok = !empty($result['ok']);
            $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');
            $repo->logMail(
                (int) $row['id'],
                (string) $recipient['email'],
                $subject,
                $body,
                $ok ? 'sent' : 'failed',
                $error,
                false
            );

            if ($ok) {
                $sent++;
            } else {
                $failed++;
                $lastError = $error ?? '';
            }
        }

        if ($sent > 0 && $failed < 1) {
            flash('success', 'Mail gonderildi. Alici sayisi: ' . $sent);
        } elseif ($sent > 0) {
            flash('error', 'Mail kismen gonderildi. Basarili: ' . $sent . ', basarisiz: ' . $failed);
        } else {
            flash('error', 'Mail gonderilemedi: ' . ($lastError ?: 'Alici sunucusu kabul etmedi.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/renewals'));
}

function handle_supplier_price_request_mail(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydi bulunamadi.');
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'Lisans fiyat talebi: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Yenileme'));
        }
        $subject = mb_substr($subject, 0, 240);

        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '') {
            $message = supplier_price_default_message($row);
        }

        $recipients = supplier_price_mail_recipients_from_request($repo, $row);
        if ($recipients === []) {
            throw new RuntimeException('Fiyat talebi gonderilecek en az bir tedarikci yetkilisi secin veya manuel e-posta yazin.');
        }

        $sent = 0;
        $failed = 0;
        $lastError = '';

        foreach ($recipients as $recipient) {
            $quoteRequest = $repo->createSupplierQuoteRequest((int) $row['id'], $recipient, $subject, $message, 'mail');
            $result = send_supplier_quote_request_email($repo, $row, $recipient, $quoteRequest, $subject, $message);
            $ok = !empty($result['ok']);
            $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');

            if ($ok) {
                $sent++;
            } else {
                $failed++;
                $lastError = $error ?? '';
            }
        }

        if ($sent > 0) {
            $repo->markSupplierPriceRequested((int) $row['id']);
        }

        if ($sent > 0 && $failed < 1) {
            flash('success', 'Tedarikci fiyat talebi maili gonderildi. Alici sayisi: ' . $sent);
        } elseif ($sent > 0) {
            flash('error', 'Tedarikci fiyat talebi kismen gonderildi. Basarili: ' . $sent . ', basarisiz: ' . $failed);
        } else {
            flash('error', 'Tedarikci fiyat talebi gonderilemedi: ' . ($lastError ?: 'Alici sunucusu kabul etmedi.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function handle_supplier_quote_select(RenewalRepository $repo, int $lineId): void
{
    verify_csrf();

    try {
        $term = (string) ($_POST['term'] ?? '');
        $selected = $repo->selectSupplierQuoteLine($lineId, $term, (int) ($_SESSION['user_id'] ?? 0));
        flash(
            'success',
            'Kalem için tedarikçi teklifi seçildi: '
            . supplier_quote_term_label($selected['term'])
            . ' / '
            . money_format_local($selected['price'], (string) $selected['currency'])
            . '. Müşteri teklifini hazırladığınızda tedarikçiye işlem maili müşteri onayından sonra gönderilecek.'
        );
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function send_supplier_quote_selection_email(RenewalRepository $repo, array $selected): array
{
    $email = trim((string) ($selected['recipient_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'status' => 'skipped', 'error' => 'Tedarikçi e-postası yok.'];
    }

    $row = $repo->find((int) ($selected['renewal_id'] ?? 0));
    if (!$row) {
        return ['ok' => false, 'status' => 'failed', 'error' => 'Yenileme kaydı bulunamadı.'];
    }

    $itemTitle = trim((string) ($selected['item_title'] ?? ''));
    if ($itemTitle === '') {
        $itemTitle = (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Ürün / hizmet'));
    }

    $subject = mb_substr(
        'Teklifiniz seçildi: ' . (string) ($row['company_name'] ?? '-') . ' - ' . $itemTitle,
        0,
        240
    );
    $body = supplier_quote_selection_body($row, $selected, $itemTitle);
    $result = Mailer::sendWithResult($email, $subject, $body, true);
    $ok = !empty($result['ok']);
    $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');

    $repo->logMail(
        (int) $row['id'],
        $email,
        $subject,
        $body,
        $ok ? 'sent' : 'failed',
        $error,
        false
    );

    return ['ok' => $ok, 'status' => $ok ? 'sent' : 'failed', 'error' => $error];
}

function handle_customer_offer_send(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydı bulunamadı.');
        }

        $recipients = customer_offer_recipients_from_request($repo, $row);
        if ($recipients === []) {
            throw new RuntimeException('Müşteri teklifini göndermek için en az bir alıcı seçin veya manuel e-posta yazın.');
        }

        $currency = normalize_allowed_currency($_POST['currency'] ?? 'TRY');
        $subject = trim((string) ($_POST['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'Yenileme teklifiniz: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Ürün / hizmet'));
        }
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '') {
            $message = 'Seçilen tedarikçi teklifleri üzerinden yenileme teklifinizi hazırladık. Lütfen fiyatları inceleyip onay, revize veya red tercihinizi iletin.';
        }

        $sent = 0;
        $failed = 0;
        $lastError = '';
        foreach ($recipients as $recipient) {
            $offer = $repo->createCustomerOffer($renewalId, $recipient, [
                'currency' => $currency,
                'subject' => $subject,
                'message' => $message,
                'lines' => $_POST['offer_lines'] ?? [],
            ], (int) ($_SESSION['user_id'] ?? 0));

            $body = customer_offer_mail_body($row, $offer, $message);
            $result = Mailer::sendWithResult((string) $recipient['email'], $subject, $body, true);
            $ok = !empty($result['ok']);
            $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');
            $repo->logMail(
                (int) $row['id'],
                (string) $recipient['email'],
                $subject,
                $body,
                $ok ? 'sent' : 'failed',
                $error,
                false
            );

            $ok ? $sent++ : $failed++;
            if (!$ok) {
                $lastError = $error ?? '';
            }
        }

        if ($sent > 0 && $failed < 1) {
            flash('success', 'Müşteri teklifi gönderildi. Alıcı sayısı: ' . $sent);
        } elseif ($sent > 0) {
            flash('error', 'Müşteri teklifi kısmen gönderildi. Başarılı: ' . $sent . ', başarısız: ' . $failed);
        } else {
            flash('error', 'Müşteri teklifi gönderilemedi: ' . ($lastError ?: 'Alıcı sunucusu kabul etmedi.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function customer_offer_recipients_from_request(RenewalRepository $repo, array $row): array
{
    $selected = $_POST['customer_offer_recipients'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }

    $selectedEmails = [];
    foreach ($selected as $email) {
        $normalized = trim(mb_strtolower((string) $email));
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            $selectedEmails[$normalized] = true;
        }
    }

    $recipients = [];
    foreach (renewal_customer_contacts($row) as $contact) {
        $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
        if ($email === '' || !isset($selectedEmails[$email])) {
            continue;
        }

        $recipients[$email] = [
            'name' => (string) (($contact['full_name'] ?? '') ?: $email),
            'email' => $email,
        ];
    }

    $customEmail = trim(mb_strtolower((string) ($_POST['custom_customer_offer_email'] ?? '')));
    if ($customEmail !== '') {
        if (!filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Manuel müşteri e-posta adresi geçersiz.');
        }
        $recipients[$customEmail] = [
            'name' => 'Manuel müşteri alıcısı',
            'email' => $customEmail,
        ];
    }

    return array_values($recipients);
}

function handle_supplier_price_request_link(RenewalRepository $repo, int $renewalId): void
{
    $wantsJson = wants_json_response();
    $created = [];
    $redirectToCreatedLink = true;
    if (!csrf_is_valid()) {
        if ($wantsJson) {
            json_response(['ok' => false, 'message' => 'Oturum dogrulamasi basarisiz. Sayfayi yenileyip tekrar deneyin.'], 419);
        }
        verify_csrf();
    }

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydi bulunamadi.');
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        if ($subject === '') {
            $subject = 'Lisans fiyat talebi: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Yenileme'));
        }
        $subject = mb_substr($subject, 0, 240);

        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '') {
            $message = supplier_price_default_message($row);
        }

        $recipients = supplier_price_link_recipients_from_request($repo, $row);
        if ($recipients === []) {
            throw new RuntimeException('Teklif linki olusturmak icin en az bir tedarikci yetkilisi secin veya manuel e-posta yazin.');
        }

        $sendEmail = !empty($_POST['send_quote_email']);
        $redirectToCreatedLink = !$sendEmail;
        $mailSent = 0;
        $mailFailed = 0;
        $mailSkipped = 0;
        $lastMailError = '';

        foreach ($recipients as $recipient) {
            $quoteRequest = $repo->createSupplierQuoteRequest($renewalId, $recipient, $subject, $message, 'manual');
            $createdLink = [
                'label' => supplier_quote_recipient_label($recipient),
                'url' => (string) $quoteRequest['url'],
                'created_at' => date('d.m.Y H:i'),
            ];

            if ($sendEmail) {
                $email = trim((string) ($recipient['email'] ?? ''));
                if ($email === '') {
                    $mailSkipped++;
                    $createdLink['mail_status'] = 'E-posta yok, sadece link oluşturuldu.';
                    $createdLink['mail_status_type'] = 'skipped';
                } else {
                    $mailResult = send_supplier_quote_request_email($repo, $row, $recipient, $quoteRequest, $subject, $message);
                    if (!empty($mailResult['ok'])) {
                        $mailSent++;
                        $createdLink['mail_status'] = 'Mail gönderildi.';
                        $createdLink['mail_status_type'] = 'sent';
                    } else {
                        $mailFailed++;
                        $lastMailError = (string) ($mailResult['error'] ?? 'transport-failed');
                        $createdLink['mail_status'] = 'Mail gönderilemedi: ' . $lastMailError;
                        $createdLink['mail_status_type'] = 'failed';
                    }
                }
            }

            $created[] = $createdLink;
        }

        $_SESSION['_supplier_quote_links'] ??= [];
        $previous = $_SESSION['_supplier_quote_links'][$renewalId] ?? [];
        if (!is_array($previous)) {
            $previous = [];
        }
        $_SESSION['_supplier_quote_links'][$renewalId] = array_slice(array_merge($created, $previous), 0, 12);

        if ($sendEmail && $mailSent > 0) {
            $repo->markSupplierPriceRequested((int) $row['id']);
        }

        $resultMessage = supplier_quote_link_result_message($sendEmail, $mailSent, $mailFailed, $mailSkipped, $lastMailError);

        if ($wantsJson) {
            json_response([
                'ok' => true,
                'message' => $resultMessage,
                'links' => $created,
            ]);
        }

        flash($sendEmail && $mailFailed > 0 && $mailSent < 1 ? 'error' : 'success', $resultMessage);
    } catch (Throwable $e) {
        if ($wantsJson) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        flash('error', $e->getMessage());
    }

    if ($redirectToCreatedLink && !empty($created) && count($created) === 1 && !empty($created[0]['url'])) {
        redirect((string) $created[0]['url']);
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function send_supplier_quote_request_email(RenewalRepository $repo, array $row, array $recipient, array $quoteRequest, string $subject, string $message): array
{
    $email = trim((string) ($recipient['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Geçerli tedarikçi e-postası yok.'];
    }

    $body = supplier_price_request_body($row, $message, (string) ($quoteRequest['url'] ?? ''));
    $result = Mailer::sendWithResult($email, $subject, $body, true);
    $ok = !empty($result['ok']);
    $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');

    $repo->logMail(
        (int) $row['id'],
        $email,
        $subject,
        $body,
        $ok ? 'sent' : 'failed',
        $error,
        false
    );

    return ['ok' => $ok, 'error' => $error];
}

function supplier_quote_link_result_message(bool $sendEmail, int $mailSent, int $mailFailed, int $mailSkipped, string $lastError = ''): string
{
    if (!$sendEmail) {
        return 'Tedarikçi teklif linki oluşturuldu. Linki pencereden kopyalayabilirsiniz.';
    }

    if ($mailSent > 0 && $mailFailed < 1 && $mailSkipped < 1) {
        return 'Tedarikçi teklif linki oluşturuldu ve mail gönderildi. Alıcı sayısı: ' . $mailSent;
    }

    if ($mailSent > 0) {
        return 'Tedarikçi teklif linki oluşturuldu. Mail kısmen gönderildi. Başarılı: ' . $mailSent . ', başarısız: ' . $mailFailed . ', e-postasız: ' . $mailSkipped;
    }

    if ($mailSkipped > 0 && $mailFailed < 1) {
        return 'Tedarikçi teklif linki oluşturuldu fakat mail gönderilecek e-posta bulunamadı.';
    }

    return 'Tedarikçi teklif linki oluşturuldu fakat mail gönderilemedi: ' . ($lastError ?: 'Alici sunucusu kabul etmedi.');
}

function handle_supplier_price_request_whatsapp(RenewalRepository $repo, int $renewalId): void
{
    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydi bulunamadi.');
        }

        $contactId = (int) ($_GET['contact_id'] ?? 0);
        $contact = null;
        foreach (renewal_supplier_contacts($repo, $row) as $candidate) {
            if ((int) ($candidate['contact_id'] ?? 0) === $contactId) {
                $contact = $candidate;
                break;
            }
        }

        if (!$contact) {
            throw new RuntimeException('Tedarikci yetkilisi bulunamadi.');
        }

        $waNumber = whatsapp_number_from_phone((string) ($contact['phone'] ?? ''));
        if ($waNumber === null) {
            throw new RuntimeException('Tedarikci telefon numarasi WhatsApp icin uygun degil.');
        }

        $subject = 'Lisans fiyat talebi: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Yenileme'));
        $message = supplier_price_default_message($row);
        $quoteRequest = $repo->createSupplierQuoteRequest($renewalId, $contact, $subject, $message, 'whatsapp');
        $whatsappMessage = supplier_price_whatsapp_message($row, $contact, (string) $quoteRequest['url']);

        header('Location: https://wa.me/' . rawurlencode($waNumber) . '?text=' . rawurlencode($whatsappMessage));
        exit;
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect(safe_return_path($_GET['return_to'] ?? '/'));
    }
}

function supplier_price_mail_recipients_from_request(RenewalRepository $repo, array $row): array
{
    $selected = $_POST['supplier_mail_recipients'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }

    $selectedEmails = [];
    foreach ($selected as $email) {
        $normalized = trim(mb_strtolower((string) $email));
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            $selectedEmails[$normalized] = true;
        }
    }

    $recipients = [];
    foreach (renewal_supplier_contacts($repo, $row) as $contact) {
        $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
        if ($email === '' || !isset($selectedEmails[$email])) {
            continue;
        }

        $recipients[$email] = [
            'supplier_id' => (int) ($contact['supplier_id'] ?? 0),
            'contact_id' => (int) ($contact['contact_id'] ?? 0),
            'supplier_name' => (string) ($contact['supplier_name'] ?? ''),
            'name' => (string) ($contact['name'] ?? ''),
            'email' => $email,
            'phone' => (string) ($contact['phone'] ?? ''),
        ];
    }

    $customEmail = trim(mb_strtolower((string) ($_POST['custom_supplier_email'] ?? '')));
    if ($customEmail !== '') {
        if (!filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Manuel tedarikci e-posta adresi gecersiz.');
        }

        $recipients[$customEmail] = [
            'name' => 'Manuel tedarikci alicisi',
            'email' => $customEmail,
        ];
    }

    return array_values($recipients);
}

function supplier_price_link_recipients_from_request(RenewalRepository $repo, array $row): array
{
    $selected = $_POST['supplier_quote_contacts'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }

    $selectedKeys = [];
    foreach ($selected as $key) {
        $key = (string) $key;
        if (preg_match('/^\d+$/', $key) === 1) {
            $selectedKeys[$key] = true;
        }
    }

    $recipients = [];
    $contacts = renewal_supplier_contacts($repo, $row);
    foreach ($contacts as $index => $contact) {
        if (!isset($selectedKeys[(string) $index])) {
            continue;
        }

        $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
        $phone = normalize_phone_number((string) ($contact['phone'] ?? ''));
        $dedupeKey = $email !== '' ? 'email:' . $email : 'phone:' . preg_replace('/\D+/', '', $phone);
        if ($dedupeKey === 'phone:' || isset($recipients[$dedupeKey])) {
            continue;
        }

        $recipients[$dedupeKey] = [
            'supplier_id' => (int) ($contact['supplier_id'] ?? 0),
            'contact_id' => (int) ($contact['contact_id'] ?? 0),
            'supplier_name' => (string) ($contact['supplier_name'] ?? ''),
            'name' => (string) ($contact['name'] ?? ''),
            'email' => $email,
            'phone' => $phone,
        ];
    }

    $customEmail = trim(mb_strtolower((string) ($_POST['custom_supplier_email'] ?? '')));
    if ($customEmail !== '') {
        if (!filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Manuel tedarikci e-posta adresi gecersiz.');
        }

        $recipients['email:' . $customEmail] = [
            'name' => 'Manuel tedarikci alicisi',
            'email' => $customEmail,
        ];
    }

    return array_values($recipients);
}

function supplier_quote_recipient_label(array $recipient): string
{
    $parts = array_filter([
        trim((string) ($recipient['supplier_name'] ?? '')),
        trim((string) ($recipient['name'] ?? '')),
    ]);
    $identity = trim((string) (($recipient['email'] ?? '') ?: ($recipient['phone'] ?? '')));
    if ($identity !== '') {
        $parts[] = $identity;
    }

    return implode(' - ', $parts) ?: 'Tedarikci teklif linki';
}

function renewal_mail_recipients_from_request(RenewalRepository $repo, array $row): array
{
    $selected = $_POST['mail_recipients'] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }

    $selectedEmails = [];
    foreach ($selected as $email) {
        $normalized = trim(mb_strtolower((string) $email));
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            $selectedEmails[$normalized] = true;
        }
    }

    $recipients = [];
    foreach (renewal_customer_contacts($row) as $contact) {
        $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
        if ($email === '' || !isset($selectedEmails[$email])) {
            continue;
        }

        $recipients[$email] = [
            'name' => (string) ($contact['full_name'] ?? ''),
            'email' => $email,
        ];
    }

    $customEmail = trim(mb_strtolower((string) ($_POST['custom_email'] ?? '')));
    if ($customEmail !== '') {
        if (!filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Manuel e-posta adresi gecersiz.');
        }

        $recipients[$customEmail] = [
            'name' => 'Manuel alici',
            'email' => $customEmail,
        ];
    }

    return array_values($recipients);
}

function manual_renewal_mail_body(array $row, string $message): string
{
    $days = days_until($row['renewal_date'] ?? null);
    $daysLabel = $days === null ? '-' : ($days < 0 ? abs($days) . ' gün geçti' : $days . ' gün');
    $total = money_format_local($row['item_total'] ?? $row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'));
    $paymentLink = PaymentLink::urlForRenewal((int) $row['id'], 60);
    $summaryLink = renewal_summary_url((int) $row['id'], 60);

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Yenileme bilgilendirmesi</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">' . h((string) ($row['company_name'] ?? '-')) . '</h1>'
        . '<div style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . nl2br(h($message), false) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . manual_mail_row('Kayıt', (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-')))
        . manual_mail_row('Yenileme tarihi', !empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-')
        . manual_mail_row('Kalan süre', $daysLabel)
        . manual_mail_row('Toplam', $total . ' KDV dahil')
        . manual_mail_row('Ödeme şekli', renewal_payment_label($row))
        . '</table>'
        . '<p style="margin:20px 0 0;"><a href="' . h($summaryLink) . '" style="display:inline-block;background:#eef6f3;color:#0f625b;text-decoration:none;border:1px solid #cfe1db;border-radius:8px;padding:13px 18px;font-weight:700;">PDF / özet sayfasını aç</a></p>'
        . '<p style="margin:10px 0 0;"><a href="' . h($paymentLink) . '" style="display:inline-block;background:#101b18;color:#ffffff;text-decoration:none;border-radius:8px;padding:13px 18px;font-weight:700;">Ödeme / tercih ekranını aç</a></p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function manual_mail_row(string $label, string $value): string
{
    return '<tr>'
        . '<td style="padding:12px 14px;border-bottom:1px solid #d8e0dd;color:#61726c;font-weight:700;width:38%;">' . h($label) . '</td>'
        . '<td style="padding:12px 14px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . h($value) . '</td>'
        . '</tr>';
}

function send_manual_renewal_notification(RenewalRepository $repo, int $renewalId): array
{
    $row = $repo->find($renewalId);
    if (!$row) {
        throw new RuntimeException('Yenileme kaydi bulunamadi.');
    }

    if (($row['status'] ?? '') !== 'active') {
        throw new RuntimeException('Sadece aktif yenilemeler icin bilgilendirme gonderilebilir.');
    }

    $recipients = $repo->notificationRecipients((int) $row['customer_id']);
    if ($recipients === []) {
        $repo->logMail(
            (int) $row['id'],
            'missing-approved-recipient',
            'Eksik bilgilendirme yetkilisi',
            'Manuel bilgilendirme icin bilgilendirme kutusu onayli ve e-posta adresi dolu yetkili bulunamadi.',
            'failed',
            'missing-approved-recipient'
        );

        return [
            'sent' => 0,
            'failed' => 1,
            'message' => 'Bilgilendirme kutusu onayli ve e-posta adresi dolu yetkili bulunamadi.',
        ];
    }

    $settings = (new SettingsRepository())->all();
    $days = days_until($row['renewal_date']);
    [$subject, $statusLine] = renewal_notification_subject_and_status($row, $days);
    $sent = 0;
    $failed = 0;

    foreach ($recipients as $recipient) {
        $delivery = $repo->createNotificationDelivery((int) $row['id'], $recipient);
        $recipientForMail = array_merge($recipient, ['read_ack_url' => $delivery['read_url']]);
        $mail = MailTemplate::renderRenewal($settings, $row, $recipientForMail, $statusLine, $days);
        $body = (string) $mail['body'];
        $ok = Mailer::send(
            (string) $recipient['email'],
            $subject,
            $body,
            (bool) $mail['is_html'],
            $mail['inline_attachments'] ?? []
        );
        $mailLogId = $repo->logMail(
            (int) $row['id'],
            (string) $recipient['email'],
            $subject,
            $body,
            $ok ? 'sent' : 'failed',
            $ok ? null : 'transport-failed'
        );
        $repo->updateNotificationDeliveryStatus(
            (int) $delivery['id'],
            $mailLogId,
            $ok ? 'sent' : 'failed',
            $ok ? null : 'transport-failed'
        );

        $ok ? $sent++ : $failed++;
    }

    return [
        'sent' => $sent,
        'failed' => $failed,
        'message' => $sent > 0 ? 'Bilgilendirme gonderildi.' : 'Bilgilendirme gonderilemedi.',
    ];
}

function handle_renewal_decision(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydi bulunamadi.');
        }

        $decision = (string) ($_POST['decision'] ?? '');
        $createdBy = (int) ($_SESSION['user_id'] ?? 0);

        if ($decision === 'approved') {
            $paymentTerms = trim((string) ($_POST['payment_terms'] ?? ''));
            if ($paymentTerms === '') {
                throw new RuntimeException('Onay icin odeme sartlarini yazin.');
            }

            $repo->markDecisionApproved($renewalId, $paymentTerms);
            $repo->recordRenewalDecision([
                'renewal_id' => $renewalId,
                'decision' => 'approved',
                'payment_terms' => $paymentTerms,
                'created_by' => $createdBy,
            ]);
            flash('success', 'Yenileme onaylandi ve odeme sartlari kaydedildi.');
        } elseif ($decision === 'rejected') {
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($reason === '') {
                throw new RuntimeException('Reddedildi bilgisinde sebep zorunlu.');
            }

            $repo->markDecisionRejected($renewalId);
            $repo->recordRenewalDecision([
                'renewal_id' => $renewalId,
                'decision' => 'rejected',
                'reason' => $reason,
                'created_by' => $createdBy,
            ]);
            flash('success', 'Yenileme reddedildi olarak kaydedildi ve iptal durumuna alindi.');
        } elseif ($decision === 'postponed') {
            $postponedDate = renewal_decision_postponed_date($row);
            $note = trim((string) ($_POST['note'] ?? ''));

            $repo->postponeRenewal($renewalId, $postponedDate);
            $repo->recordRenewalDecision([
                'renewal_id' => $renewalId,
                'decision' => 'postponed',
                'postponed_date' => $postponedDate,
                'note' => $note,
                'created_by' => $createdBy,
            ]);
            flash('success', 'Yenileme tarihi ' . date('d.m.Y', strtotime($postponedDate)) . ' olarak ertelendi.');
        } elseif ($decision === 'revision_requested') {
            $note = trim((string) ($_POST['note'] ?? ''));
            $mailResult = send_supplier_revision_request($repo, $row, $note);

            $repo->recordRenewalDecision([
                'renewal_id' => $renewalId,
                'decision' => 'revision_requested',
                'note' => $note,
                'mail_status' => (string) $mailResult['status'],
                'mail_error' => (string) ($mailResult['error'] ?? ''),
                'created_by' => $createdBy,
            ]);

            if ((int) ($mailResult['sent'] ?? 0) > 0 && (int) ($mailResult['failed'] ?? 0) < 1) {
                flash('success', 'Revize talebi tedarikciye gonderildi.');
            } elseif ((int) ($mailResult['sent'] ?? 0) > 0) {
                flash('error', 'Revize talebi kismen gonderildi: ' . (string) ($mailResult['error'] ?? ''));
            } else {
                flash('error', 'Revize talebi gonderilemedi: ' . (string) ($mailResult['error'] ?? 'Alici bulunamadi.'));
            }
        } else {
            throw new RuntimeException('Gecersiz takip karari.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/renewals'));
}

function renewal_decision_postponed_date(array $row): string
{
    $quick = (string) ($_POST['postpone_quick'] ?? '');
    $today = new DateTimeImmutable('today');
    $date = null;

    if ($quick === '1m') {
        $date = $today->modify('+1 month');
    } elseif ($quick === '2m') {
        $date = $today->modify('+2 months');
    } elseif ($quick === '3m') {
        $date = $today->modify('+3 months');
    } elseif ($quick === 'year_end') {
        $date = new DateTimeImmutable(date('Y') . '-12-31');
    } else {
        $rawDate = trim((string) ($_POST['postponed_date'] ?? ''));
        if ($rawDate !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $rawDate);
            if ($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $rawDate) {
                $date = $parsed;
            }
        }
    }

    if (!$date) {
        throw new RuntimeException('Erteleme icin tarih secin.');
    }

    if ($date < $today) {
        throw new RuntimeException('Erteleme tarihi bugunden once olamaz.');
    }

    return $date->format('Y-m-d');
}

function send_supplier_revision_request(RenewalRepository $repo, array $row, string $note): array
{
    $recipients = $repo->supplierNotificationRecipients(
        empty($row['supplier_id']) ? null : (int) $row['supplier_id'],
        empty($row['supplier_group_id']) ? null : (int) $row['supplier_group_id']
    );

    if ($recipients === []) {
        return [
            'status' => 'failed',
            'sent' => 0,
            'failed' => 1,
            'error' => 'Bilgilendirme kutusu acik tedarikci yetkilisi bulunamadi.',
        ];
    }

    $subject = 'Fiyat revize talebi: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Yenileme'));
    $body = supplier_revision_request_body($row, $note);
    $sent = 0;
    $failed = 0;
    $errors = [];

    foreach ($recipients as $recipient) {
        $result = Mailer::sendWithResult((string) $recipient['email'], $subject, $body, true);
        $ok = !empty($result['ok']);
        $repo->logMail(
            (int) $row['id'],
            (string) $recipient['email'],
            $subject,
            $body,
            $ok ? 'sent' : 'failed',
            $ok ? null : (string) ($result['error'] ?? 'transport-failed'),
            false
        );

        if ($ok) {
            $sent++;
        } else {
            $failed++;
            $errors[] = (string) $recipient['email'] . ': ' . (string) ($result['error'] ?? 'transport-failed');
        }
    }

    if ($sent > 0) {
        $repo->markSupplierPriceRequested((int) $row['id']);
    }

    return [
        'status' => $failed < 1 ? 'sent' : ($sent > 0 ? 'partial' : 'failed'),
        'sent' => $sent,
        'failed' => $failed,
        'error' => implode(' | ', $errors),
    ];
}

function supplier_revision_request_body(array $row, string $note): string
{
    $rows = [
        'Müşteri' => (string) ($row['company_name'] ?? '-'),
        'Ürün / hizmet' => (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-')),
        'Marka' => (string) (($row['brand'] ?? '') ?: '-'),
        'Yenileme tarihi' => !empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-',
        'Toplam' => money_format_local($row['item_total'] ?? $row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY')),
        'Talep' => 'Müşteriden olumlu dönüş alabilmek için fiyat indirimi / revize teklif rica ederiz.',
        'Not' => $note !== '' ? $note : '-',
    ];

    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;width:34%;">' . h($label) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . nl2br(h($value), false) . '</td>'
            . '</tr>';
    }

    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f2f6f4;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d9e3df;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Revize talebi</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">Fiyat indirimi / revize teklif rica ederiz.</h1>'
        . '<p style="margin:0 0 18px;color:#607069;line-height:1.5;">Aşağıdaki yenileme için daha uygun fiyat çalışması beklenmektedir.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function renewal_supplier_contacts(RenewalRepository $repo, array $row): array
{
    $contacts = $repo->supplierCommunicationRecipients(
        empty($row['supplier_id']) ? null : (int) $row['supplier_id'],
        empty($row['supplier_group_id']) ? null : (int) $row['supplier_group_id']
    );

    $unique = [];
    foreach ($contacts as $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
        $phone = normalize_phone_number((string) ($contact['phone'] ?? ''));
        $name = trim((string) ($contact['name'] ?? $contact['full_name'] ?? ''));
        $supplierName = trim((string) ($contact['supplier_name'] ?? ''));
        if ($email === '' && $phone === '') {
            continue;
        }

        $key = $email !== '' ? 'email:' . $email : 'phone:' . preg_replace('/\D+/', '', $phone);
        if (isset($unique[$key])) {
            continue;
        }

        $unique[$key] = [
            'supplier_id' => (int) ($contact['supplier_id'] ?? 0),
            'contact_id' => (int) ($contact['contact_id'] ?? 0),
            'supplier_name' => $supplierName,
            'name' => $name !== '' ? $name : ($supplierName !== '' ? $supplierName : ($email ?: $phone)),
            'email' => $email,
            'phone' => $phone,
        ];
    }

    return array_values($unique);
}

function supplier_price_default_message(array $row): string
{
    $days = days_until($row['renewal_date'] ?? null);
    $statusLine = $days !== null && $days < 0
        ? 'Yenileme tarihi ' . abs($days) . ' gün önce geçti.'
        : 'Yenilemeye kalan süre: ' . max(0, (int) $days) . ' gün.';
    $title = (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'yenileme kaydı'));

    return implode("\n", [
        'Merhaba,',
        '',
        (string) ($row['company_name'] ?? '-') . ' müşterimiz için aşağıdaki ürün / hizmet yenilemesi yaklaşmaktadır.',
        'Güncel yenileme teklifinizi peşin, 30 gün, 60 gün, çek veya özel vade seçenekleriyle paylaşmanızı rica ederiz.',
        'Fiyat yazmak istemezseniz teklif dosyanızı veya genel teklif notunuzu formdan iletebilirsiniz.',
        '',
        'Ürün / hizmet: ' . $title,
        'Marka: ' . (string) (($row['brand'] ?? '') ?: '-'),
        'Lisans / referans no: ' . (string) (($row['license_key'] ?? '') ?: '-'),
        'Talep edilen periyot: ' . (string) (($row['renewal_period_name'] ?? '') ?: '-'),
        'Yenileme tarihi: ' . (!empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-'),
        $statusLine,
        '',
        'Teşekkürler.',
    ]);
}

function supplier_price_request_body(array $row, string $message, string $quoteUrl = ''): string
{
    $rows = [
        'Müşteri' => (string) ($row['company_name'] ?? '-'),
        'Ürün / hizmet' => (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-')),
        'Marka' => (string) (($row['brand'] ?? '') ?: '-'),
        'Lisans / referans no' => (string) (($row['license_key'] ?? '') ?: '-'),
        'Talep edilen periyot' => (string) (($row['renewal_period_name'] ?? '') ?: '-'),
        'Yenileme tarihi' => !empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-',
    ];

    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;width:34%;">' . h($label) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . nl2br(h($value), false) . '</td>'
            . '</tr>';
    }

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:24px;background:#f2f6f4;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d9e3df;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Tedarikçi fiyat talebi</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">Güncel yenileme fiyatı rica ederiz.</h1>'
        . '<div style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . nl2br(h($message), false) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . ($quoteUrl !== '' ? '<p style="margin:20px 0 0;"><a href="' . h($quoteUrl) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:14px 20px;font-weight:700;">Teklif formunu aç</a></p>' : '')
        . '<p style="margin:14px 0 0;color:#607069;font-size:13px;line-height:1.5;">Formda nakliye, KDV, vade ve teklif notu onayı zorunludur. Fiyat yazmak istemezseniz teklifinizi dosya veya not olarak iletebilirsiniz.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function customer_offer_mail_body(array $row, array $offer, string $message): string
{
    $currency = normalize_allowed_currency($offer['currency'] ?? 'TRY');
    $rows = [
        'Müşteri' => (string) ($row['company_name'] ?? '-'),
        'Kayıt' => (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-')),
        'Ara toplam' => money_format_local($offer['subtotal'] ?? null, $currency),
        'KDV' => money_format_local($offer['vat_total'] ?? null, $currency),
        'KDV dahil toplam' => money_format_local($offer['total'] ?? null, $currency),
    ];

    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;width:34%;">' . h($label) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . h($value) . '</td>'
            . '</tr>';
    }

    $lineRows = '';
    foreach (($offer['lines'] ?? []) as $line) {
        if (!is_array($line)) {
            continue;
        }
        $quantity = (float) ($line['quantity'] ?? 1);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        $subtotal = (float) ($line['line_subtotal'] ?? ($quantity * $unitPrice));
        $total = (float) ($line['line_total'] ?? $subtotal);
        $lineRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . h((string) ($line['item_title'] ?? '-')) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;text-align:center;">' . h(number_format($quantity, 2, ',', '.')) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;text-align:right;">' . h(money_format_local($unitPrice, $currency)) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;text-align:right;">' . h(money_format_local($subtotal, $currency)) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#147c72;font-weight:700;text-align:right;">' . h(money_format_local($total, $currency)) . '</td>'
            . '</tr>';
    }
    $lineTable = $lineRows === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;border-collapse:collapse;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr>'
        . '<th align="left" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Ürün / hizmet</th>'
        . '<th align="center" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Adet</th>'
        . '<th align="right" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Birim fiyat</th>'
        . '<th align="right" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Toplam</th>'
        . '<th align="right" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">KDV dahil</th>'
        . '</tr>'
        . $lineRows
        . '</table>';

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:24px;background:#f2f6f4;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d9e3df;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Müşteri yenileme teklifi</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">Yenileme teklifinizi inceleyebilirsiniz.</h1>'
        . '<div style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . nl2br(h($message), false) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . $lineTable
        . '<p style="margin:20px 0 0;"><a href="' . h((string) ($offer['url'] ?? '')) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:14px 20px;font-weight:700;">Teklifi incele ve yanıtla</a></p>'
        . '<p style="margin:14px 0 0;color:#607069;font-size:13px;line-height:1.5;">Bu bağlantı üzerinden onay, revize isteği veya red tercihinizi iletebilirsiniz.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function supplier_quote_selection_body(array $row, array $selected, string $itemTitle): string
{
    $contactName = trim((string) ($selected['contact_name'] ?? ''));
    $termLabel = supplier_quote_term_label((string) ($selected['term'] ?? ''), (string) ($selected['custom_term'] ?? ''));
    $price = money_format_local($selected['price'] ?? 0, (string) ($selected['currency'] ?? 'TRY'));
    $rows = [
        'Müşteri' => (string) ($row['company_name'] ?? '-'),
        'Ürün / hizmet' => $itemTitle,
        'Seçilen vade' => $termLabel,
        'Seçilen fiyat' => $price,
        'KDV durumu' => !empty($selected['vat_included']) ? 'KDV dahil' : 'KDV hariç',
        'Yenileme tarihi' => !empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-',
    ];
    if (trim((string) ($selected['delivery_note'] ?? '')) !== '') {
        $rows['Nakliye / teslim'] = (string) $selected['delivery_note'];
    }
    if (trim((string) ($selected['note'] ?? '')) !== '') {
        $rows['Teklif notu'] = (string) $selected['note'];
    }

    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;width:34%;">' . h($label) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . nl2br(h($value), false) . '</td>'
            . '</tr>';
    }

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:24px;background:#f2f6f4;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d9e3df;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Tedarikçi teklif seçimi</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">Teklifiniz seçildi, işleme alabilirsiniz.</h1>'
        . '<p style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">'
        . h($contactName !== '' ? 'Merhaba ' . $contactName . ',' : 'Merhaba,')
        . '<br>Paylaştığınız teklif aşağıdaki şartlarla seçilmiştir. Lütfen ilgili işlem / yenileme sürecini başlatabilirsiniz.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . '<p style="margin:18px 0 0;color:#607069;font-size:13px;line-height:1.5;">Herhangi bir değişiklik gerekiyorsa lütfen bizimle iletişime geçiniz.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function supplier_price_whatsapp_message(array $row, array $contact, string $quoteUrl = ''): string
{
    $name = trim((string) ($contact['name'] ?? ''));
    $message = supplier_price_default_message($row);
    if ($quoteUrl !== '') {
        $message .= "\n\nTeklif formu:\n" . $quoteUrl;
    }
    if ($name === '') {
        return $message;
    }

    return str_replace('Merhaba,', 'Merhaba ' . $name . ',', $message);
}

function renewal_notification_subject_and_status(array $row, ?int $days): array
{
    $recordType = $row['kind'] === 'product' ? 'lisans' : 'üyelik / hizmet';

    if ($days !== null && $days < 0) {
        return [
            sprintf('%s süresi doldu: %s', ucfirst($recordType), $row['title']),
            sprintf('Bu %s %d gün önce sona erdi.', $recordType, abs($days)),
        ];
    }

    if ($days === 0) {
        return [
            sprintf('Bugün sona eriyor: %s', $row['title']),
            sprintf('Bu %s bugün sona eriyor.', $recordType),
        ];
    }

    return [
        sprintf('Yenileme hatırlatması: %s', $row['title']),
        sprintf('Kalan süre: %d gün.', max(0, (int) $days)),
    ];
}

function handle_public_renewal_payment(string $method, int $renewalId): void
{
    $expires = (string) ($_GET['expires'] ?? $_POST['expires'] ?? '');
    $signature = (string) ($_GET['sig'] ?? $_POST['sig'] ?? '');
    $linkEmail = public_payment_link_email();

    if (!PaymentLink::isValid($renewalId, $expires, $signature, $linkEmail)) {
        http_response_code(403);
        render_public_layout('Ödeme seçimi', static function (): void {
            ?>
            <section class="login-panel customer-info-public">
                <div class="alert error">Ödeme bağlantısı geçersiz veya süresi dolmuş.</div>
            </section>
            <?php
        });
        return;
    }

    $repo = new RenewalRepository();
    $renewal = $repo->find($renewalId);
    if (!$renewal || ($renewal['status'] ?? '') !== 'active') {
        http_response_code(404);
        render_public_layout('Ödeme seçimi', static function (): void {
            ?>
            <section class="login-panel customer-info-public">
                <div class="alert error">Aktif ödeme kaydı bulunamadı.</div>
            </section>
            <?php
        });
        return;
    }

    if (renewal_payment_choice_completed($renewal)) {
        redirect(url('/renewals/' . $renewalId . '/payment/thanks?' . public_payment_link_query($expires, $signature, $linkEmail)));
    }

    $items = $repo->renewalItems($renewalId);
    $totalAmount = (float) (($renewal['item_total'] ?? 0) ?: ($renewal['amount'] ?? 0));
    $currency = (string) ($renewal['currency'] ?? 'TRY');
    $exchangeRates = ExchangeRates::latest();
    $settings = (new SettingsRepository())->all();
    $paymentMethods = $repo->paymentMethods();
    $error = null;
    $notice = null;
    $selectedMethod = '';
    $directCreditCard = $method === 'GET' && (string) ($_GET['method'] ?? '') === 'credit_card';

    if ($directCreditCard) {
        $creditCardMethod = first_credit_card_payment_method($paymentMethods);
        try {
            if ($creditCardMethod === null) {
                throw new RuntimeException('Kredi kartı ödeme yöntemi tanımlı değil. Lütfen firma yetkilisiyle iletişime geçin.');
            }

            if ($totalAmount <= 0) {
                throw new RuntimeException('Kredi kartı ödemesi için tutar tanımlı değil. Lütfen firma yetkilisiyle iletişime geçin.');
            }

            $selectedMethod = (string) $creditCardMethod['name'];
            $checkoutUrl = create_iyzico_checkout_url($repo, $renewal, $totalAmount, $currency, null, 'mail');
            $repo->setRenewalPaymentMethod($renewalId, $selectedMethod, $linkEmail);
            redirect($checkoutUrl);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    if ($method === 'POST') {
        verify_csrf();
        $selectedMethod = trim((string) ($_POST['payment_method'] ?? ''));
        $methodRow = payment_method_row_by_name($paymentMethods, $selectedMethod);

        try {
            if (!$methodRow) {
                throw new RuntimeException('Lütfen geçerli bir ödeme yöntemi seçin.');
            }

            if (payment_method_is_credit_card($selectedMethod)) {
                $amount = (float) (($renewal['item_total'] ?? 0) ?: ($renewal['amount'] ?? 0));
                if ($amount <= 0) {
                    throw new RuntimeException('Kredi kartı ödemesi için tutar tanımlı değil. Lütfen firma yetkilisiyle iletişime geçin.');
                }

                $checkoutUrl = create_iyzico_checkout_url($repo, $renewal, $amount, (string) ($renewal['currency'] ?? 'TRY'), null, 'public');
                $repo->setRenewalPaymentMethod($renewalId, $selectedMethod, $linkEmail);
                redirect($checkoutUrl);
            }

            if (payment_method_is_bank_transfer($selectedMethod)) {
                $receipt = save_bank_transfer_receipt_upload($renewalId);
                $note = trim((string) ($_POST['payment_note'] ?? ''));
                $recipients = bank_transfer_notification_recipients($settings);
                $receiptId = $repo->createPaymentReceipt([
                    'renewal_id' => $renewalId,
                    'payment_method' => $selectedMethod,
                    'payer_email' => $linkEmail,
                    'note' => $note,
                    'original_name' => $receipt['original_name'],
                    'stored_path' => $receipt['stored_path'],
                    'mime_type' => $receipt['mime_type'],
                    'file_size' => $receipt['file_size'],
                    'notification_recipients' => implode(', ', $recipients),
                    'notification_status' => 'pending',
                ]);

                $repo->setRenewalPaymentMethod($renewalId, $selectedMethod, $linkEmail);
                $mailResult = send_bank_transfer_receipt_notification(
                    $repo,
                    $renewal,
                    $totalAmount,
                    $currency,
                    $selectedMethod,
                    $linkEmail,
                    $note,
                    $receipt,
                    $recipients
                );
                $repo->updatePaymentReceiptNotification(
                    $receiptId,
                    (string) $mailResult['status'],
                    (string) ($mailResult['error'] ?? ''),
                    implode(', ', $recipients)
                );

                redirect(url('/renewals/' . $renewalId . '/payment/thanks?' . public_payment_link_query($expires, $signature, $linkEmail)));
            }

            $repo->setRenewalPaymentMethod($renewalId, $selectedMethod, $linkEmail);
            redirect(url('/renewals/' . $renewalId . '/payment/thanks?' . public_payment_link_query($expires, $signature, $linkEmail)));
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    render_public_layout('Ödeme seçimi', static function () use ($renewal, $items, $totalAmount, $currency, $exchangeRates, $settings, $paymentMethods, $expires, $signature, $linkEmail, $error, $notice, $selectedMethod): void {
        $ibanInfo = trim((string) ($settings['bank_transfer.iban_info'] ?? ''));
        ?>
        <section class="login-panel customer-info-public payment-choice-public">
            <div class="login-heading">
                <p class="eyebrow">Ödeme seçimi</p>
                <h1>Ödeme yönteminizi seçin.</h1>
                <p class="muted compact"><?= h($renewal['company_name']) ?> için <?= h($renewal['item_summary'] ?? $renewal['title']) ?> yenileme kaydı.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>
            <?php if ($notice): ?>
                <div class="alert success"><?= h($notice) ?></div>
            <?php endif; ?>

            <div class="payment-choice-summary">
                <div>
                    <span>Yenileme tarihi</span>
                    <strong><?= h(date('d.m.Y', strtotime((string) $renewal['renewal_date']))) ?></strong>
                </div>
                <div>
                    <span>Toplam tutar</span>
                    <strong><?= h(money_format_local($totalAmount > 0 ? $totalAmount : null, $currency)) ?></strong>
                    <em>KDV dahil</em>
                </div>
            </div>

            <?= render_payment_exchange_panel($totalAmount, $currency, $exchangeRates) ?>

            <?= render_public_renewal_items($items, $currency) ?>

            <form method="post" class="form-grid" enctype="multipart/form-data" data-public-payment-form>
                <?= csrf_field() ?>
                <input type="hidden" name="expires" value="<?= h($expires) ?>">
                <input type="hidden" name="sig" value="<?= h($signature) ?>">
                <input type="hidden" name="email" value="<?= h($linkEmail) ?>">
                <label>
                    Ödeme yöntemi
                    <select name="payment_method" required data-public-payment-method>
                        <option value="">Ödeme yöntemi seçin</option>
                        <?php foreach ($paymentMethods as $paymentMethod): ?>
                            <?= option((string) $paymentMethod['name'], (string) $paymentMethod['name'], $selectedMethod) ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="bank-transfer-public" data-bank-transfer-panel hidden>
                    <div class="section-head">
                        <h2>Havale / EFT bilgileri</h2>
                        <span class="badge active">Dekont gerekli</span>
                    </div>
                    <?php if ($ibanInfo !== ''): ?>
                        <div class="bank-transfer-iban"><?= nl2br(h($ibanInfo), false) ?></div>
                    <?php else: ?>
                        <div class="settings-note">
                            <strong>IBAN bilgisi tanımlı değil</strong>
                            <span>Lütfen firma yetkilisiyle iletişime geçin.</span>
                        </div>
                    <?php endif; ?>
                    <div class="form-grid two">
                        <label>
                            Makbuz / dekont
                            <input type="file" name="payment_receipt" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" data-bank-transfer-receipt>
                            <span class="field-help">PDF, JPG, PNG veya WebP. En fazla 8 MB.</span>
                        </label>
                        <label>
                            Not
                            <textarea name="payment_note" rows="3" placeholder="Örn: Ödeme açıklaması, gönderen hesap veya işlem notu"><?= h((string) ($_POST['payment_note'] ?? '')) ?></textarea>
                        </label>
                    </div>
                </div>
                <?php if ($paymentMethods): ?>
                    <div class="payment-method-descriptions">
                        <?php foreach ($paymentMethods as $paymentMethod): ?>
                            <div>
                                <strong><?= h($paymentMethod['name']) ?></strong>
                                <span><?= h($paymentMethod['description'] ?: 'Açıklama girilmedi.') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <button type="submit" class="button primary">Devam et</button>
            </form>
        </section>
        <?php
    });
}

function handle_public_renewal_payment_thanks(int $renewalId): void
{
    $expires = (string) ($_GET['expires'] ?? '');
    $signature = (string) ($_GET['sig'] ?? '');
    $linkEmail = public_payment_link_email();

    if (!PaymentLink::isValid($renewalId, $expires, $signature, $linkEmail)) {
        http_response_code(403);
        render_public_layout('Ödeme tercihi', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Ödeme tercihi</p>
                <h1>Bağlantı geçersiz.</h1>
                <p class="muted">Ödeme tercih bağlantısının süresi dolmuş olabilir. Lütfen firma yetkilisiyle iletişime geçin.</p>
            </section>
            <?php
        });
        return;
    }

    $repo = new RenewalRepository();
    $renewal = $repo->find($renewalId);
    if (!$renewal) {
        http_response_code(404);
        render_public_layout('Ödeme tercihi', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Ödeme tercihi</p>
                <h1>Kayıt bulunamadı.</h1>
                <p class="muted">Bu ödeme tercihi için sistemde aktif kayıt bulunamadı.</p>
            </section>
            <?php
        });
        return;
    }

    $totalAmount = (float) (($renewal['item_total'] ?? 0) ?: ($renewal['amount'] ?? 0));
    $currency = (string) ($renewal['currency'] ?? 'TRY');
    $exchangeRates = ExchangeRates::latest();
    $selectedNotice = renewal_payment_selected_notice($renewal, $linkEmail);

    render_public_layout('Teşekkürler', static function () use ($renewal, $totalAmount, $currency, $exchangeRates, $selectedNotice): void {
        ?>
        <section class="public-card payment-result-card success payment-thanks-card">
            <p class="eyebrow">Ödeme tercihi</p>
            <h1>Teşekkür ederiz.</h1>
            <p><?= h($selectedNotice) ?></p>
            <div class="payment-result-summary">
                <span>Müşteri</span>
                <strong><?= h($renewal['company_name'] ?? '-') ?></strong>
                <span>Kayıt</span>
                <strong><?= h($renewal['item_summary'] ?? $renewal['title'] ?? '-') ?></strong>
                <span>Ödeme tercihi</span>
                <strong><?= h(renewal_payment_label($renewal)) ?></strong>
                <span>Toplam</span>
                <strong><?= h(money_format_local($totalAmount > 0 ? $totalAmount : null, $currency)) ?> KDV dahil</strong>
            </div>
            <?= render_payment_exchange_panel($totalAmount, $currency, $exchangeRates) ?>
            <p class="muted compact">Bu sayfayı kapatabilirsiniz.</p>
        </section>
        <?php
    });
}

function handle_public_renewal_summary(int $renewalId): void
{
    $expires = (string) ($_GET['expires'] ?? '');
    $signature = (string) ($_GET['sig'] ?? '');
    $linkEmail = public_payment_link_email();

    if (!PaymentLink::isValid($renewalId, $expires, $signature, $linkEmail)) {
        http_response_code(403);
        render_public_layout('Yenileme özeti', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Yenileme özeti</p>
                <h1>Bağlantı geçersiz.</h1>
                <p class="muted">PDF / özet bağlantısının süresi dolmuş olabilir. Lütfen firma yetkilisiyle iletişime geçin.</p>
            </section>
            <?php
        });
        return;
    }

    $repo = new RenewalRepository();
    $renewal = $repo->find($renewalId);
    if (!$renewal) {
        http_response_code(404);
        render_public_layout('Yenileme özeti', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Yenileme özeti</p>
                <h1>Kayıt bulunamadı.</h1>
                <p class="muted">Bu bağlantı için sistemde aktif kayıt bulunamadı.</p>
            </section>
            <?php
        });
        return;
    }

    $items = $repo->renewalItems($renewalId);
    $totalAmount = (float) (($renewal['item_total'] ?? 0) ?: ($renewal['amount'] ?? 0));
    $currency = (string) ($renewal['currency'] ?? 'TRY');
    $paymentUrl = PaymentLink::urlForRenewal($renewalId, 60, $linkEmail);
    $directCardUrl = $paymentUrl . (str_contains($paymentUrl, '?') ? '&' : '?') . 'method=credit_card';

    render_public_layout('Yenileme özeti', static function () use ($renewal, $items, $totalAmount, $currency, $directCardUrl): void {
        $days = days_until($renewal['renewal_date'] ?? null);
        $daysLabel = $days === null ? '-' : ($days < 0 ? abs($days) . ' gün geçti' : $days . ' gün');
        ?>
        <section class="public-card renewal-public-summary">
            <div class="summary-print-actions">
                <button type="button" class="button secondary" onclick="window.print()">PDF olarak kaydet / yazdır</button>
                <a class="button primary" href="<?= h($directCardUrl) ?>">Kredi kartı ile hemen öde</a>
            </div>

            <div class="login-heading">
                <p class="eyebrow">Yenileme özeti</p>
                <h1><?= h((string) ($renewal['company_name'] ?? '-')) ?></h1>
                <p class="muted compact"><?= h((string) (($renewal['item_summary'] ?? '') ?: ($renewal['title'] ?? '-'))) ?> kaydı için yenileme bilgileri.</p>
            </div>

            <div class="payment-choice-summary">
                <div>
                    <span>Yenileme tarihi</span>
                    <strong><?= h(!empty($renewal['renewal_date']) ? date('d.m.Y', strtotime((string) $renewal['renewal_date'])) : '-') ?></strong>
                </div>
                <div>
                    <span>Kalan süre</span>
                    <strong><?= h($daysLabel) ?></strong>
                </div>
                <div>
                    <span>Toplam tutar</span>
                    <strong><?= h(money_format_local($totalAmount > 0 ? $totalAmount : null, $currency)) ?></strong>
                    <em>KDV dahil</em>
                </div>
                <div>
                    <span>Ödeme şekli</span>
                    <strong><?= h(renewal_payment_label($renewal)) ?></strong>
                </div>
            </div>

            <?= render_public_renewal_items($items, $currency) ?>

            <?php if (!empty($renewal['definition_notification_info'])): ?>
                <div class="definition-info-note public-summary-note">
                    <span>Bilgilendirme</span>
                    <p><?= nl2br(h((string) $renewal['definition_notification_info']), false) ?></p>
                </div>
            <?php endif; ?>
        </section>
        <?php
    });
}

function handle_public_renewal_read(int $renewalId): void
{
    $token = trim((string) ($_GET['token'] ?? ''));
    $repo = new RenewalRepository();
    $read = $repo->markNotificationDeliveryRead(
        $renewalId,
        $token,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    if (!$read) {
        http_response_code(404);
        render_public_layout('Okundu bilgisi', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Bildirim okundu</p>
                <h1>Bağlantı bulunamadı.</h1>
                <p class="muted">Bu okundu bağlantısı geçersiz olabilir. Lütfen size gelen son bilgilendirme mailindeki bağlantıyı kullanın.</p>
            </section>
            <?php
        });
        return;
    }

    render_public_layout('Okundu bilgisi', static function () use ($read): void {
        ?>
        <section class="public-card payment-result-card success">
            <p class="eyebrow">Bildirim okundu</p>
            <h1>Teşekkür ederiz.</h1>
            <p>Yenileme bildirimi okundu olarak kaydedildi.</p>
            <div class="payment-result-summary">
                <span>Müşteri</span>
                <strong><?= h((string) ($read['company_name'] ?? '-')) ?></strong>
                <span>Kayıt</span>
                <strong><?= h((string) (($read['item_summary'] ?? '') ?: ($read['title'] ?? '-'))) ?></strong>
                <span>Okuyan</span>
                <strong><?= h((string) (($read['recipient_name'] ?? '') ?: ($read['recipient_email'] ?? '-'))) ?></strong>
                <span>E-posta</span>
                <strong><?= h((string) ($read['recipient_email'] ?? '-')) ?></strong>
                <span>Okunma zamanı</span>
                <strong><?= h(!empty($read['read_at']) ? date('d.m.Y H:i', strtotime((string) $read['read_at'])) : date('d.m.Y H:i')) ?></strong>
            </div>
            <p class="muted compact">Bu sayfayı kapatabilirsiniz.</p>
        </section>
        <?php
    });
}

function public_payment_link_email(): string
{
    return PaymentLink::normalizeEmail((string) ($_GET['email'] ?? $_POST['email'] ?? ''));
}

function public_payment_link_query(string $expires, string $signature, string $email = ''): string
{
    $query = [
        'expires' => $expires,
        'sig' => $signature,
    ];

    if ($email !== '') {
        $query['email'] = $email;
    }

    return http_build_query($query);
}

function renewal_summary_url(int $renewalId, int $ttlDays = 60, string $recipientEmail = ''): string
{
    $signedPaymentUrl = PaymentLink::urlForRenewal($renewalId, $ttlDays, $recipientEmail);
    $query = parse_url($signedPaymentUrl, PHP_URL_QUERY);

    return url('/renewals/' . $renewalId . '/summary') . ($query ? '?' . $query : '');
}

function renewal_payment_choice_completed(array $renewal): bool
{
    return trim((string) ($renewal['payment_method'] ?? '')) !== ''
        && empty($renewal['payment_customer_choice']);
}

function renewal_payment_selected_notice(array $renewal, string $linkEmail = ''): string
{
    $selectedEmail = PaymentLink::normalizeEmail((string) (($renewal['payment_selected_email'] ?? '') ?: $linkEmail));
    if ($selectedEmail !== '') {
        return 'Bu ödeme tercihi daha önce ' . $selectedEmail . ' adresine gelen bağlantı üzerinden seçildi. Teşekkür ederiz.';
    }

    return 'Ödeme tercihiniz alınmış görünüyor. Ekibimiz yenileme işlemini bu tercihe göre takip edecek. Teşekkür ederiz.';
}

function payment_method_row_by_name(array $methods, string $name): ?array
{
    foreach ($methods as $method) {
        if (trim((string) $method['name']) === $name) {
            return $method;
        }
    }

    return null;
}

function payment_method_is_credit_card(string $name): bool
{
    $name = mb_strtolower($name);

    return str_contains($name, 'kredi') && str_contains($name, 'kart');
}

function first_credit_card_payment_method(array $methods): ?array
{
    foreach ($methods as $method) {
        if (payment_method_is_credit_card((string) ($method['name'] ?? ''))) {
            return $method;
        }
    }

    return null;
}

function payment_method_is_bank_transfer(string $name): bool
{
    $name = mb_strtolower($name);

    return str_contains($name, 'havale') || str_contains($name, 'eft');
}

function save_bank_transfer_receipt_upload(int $renewalId): array
{
    $file = $_FILES['payment_receipt'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Havale / EFT icin makbuz veya dekont yukleyin.');
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Makbuz yuklenemedi. Lutfen dosyayi kontrol edin.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Makbuz dosyasi okunamadi.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > 8 * 1024 * 1024) {
        throw new RuntimeException('Makbuz dosyasi en fazla 8 MB olabilir.');
    }

    $originalName = sanitize_uploaded_filename((string) ($file['name'] ?? 'makbuz'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Makbuz dosyasi PDF, JPG, PNG veya WebP olmalidir.');
    }

    $mimeType = bank_transfer_receipt_mime_type($tmpPath, $extension);
    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('Makbuz dosya turu desteklenmiyor.');
    }

    $dir = ROOT_PATH . '/storage/payment_receipts';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $storedName = 'renewal_' . $renewalId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
    $storedPath = $dir . '/' . $storedName;
    if (!move_uploaded_file($tmpPath, $storedPath)) {
        throw new RuntimeException('Makbuz dosyasi kaydedilemedi.');
    }
    @chmod($storedPath, 0640);

    return [
        'original_name' => $originalName,
        'stored_path' => $storedPath,
        'mime_type' => $mimeType,
        'file_size' => $size,
    ];
}

function sanitize_uploaded_filename(string $name): string
{
    $name = trim(basename(str_replace('\\', '/', $name)));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'makbuz';
    $name = trim($name, " .\t\n\r\0\x0B");

    return $name !== '' ? mb_substr($name, 0, 160) : 'makbuz';
}

function bank_transfer_receipt_mime_type(string $path, string $extension): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mimeType = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }
    }

    return match ($extension) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

function bank_transfer_notification_recipients(array $settings): array
{
    $raw = (string) (($settings['bank_transfer.notify_emails'] ?? '') ?: ($settings['mail.from_email'] ?? ''));
    $parts = preg_split('/[\s,;]+/', $raw) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = trim($part);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[strtolower($email)] = $email;
        }
    }

    return array_values($emails);
}

function send_bank_transfer_receipt_notification(
    RenewalRepository $repo,
    array $renewal,
    float $amount,
    string $currency,
    string $paymentMethod,
    string $payerEmail,
    string $note,
    array $receipt,
    array $recipients
): array {
    if ($recipients === []) {
        return ['status' => 'failed', 'error' => 'Havale/EFT bildirim alicisi tanimli degil.'];
    }

    $subject = 'Havale/EFT makbuzu: ' . (string) ($renewal['company_name'] ?? 'Musteri');
    $body = bank_transfer_receipt_mail_body($renewal, $amount, $currency, $paymentMethod, $payerEmail, $note, $receipt);
    $attachments = [[
        'path' => (string) $receipt['stored_path'],
        'name' => (string) $receipt['original_name'],
        'content_type' => (string) $receipt['mime_type'],
    ]];

    $sent = 0;
    $errors = [];
    foreach ($recipients as $recipient) {
        $result = Mailer::sendWithResult($recipient, $subject, $body, true, [], $attachments);
        if (!empty($result['ok'])) {
            $sent++;
        } else {
            $errors[] = $recipient . ': ' . (string) ($result['error'] ?? 'Bilinmeyen hata');
        }
    }

    if ($sent === count($recipients)) {
        return ['status' => 'sent', 'error' => ''];
    }

    if ($sent > 0) {
        return ['status' => 'partial', 'error' => implode(' | ', $errors)];
    }

    return ['status' => 'failed', 'error' => implode(' | ', $errors)];
}

function bank_transfer_receipt_mail_body(array $renewal, float $amount, string $currency, string $paymentMethod, string $payerEmail, string $note, array $receipt): string
{
    $rows = [
        'Müşteri' => (string) ($renewal['company_name'] ?? '-'),
        'Kayıt' => (string) (($renewal['item_summary'] ?? '') ?: ($renewal['title'] ?? '-')),
        'Ödeme yöntemi' => $paymentMethod,
        'Tutar' => money_format_local($amount > 0 ? $amount : null, $currency) . ' KDV dahil',
        'Yenileme tarihi' => !empty($renewal['renewal_date']) ? date('d.m.Y', strtotime((string) $renewal['renewal_date'])) : '-',
        'Link e-postası' => $payerEmail !== '' ? $payerEmail : '-',
        'Makbuz dosyası' => (string) ($receipt['original_name'] ?? '-'),
        'Dosya boyutu' => payment_receipt_human_size((int) ($receipt['file_size'] ?? 0)),
        'Not' => $note !== '' ? $note : '-',
    ];

    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;width:34%;">' . h($label) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . nl2br(h($value), false) . '</td>'
            . '</tr>';
    }

    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f2f6f4;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d9e3df;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Havale / EFT bildirimi</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">Müşteri makbuz gönderdi.</h1>'
        . '<p style="margin:0 0 18px;color:#607069;line-height:1.5;">Makbuz / dekont dosyası bu e-postaya eklenmiştir.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function payment_receipt_human_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }

    return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
}

function render_public_renewal_items(array $items, string $currency): string
{
    if ($items === []) {
        return '';
    }

    ob_start();
    ?>
    <div class="public-payment-items">
        <h2>Ürünler</h2>
        <?php foreach ($items as $item): ?>
            <?php
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = ($item['unit_price'] ?? null) === null ? null : (float) $item['unit_price'];
            $vatRate = (float) ($item['vat_rate'] ?? 0);
            $net = $unitPrice === null ? null : $quantity * $unitPrice;
            $lineTotal = $net === null ? null : $net + ($net * $vatRate / 100);
            ?>
            <div class="public-payment-item">
                <div>
                    <strong><?= h($item['title'] ?? '-') ?></strong>
                    <span><?= h(($item['brand'] ?? '') ?: '-') ?></span>
                </div>
                <div>
                    <span><?= h(number_format($quantity, 2, ',', '.')) ?> adet</span>
                    <strong><?= h(money_format_local($lineTotal, $currency)) ?></strong>
                    <em>KDV %<?= h(number_format($vatRate, 2, ',', '.')) ?> dahil</em>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

function render_customer_offer_lines_public(array $lines, string $currency): string
{
    if ($lines === []) {
        return '<div class="empty">Teklif satırı bulunamadı.</div>';
    }

    ob_start();
    ?>
    <div class="public-payment-items customer-offer-lines">
        <h2>Teklif kalemleri</h2>
        <?php foreach ($lines as $line): ?>
            <?php
            $quantity = (float) ($line['quantity'] ?? 1);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $subtotal = (float) ($line['line_subtotal'] ?? ($quantity * $unitPrice));
            $vatRate = (float) ($line['vat_rate'] ?? 0);
            $total = (float) ($line['line_total'] ?? ($subtotal + ($subtotal * $vatRate / 100)));
            ?>
            <div class="public-payment-item customer-offer-line">
                <div>
                    <strong><?= h((string) ($line['item_title'] ?? '-')) ?></strong>
                    <span><?= h(number_format($quantity, 2, ',', '.')) ?> adet</span>
                </div>
                <div class="price-breakdown">
                    <span>Birim fiyat: <b><?= h(money_format_local($unitPrice, $currency)) ?></b></span>
                    <span>Toplam: <b><?= h(money_format_local($subtotal, $currency)) ?></b></span>
                    <span>KDV'li fiyat: <b><?= h(money_format_local($total, $currency)) ?></b></span>
                    <em>KDV %<?= h(number_format($vatRate, 2, ',', '.')) ?></em>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function send_supplier_customer_offer_approval_emails(RenewalRepository $repo, array $offer, array $lines): array
{
    $sent = 0;
    $failed = 0;
    foreach ($lines as $line) {
        $email = trim((string) ($line['supplier_recipient_email'] ?? ''));
        if ($email === '') {
            continue;
        }

        $selected = [
            'renewal_id' => (int) ($offer['renewal_id'] ?? 0),
            'recipient_email' => $email,
            'contact_name' => (string) ($line['supplier_contact_name'] ?? ''),
            'item_title' => (string) ($line['item_title'] ?? ''),
            'price' => $line['supplier_price'] ?? $line['unit_price'] ?? 0,
            'currency' => (string) (($line['supplier_currency'] ?? '') ?: ($offer['currency'] ?? 'TRY')),
            'term' => (string) ($line['supplier_term'] ?? ''),
            'custom_term' => (string) ($line['supplier_custom_term'] ?? ''),
            'vat_included' => (int) ($line['supplier_vat_included'] ?? 1),
            'delivery_note' => (string) ($line['supplier_delivery_note'] ?? ''),
            'note' => (string) ($line['supplier_note'] ?? ''),
        ];
        $result = send_supplier_quote_selection_email($repo, $selected);
        !empty($result['ok']) ? $sent++ : $failed++;
    }

    return ['sent' => $sent, 'failed' => $failed];
}

function render_payment_exchange_panel(float $amount, string $currency, array $exchangeRates): string
{
    $currency = strtoupper(trim($currency));
    $usdRate = payment_exchange_rate($exchangeRates, 'USD');
    $currencyRate = $currency === 'TRY' ? 1.0 : payment_exchange_rate($exchangeRates, $currency);
    $tlAmount = $amount > 0 && $currencyRate !== null ? $amount * $currencyRate : null;
    $rateNote = $currency === 'TRY'
        ? 'Tutar TL olarak belirlenmiş.'
        : ($currencyRate !== null ? $currency . ' satış kuru ile hesaplandı.' : $currency . ' için TL kuru alınamadı.');

    ob_start();
    ?>
    <div class="payment-fx-panel">
        <div>
            <span>Dolar kuru</span>
            <strong><?= h($usdRate !== null ? format_exchange_rate($usdRate) : '-') ?></strong>
            <em>TCMB satış</em>
        </div>
        <div>
            <span>TL karşılığı</span>
            <strong><?= h($tlAmount !== null ? money_format_local($tlAmount, 'TRY') : '-') ?></strong>
            <em><?= h($rateNote) ?></em>
        </div>
        <p><?= h(exchange_rate_date_label($exchangeRates)) ?></p>
    </div>
    <?php

    return (string) ob_get_clean();
}

function payment_exchange_rate(array $exchangeRates, string $currency): ?float
{
    $currency = strtoupper(trim($currency));
    if ($currency === 'TRY') {
        return 1.0;
    }

    $rate = $exchangeRates['rates'][$currency] ?? null;
    if (!is_array($rate)) {
        return null;
    }

    $value = $rate['selling'] ?? $rate['buying'] ?? null;

    return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
}

function normalize_allowed_currency(mixed $currency): string
{
    $currency = strtoupper(trim((string) $currency));

    return in_array($currency, allowed_currency_options(), true) ? $currency : 'TRY';
}

function allowed_currency_options(): array
{
    return ['TRY', 'USD', 'EUR'];
}

function create_iyzico_checkout_url(RenewalRepository $repo, array $renewal, float $amount, string $currency, ?int $createdBy, string $source): string
{
    $client = new IyzicoClient(new SettingsRepository());
    if (!$client->isEnabled() || !$client->isConfigured()) {
        throw new RuntimeException('Kredi kartı ödemesi şu anda aktif değil. Lütfen firma yetkilisiyle iletişime geçin.');
    }

    $renewalId = (int) $renewal['id'];
    $currency = normalize_allowed_currency($currency);

    $conversationId = $source . '-renewal-' . $renewalId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $result = $client->initializeCheckout($renewal, $amount, $currency, $conversationId);
    $response = $result['response'];
    $paymentPageUrl = trim((string) ($response['paymentPageUrl'] ?? ''));
    $token = trim((string) ($response['token'] ?? ''));
    $ok = (string) ($response['status'] ?? '') === 'success' && $paymentPageUrl !== '' && $token !== '';

    $repo->createIyzicoPayment([
        'renewal_id' => $renewalId,
        'conversation_id' => $conversationId,
        'token' => $token,
        'amount' => $amount,
        'currency' => $currency,
        'status' => $ok ? 'pending' : 'failed',
        'payment_page_url' => $paymentPageUrl,
        'error_message' => $ok ? '' : ((string) ($response['errorMessage'] ?? 'iyzico odeme linki olusturulamadi.')),
        'raw_request' => $result['request'],
        'raw_response' => $response,
        'created_by' => $createdBy,
    ]);

    if (!$ok) {
        throw new RuntimeException((string) ($response['errorMessage'] ?? 'iyzico odeme linki olusturulamadi.'));
    }

    return $paymentPageUrl;
}

function handle_iyzico_callback(string $method): void
{
    $token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(422);
        render_public_layout('Odeme sonucu', static function (): void {
            ?>
            <section class="public-card payment-result-card">
                <p class="eyebrow">iyzico</p>
                <h1>Odeme sonucu okunamadi.</h1>
                <p class="muted">iyzico token bilgisi gelmedi. Odeme sayfasindan tekrar deneyin.</p>
            </section>
            <?php
        });
        return;
    }

    $repo = new RenewalRepository();
    $payment = $repo->findIyzicoPaymentByToken($token);
    if (!$payment) {
        http_response_code(404);
        render_public_layout('Odeme sonucu', static function (): void {
            ?>
            <section class="public-card payment-result-card">
                <p class="eyebrow">iyzico</p>
                <h1>Odeme kaydi bulunamadi.</h1>
                <p class="muted">Bu token icin sistemde kayit yok. Lutfen firma yetkilisine bilgi verin.</p>
            </section>
            <?php
        });
        return;
    }

    $request = [];
    $response = [];
    $localStatus = 'failed';
    $message = 'Odeme sonucu alinamadi.';

    try {
        $client = new IyzicoClient(new SettingsRepository());
        $result = $client->retrieveCheckout($token, (string) $payment['conversation_id']);
        $request = $result['request'];
        $response = $result['response'];
        $localStatus = iyzico_local_status($response);
        $message = iyzico_result_message($localStatus, $response);
        $repo->updateIyzicoPaymentResult((int) $payment['id'], $request, $response, $localStatus);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $repo->updateIyzicoPaymentResult((int) $payment['id'], $request, ['errorMessage' => $message], 'failed');
    }

    $exchangeRates = ExchangeRates::latest();

    render_public_layout('Odeme sonucu', static function () use ($payment, $localStatus, $message, $exchangeRates): void {
        $tone = $localStatus === 'paid' ? 'success' : ($localStatus === 'review' ? 'warning' : 'error');
        $amount = (float) ($payment['amount'] ?? 0);
        $currency = (string) ($payment['currency'] ?? 'TRY');
        ?>
        <section class="public-card payment-result-card <?= h($tone) ?>">
            <p class="eyebrow">iyzico odeme sonucu</p>
            <h1><?= $localStatus === 'paid' ? 'Odeme alindi.' : 'Odeme tamamlanamadi.' ?></h1>
            <p><?= h($message) ?></p>
            <div class="payment-result-summary">
                <span>Musteri</span>
                <strong><?= h($payment['company_name'] ?? '-') ?></strong>
                <span>Kayit</span>
                <strong><?= h($payment['title'] ?? '-') ?></strong>
                <span>Tutar</span>
                <strong><?= h(money_format_local($payment['amount'] ?? null, $currency)) ?></strong>
            </div>
            <?= render_payment_exchange_panel($amount, $currency, $exchangeRates) ?>
        </section>
        <?php
    });
}

function iyzico_local_status(array $response): string
{
    if ((string) ($response['status'] ?? '') !== 'success') {
        return 'failed';
    }

    $paymentStatus = strtoupper((string) ($response['paymentStatus'] ?? ''));
    $fraudStatus = (string) ($response['fraudStatus'] ?? '');

    if ($paymentStatus === 'SUCCESS' && ($fraudStatus === '' || $fraudStatus === '1')) {
        return 'paid';
    }

    if ($paymentStatus === 'SUCCESS') {
        return 'review';
    }

    return $paymentStatus === '' ? 'pending' : 'failed';
}

function iyzico_result_message(string $status, array $response): string
{
    if ($status === 'paid') {
        return 'Kredi karti odemesi basariyla tamamlandi.';
    }

    if ($status === 'review') {
        return 'Odeme alindi fakat iyzico tarafinda kontrol bekliyor.';
    }

    if (!empty($response['errorMessage'])) {
        return (string) $response['errorMessage'];
    }

    return 'Odeme tamamlanamadi veya iptal edildi.';
}

function handle_push_public_key(): void
{
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $user = Auth::user();
        echo json_encode([
            'ok' => true,
            'publicKey' => WebPush::publicKey(),
            'activeCount' => WebPush::activeCount((int) ($user['id'] ?? 0)),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function handle_push_subscribe(): void
{
    verify_json_csrf();
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $user = Auth::user();
        $payload = json_payload();
        WebPush::saveSubscription((int) ($user['id'] ?? 0), $payload, $_SERVER['HTTP_USER_AGENT'] ?? '');
        echo json_encode(['ok' => true, 'activeCount' => WebPush::activeCount((int) ($user['id'] ?? 0))], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function handle_push_unsubscribe(): void
{
    verify_json_csrf();
    header('Content-Type: application/json; charset=UTF-8');
    $payload = json_payload();
    WebPush::deleteSubscription((string) ($payload['endpoint'] ?? ''));
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

function handle_push_test(): void
{
    verify_json_csrf();
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $user = Auth::user();
        $result = WebPush::sendToUser((int) ($user['id'] ?? 0), [
            'title' => 'Test bildirimi',
            'body' => 'Web push bildirimi çalışıyor.',
            'url' => '/settings#notification-settings',
        ]);
        $message = $result['sent'] > 0
            ? 'Test bildirimi gonderildi.'
            : ($result['total'] < 1
                ? 'Aktif web push aboneligi bulunamadi.'
                : 'Push servisi reddetti. Kod: ' . (string) ($result['last_status'] ?? 0));
        echo json_encode(['ok' => $result['sent'] > 0, 'message' => $message, 'result' => $result], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function json_payload(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);

    return is_array($decoded) ? $decoded : [];
}

function verify_json_csrf(): void
{
    $posted = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $session = $_SESSION['_csrf_token'] ?? '';

    if (!is_string($session) || !hash_equals($session, $posted)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => 'Oturum dogrulamasi basarisiz.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function handle_login(string $method): void
{
    if (Auth::check()) {
        redirect('/');
    }

    $error = null;
    $exchangeRates = ExchangeRates::latest();

    if ($method === 'POST') {
        verify_csrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        try {
            $ipAddress = login_client_ip();
            $lock = login_ip_lock_status($ipAddress);
            if ($lock !== null) {
                $error = 'Bu IP adresi gecici olarak kilitlendi. Kalan sure: ' . human_login_lock_remaining($lock) . '.';
            } elseif (Auth::attempt($email, $password)) {
                clear_login_ip_attempts($ipAddress);
                flash('success', 'Hos geldiniz.');
                redirect(first_allowed_path() ?? '/');
            } else {
                $attempt = record_failed_login_ip_attempt($ipAddress, $email);
                if ($attempt['locked']) {
                    $error = (int) $attempt['failed_count'] >= 10
                        ? '10 hatali giris nedeniyle bu IP adresi 1 gun kilitlendi.'
                        : '2 hatali giris nedeniyle bu IP adresi 10 dakika kilitlendi.';
                } else {
                    $error = 'E-posta veya sifre hatali.';
                }
            }
        } catch (Throwable $e) {
            $error = 'Veritabani baglantisi kurulamadi. Kurulum adimlarini kontrol edin.';
        }
    }

    render_public_layout('Giris', static function () use ($error, $exchangeRates): void {
        ?>
        <section class="login-panel">
            <div class="login-heading">
                <p class="eyebrow">Güvenli panel girişi</p>
                <h1>Hızlı Takip ve Teklif Platformu</h1>
                <p class="login-subtitle">Ürün, Hizmet Yenileme ve Teklif Süreçlerinizi tek panelden takip edin</p>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <label>
                    E-posta adresi
                    <input type="email" name="email" value="<?= h(old('email')) ?>" required autofocus>
                </label>
                <label>
                    Şifre
                    <input type="password" name="password" value="" required>
                </label>
                <button type="submit" class="button primary">Panele giriş yap</button>
                <a class="login-help-link" href="<?= h(url('/forgot-password')) ?>">
                    <?= login_fish_icon('login-help-icon') ?>
                    <span>Şifremi unuttum</span>
                </a>
            </form>

        </section>

        <div class="login-rates">
            <?= render_exchange_rates($exchangeRates, 'compact') ?>
        </div>
        <?php
    });
}

function login_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $ip !== '' ? substr($ip, 0, 45) : '0.0.0.0';
}

function ensure_login_ip_attempts_schema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    Database::connection()->exec(
        "CREATE TABLE IF NOT EXISTS login_ip_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            last_email VARCHAR(190) NULL,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            first_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_login_ip_attempts_ip (ip_address),
            INDEX idx_login_ip_attempts_locked_until (locked_until),
            INDEX idx_login_ip_attempts_last_failed_at (last_failed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

function login_ip_lock_status(string $ipAddress): ?DateTimeImmutable
{
    ensure_login_ip_attempts_schema();

    $stmt = Database::connection()->prepare(
        'SELECT locked_until FROM login_ip_attempts WHERE ip_address = :ip_address AND locked_until > NOW() LIMIT 1'
    );
    $stmt->execute(['ip_address' => $ipAddress]);
    $lockedUntil = $stmt->fetchColumn();

    if (!$lockedUntil) {
        return null;
    }

    return new DateTimeImmutable((string) $lockedUntil);
}

function record_failed_login_ip_attempt(string $ipAddress, string $email): array
{
    ensure_login_ip_attempts_schema();

    $stmt = Database::connection()->prepare(
        "INSERT INTO login_ip_attempts
            (ip_address, last_email, failed_count, locked_until, first_failed_at, last_failed_at)
         VALUES
            (:ip_address, :last_email, 1, NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            last_email = VALUES(last_email),
            failed_count = failed_count + 1,
            locked_until = CASE
                WHEN failed_count + 1 >= 10 THEN DATE_ADD(NOW(), INTERVAL 1 DAY)
                WHEN failed_count + 1 >= 2 THEN DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                ELSE locked_until
            END,
            last_failed_at = NOW(),
            updated_at = NOW()"
    );
    $stmt->execute([
        'ip_address' => $ipAddress,
        'last_email' => $email !== '' ? substr($email, 0, 190) : null,
    ]);

    $status = Database::connection()->prepare(
        'SELECT failed_count, locked_until FROM login_ip_attempts WHERE ip_address = :ip_address LIMIT 1'
    );
    $status->execute(['ip_address' => $ipAddress]);
    $row = $status->fetch() ?: ['failed_count' => 1, 'locked_until' => null];
    $lockedUntil = (string) ($row['locked_until'] ?? '');

    return [
        'failed_count' => (int) ($row['failed_count'] ?? 1),
        'locked' => $lockedUntil !== '' && new DateTimeImmutable($lockedUntil) > new DateTimeImmutable('now'),
        'locked_until' => $lockedUntil,
    ];
}

function clear_login_ip_attempts(string $ipAddress): void
{
    ensure_login_ip_attempts_schema();

    $stmt = Database::connection()->prepare('DELETE FROM login_ip_attempts WHERE ip_address = :ip_address');
    $stmt->execute(['ip_address' => $ipAddress]);
}

function human_login_lock_remaining(DateTimeImmutable $lockedUntil): string
{
    $seconds = max(1, $lockedUntil->getTimestamp() - time());

    if ($seconds >= 3600) {
        $hours = (int) ceil($seconds / 3600);
        return $hours . ' saat';
    }

    $minutes = (int) ceil($seconds / 60);
    return $minutes . ' dakika';
}

function handle_forgot_password(string $method): void
{
    if (Auth::check()) {
        redirect('/');
    }

    $error = null;

    if ($method === 'POST') {
        verify_csrf();
        $email = trim((string) ($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Gecerli bir e-posta adresi girin.';
        } else {
            try {
                $repo = new UserRepository();
                $user = $repo->findByEmail($email, true);

                if ($user) {
                    $temporaryPassword = temporary_password();
                    $oldHash = (string) ($user['password_hash'] ?? '');
                    $repo->updatePassword((int) $user['id'], $temporaryPassword);

                    $result = send_user_account_mail($user, $temporaryPassword, 'reset');
                    if (!$result['ok']) {
                        if ($oldHash !== '') {
                            $repo->setPasswordHash((int) $user['id'], $oldHash);
                        }

                        $error = 'Mail gonderilemedi, sifre degistirilmedi: ' . (string) $result['error'];
                    }
                }

                if ($error === null) {
                    flash('success', 'E-posta sistemde kayitli ve aktifse gecici sifre mail olarak gonderildi.');
                    redirect('/login');
                }
            } catch (Throwable $e) {
                $error = 'Sifre yenileme islemi tamamlanamadi: ' . $e->getMessage();
            }
        }
    }

    render_public_layout('Sifremi Unuttum', static function () use ($error): void {
        ?>
        <section class="login-panel">
            <div class="login-heading">
                <?= login_fish_icon() ?>
                <p class="eyebrow">Sifre yenileme</p>
                <h1>Gecici yeni sifre gonderelim.</h1>
                <p class="muted compact">Kayitli aktif kullanici icin yeni sifre mail olarak gonderilir.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <label>
                    E-posta
                    <input type="email" name="email" value="<?= h(old('email')) ?>" required autofocus>
                </label>
                <button type="submit" class="button primary">Gecici sifre gonder</button>
                <a class="button secondary" href="<?= h(url('/login')) ?>">Giris paneline don</a>
            </form>
        </section>
        <?php
    });
}

function login_fish_icon(string $extraClass = ''): string
{
    $class = trim('login-fish-icon ' . $extraClass);

    return <<<HTML
<span class="{$class}" aria-hidden="true">
    <svg viewBox="0 0 64 64" focusable="false">
        <path d="M8 32s10-14 27-14c12 0 21 7 25 14-4 7-13 14-25 14C18 46 8 32 8 32Z"></path>
        <path d="M52 24v16l8-8-8-8Z"></path>
        <circle cx="24" cy="29" r="2.5"></circle>
        <path d="M36 20c-4 3-4 21 0 24"></path>
    </svg>
</span>
HTML;
}

function temporary_password(int $length = 10): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    $max = strlen($alphabet) - 1;

    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }

    return $password;
}

function send_user_account_mail(array $user, string $plainPassword, string $type = 'created'): array
{
    $name = trim((string) ($user['name'] ?? ''));
    $email = trim((string) ($user['email'] ?? ''));
    $role = (string) ($user['role'] ?? 'staff');
    $isActive = (int) ($user['is_active'] ?? 1) === 1;
    $appName = (string) app_config('app.name', 'Yenileme Takip Sistemi');
    $loginUrl = absolute_app_url('/login');
    $title = $type === 'reset' ? 'Şifreniz yenilendi' : 'Kullanıcı hesabınız oluşturuldu';
    $lead = $type === 'reset'
        ? 'Panel girişiniz için geçici yeni şifre oluşturuldu.'
        : 'Panel giriş bilgileriniz aşağıdadır.';

    $body = '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;padding:24px;">'
        . '<tr><td>'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">' . h($appName) . '</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;color:#17201c;">' . h($title) . '</h1>'
        . '<p style="margin:0 0 18px;color:#607069;line-height:1.5;">Merhaba ' . h($name ?: $email) . ', ' . h($lead) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:18px 0;">'
        . user_mail_row('Giriş adresi', '<a href="' . h($loginUrl) . '" style="color:#147c72;">' . h($loginUrl) . '</a>')
        . user_mail_row('E-posta', h($email))
        . user_mail_row('Şifre', '<strong style="font-size:18px;color:#0f625b;">' . h($plainPassword) . '</strong>')
        . user_mail_row('Rol', h($role === 'admin' ? 'Admin' : 'Personel'))
        . user_mail_row('Hesap durumu', h($isActive ? 'Aktif' : 'Pasif'))
        . '</table>'
        . '<p style="margin:18px 0 0;color:#607069;line-height:1.5;">Bu bilgileri güvenli şekilde saklayın. Hesap pasifse giriş için yöneticinizin hesabı aktif etmesi gerekir.</p>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</body></html>';

    return Mailer::sendWithResult($email, $appName . ' - ' . $title, $body, true);
}

function user_mail_row(string $label, string $value): string
{
    return '<tr>'
        . '<td style="padding:10px 0;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;width:38%;">' . h($label) . '</td>'
        . '<td style="padding:10px 0;border-bottom:1px solid #d8e0dd;color:#17201c;">' . $value . '</td>'
        . '</tr>';
}

function handle_customer_info_public(string $method, string $token): void
{
    $requestRepo = new CustomerInfoRequestRepository();
    $request = $requestRepo->findByToken($token);
    $error = null;
    $notice = null;
    $values = customer_info_form_values([], (string) ($request['recipient_email'] ?? ''), (string) ($request['recipient_name'] ?? ''));
    $extracted = [];

    if (!$request) {
        render_public_layout('Cari Bilgi Formu', static function (): void {
            echo '<section class="login-panel"><div class="alert error">Cari bilgi talebi bulunamadi veya baglanti gecersiz.</div></section>';
        });
        return;
    }

    if (($request['status'] ?? '') !== 'pending') {
        render_public_layout('Cari Bilgi Formu', static function () use ($request): void {
            $message = ($request['status'] ?? '') === 'submitted'
                ? 'Cari bilgileriniz alinmistir. Tesekkur ederiz.'
                : 'Bu baglantinin suresi dolmus.';
            echo '<section class="login-panel"><div class="alert success">' . h($message) . '</div></section>';
        });
        return;
    }

    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'submit_request');
        $values = customer_info_form_values($_POST, (string) $request['recipient_email'], (string) ($request['recipient_name'] ?? ''));

        try {
            if ($action === 'analyze_certificate') {
                TaxCertificateAnalyzer::assertValidUpload($_FILES['tax_certificate'] ?? []);
                $extracted = TaxCertificateAnalyzer::analyze((string) ($_FILES['tax_certificate']['tmp_name'] ?? ''));
                if (!tax_certificate_has_customer_fields($extracted)) {
                    throw new RuntimeException('Vergi levhasindaki bilgiler otomatik okunamadi. PDF metin tabanli degilse alanlari manuel doldurabilirsiniz.');
                }
                $values = merge_customer_info_values($values, $extracted);
                $notice = 'Vergi levhasi analiz edildi. Lutfen bilgileri kontrol edip gonderin.';
            } else {
                $uploadPath = null;
                if (isset($_FILES['tax_certificate']) && (int) ($_FILES['tax_certificate']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $uploadPath = TaxCertificateAnalyzer::saveUploaded($_FILES['tax_certificate'], 'cari-' . (int) $request['id']);
                    if (strtolower(pathinfo($uploadPath, PATHINFO_EXTENSION)) === 'pdf') {
                        $extracted = TaxCertificateAnalyzer::analyze(ROOT_PATH . '/' . $uploadPath);
                        $values = merge_customer_info_values($values, $extracted);
                    }
                }

                $errors = customer_info_public_errors($values);
                if ($errors !== []) {
                    throw new RuntimeException(implode(' ', $errors));
                }

                $customerId = $requestRepo->complete($request, $values, $uploadPath, $extracted);
                $notificationResult = send_customer_info_completed_notification($request, $customerId, $values);
                if (!$notificationResult['ok']) {
                    error_log('Cari bilgi tamamlandi bildirimi gonderilemedi: ' . (string) $notificationResult['error']);
                }
                render_public_layout('Cari Bilgi Formu', static function () use ($customerId): void {
                    ?>
                    <section class="login-panel">
                        <div class="alert success">Cari bilgileriniz alindi. Tesekkur ederiz.</div>
                        <p class="muted compact">Kayit numarasi: <?= h((string) $customerId) ?></p>
                    </section>
                    <?php
                });
                return;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    render_public_layout('Cari Bilgi Formu', static function () use ($request, $values, $error, $notice): void {
        ?>
        <section class="login-panel customer-info-public">
            <div class="login-heading">
                <p class="eyebrow">Cari bilgi formu</p>
                <h1>Firma bilgilerinizi tamamlayin.</h1>
                <p class="muted compact">Vergi levhanizi yukleyerek alanlari otomatik doldurabilir veya bilgileri manuel girebilirsiniz.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>
            <?php if ($notice): ?>
                <div class="alert success"><?= h($notice) ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="form-grid">
                <?= csrf_field() ?>
                <label class="span-2">
                    Vergi levhasi
                    <input type="file" name="tax_certificate" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg">
                    <span class="field-help">PDF vergi levhasinda unvan, VKN, vergi dairesi ve adres alanlari otomatik okunur.</span>
                </label>
                <button type="submit" name="action" value="analyze_certificate" class="button secondary span-2" formnovalidate>Vergi levhasini analiz et</button>

                <label>Firma adi <input name="company_name" value="<?= h($values['company_name']) ?>" required></label>
                <div class="contact-editor customer-info-contact-editor span-2" data-contact-editor data-next-index="<?= h((string) next_contact_index($values['contacts'])) ?>">
                    <div class="section-head">
                        <div>
                            <h3>Yetkililer</h3>
                            <span>Birden fazla yetkili ekleyebilirsiniz.</span>
                        </div>
                        <button type="button" class="button small secondary" data-add-contact>+ Yetkili ekle</button>
                    </div>
                    <div class="contact-list" data-contact-list>
                        <?php foreach ($values['contacts'] as $index => $contact): ?>
                            <?= render_contact_input_row((int) $index, $contact) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <label>Vergi dairesi <input name="tax_office" value="<?= h($values['tax_office']) ?>"></label>
                <label>Vergi no / TC kimlik no <input name="tax_number" value="<?= h($values['tax_number']) ?>"></label>
                <label>Il <input name="city" value="<?= h($values['city']) ?>"></label>
                <label>Ilce <input name="district" value="<?= h($values['district']) ?>"></label>
                <label class="span-2">Adres <textarea name="address" rows="3"><?= h($values['address']) ?></textarea></label>
                <label class="span-2">Not <textarea name="notes" rows="3"><?= h($values['notes']) ?></textarea></label>
                <button type="submit" name="action" value="submit_request" class="button primary span-2">Bilgileri gonder</button>
            </form>

            <p class="muted compact">Baglanti gecerlilik suresi: <?= h(date('d.m.Y H:i', strtotime((string) $request['expires_at']))) ?></p>
        </section>
        <?php
    });
}

function handle_supplier_quote_public(string $method, string $token): void
{
    $repo = new RenewalRepository();
    $request = $repo->findSupplierQuoteRequestByToken($token);
    $error = null;

    if (!$request) {
        render_public_layout('Tedarikci Teklif Formu', static function (): void {
            echo '<section class="login-panel"><div class="alert error">Teklif talebi bulunamadı veya bağlantı geçersiz.</div></section>';
        });
        return;
    }

    $items = $repo->renewalItems((int) $request['renewal_id']);
    if ($items === []) {
        $items = [[
            'id' => 0,
            'title' => $request['title'] ?? 'Ürün / hizmet',
            'brand' => $request['brand'] ?? '',
            'license_key' => $request['license_key'] ?? '',
            'quantity' => '1',
        ]];
    }

    $expired = strtotime((string) $request['expires_at']) < time();
    if ($expired && ($request['status'] ?? '') !== 'submitted') {
        render_public_layout('Tedarikci Teklif Formu', static function () use ($request): void {
            ?>
            <section class="login-panel supplier-quote-public">
                <div class="alert error">Bu teklif bağlantısının süresi dolmuş.</div>
                <p class="muted compact">Talep: <?= h((string) ($request['company_name'] ?? '-')) ?></p>
            </section>
            <?php
        });
        return;
    }

    if (($request['status'] ?? '') === 'submitted' && $method !== 'POST') {
        render_public_layout('Tedarikci Teklif Formu', static function () use ($request): void {
            ?>
            <section class="login-panel supplier-quote-public">
                <div class="alert success">Teklifiniz alınmış. Teşekkür ederiz.</div>
                <p class="muted compact"><?= h((string) ($request['company_name'] ?? '-')) ?> için teklif kaydınız panele işlendi.</p>
            </section>
            <?php
        });
        return;
    }

    $repo->markSupplierQuoteRequestOpened(
        (int) $request['id'],
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    if ($method === 'POST') {
        verify_csrf();

        try {
            if (empty($_POST['terms_acknowledged'])) {
                throw new RuntimeException('Teklifi göndermek için fiyat, vade, KDV, nakliye ve not onayını işaretleyin.');
            }

            $attachment = supplier_quote_save_upload($_FILES['supplier_quote_file'] ?? null, (int) $request['id']);
            if (!supplier_quote_has_payload($_POST, $attachment)) {
                throw new RuntimeException('En az bir fiyat yazın veya teklif dosyası/notu iletin.');
            }

            $submissionErrors = supplier_quote_submission_errors($_POST, $attachment);
            if ($submissionErrors !== []) {
                throw new RuntimeException(implode(' ', $submissionErrors));
            }

            $repo->submitSupplierQuote(
                (int) $request['id'],
                $_POST,
                $attachment,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );

            render_public_layout('Tedarikci Teklif Formu', static function () use ($request): void {
                ?>
                <section class="login-panel supplier-quote-public">
                    <div class="alert success">Teklifiniz alınmıştır. Teşekkür ederiz.</div>
                    <p class="muted compact"><?= h((string) ($request['company_name'] ?? '-')) ?> için gönderdiğiniz teklif sistemde kayıt altına alındı.</p>
                </section>
                <?php
            });
            return;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    render_public_layout('Tedarikci Teklif Formu', static function () use ($request, $items, $error): void {
        ?>
        <section class="login-panel supplier-quote-public">
            <div class="login-heading">
                <p class="eyebrow">Tedarikçi teklif formu</p>
                <h1>Fiyat teklifinizi iletin.</h1>
                <p class="muted compact">
                    Lütfen her kalem için peşin, 30 gün, 60 gün, çek veya özel vade fiyatlarını yazın.
                    Nakliye, KDV, teslim ve özel şartları not alanlarında belirtmeden teklif gönderilemez.
                </p>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="supplier-quote-context">
                <div><span>Müşteri</span><strong><?= h((string) ($request['company_name'] ?? '-')) ?></strong></div>
                <div><span>Tedarikçi</span><strong><?= h((string) (($request['supplier_display'] ?? '') ?: ($request['recipient_email'] ?? '-'))) ?></strong></div>
                <div><span>Yenileme tarihi</span><strong><?= h(!empty($request['renewal_date']) ? date('d.m.Y', strtotime((string) $request['renewal_date'])) : '-') ?></strong></div>
                <div><span>Talep edilen periyot</span><strong><?= h((string) (($request['renewal_period_name'] ?? '') ?: '-')) ?></strong></div>
            </div>

            <form method="post" enctype="multipart/form-data" class="supplier-quote-form">
                <?= csrf_field() ?>
                <input type="hidden" name="currency" value="<?= h((string) ($request['currency'] ?? 'TRY')) ?>">

                <?php foreach ($items as $item): ?>
                    <?= render_supplier_quote_item_form($item, (string) ($request['currency'] ?? 'TRY')) ?>
                <?php endforeach; ?>

                <div class="supplier-quote-card">
                    <div class="section-head compact">
                        <div>
                            <h3>Dosya veya genel teklif notu</h3>
                            <span>Fiyatları tek tek yazmak istemezseniz teklifinizi dosya/not olarak iletebilirsiniz.</span>
                        </div>
                    </div>
                    <label>
                        Teklif dosyası
                        <input type="file" name="supplier_quote_file" accept=".pdf,.xls,.xlsx,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/png,image/jpeg">
                    </label>
                    <label>
                        Genel teklif notu
                        <textarea name="quote_note" rows="4" placeholder="Nakliye dahil/hariç, teslim süresi, stok, KDV ve teklif geçerlilik süresini yazınız."><?= h($_POST['quote_note'] ?? '') ?></textarea>
                    </label>
                </div>

                <label class="supplier-quote-ack">
                    <input type="checkbox" name="terms_acknowledged" value="1" <?= !empty($_POST['terms_acknowledged']) ? 'checked' : '' ?> required>
                    <span>Fiyat, vade, KDV, nakliye, teslim ve teklif notlarını eksiksiz belirttiğimi onaylıyorum.</span>
                </label>

                <button type="submit" class="button primary full">Teklifi gönder</button>
            </form>
        </section>
        <?php
    });
}

function handle_customer_offer_public(string $method, string $token): void
{
    $repo = new RenewalRepository();
    $offer = $repo->findCustomerOfferByToken($token);
    $error = null;

    if (!$offer) {
        render_public_layout('Müşteri Teklifi', static function (): void {
            echo '<section class="login-panel"><div class="alert error">Teklif bulunamadı veya bağlantı geçersiz.</div></section>';
        });
        return;
    }

    $lines = $repo->customerOfferLines((int) $offer['id']);
    $expired = strtotime((string) $offer['expires_at']) < time();
    if ($expired && !in_array((string) ($offer['status'] ?? ''), ['approved', 'revision_requested', 'rejected'], true)) {
        render_public_layout('Müşteri Teklifi', static function () use ($offer): void {
            ?>
            <section class="login-panel supplier-quote-public">
                <div class="alert error">Bu teklif bağlantısının süresi dolmuş.</div>
                <p class="muted compact">Müşteri: <?= h((string) ($offer['company_name'] ?? '-')) ?></p>
            </section>
            <?php
        });
        return;
    }

    if (in_array((string) ($offer['status'] ?? ''), ['sent', 'opened'], true)) {
        $repo->markCustomerOfferOpened((int) $offer['id']);
        $offer['status'] = 'opened';
    }

    if (in_array((string) ($offer['status'] ?? ''), ['approved', 'revision_requested', 'rejected'], true)) {
        render_public_layout('Müşteri Teklifi', static function () use ($offer): void {
            $status = (string) ($offer['status'] ?? '');
            ?>
            <section class="public-card payment-result-card success">
                <p class="eyebrow">Teklif yanıtı</p>
                <h1><?= $status === 'approved' ? 'Bu teklif onaylandı.' : ($status === 'revision_requested' ? 'Bu teklif için revize istendi.' : 'Bu teklif reddedildi.') ?></h1>
                <p class="muted compact">Yanıt tarihi: <?= h(!empty($offer['responded_at']) ? date('d.m.Y H:i', strtotime((string) $offer['responded_at'])) : '-') ?></p>
                <?php if (!empty($offer['response_note'])): ?>
                    <div class="settings-note"><?= nl2br(h((string) $offer['response_note']), false) ?></div>
                <?php endif; ?>
            </section>
            <?php
        });
        return;
    }

    if ($method === 'POST') {
        verify_csrf();
        $decision = (string) ($_POST['decision'] ?? '');
        $note = trim((string) ($_POST['response_note'] ?? ''));

        try {
            if (!in_array($decision, ['approved', 'revision_requested', 'rejected'], true)) {
                throw new RuntimeException('Lütfen geçerli bir teklif yanıtı seçin.');
            }
            if ($decision !== 'approved' && $note === '') {
                throw new RuntimeException('Revize veya red yanıtı için açıklama yazın.');
            }

            $repo->respondCustomerOffer(
                (int) $offer['id'],
                $decision,
                $note,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );

            $supplierMailSummary = ['sent' => 0, 'failed' => 0];
            if ($decision === 'approved') {
                $repo->applyCustomerOfferToRenewal((int) $offer['renewal_id'], (int) $offer['id']);
                $supplierMailSummary = send_supplier_customer_offer_approval_emails($repo, $offer, $lines);
            }

            $paymentUrl = $decision === 'approved'
                ? PaymentLink::urlForRenewal((int) $offer['renewal_id'], 60, (string) ($offer['recipient_email'] ?? ''))
                : '';

            render_public_layout('Müşteri Teklifi', static function () use ($decision, $paymentUrl, $supplierMailSummary): void {
                ?>
                <section class="public-card payment-result-card success">
                    <p class="eyebrow">Teklif yanıtı</p>
                    <h1><?= $decision === 'approved' ? 'Teklif onaylandı.' : ($decision === 'revision_requested' ? 'Revize talebiniz alındı.' : 'Red yanıtınız alındı.') ?></h1>
                    <?php if ($decision === 'approved'): ?>
                        <p>Teşekkür ederiz. Seçilen tedarikçilere işlem bilgisi iletildi.</p>
                        <p class="muted compact">Tedarikçi mail durumu: <?= h((string) $supplierMailSummary['sent']) ?> gönderildi, <?= h((string) $supplierMailSummary['failed']) ?> başarısız.</p>
                        <?php if ($paymentUrl !== ''): ?>
                            <a class="button primary" href="<?= h($paymentUrl) ?>">Ödeme seçimine geç</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>Yanıtınız firma yetkililerine iletilmek üzere kayıt altına alındı.</p>
                    <?php endif; ?>
                </section>
                <?php
            });
            return;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    render_public_layout('Müşteri Teklifi', static function () use ($offer, $lines, $error): void {
        $currency = (string) ($offer['currency'] ?? 'TRY');
        ?>
        <section class="login-panel customer-offer-public">
            <div class="login-heading">
                <p class="eyebrow">Yenileme teklifi</p>
                <h1>Teklifinizi inceleyin.</h1>
                <p class="muted compact"><?= h((string) ($offer['company_name'] ?? '-')) ?> için hazırlanan yenileme teklifidir.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($offer['message_body'])): ?>
                <div class="settings-note"><?= nl2br(h((string) $offer['message_body']), false) ?></div>
            <?php endif; ?>

            <?= render_customer_offer_lines_public($lines, $currency) ?>

            <div class="payment-choice-summary customer-offer-totals">
                <div><span>Ara toplam</span><strong><?= h(money_format_local($offer['subtotal'] ?? null, $currency)) ?></strong></div>
                <div><span>KDV</span><strong><?= h(money_format_local($offer['vat_total'] ?? null, $currency)) ?></strong></div>
                <div><span>KDV dahil toplam</span><strong><?= h(money_format_local($offer['total'] ?? null, $currency)) ?></strong></div>
            </div>

            <form method="post" class="form-grid">
                <?= csrf_field() ?>
                <label class="span-2">
                    Yanıt notu
                    <textarea name="response_note" rows="3" placeholder="Revize veya red için açıklamanızı yazın."><?= h((string) ($_POST['response_note'] ?? '')) ?></textarea>
                </label>
                <div class="inline-actions span-2">
                    <button type="submit" name="decision" value="approved" class="button primary">Teklifi onayla</button>
                    <button type="submit" name="decision" value="revision_requested" class="button secondary">Revize iste</button>
                    <button type="submit" name="decision" value="rejected" class="button danger">Reddet</button>
                </div>
            </form>
            <p class="muted compact">Bağlantı geçerlilik süresi: <?= h(date('d.m.Y H:i', strtotime((string) $offer['expires_at']))) ?></p>
        </section>
        <?php
    });
}

function render_supplier_quote_item_form(array $item, string $currency): string
{
    $id = (int) ($item['id'] ?? 0);
    $key = (string) $id;
    $posted = is_array($_POST['lines'][$key] ?? null) ? $_POST['lines'][$key] : [];
    $title = (string) (($item['title'] ?? '') ?: ($item['definition_name'] ?? 'Ürün / hizmet'));

    ob_start();
    ?>
    <div class="supplier-quote-card">
        <div class="supplier-quote-item-head">
            <div>
                <span>Kalem</span>
                <strong><?= h($title) ?></strong>
                <em>
                    <?= h(trim((string) (($item['brand'] ?? '') ?: '-'))) ?>
                    <?= !empty($item['license_key']) ? ' / ' . h((string) $item['license_key']) : '' ?>
                </em>
            </div>
            <b><?= h(number_format((float) ($item['quantity'] ?? 1), 2, ',', '.')) ?> adet</b>
        </div>
        <input type="hidden" name="lines[<?= h($key) ?>][item_title]" value="<?= h($title) ?>">
        <div class="supplier-quote-price-grid">
            <label>Peşin <input type="number" min="0" step="0.01" name="lines[<?= h($key) ?>][price_cash]" value="<?= h($posted['price_cash'] ?? '') ?>" placeholder="0.00"></label>
            <label>30 gün <input type="number" min="0" step="0.01" name="lines[<?= h($key) ?>][price_30]" value="<?= h($posted['price_30'] ?? '') ?>" placeholder="0.00"></label>
            <label>60 gün <input type="number" min="0" step="0.01" name="lines[<?= h($key) ?>][price_60]" value="<?= h($posted['price_60'] ?? '') ?>" placeholder="0.00"></label>
            <label>Çek / vade <input type="number" min="0" step="0.01" name="lines[<?= h($key) ?>][price_check]" value="<?= h($posted['price_check'] ?? '') ?>" placeholder="0.00"></label>
            <label>Özel vade adı <input name="lines[<?= h($key) ?>][custom_term]" value="<?= h($posted['custom_term'] ?? '') ?>" placeholder="Örn: 90 gün"></label>
            <label>Özel vade fiyatı <input type="number" min="0" step="0.01" name="lines[<?= h($key) ?>][price_custom]" value="<?= h($posted['price_custom'] ?? '') ?>" placeholder="0.00"></label>
            <label>
                Para birimi
                <select name="lines[<?= h($key) ?>][currency]">
                    <?php foreach (allowed_currency_options() as $currencyOption): ?>
                        <?= option($currencyOption, $currencyOption, normalize_allowed_currency($posted['currency'] ?? $currency)) ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="supplier-quote-check">
                <input type="checkbox" name="lines[<?= h($key) ?>][vat_included]" value="1" <?= !isset($posted['vat_included']) || !empty($posted['vat_included']) ? 'checked' : '' ?>>
                KDV dahil
            </label>
        </div>
        <label>
            Nakliye / teslim şartı
            <textarea name="lines[<?= h($key) ?>][delivery_note]" rows="2" placeholder="Nakliye dahil mi, teslim süresi nedir?"><?= h($posted['delivery_note'] ?? '') ?></textarea>
        </label>
        <label>
            Kalem notu
            <textarea name="lines[<?= h($key) ?>][note]" rows="2" placeholder="Stok, muadil ürün, garanti veya özel şartlar"><?= h($posted['note'] ?? '') ?></textarea>
        </label>
    </div>
    <?php
    return (string) ob_get_clean();
}

function supplier_quote_save_upload(?array $file, int $requestId): ?array
{
    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Teklif dosyası yüklenemedi.');
    }

    if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException('Teklif dosyası en fazla 10 MB olabilir.');
    }

    $original = (string) ($file['name'] ?? 'teklif');
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'xls', 'xlsx', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Teklif dosyası PDF, Excel, Word veya görsel olmalıdır.');
    }

    $dir = ROOT_PATH . '/storage/supplier_quotes';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Teklif dosyası klasörü oluşturulamadı.');
    }

    $storedName = 'tedarikci-teklif-' . $requestId . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $target = $dir . '/' . $storedName;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
        throw new RuntimeException('Teklif dosyası kaydedilemedi.');
    }

    return [
        'original_name' => $original,
        'stored_path' => 'storage/supplier_quotes/' . $storedName,
        'mime_type' => (string) ($file['type'] ?? ''),
        'file_size' => (int) ($file['size'] ?? 0),
    ];
}

function supplier_quote_has_payload(array $data, ?array $attachment): bool
{
    if ($attachment !== null || trim((string) ($data['quote_note'] ?? '')) !== '') {
        return true;
    }

    $lines = $data['lines'] ?? [];
    if (!is_array($lines)) {
        return false;
    }

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }

        foreach (['price_cash', 'price_30', 'price_60', 'price_check', 'price_custom'] as $field) {
            $value = str_replace(',', '.', trim((string) ($line[$field] ?? '')));
            if ($value !== '' && is_numeric($value) && (float) $value > 0) {
                return true;
            }
        }
    }

    return false;
}

function supplier_quote_submission_errors(array $data, ?array $attachment): array
{
    $errors = [];
    $globalNote = trim((string) ($data['quote_note'] ?? ''));
    $lines = $data['lines'] ?? [];
    $hasAnyPrice = false;

    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $lineHasPrice = false;
            foreach (['price_cash', 'price_30', 'price_60', 'price_check', 'price_custom'] as $field) {
                $value = str_replace(',', '.', trim((string) ($line[$field] ?? '')));
                if ($value !== '' && is_numeric($value) && (float) $value > 0) {
                    $lineHasPrice = true;
                    $hasAnyPrice = true;
                }
            }

            if ($lineHasPrice && $globalNote === '' && trim((string) ($line['delivery_note'] ?? '')) === '' && trim((string) ($line['note'] ?? '')) === '') {
                $errors[] = 'Fiyat yazılan her kalem için nakliye/teslim veya kalem notu girin.';
                break;
            }
        }
    }

    if (!$hasAnyPrice && $attachment === null && $globalNote === '') {
        $errors[] = 'Fiyat yazmadan teklif iletmek için dosya yükleyin veya genel teklif notu yazın.';
    }

    return $errors;
}

function customer_info_form_values(array $source, string $fallbackEmail = '', string $fallbackContactName = ''): array
{
    $contacts = customer_info_contact_rows($source, $fallbackEmail, $fallbackContactName);
    $primaryContact = $contacts[0] ?? [];

    return [
        'company_name' => trim((string) ($source['company_name'] ?? '')),
        'contact_name' => trim((string) ($source['contact_name'] ?? ($primaryContact['full_name'] ?? $fallbackContactName))),
        'email' => trim((string) ($source['email'] ?? ($primaryContact['email'] ?? $fallbackEmail))),
        'phone' => normalize_phone_number($source['phone'] ?? ($primaryContact['phone'] ?? '')),
        'tax_office' => trim((string) ($source['tax_office'] ?? '')),
        'tax_number' => trim((string) ($source['tax_number'] ?? '')),
        'city' => trim((string) ($source['city'] ?? '')),
        'district' => trim((string) ($source['district'] ?? '')),
        'address' => trim((string) ($source['address'] ?? '')),
        'notes' => trim((string) ($source['notes'] ?? '')),
        'contacts' => $contacts,
    ];
}

function customer_info_contact_rows(array $source, string $fallbackEmail = '', string $fallbackContactName = ''): array
{
    $rows = $source['contacts'] ?? null;
    if (is_array($rows) && $rows !== []) {
        $contacts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $contacts[] = [
                'full_name' => trim((string) ($row['full_name'] ?? '')),
                'email' => trim((string) ($row['email'] ?? '')),
                'phone' => normalize_phone_number($row['phone'] ?? ''),
                'notify_enabled' => !empty($row['notify_enabled']) ? 1 : 0,
            ];
        }

        return $contacts !== [] ? $contacts : default_contact_rows();
    }

    return [[
        'full_name' => trim((string) ($source['contact_name'] ?? $fallbackContactName)),
        'email' => trim((string) ($source['email'] ?? $fallbackEmail)),
        'phone' => normalize_phone_number($source['phone'] ?? ''),
        'notify_enabled' => 1,
    ]];
}

function tax_certificate_has_customer_fields(array $extracted): bool
{
    foreach (['company_name', 'contact_name', 'tax_office', 'tax_number', 'city', 'district', 'address'] as $key) {
        if (trim((string) ($extracted[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function merge_customer_info_values(array $values, array $extracted): array
{
    foreach (['company_name', 'contact_name', 'tax_office', 'tax_number', 'city', 'district', 'address'] as $key) {
        $extractedValue = trim((string) ($extracted[$key] ?? ''));
        if ($extractedValue !== '' && trim((string) ($values[$key] ?? '')) === '') {
            $values[$key] = $extractedValue;
        }
    }

    if (
        trim((string) ($values['contact_name'] ?? '')) !== ''
        && isset($values['contacts'][0])
        && trim((string) ($values['contacts'][0]['full_name'] ?? '')) === ''
    ) {
        $values['contacts'][0]['full_name'] = trim((string) $values['contact_name']);
    }

    return $values;
}

function customer_info_public_errors(array $values): array
{
    $errors = [];
    if (trim((string) ($values['company_name'] ?? '')) === '') {
        $errors[] = 'Firma adi zorunlu.';
    }

    if (($values['email'] ?? '') !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-posta adresi gecersiz.';
    }

    foreach (($values['contacts'] ?? []) as $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $email = trim((string) ($contact['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Yetkili e-posta adreslerinden biri gecersiz.';
            break;
        }

        if (!empty($contact['notify_enabled']) && $email === '') {
            $errors[] = 'Bilgilendirme gonderilecek yetkililer icin e-posta zorunlu.';
            break;
        }
    }

    return $errors;
}

function absolute_app_url(string $path): string
{
    return url($path);
}

function render_dashboard(RenewalRepository $repo): void
{
    $stats = $repo->stats();
    $upcoming = $repo->upcoming();
    $canManageRenewals = Auth::can('renewals.manage');
    $canRequestCustomerInfo = Auth::can('customers.manage');
    $canViewRenewals = Auth::can('renewals.view');
    $showDetails = Auth::can('dashboard.details');
    $exchangeRates = ExchangeRates::latest();
    $customersForRequest = $canRequestCustomerInfo ? $repo->customers() : [];

    render_layout('Dashboard', static function () use ($stats, $upcoming, $canManageRenewals, $canRequestCustomerInfo, $canViewRenewals, $showDetails, $exchangeRates, $customersForRequest): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Bugun: <?= h(date('d.m.Y')) ?></p>
                <h1>Hızlı Takip ve Teklif Paneli</h1>
            </div>
            <?php if ($canRequestCustomerInfo || $canManageRenewals): ?>
                <div class="page-actions">
                    <?php if ($canRequestCustomerInfo): ?>
                        <button type="button" class="button secondary" data-dialog-open="customer-info-request-dialog">Cari bilgi talep et</button>
                    <?php endif; ?>
                    <?php if ($canManageRenewals): ?>
                        <a href="<?= h(url('/renewals/create?old=1')) ?>" class="button secondary">Eski tarihli giriş</a>
                        <a href="<?= h(url('/renewals/create')) ?>" class="button primary">Yeni kayit</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($canRequestCustomerInfo): ?>
            <?= render_customer_info_request_dialog($customersForRequest, '/') ?>
        <?php endif; ?>

        <section class="stats">
            <?= stat_card('Toplam', $stats['total']) ?>
            <?= stat_card('Aktif', $stats['active']) ?>
            <?= stat_card('Yaklasan (60 gun)', $stats['due_soon'], 'warning') ?>
            <?= stat_card('Geciken', $stats['overdue'], 'danger') ?>
            <?= stat_card('Musteri', $stats['customers']) ?>
        </section>

        <?php if ($showDetails && $canViewRenewals): ?>
            <section class="dashboard-split">
                <div class="dashboard-lane dashboard-lane-renewals">
                    <div class="lane-head">
                        <div>
                            <p class="eyebrow">Yenilenen ürünler</p>
                            <h2>Takipteki ürün ve hizmetler</h2>
                        </div>
                        <span class="lane-count"><?= h((string) count($upcoming)) ?></span>
                    </div>
                    <?= render_dashboard_lane($upcoming, 'renewals', $canManageRenewals, false, true, 'Takipte ürün veya hizmet bulunmuyor.') ?>
                </div>

                <div class="dashboard-lane dashboard-lane-empty" aria-hidden="true"></div>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="empty">Bu kullanici icin panel detaylari kapali.</div>
            </section>
        <?php endif; ?>

        <div class="dashboard-fx-footer">
            <?= render_exchange_rates($exchangeRates, 'compact') ?>
        </div>
        <?php
    });
}

function render_renewals(RenewalRepository $repo): void
{
    $filters = [
        'q' => trim((string) ($_GET['q'] ?? '')),
        'state' => trim((string) ($_GET['state'] ?? '')),
        'kind' => trim((string) ($_GET['kind'] ?? '')),
    ];
    $rows = $repo->all($filters);
    $canManageRenewals = Auth::can('renewals.manage');

    render_layout('Yenilemeler', static function () use ($rows, $filters, $canManageRenewals): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Takip listesi</p>
                <h1>Yenilemeler</h1>
            </div>
            <?php if ($canManageRenewals): ?>
                <div class="page-actions">
                    <a href="<?= h(url('/renewals/create?old=1')) ?>" class="button secondary">Eski tarihli giriş</a>
                    <a href="<?= h(url('/renewals/create')) ?>" class="button primary">Yeni kayit</a>
                </div>
            <?php endif; ?>
        </div>

        <form method="get" class="toolbar">
            <input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Musteri, urun veya hizmet ara">
            <select name="state">
                <?= option('', 'Tum durumlar', $filters['state']) ?>
                <?= option('due_soon', 'Yaklasan', $filters['state']) ?>
                <?= option('overdue', 'Geciken', $filters['state']) ?>
                <?= option('active', 'Aktif', $filters['state']) ?>
                <?= option('renewed', 'Yenilendi', $filters['state']) ?>
                <?= option('cancelled', 'Iptal', $filters['state']) ?>
            </select>
            <select name="kind">
                <?= option('', 'Tum tipler', $filters['kind']) ?>
                <?= option('product', 'Urun', $filters['kind']) ?>
                <?= option('service', 'Hizmet', $filters['kind']) ?>
            </select>
            <button class="button secondary" type="submit">Filtrele</button>
        </form>

        <section class="renewal-list-panel">
            <?= render_renewal_table($rows, true, true) ?>
        </section>
        <?php
    });
}

function render_dashboard_lane(array $rows, string $variant, bool $canManage, bool $canDelete, bool $canNotify, string $emptyMessage): string
{
    if ($rows === []) {
        return '<div class="empty lane-empty">' . h($emptyMessage) . '</div>';
    }

    ob_start();
    ?>
    <div class="dashboard-card-list">
        <?php foreach ($rows as $row): ?>
            <?php
            $days = days_until($row['renewal_date']);
            $daysLabel = $days === null ? '-' : ($days < 0 ? abs($days) . ' gün geçti' : $days . ' gün');
            $urgencyClass = renewal_urgency_class($row, $days);
            $countdownClass = renewal_countdown_class($row, $days);
            $countdownStyle = renewal_countdown_style($row, $days);
            $urgencyLabel = renewal_urgency_label($row, $days);
            $readSummary = renewal_notification_read_summary($row);
            $canAcknowledge = ($row['status'] ?? '') === 'active' && renewal_can_acknowledge($row, $days);
            $canNotifyRow = $canNotify && $canManage && ($row['status'] ?? '') === 'active';
            $latestDecision = null;
            try {
                $latestDecision = (new RenewalRepository())->latestRenewalDecision((int) $row['id']);
            } catch (Throwable) {
                $latestDecision = null;
            }
            ?>
            <details class="dashboard-track-card <?= h($variant) ?> <?= h($urgencyClass) ?> <?= h($countdownClass) ?>"<?= $countdownStyle ?>>
                <summary>
                    <span class="track-main">
                        <small><?= $variant === 'offers' ? 'Teklif / Cari' : 'Müşteri' ?></small>
                        <strong><?= h((string) $row['company_name']) ?></strong>
                        <em><?= h((string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-'))) ?></em>
                    </span>
                    <span class="track-side">
                        <span class="badge <?= h($urgencyClass) ?>"><?= h($urgencyLabel) ?></span>
                        <span class="track-days">
                            <small>Kalan</small>
                            <strong><?= h($daysLabel) ?></strong>
                        </span>
                    </span>
                </summary>

                <div class="track-body">
                    <div class="track-meta-grid">
                        <div>
                            <span>Yenileme</span>
                            <strong><?= h(!empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-') ?></strong>
                        </div>
                        <div>
                            <span>Toplam</span>
                            <strong><?= h(money_format_local($row['item_total'] ?? $row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'))) ?></strong>
                        </div>
                        <div>
                            <span>Ödeme</span>
                            <strong><?= h(renewal_payment_label($row)) ?></strong>
                        </div>
                        <div>
                            <span>Okunma</span>
                            <strong><?= h((string) ($readSummary['label'] ?? '-')) ?></strong>
                        </div>
                    </div>

                    <?php if ($latestDecision): ?>
                        <div class="decision-note compact">
                            <span>Son karar</span>
                            <strong><?= h(renewal_decision_label((string) $latestDecision['decision'])) ?></strong>
                            <?php if (!empty($latestDecision['payment_terms'])): ?>
                                <em><?= h((string) $latestDecision['payment_terms']) ?></em>
                            <?php elseif (!empty($latestDecision['reason'])): ?>
                                <em><?= h((string) $latestDecision['reason']) ?></em>
                            <?php elseif (!empty($latestDecision['postponed_date'])): ?>
                                <em><?= h(date('d.m.Y', strtotime((string) $latestDecision['postponed_date']))) ?></em>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($readSummary['readers'])): ?>
                        <div class="notification-read-note compact">
                            <span>Okuyanlar</span>
                            <?= render_notification_reader_chips((string) $readSummary['readers']) ?>
                        </div>
                    <?php endif; ?>

                    <?= render_supplier_quote_comparison(new RenewalRepository(), $row) ?>

                    <div class="track-actions">
                        <?php if ($canManage && $variant !== 'offers'): ?>
                            <button type="button" class="button small secondary" data-dialog-open="renewal-mail-<?= h($row['id']) ?>">Mail olarak gönder</button>
                            <button type="button" class="button small whatsapp" data-dialog-open="renewal-whatsapp-<?= h($row['id']) ?>">WhatsApp PDF gönder</button>
                            <button type="button" class="button small supplier-price" data-dialog-open="supplier-price-<?= h($row['id']) ?>">Tedarikçiden fiyat al</button>
                            <a href="<?= h(url('/renewals/' . $row['id'] . '/edit')) ?>" class="button small secondary">Düzenle</a>
                            <?= render_renewal_communication_dialogs($row) ?>
                            <?= render_supplier_price_request_dialog($row) ?>
                        <?php endif; ?>
                        <?php if ($variant === 'offers'): ?>
                            <?= render_renewal_actions($row, $canManage, $canDelete, $canAcknowledge, $canNotifyRow) ?>
                        <?php else: ?>
                            <?= render_renewal_actions($row, false, false, $canAcknowledge, $canNotifyRow) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function handle_renewal_form(RenewalRepository $repo, string $method, ?int $id = null): void
{
    $renewal = $id ? $repo->find($id) : null;
    $oldEntryMode = !$id && ((string) ($_GET['old'] ?? '') === '1' || !empty($_POST['old_entry_mode']));
    if ($id && !$renewal) {
        flash('error', 'Kayit bulunamadi.');
        redirect('/renewals');
    }

    $errors = [];
    if ($method === 'POST') {
        verify_csrf();
        $errors = validate_renewal($_POST);

        if ($errors === []) {
            if ($id) {
                $repo->update($id, $_POST);
                flash('success', 'Yenileme kaydi guncellendi.');
            } else {
                $newId = $repo->create($_POST);
                if ($oldEntryMode) {
                    $repo->recordRenewalDecision([
                        'renewal_id' => $newId,
                        'decision' => 'approved',
                        'payment_terms' => trim((string) ($_POST['payment_method'] ?? '')) ?: 'Eski tarihli onaylanmış teklif',
                        'note' => 'Eski tarihli giriş olarak kaydedildi.',
                        'created_by' => (int) ($_SESSION['user_id'] ?? 0),
                    ]);
                }
                flash('success', $oldEntryMode ? 'Eski tarihli onaylanmis teklif takip listesine alindi.' : 'Yenileme kaydi olusturuldu.');
            }
            redirect('/');
        }

        $renewal = array_merge($renewal ?? [], $_POST);
        $renewal['payment_customer_choice'] = !empty($_POST['payment_customer_choice']) ? '1' : '0';
    }

    $customers = $repo->customers();
    $suppliers = $repo->suppliers();
    $supplierGroups = $repo->supplierGroups();
    $definitions = $repo->renewalDefinitions();
    $periods = $repo->renewalPeriods();
    $itemRows = renewal_item_rows($repo, $renewal, $id);
    $reminderRows = renewal_reminder_days($renewal);
    $invoiceRows = $id ? $repo->invoicePeriods($id) : [];
    $paymentRows = $id ? $repo->iyzicoPayments($id) : [];
    $settings = $id ? (new SettingsRepository())->all() : [];
    $iyzicoReady = $id
        && (string) ($settings['iyzico.enabled'] ?? '0') === '1'
        && trim((string) ($settings['iyzico.api_key'] ?? '')) !== ''
        && trim((string) ($settings['iyzico.secret_key'] ?? '')) !== '';
    $nextRenewalStartDate = null;
    $nextRenewalDate = null;
    if ($id) {
        try {
            $nextRenewalStartDate = (string) ($renewal['renewal_date'] ?? date('Y-m-d'));
            $nextRenewalDate = $repo->previewNextRenewalDate($id, $nextRenewalStartDate);
        } catch (Throwable) {
            $nextRenewalStartDate = null;
            $nextRenewalDate = null;
        }
    }

    render_layout($id ? 'Yenileme duzenle' : ($oldEntryMode ? 'Eski tarihli giriş' : 'Yeni yenileme'), static function () use ($id, $renewal, $customers, $suppliers, $supplierGroups, $definitions, $periods, $itemRows, $errors, $reminderRows, $invoiceRows, $paymentRows, $iyzicoReady, $settings, $nextRenewalStartDate, $nextRenewalDate, $oldEntryMode): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow"><?= $id ? 'Kayit duzenle' : ($oldEntryMode ? 'Onaylı eski teklif' : 'Yeni kayit') ?></p>
                <h1><?= $id ? 'Yenileme Duzenle' : ($oldEntryMode ? 'Eski Tarihli Giriş' : 'Yeni Yenileme') ?></h1>
            </div>
            <a href="<?= h(url('/')) ?>" class="button secondary">Dashboard'a dön</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert error"><?= h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <section class="panel renewal-form-panel">
            <form method="post" class="renewal-form">
                <?= csrf_field() ?>
                <?php if ($oldEntryMode): ?>
                    <input type="hidden" name="old_entry_mode" value="1">
                    <div class="legacy-entry-note">
                        <strong>Eski tarihli onaylanmış teklif girişi</strong>
                        <span>Daha önce alınmış ürün veya hizmeti eski satın alma/onay tarihi ve o günkü fiyatlarıyla girin. Sistem seçtiğiniz periyoda göre yenileme gününü hesaplayıp takip listesine alır.</span>
                    </div>
                <?php endif; ?>
                <div class="renewal-form-section">
                    <div class="form-section-title">
                        <span class="form-step">01</span>
                        <div>
                            <h2>Müşteri</h2>
                            <p>Bu yenilemenin bağlı olduğu cari</p>
                        </div>
                    </div>
                    <div class="section-fields">
                        <label class="field-wide">
                            Müşteri
                            <select name="customer_id" required>
                                <option value="">Müşteri seçin</option>
                                <?php foreach ($customers as $customer): ?>
                                    <?= option((string) $customer['id'], $customer['company_name'], (string) ($renewal['customer_id'] ?? '')) ?>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="renewal-form-section">
                    <div class="form-section-title">
                        <span class="form-step">02</span>
                        <div>
                            <h2>Ürünler</h2>
                            <p>Adet, birim fiyat ve KDV dahil toplam</p>
                        </div>
                    </div>
                    <input type="hidden" name="title" value="<?= h($renewal['title'] ?? '') ?>">
                    <input type="hidden" name="definition_id" value="<?= h($renewal['definition_id'] ?? '') ?>">
                    <input type="hidden" name="kind" value="<?= h($renewal['kind'] ?? 'product') ?>">
                    <input type="hidden" name="brand" value="<?= h($renewal['brand'] ?? '') ?>">
                    <input type="hidden" name="license_key" value="<?= h($renewal['license_key'] ?? '') ?>">
                    <div class="product-editor" data-renewal-items data-next-index="<?= h((string) next_renewal_item_index($itemRows)) ?>">
                        <div class="section-head product-editor-head">
                            <div>
                                <h3>Ürün / hizmet satırları</h3>
                                <span class="muted compact">+ ile aynı yenileme içine markanın diğer ürününü ekleyebilirsiniz.</span>
                            </div>
                            <button type="button" class="button small secondary" data-add-renewal-item>+ Ürün ekle</button>
                        </div>
                        <div class="product-list" data-renewal-item-list>
                            <?php foreach ($itemRows as $index => $item): ?>
                                <?= render_renewal_item_row((int) $index, (array) $item, $definitions) ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$definitions): ?>
                            <span class="muted compact">Önce Ayarlar > Tanımlamalar bölümünden ürün/hizmet adı ekleyebilirsiniz. Yine de özel ad yazarak kayıt açabilirsiniz.</span>
                        <?php endif; ?>
                        <div class="product-total-bar">
                            <label>
                                Para birimi
                                <select name="currency" data-renewal-currency>
                                    <?php foreach (allowed_currency_options() as $currency): ?>
                                        <?= option($currency, $currency, (string) ($renewal['currency'] ?? 'TRY')) ?>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="product-total-card">
                                <span>Toplam</span>
                                <strong data-renewal-items-total>-</strong>
                                <em>KDV dahil</em>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="renewal-form-section">
                    <div class="form-section-title">
                        <span class="form-step">03</span>
                        <div>
                            <h2>Tedarikçi</h2>
                            <p>Tedarikçi, grup ve lisans bilgisi</p>
                        </div>
                    </div>
                    <div class="section-fields two">
                        <label>
                            Tedarikçi seç
                            <select name="supplier_id">
                                <option value="">Manuel / seçilmedi</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <?php $supplierLabel = $supplier['company_name'] . (!empty($supplier['supplier_group_name']) ? ' - ' . $supplier['supplier_group_name'] : ''); ?>
                                    <?= option((string) $supplier['id'], $supplierLabel, (string) ($renewal['supplier_id'] ?? '')) ?>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Tedarikçi grubu
                            <select name="supplier_group_id">
                                <option value="">Grup seçilmedi</option>
                                <?php foreach ($supplierGroups as $group): ?>
                                    <?= option((string) $group['id'], $group['name'], (string) ($renewal['supplier_group_id'] ?? '')) ?>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Manuel tedarikçi
                            <input name="supplier" value="<?= h($renewal['supplier'] ?? '') ?>" placeholder="Listede yoksa yazın">
                        </label>
                        <label>
                            Lisans / referans no
                            <input name="license_key" value="<?= h($renewal['license_key'] ?? '') ?>" placeholder="Opsiyonel">
                        </label>
                    </div>
                </div>

                <div class="renewal-form-section">
                    <div class="form-section-title">
                        <span class="form-step">04</span>
                        <div>
                            <h2>Dönem ve ödeme</h2>
                            <p>Başlangıç, periyot, fatura ve ödeme</p>
                        </div>
                    </div>
                    <div class="section-fields two">
                        <label>
                            <?= $oldEntryMode ? 'Eski satın alma / onay tarihi' : 'Başlangıç tarihi' ?>
                            <input type="date" name="start_date" value="<?= h($renewal['start_date'] ?? ($id ? '' : ($oldEntryMode ? '' : date('Y-m-d')))) ?>" data-renewal-start-date <?= $oldEntryMode ? 'required' : '' ?>>
                            <?php if ($oldEntryMode): ?>
                                <span class="muted compact">Örn: Ürünün ilk alındığı veya teklifin onaylandığı tarih.</span>
                            <?php endif; ?>
                        </label>
                        <label>
                            Yenileme periyodu
                            <select name="renewal_period_id" required data-renewal-period-select>
                                <option value="">Periyot seçin</option>
                                <?php foreach ($periods as $period): ?>
                                    <option
                                        value="<?= h($period['id']) ?>"
                                        data-count="<?= h($period['interval_count']) ?>"
                                        data-unit="<?= h($period['interval_unit']) ?>"
                                        <?= (string) ($renewal['renewal_period_id'] ?? '') === (string) $period['id'] ? 'selected' : '' ?>
                                    >
                                        <?= h(period_label($period)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$periods): ?>
                                <span class="muted compact">Önce Ayarlar > Tanımlamalar bölümünden yenileme periyodu ekleyin.</span>
                            <?php elseif (!empty($renewal['renewal_date'])): ?>
                                <span class="muted compact">Hesaplanan bitiş: <?= h(date('d.m.Y', strtotime($renewal['renewal_date']))) ?></span>
                            <?php endif; ?>
                            <span class="period-preview" data-invoice-period-preview></span>
                        </label>
                        <?php
                        $paymentMethod = (string) ($renewal['payment_method'] ?? '');
                        $paymentOptions = payment_method_options();
                        ?>
                        <div class="field-block payment-choice">
                            <label>
                                Ödeme şekli
                                <select name="payment_method" data-payment-method-select>
                                    <?= option('', 'Ödeme şeklini seçin', $paymentMethod) ?>
                                    <?php if ($paymentMethod !== '' && !in_array($paymentMethod, $paymentOptions, true)): ?>
                                        <?= option($paymentMethod, $paymentMethod, $paymentMethod) ?>
                                    <?php endif; ?>
                                    <?php foreach ($paymentOptions as $paymentOption): ?>
                                        <?= option($paymentOption, $paymentOption, $paymentMethod) ?>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="checkline choice-line">
                                <input
                                    type="checkbox"
                                    name="payment_customer_choice"
                                    value="1"
                                    data-payment-customer-choice
                                    <?= !empty($renewal['payment_customer_choice']) || (!$id && $paymentMethod === '') ? 'checked' : '' ?>
                                >
                                Müşteri ödeme şeklini kendi seçsin
                            </label>
                        </div>
                        <label>
                            Fatura numarası
                            <input
                                name="invoice_number"
                                value="<?= h($renewal['invoice_number'] ?? $renewal['current_invoice_number'] ?? '') ?>"
                                placeholder="Örn: BIGA-2026-001"
                            >
                            <span class="muted compact">Bilgilendirme sonrası fatura kesilince de girebilirsiniz.</span>
                        </label>
                    </div>
                </div>

                <div class="renewal-form-section">
                    <div class="form-section-title">
                        <span class="form-step">05</span>
                        <div>
                            <h2>Bildirimler</h2>
                            <p>Hatırlatma, fiyat talebi ve durum</p>
                        </div>
                    </div>
                    <div class="section-fields">
                        <div class="contact-editor reminder-editor" data-reminder-editor data-next-index="<?= h((string) next_reminder_index($reminderRows)) ?>">
                            <div class="section-head">
                                <h3>Bilgilendirme günleri</h3>
                                <button type="button" class="button small secondary" data-add-reminder>+ Gün ekle</button>
                            </div>
                            <div class="contact-list" data-reminder-list>
                                <?php foreach ($reminderRows as $index => $day): ?>
                                    <?= render_reminder_input_row((int) $index, (int) $day) ?>
                                <?php endforeach; ?>
                            </div>
                            <span class="muted compact">7 gün kala günlük bildirim aktiftir.</span>
                        </div>

                        <div class="contact-editor supplier-price-box">
                            <div class="section-head">
                                <h3>Tedarikçi fiyat talebi</h3>
                                <span class="badge <?= !empty($renewal['supplier_price_request_enabled']) ? 'active' : 'cancelled' ?>">
                                    <?= !empty($renewal['supplier_price_request_enabled']) ? 'Açık' : 'Kapalı' ?>
                                </span>
                            </div>
                            <div class="section-fields two">
                                <label class="checkline choice-line">
                                    <input
                                        type="checkbox"
                                        name="supplier_price_request_enabled"
                                        value="1"
                                        <?= !empty($renewal['supplier_price_request_enabled']) ? 'checked' : '' ?>
                                    >
                                    Tedarikçiye lisans fiyatı sor
                                </label>
                                <label>
                                    Kaç gün kala fiyat sorulsun
                                    <input
                                        type="number"
                                        min="1"
                                        name="supplier_price_request_days"
                                        value="<?= h($renewal['supplier_price_request_days'] ?? '30') ?>"
                                    >
                                </label>
                            </div>
                        </div>

                        <div class="section-fields two">
                            <label>
                                Durum
                                <select name="status">
                                    <?= option('active', 'Aktif', (string) ($renewal['status'] ?? 'active')) ?>
                                    <?= option('renewed', 'Yenilendi', (string) ($renewal['status'] ?? 'active')) ?>
                                    <?= option('cancelled', 'İptal', (string) ($renewal['status'] ?? 'active')) ?>
                                </select>
                            </label>
                            <label>
                                Notlar
                                <textarea name="notes" rows="3" placeholder="Müşteri veya ürünle ilgili not"><?= h($renewal['notes'] ?? '') ?></textarea>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="renewal-actions-bar">
                    <a href="<?= h(url('/renewals')) ?>" class="button secondary">Vazgeç</a>
                    <button type="submit" class="button primary"><?= $id ? 'Güncelle' : ($oldEntryMode ? 'Eski kaydı takip listesine al' : 'Kaydı oluştur') ?></button>
                </div>
            </form>
        </section>

        <?php if ($id): ?>
            <section class="panel narrow payment-panel" id="iyzico-payment">
                <div class="section-head">
                    <div>
                        <h2>Kredi kartı tahsilatı</h2>
                        <p class="muted compact">Kart bilgisi panelde tutulmaz; müşteri iyzico güvenli ödeme sayfasına yönlendirilir.</p>
                    </div>
                    <span class="badge <?= $iyzicoReady ? 'active' : 'cancelled' ?>">
                        <?= $iyzicoReady ? 'iyzico hazır' : 'Ayar bekliyor' ?>
                    </span>
                </div>

                <?php if (!$iyzicoReady): ?>
                    <div class="settings-note payment-note">
                        <strong>iyzico ayarları eksik</strong>
                        <span>Ödeme linki oluşturmak için Ayarlar bölümünden iyzico API anahtarlarını girip entegrasyonu aktif edin.</span>
                    </div>
                    <a class="button secondary" href="<?= h(url('/settings#iyzico-settings')) ?>">iyzico ayarlarına git</a>
                <?php else: ?>
                    <form
                        method="post"
                        action="<?= h(url('/renewals/' . $id . '/payments/iyzico/create')) ?>"
                        class="payment-create-form"
                        onsubmit="return confirm('iyzico ödeme sayfası oluşturulsun mu?')"
                    >
                        <?= csrf_field() ?>
                        <label>
                            Tahsil edilecek tutar
                            <input type="number" min="0.01" step="0.01" name="amount" value="<?= h($renewal['item_total'] ?? $renewal['amount'] ?? '') ?>" placeholder="Örn: 1500.00" required>
                        </label>
                        <label>
                            Para birimi
                            <select name="currency">
                                <?php foreach (allowed_currency_options() as $currency): ?>
                                    <?= option($currency, $currency, (string) ($renewal['currency'] ?? 'TRY')) ?>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="button primary">iyzico ödeme linki oluştur</button>
                    </form>
                <?php endif; ?>

                <?php if ($paymentRows): ?>
                    <div class="payment-list">
                        <?php foreach ($paymentRows as $paymentRow): ?>
                            <div class="payment-row">
                                <div>
                                    <span class="badge <?= h(iyzico_status_badge_class((string) $paymentRow['status'])) ?>">
                                        <?= h(iyzico_status_label((string) $paymentRow['status'])) ?>
                                    </span>
                                    <strong><?= h(money_format_local($paymentRow['amount'] ?? null, (string) ($paymentRow['currency'] ?? 'TRY'))) ?></strong>
                                    <span class="muted compact"><?= h(date('d.m.Y H:i', strtotime((string) $paymentRow['created_at']))) ?></span>
                                </div>
                                <div class="payment-row-actions">
                                    <?php if (!empty($paymentRow['payment_id'])): ?>
                                        <span class="muted compact">Ödeme no: <?= h($paymentRow['payment_id']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((string) ($paymentRow['status'] ?? '') === 'pending' && !empty($paymentRow['payment_page_url'])): ?>
                                        <a class="button small secondary" href="<?= h((string) $paymentRow['payment_page_url']) ?>" target="_blank" rel="noopener">Linki aç</a>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($paymentRow['error_message'])): ?>
                                    <p class="payment-row-error"><?= h($paymentRow['error_message']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel narrow">
                <h2>Yenilendi olarak ilerlet</h2>
                <form
                    method="post"
                    action="<?= h(url('/renewals/' . $id . '/renew')) ?>"
                    class="inline-form"
                    onsubmit="return confirm('Kayit mevcut yenileme tarihinden sonraki doneme ilerletilsin mi?')"
                    data-renew-form
                    data-start-date="<?= h($nextRenewalStartDate ?? date('Y-m-d')) ?>"
                    data-end-date="<?= h($nextRenewalDate ?? '') ?>"
                >
                    <?= csrf_field() ?>
                    <label>
                        Yeni dönem fatura no
                        <input name="invoice_number" required placeholder="Örn: BIGA-<?= h(date('Y')) ?>-001">
                    </label>
                    <span class="muted compact" data-renew-period-preview></span>
                    <button type="submit" class="button secondary">Periyoda gore yenile</button>
                </form>
            </section>
            <?php if ($invoiceRows): ?>
                <section class="panel narrow">
                    <h2>Fatura dönemleri</h2>
                    <div class="detail-grid">
                        <?php foreach ($invoiceRows as $invoiceRow): ?>
                            <div>
                                <span><?= h(date('d.m.Y', strtotime($invoiceRow['period_start_date']))) ?> - <?= h(date('d.m.Y', strtotime($invoiceRow['period_end_date']))) ?></span>
                                <strong><?= h($invoiceRow['invoice_number']) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    });
}

function handle_customers(RenewalRepository $repo, string $method): void
{
    $errors = [];
    $requestRepo = new CustomerInfoRequestRepository();
    if ($method === 'POST') {
        require_permission('customers.manage');
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'create_customer');

        if ($action === 'send_customer_info_request') {
            try {
                send_customer_info_request($requestRepo, $_POST);
                flash('success', 'Cari bilgi talep maili gonderildi.');
                redirect('/customers');
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        } else {
            $errors = validate_customer_form($_POST);

            if ($errors === []) {
                $repo->createCustomer($_POST);
                flash('success', 'Musteri eklendi.');
                redirect('/customers');
            }
        }
    }

    $customers = $repo->customersWithContacts();
    $contactRows = submitted_contact_rows();
    $parasutStatus = (new ParasutClient())->status();
    $canManage = Auth::can('customers.manage');
    $canDelete = Auth::can('customers.delete');
    $canDetails = Auth::can('customers.details');

    render_layout('Musteriler', static function () use ($customers, $errors, $parasutStatus, $contactRows, $canManage, $canDelete, $canDetails): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Cari kaynak</p>
                <h1>Musteriler</h1>
            </div>
            <div class="page-actions">
                <?php if ($canManage): ?>
                    <button type="button" class="button primary" data-dialog-open="customer-create-dialog">+ Yeni müşteri</button>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <a href="<?= h(url('/customers/deleted')) ?>" class="button secondary">Silinenler havuzu</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert error"><?= h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <?= render_customer_create_dialog($customers, $contactRows, $parasutStatus, $errors !== []) ?>
        <?php endif; ?>

        <section class="single-panel">
            <div class="panel">
                <h2>Kayitli musteriler</h2>
                <div class="stack">
                    <?php foreach ($customers as $customer): ?>
                            <?php if ($canDetails): ?>
                                <details class="customer-row customer-row-compact">
                                    <summary class="customer-summary">
                                        <span class="customer-summary-main">
                                            <strong><?= h($customer['company_name']) ?></strong>
                                            <small>
                                                <?= h($customer['contact_name'] ?: 'Yetkili yok') ?>
                                                <?= !empty($customer['city']) ? ' - ' . h($customer['city']) : '' ?>
                                            </small>
                                        </span>
                                        <span class="customer-summary-meta">
                                            <span class="badge active"><?= h((string) count($customer['contacts'] ?? [])) ?> yetkili</span>
                                            <?php if (!empty($customer['tax_number'])): ?>
                                                <span><?= h($customer['tax_number']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="customer-detail-toggle">
                                            <span class="closed">Göster</span>
                                            <span class="open">Gizle</span>
                                        </span>
                                    </summary>
                                    <div class="customer-details">
                                        <div class="customer-detail-grid">
                                            <span><small>Yetkili</small><strong><?= h($customer['contact_name'] ?: '-') ?></strong></span>
                                            <span><small>E-posta</small><strong><?= h($customer['email'] ?: '-') ?></strong></span>
                                            <span><small>Telefon</small><strong><?= h($customer['phone'] ?: '-') ?></strong></span>
                                            <span><small>Konum</small><strong><?= h(trim((string) (($customer['city'] ?? '') . ' / ' . ($customer['district'] ?? '')), ' /') ?: '-') ?></strong></span>
                                            <span><small>Vergi no</small><strong><?= h($customer['tax_number'] ?: '-') ?></strong></span>
                                            <span><small>Paraşüt</small><strong><?= h($customer['parasut_contact_id'] ? '#' . $customer['parasut_contact_id'] : '-') ?></strong></span>
                                        </div>
                                        <?php if (!empty($customer['address'])): ?>
                                            <p class="customer-address"><?= h($customer['address']) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($customer['contacts'])): ?>
                                            <div class="customer-contacts">
                                                <?php foreach ($customer['contacts'] as $contact): ?>
                                                    <div class="customer-contact">
                                                        <span>
                                                            <strong><?= h($contact['full_name']) ?></strong>
                                                            <?= h($contact['email'] ?: '-') ?>
                                                            <?= !empty($contact['phone']) ? ' - ' . h($contact['phone']) : '' ?>
                                                        </span>
                                                        <span class="badge <?= ((int) $contact['notify_enabled'] === 1) ? 'active' : 'cancelled' ?>">
                                                            <?= ((int) $contact['notify_enabled'] === 1) ? 'Bilgilendirme acik' : 'Bilgilendirme kapali' ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($canManage || $canDelete): ?>
                                            <div class="customer-actions">
                                                <?php if ($canManage): ?>
                                                    <a href="<?= h(url('/customers/' . $customer['id'] . '/edit')) ?>" class="button small secondary" onclick="return confirm('Bu musteri karti duzenlensin mi?')">Duzenle</a>
                                                <?php endif; ?>
                                                <?php if ($canDelete): ?>
                                                    <form method="post" action="<?= h(url('/customers/' . $customer['id'] . '/delete')) ?>" onsubmit="return confirm('Bu musteri silinenler havuzuna tasinsin mi?')">
                                                        <?= csrf_field() ?>
                                                        <button type="submit" class="button small danger">Sil</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php else: ?>
                                <div class="customer-row customer-row-compact">
                                    <div class="customer-row-head">
                                        <strong><?= h($customer['company_name']) ?></strong>
                                        <span>Detay bilgileri yetkinize kapali.</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
    });
}

function render_customer_create_dialog(array $customers, array $contactRows, array $parasutStatus, bool $openOnLoad = false): string
{
    ob_start();
    ?>
    <dialog class="app-dialog customer-create-dialog" id="customer-create-dialog" <?= $openOnLoad ? 'data-auto-open-dialog' : '' ?>>
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <p class="eyebrow">Yeni cari</p>
                    <h2>Yeni müşteri tanımla</h2>
                    <span>Manuel kayıt oluşturabilir veya Paraşüt carisinden bilgileri alabilirsiniz.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <div class="parasut-search customer-parasut-search" data-parasut-search data-parasut-type="all" data-parasut-target-form="customer-create-form">
                <label>
                    <span>Paraşüt cari ara</span>
                    <small>Cari adını yazın, sistem müşteri ve tedarikçi kayıtlarında arar.</small>
                    <input type="search" data-parasut-query placeholder="Cari adı yazın" autocomplete="off">
                </label>
                <div class="suggestions" data-parasut-results>
                    <?php if (!$parasutStatus['connected']): ?>
                        <a href="<?= h(url('/settings#parasut-settings')) ?>">Paraşüt bağlantısını yap</a>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" class="form-grid customer-create-form" data-parasut-form="customer-create-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_customer">
                <input type="hidden" name="parasut_contact_id" value="<?= h(old('parasut_contact_id')) ?>">
                <label>Firma adı <input name="company_name" value="<?= h(old('company_name')) ?>" required></label>
                <label>Yetkili <input name="contact_name" value="<?= h(old('contact_name')) ?>"></label>
                <label>E-posta <input type="email" name="email" value="<?= h(old('email')) ?>"></label>
                <label>Telefon <input name="phone" value="<?= h(old('phone')) ?>"></label>
                <label>Vergi dairesi <input name="tax_office" value="<?= h(old('tax_office')) ?>"></label>
                <label>Vergi no <input name="tax_number" value="<?= h(old('tax_number')) ?>"></label>
                <label>İl <input name="city" value="<?= h(old('city')) ?>"></label>
                <label>İlçe <input name="district" value="<?= h(old('district')) ?>"></label>
                <label class="span-2">Adres <textarea name="address" rows="3"><?= h(old('address')) ?></textarea></label>
                <div class="contact-editor span-2" data-contact-editor data-next-index="<?= h((string) next_contact_index($contactRows)) ?>">
                    <div class="section-head">
                        <h3>Yetkililer</h3>
                        <button type="button" class="button small secondary" data-add-contact>+ Yetkili ekle</button>
                    </div>
                    <div class="contact-list" data-contact-list>
                        <?php foreach ($contactRows as $index => $contact): ?>
                            <?= render_contact_input_row((int) $index, $contact) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <label class="span-2">Notlar <textarea name="notes" rows="3"><?= h(old('notes')) ?></textarea></label>
                <div class="form-actions span-2">
                    <button type="button" class="button secondary" data-dialog-close>Vazgeç</button>
                    <button type="submit" class="button primary">Müşteri ekle</button>
                </div>
            </form>
        </div>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function render_customer_info_request_dialog(array $customers, string $returnTo = '/', bool $openOnLoad = false): string
{
    ob_start();
    ?>
    <dialog class="app-dialog customer-info-request-dialog" id="customer-info-request-dialog" <?= $openOnLoad ? 'data-auto-open-dialog' : '' ?>>
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <p class="eyebrow">Cari bilgi talebi</p>
                    <h2>Müşteriden cari bilgisi talep et</h2>
                    <span>Mail adresini yazın. Müşteri güvenli bağlantıdan formu doldurabilir veya vergi levhası yükleyebilir.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <div class="customer-request-box dashboard-request-box">
                <div class="customer-request-copy">
                    <strong>Ürün yenileme bildirimi ve teklif için cari bilgileri isteyin</strong>
                    <span>Gönderilen bağlantı ile müşteri firma, vergi, adres ve yetkili bilgilerini tamamlar. Form doldurulunca size bilgi maili gelir.</span>
                </div>
                <form method="post" action="<?= h(url('/customer-info/request')) ?>" class="request-inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                    <label>
                        Yetkili adı
                        <input name="request_contact_name" placeholder="Ad soyad">
                    </label>
                    <label>
                        E-posta adresi
                        <input type="email" name="request_email" placeholder="musteri@firma.com" required>
                    </label>
                    <label>
                        Kayıt tipi
                        <select name="request_customer_id">
                            <option value="">Yeni cari olarak oluşsun</option>
                            <?php foreach ($customers as $customerOption): ?>
                                <?= option((string) $customerOption['id'], $customerOption['company_name'], '') ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="button secondary" data-dialog-close>Vazgeç</button>
                        <button type="submit" class="button primary">Talep maili gönder</button>
                    </div>
                </form>
            </div>
        </div>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function handle_customer_edit(RenewalRepository $repo, string $method, int $id): void
{
    $customer = $repo->findCustomer($id);
    if (!$customer) {
        flash('error', 'Musteri bulunamadi.');
        redirect('/customers');
    }

    $errors = [];
    $contactRows = $customer['contacts'] ?: default_contact_rows();

    if ($method === 'POST') {
        verify_csrf();
        $errors = validate_customer_form($_POST);

        if ($errors === []) {
            $repo->updateCustomer($id, $_POST);
            flash('success', 'Musteri guncellendi.');
            redirect('/customers');
        }

        $customer = array_merge($customer, $_POST);
        $contactRows = submitted_contact_rows();
    }

    $parasutStatus = (new ParasutClient())->status();

    render_layout('Musteri Duzenle', static function () use ($customer, $contactRows, $parasutStatus, $errors): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Musteri karti</p>
                <h1>Musteri Duzenle</h1>
            </div>
            <a href="<?= h(url('/customers')) ?>" class="button secondary">Musterilere don</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert error"><?= h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <section class="panel narrow-form">
            <form method="post" class="form-grid" onsubmit="return confirm('Musteri bilgileri guncellensin mi?')">
                <?= csrf_field() ?>
                <?= render_customer_form_fields($customer, $contactRows, $parasutStatus, false) ?>
                <button type="submit" class="button primary">Guncelle</button>
            </form>
        </section>
        <?php
    });
}

function render_deleted_customers(RenewalRepository $repo): void
{
    $customers = $repo->deletedCustomersWithContacts();

    render_layout('Silinenler Havuzu', static function () use ($customers): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Havuz</p>
                <h1>Silinenler Havuzu</h1>
            </div>
            <a href="<?= h(url('/customers')) ?>" class="button secondary">Musterilere don</a>
        </div>

        <section class="panel">
            <?php if ($customers === []): ?>
                <div class="empty">Silinen musteri bulunmuyor.</div>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($customers as $customer): ?>
                        <div class="customer-row">
                            <div class="customer-row-head">
                                <strong><?= h($customer['company_name']) ?></strong>
                                <form method="post" action="<?= h(url('/customers/' . $customer['id'] . '/restore')) ?>" onsubmit="return confirm('Bu musteri havuzdan geri alinsin mi?')">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="button small secondary">Geri al</button>
                                </form>
                            </div>
                            <span>Silinme tarihi: <?= h($customer['deleted_at'] ? date('d.m.Y H:i', strtotime($customer['deleted_at'])) : '-') ?></span>
                            <span><?= h($customer['email'] ?: '-') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    });
}

function handle_suppliers(RenewalRepository $repo, string $method): void
{
    $errors = [];

    if ($method === 'POST') {
        require_permission('suppliers.manage');
        verify_csrf();

        try {
            $errors = validate_supplier_form($_POST);
            if ($errors === []) {
                $repo->createSupplier($_POST);
                flash('success', 'Tedarikci eklendi.');
                redirect('/suppliers');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $suppliers = $repo->suppliersWithContacts();
    $supplierGroups = $repo->supplierGroups();
    $supplierFormData = $method === 'POST' ? $_POST : [];
    $contactRows = $supplierFormData !== [] ? submitted_contact_rows() : default_contact_rows();
    $parasutStatus = (new ParasutClient())->status();
    $canManage = Auth::can('suppliers.manage');
    $canDelete = Auth::can('suppliers.delete');
    $canDetails = Auth::can('suppliers.details');
    $canDefinitions = Auth::can('definitions.manage');

    render_layout('Tedarikciler', static function () use ($suppliers, $supplierGroups, $supplierFormData, $errors, $parasutStatus, $contactRows, $canManage, $canDelete, $canDetails, $canDefinitions): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Tedarik kaynagi</p>
                <h1>Tedarikciler</h1>
            </div>
            <div class="customer-actions">
                <?php if ($canManage): ?>
                    <button type="button" class="button primary" data-dialog-open="supplier-create-dialog">Yeni tedarikçi</button>
                <?php endif; ?>
                <?php if ($canDefinitions): ?>
                    <a href="<?= h(url('/settings/definitions#supplier-groups')) ?>" class="button secondary">Gruplari yonet</a>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <a href="<?= h(url('/suppliers/deleted')) ?>" class="button secondary">Silinenler havuzu</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert error"><?= h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <dialog class="app-dialog supplier-create-dialog" id="supplier-create-dialog" <?= $errors ? 'data-auto-open-dialog' : '' ?>>
                <div class="app-dialog-body">
                    <div class="section-head dialog-head">
                        <div>
                            <h2>Yeni tedarikçi</h2>
                            <span>Paraşüt carilerinden seçebilir veya tedarikçiyi manuel olarak ekleyebilirsiniz.</span>
                        </div>
                        <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
                    </div>

                    <form method="post" class="form-grid supplier-create-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_supplier">
                        <?= render_customer_form_fields($supplierFormData, $contactRows, $parasutStatus, true, 'all', $supplierGroups) ?>
                        <button type="submit" class="button primary full">Tedarikçi ekle</button>
                    </form>
                </div>
            </dialog>
        <?php endif; ?>

        <section class="single-panel">
            <div class="panel">
                <h2>Kayitli tedarikciler</h2>
                <div class="stack">
                    <?php foreach ($suppliers as $supplier): ?>
                            <?php if ($canDetails): ?>
                                <details class="customer-row customer-row-compact supplier-row-compact">
                                    <summary class="customer-summary">
                                        <span class="customer-summary-main">
                                            <strong><?= h($supplier['company_name']) ?></strong>
                                            <small>
                                                <?= h($supplier['contact_name'] ?: 'Yetkili yok') ?>
                                                <?= !empty($supplier['city']) ? ' - ' . h($supplier['city']) : '' ?>
                                            </small>
                                        </span>
                                        <span class="customer-summary-meta">
                                            <?php if (!empty($supplier['supplier_group_name'])): ?>
                                                <span class="badge active"><?= h($supplier['supplier_group_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($supplier['tax_number'])): ?>
                                                <span><?= h($supplier['tax_number']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="customer-detail-toggle">
                                            <span class="closed">Göster</span>
                                            <span class="open">Gizle</span>
                                        </span>
                                    </summary>
                                    <div class="customer-details">
                                        <div class="customer-detail-grid">
                                            <span><small>Yetkili</small><strong><?= h($supplier['contact_name'] ?: '-') ?></strong></span>
                                            <span><small>E-posta</small><strong><?= h($supplier['email'] ?: '-') ?></strong></span>
                                            <span><small>Telefon</small><strong><?= h($supplier['phone'] ?: '-') ?></strong></span>
                                            <span><small>Konum</small><strong><?= h(trim((string) (($supplier['city'] ?? '') . ' / ' . ($supplier['district'] ?? '')), ' /') ?: '-') ?></strong></span>
                                            <span><small>Vergi no</small><strong><?= h($supplier['tax_number'] ?: '-') ?></strong></span>
                                            <span><small>Paraşüt</small><strong><?= h($supplier['parasut_contact_id'] ? '#' . $supplier['parasut_contact_id'] : '-') ?></strong></span>
                                        </div>
                                        <?= render_contact_badges($supplier['contacts'] ?? []) ?>
                                        <?php if ($canManage || $canDelete): ?>
                                            <div class="customer-actions">
                                                <?php if ($canManage): ?>
                                                    <a href="<?= h(url('/suppliers/' . $supplier['id'] . '/edit')) ?>" class="button small secondary" onclick="return confirm('Bu tedarikci karti duzenlensin mi?')">Duzenle</a>
                                                <?php endif; ?>
                                                <?php if ($canDelete): ?>
                                                    <form method="post" action="<?= h(url('/suppliers/' . $supplier['id'] . '/delete')) ?>" onsubmit="return confirm('Bu tedarikci silinenler havuzuna tasinsin mi?')">
                                                        <?= csrf_field() ?>
                                                        <button type="submit" class="button small danger">Sil</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php else: ?>
                                <div class="customer-row customer-row-compact">
                                    <div class="customer-row-head">
                                        <strong><?= h($supplier['company_name']) ?></strong>
                                        <span>Detay bilgileri yetkinize kapali.</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
    });
}

function handle_supplier_edit(RenewalRepository $repo, string $method, int $id): void
{
    $supplier = $repo->findSupplier($id);
    if (!$supplier) {
        flash('error', 'Tedarikci bulunamadi.');
        redirect('/suppliers');
    }

    $errors = [];
    $contactRows = $supplier['contacts'] ?: default_contact_rows();

    if ($method === 'POST') {
        verify_csrf();
        $errors = validate_supplier_form($_POST);

        if ($errors === []) {
            $repo->updateSupplier($id, $_POST);
            flash('success', 'Tedarikci guncellendi.');
            redirect('/suppliers');
        }

        $supplier = array_merge($supplier, $_POST);
        $contactRows = submitted_contact_rows();
    }

    $parasutStatus = (new ParasutClient())->status();
    $supplierGroups = $repo->supplierGroups();

    render_layout('Tedarikci Duzenle', static function () use ($supplier, $contactRows, $parasutStatus, $supplierGroups, $errors): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Tedarikci karti</p>
                <h1>Tedarikci Duzenle</h1>
            </div>
            <a href="<?= h(url('/suppliers')) ?>" class="button secondary">Tedarikcilere don</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert error"><?= h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <section class="panel narrow-form">
            <form method="post" class="form-grid" onsubmit="return confirm('Tedarikci bilgileri guncellensin mi?')">
                <?= csrf_field() ?>
                <?= render_customer_form_fields($supplier, $contactRows, $parasutStatus, false, 'supplier', $supplierGroups) ?>
                <button type="submit" class="button primary">Guncelle</button>
            </form>
        </section>
        <?php
    });
}

function render_deleted_suppliers(RenewalRepository $repo): void
{
    $suppliers = $repo->suppliersWithContacts(true);

    render_layout('Silinen Tedarikciler', static function () use ($suppliers): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Havuz</p>
                <h1>Silinen Tedarikciler</h1>
            </div>
            <a href="<?= h(url('/suppliers')) ?>" class="button secondary">Tedarikcilere don</a>
        </div>

        <section class="panel">
            <?php if ($suppliers === []): ?>
                <div class="empty">Silinen tedarikci bulunmuyor.</div>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($suppliers as $supplier): ?>
                        <div class="customer-row">
                            <div class="customer-row-head">
                                <strong><?= h($supplier['company_name']) ?></strong>
                                <form method="post" action="<?= h(url('/suppliers/' . $supplier['id'] . '/restore')) ?>" onsubmit="return confirm('Bu tedarikci havuzdan geri alinsin mi?')">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="button small secondary">Geri al</button>
                                </form>
                            </div>
                            <span>Silinme tarihi: <?= h($supplier['deleted_at'] ? date('d.m.Y H:i', strtotime($supplier['deleted_at'])) : '-') ?></span>
                            <?php if (!empty($supplier['supplier_group_name'])): ?>
                                <span class="badge active">Grup: <?= h($supplier['supplier_group_name']) ?></span>
                            <?php endif; ?>
                            <span><?= h($supplier['email'] ?: '-') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    });
}

function validate_supplier_form(array $data): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return [];
    }

    $errors = [];
    if (trim((string) ($data['company_name'] ?? '')) === '') {
        $errors[] = 'Tedarikci adi zorunlu.';
    }
    foreach (submitted_contact_rows() as $contact) {
        if (!empty($contact['notify_enabled']) && trim((string) ($contact['email'] ?? '')) === '') {
            $errors[] = 'Bilgilendirme gonderilecek yetkililer icin e-posta zorunlu.';
            break;
        }
    }

    return $errors;
}

function handle_customer_info_request_submit(): void
{
    verify_csrf();
    $returnTo = safe_return_path($_POST['return_to'] ?? '/');

    try {
        send_customer_info_request(new CustomerInfoRequestRepository(), $_POST);
        flash('success', 'Cari bilgi talep maili gonderildi.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect($returnTo);
}

function render_contact_badges(array $contacts): string
{
    if ($contacts === []) {
        return '';
    }

    ob_start();
    ?>
    <div class="customer-contacts">
        <?php foreach ($contacts as $contact): ?>
            <div class="customer-contact">
                <span>
                    <strong><?= h($contact['full_name']) ?></strong>
                    <?= h($contact['email'] ?: '-') ?>
                </span>
                <span class="badge <?= ((int) $contact['notify_enabled'] === 1) ? 'active' : 'cancelled' ?>">
                    <?= ((int) $contact['notify_enabled'] === 1) ? 'Bilgilendirme acik' : 'Bilgilendirme kapali' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function validate_customer_form(array $data): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return [];
    }

    $errors = [];
    if (trim((string) ($data['company_name'] ?? '')) === '') {
        $errors[] = 'Firma adi zorunlu.';
    }
    foreach (submitted_contact_rows() as $contact) {
        if (!empty($contact['notify_enabled']) && trim((string) ($contact['email'] ?? '')) === '') {
            $errors[] = 'Bilgilendirme gonderilecek yetkililer icin e-posta zorunlu.';
            break;
        }
    }

    return $errors;
}

function send_customer_info_request(CustomerInfoRequestRepository $repo, array $data): void
{
    $email = trim((string) ($data['request_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Cari bilgi talebi icin gecerli bir e-posta adresi girin.');
    }

    $recipientName = trim((string) ($data['request_contact_name'] ?? ''));
    $customerId = empty($data['request_customer_id']) ? null : (int) $data['request_customer_id'];
    $request = $repo->create($email, $customerId, (int) ($_SESSION['user_id'] ?? 0), $recipientName);
    $link = absolute_app_url('/cari-bilgi/' . $request['token']);
    $settings = (new SettingsRepository())->all();
    $mail = MailTemplate::renderCustomerInfoRequest($settings, $request, $link);
    $result = Mailer::sendWithResult(
        $email,
        'Cari bilgi formu',
        (string) $mail['body'],
        (bool) $mail['is_html'],
        $mail['inline_attachments'] ?? []
    );

    if (!$result['ok']) {
        throw new RuntimeException((string) $result['error']);
    }
}

function send_customer_info_completed_notification(array $request, int $customerId, array $values): array
{
    $createdBy = (int) ($request['created_by'] ?? 0);
    $recipient = null;
    if ($createdBy > 0) {
        $recipient = (new UserRepository())->find($createdBy);
    }

    $to = trim((string) ($recipient['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || (int) ($recipient['is_active'] ?? 0) !== 1) {
        $settings = (new SettingsRepository())->all();
        $to = trim((string) ($settings['mail.from_email'] ?? ''));
    }

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Bildirim icin gecerli bir alici e-posta adresi bulunamadi.'];
    }

    $settings = (new SettingsRepository())->all();
    $mail = MailTemplate::renderCustomerInfoSubmitted($settings, $request, $values, $customerId);

    return Mailer::sendWithResult(
        $to,
        'Cari bilgi formu tamamlandi',
        (string) $mail['body'],
        (bool) $mail['is_html'],
        $mail['inline_attachments'] ?? []
    );
}

function customer_info_status_label(string $status): string
{
    return match ($status) {
        'submitted' => 'Tamamlandi',
        'expired' => 'Suresi doldu',
        default => 'Bekliyor',
    };
}

function render_customer_form_fields(array $customer, array $contactRows, array $parasutStatus, bool $showParasutSearch = true, string $parasutType = 'customer', array $supplierGroups = []): string
{
    ob_start();
    if ($showParasutSearch): ?>
        <div class="parasut-search" data-parasut-search data-parasut-type="all">
            <label>
                Parasut cari ara
                <input type="search" data-parasut-query placeholder="Cari adi yazin" autocomplete="off">
            </label>
            <div class="suggestions" data-parasut-results>
                <?php if (!$parasutStatus['connected']): ?>
                    <a href="<?= h(url('/settings#parasut-settings')) ?>">Parasut baglantisini yap</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <input type="hidden" name="parasut_contact_id" value="<?= h($customer['parasut_contact_id'] ?? '') ?>">
    <?php if ($parasutType !== 'customer'): ?>
        <label>
            Tedarikci grubu
            <select name="supplier_group_id">
                <option value="">Grup secilmedi</option>
                <?php foreach ($supplierGroups as $group): ?>
                    <?= option((string) $group['id'], $group['name'], (string) ($customer['supplier_group_id'] ?? '')) ?>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <label>Firma adi <input name="company_name" value="<?= h($customer['company_name'] ?? '') ?>" required></label>
    <label>Yetkili <input name="contact_name" value="<?= h($customer['contact_name'] ?? '') ?>"></label>
    <label>E-posta <input type="email" name="email" value="<?= h($customer['email'] ?? '') ?>"></label>
    <label>Telefon <input name="phone" value="<?= h($customer['phone'] ?? '') ?>"></label>
    <label>Vergi dairesi <input name="tax_office" value="<?= h($customer['tax_office'] ?? '') ?>"></label>
    <label>Vergi no <input name="tax_number" value="<?= h($customer['tax_number'] ?? '') ?>"></label>
    <label>Il <input name="city" value="<?= h($customer['city'] ?? '') ?>"></label>
    <label>Ilce <input name="district" value="<?= h($customer['district'] ?? '') ?>"></label>
    <label>Adres <textarea name="address" rows="3"><?= h($customer['address'] ?? '') ?></textarea></label>
    <div class="contact-editor" data-contact-editor data-next-index="<?= h((string) next_contact_index($contactRows)) ?>">
        <div class="section-head">
            <h3>Yetkililer</h3>
            <button type="button" class="button small secondary" data-add-contact>Yetkili ekle</button>
        </div>
        <div class="contact-list" data-contact-list>
            <?php foreach ($contactRows as $index => $contact): ?>
                <?= render_contact_input_row((int) $index, $contact) ?>
            <?php endforeach; ?>
        </div>
    </div>
    <label>Notlar <textarea name="notes" rows="3"><?= h($customer['notes'] ?? '') ?></textarea></label>
    <?php
    return (string) ob_get_clean();
}

function submitted_contact_rows(): array
{
    $rows = $_POST['contacts'] ?? null;
    if (!is_array($rows) || $rows === []) {
        return default_contact_rows();
    }

    return $rows;
}

function default_contact_rows(): array
{
    return [[
        'full_name' => '',
        'email' => '',
        'phone' => '',
        'notify_enabled' => 1,
    ]];
}

function next_contact_index(array $rows): int
{
    $numericKeys = array_filter(array_keys($rows), static fn (mixed $key): bool => is_int($key) || ctype_digit((string) $key));

    return $numericKeys === [] ? 0 : max(array_map('intval', $numericKeys)) + 1;
}

function render_contact_input_row(int $index, array $contact = []): string
{
    $checked = !empty($contact['notify_enabled']) ? 'checked' : '';

    return sprintf(
        '<div class="contact-entry" data-contact-row>
            <label>Yetkili adi <input name="contacts[%1$d][full_name]" value="%2$s" data-contact-name></label>
            <label>E-posta <input type="email" name="contacts[%1$d][email]" value="%3$s" data-contact-email></label>
            <label>Telefon <input name="contacts[%1$d][phone]" value="%4$s" data-contact-phone></label>
            <label class="checkline"><input type="checkbox" name="contacts[%1$d][notify_enabled]" value="1" %5$s> Bilgilendirme gonder</label>
            <button type="button" class="button small danger" data-remove-contact>Kaldir</button>
        </div>',
        $index,
        h($contact['full_name'] ?? ''),
        h($contact['email'] ?? ''),
        h($contact['phone'] ?? ''),
        $checked
    );
}

function renewal_item_rows(RenewalRepository $repo, ?array $renewal, ?int $id): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['items']) && is_array($_POST['items'])) {
        return array_values(array_filter($_POST['items'], 'is_array'));
    }

    if ($id) {
        $items = $repo->renewalItems($id);
        if ($items !== []) {
            return $items;
        }
    }

    return [[
        'definition_id' => $renewal['definition_id'] ?? '',
        'title' => $renewal['title'] ?? '',
        'kind' => $renewal['kind'] ?? 'product',
        'brand' => $renewal['brand'] ?? '',
        'license_key' => $renewal['license_key'] ?? '',
        'quantity' => '1',
        'unit_price' => $renewal['amount'] ?? '',
        'vat_rate' => '20',
    ]];
}

function next_renewal_item_index(array $rows): int
{
    return count($rows);
}

function render_renewal_item_row(int $index, array $item, array $definitions): string
{
    ob_start();
    $kind = (string) ($item['kind'] ?? 'product');
    $selectedDefinition = (string) ($item['definition_id'] ?? '');
    ?>
    <div class="renewal-product-row" data-renewal-item-row>
        <div class="item-row-head">
            <strong>Ürün satırı <?= h((string) ($index + 1)) ?></strong>
            <button type="button" class="button small danger" data-remove-renewal-item>Kaldır</button>
        </div>
        <div class="section-fields item-grid">
            <label class="field-wide">
                Ürün / hizmet adı
                <select name="items[<?= h((string) $index) ?>][definition_id]" data-item-definition>
                    <option value="">Tanım seçin</option>
                    <?php foreach ($definitions as $definition): ?>
                        <option
                            value="<?= h($definition['id']) ?>"
                            data-kind="<?= h($definition['kind']) ?>"
                            <?= $selectedDefinition === (string) $definition['id'] ? 'selected' : '' ?>
                        >
                            <?= h(($definition['kind'] === 'product' ? 'Ürün' : 'Hizmet') . ' - ' . $definition['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Özel ad
                <input name="items[<?= h((string) $index) ?>][title]" value="<?= h($item['title'] ?? '') ?>" placeholder="Tanım yoksa yazın">
            </label>
            <label>
                Tip
                <select name="items[<?= h((string) $index) ?>][kind]" data-item-kind>
                    <?= option('product', 'Ürün', $kind) ?>
                    <?= option('service', 'Hizmet', $kind) ?>
                </select>
            </label>
            <label>
                Marka
                <input name="items[<?= h((string) $index) ?>][brand]" value="<?= h($item['brand'] ?? '') ?>" placeholder="Örn: TrendMicro">
            </label>
            <label>
                Lisans / referans no
                <input name="items[<?= h((string) $index) ?>][license_key]" value="<?= h($item['license_key'] ?? '') ?>" placeholder="Opsiyonel">
            </label>
            <label>
                Adet
                <input type="number" min="0.01" step="0.01" name="items[<?= h((string) $index) ?>][quantity]" value="<?= h($item['quantity'] ?? '1') ?>" data-line-quantity>
            </label>
            <label>
                Birim fiyat
                <input type="number" min="0" step="0.01" name="items[<?= h((string) $index) ?>][unit_price]" value="<?= h($item['unit_price'] ?? '') ?>" placeholder="0.00" data-line-price>
            </label>
            <label>
                KDV %
                <input type="number" min="0" max="100" step="0.01" name="items[<?= h((string) $index) ?>][vat_rate]" value="<?= h($item['vat_rate'] ?? '20') ?>" data-line-vat>
            </label>
            <div class="line-total" data-line-total>Toplam: -</div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function renewal_reminder_days(?array $renewal = null): array
{
    $rows = null;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['reminder_rules'])) {
        $rows = $_POST['reminder_rules'];
    } elseif (!empty($renewal['reminder_rule_days'])) {
        $rows = explode(',', (string) $renewal['reminder_rule_days']);
    } elseif (!empty($renewal['reminder_days'])) {
        $rows = [$renewal['reminder_days']];
    }

    if (!is_array($rows) || $rows === []) {
        $rows = [app_config('reminders.default_days_before', 30), 7];
    }

    $days = [];
    foreach ($rows as $row) {
        $day = max(0, (int) $row);
        if ($day > 0) {
            $days[$day] = $day;
        }
    }

    $days[7] = 7;
    rsort($days, SORT_NUMERIC);

    return array_values($days ?: [7]);
}

function next_reminder_index(array $rows): int
{
    return count($rows);
}

function render_reminder_input_row(int $index, int $day): string
{
    $disabled = $day === 7 ? 'disabled' : '';
    $label = $day === 7 ? '<span class="badge active">Günlük</span>' : '';

    return sprintf(
        '<div class="contact-entry reminder-entry" data-reminder-row>
            <label>Gün sayısı <input type="number" min="1" name="reminder_rules[%1$d]" value="%2$d"></label>
            %3$s
            <button type="button" class="button small danger" data-remove-reminder %4$s>Kaldır</button>
        </div>',
        $index,
        $day,
        $label,
        $disabled
    );
}

function handle_parasut_integration(string $method): void
{
    $client = new ParasutClient();

    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'exchange') {
                $client->exchangeCode((string) ($_POST['code'] ?? ''), (string) ($_POST['company_id'] ?? ''));
                flash('success', 'Parasut baglantisi tamamlandi.');
                redirect('/settings#parasut-settings');
            }

            if ($action === 'disconnect') {
                $client->disconnect();
                flash('success', 'Parasut baglantisi kaldirildi.');
                redirect('/settings#parasut-settings');
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
    }

    redirect('/settings#parasut-settings');
}

function handle_definitions(RenewalRepository $repo, string $method): void
{
    $error = null;

    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'create');

        try {
            if ($action === 'create') {
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Urun/hizmet adi zorunlu.');
                }

                $repo->createRenewalDefinition($_POST);
                flash('success', 'Tanim kaydedildi.');
                redirect('/settings/definitions');
            }

            if ($action === 'delete') {
                $repo->deleteRenewalDefinition((int) ($_POST['id'] ?? 0));
                flash('success', 'Tanim silindi.');
                redirect('/settings/definitions');
            }

            if ($action === 'update') {
                $repo->updateRenewalDefinition((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Tanim guncellendi.');
                redirect('/settings/definitions');
            }

            if ($action === 'create_period') {
                if ((int) ($_POST['interval_count'] ?? 0) < 1) {
                    throw new RuntimeException('Periyot sayisi en az 1 olmali.');
                }

                $repo->createRenewalPeriod($_POST);
                flash('success', 'Yenileme periyodu kaydedildi.');
                redirect('/settings/definitions');
            }

            if ($action === 'delete_period') {
                $repo->deleteRenewalPeriod((int) ($_POST['id'] ?? 0));
                flash('success', 'Yenileme periyodu silindi.');
                redirect('/settings/definitions');
            }

            if ($action === 'update_period') {
                if ((int) ($_POST['interval_count'] ?? 0) < 1) {
                    throw new RuntimeException('Periyot sayisi en az 1 olmali.');
                }

                $repo->updateRenewalPeriod((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Yenileme periyodu guncellendi.');
                redirect('/settings/definitions');
            }

            if ($action === 'create_supplier_group') {
                if (trim((string) ($_POST['group_name'] ?? '')) === '') {
                    throw new RuntimeException('Tedarikci grup adi zorunlu.');
                }

                $repo->createSupplierGroup($_POST);
                flash('success', 'Tedarikci grubu kaydedildi.');
                redirect('/settings/definitions#supplier-groups');
            }

            if ($action === 'delete_supplier_group') {
                $repo->deleteSupplierGroup((int) ($_POST['id'] ?? 0));
                flash('success', 'Tedarikci grubu silindi.');
                redirect('/settings/definitions#supplier-groups');
            }

            if ($action === 'update_supplier_group') {
                $repo->updateSupplierGroup((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Tedarikci grubu guncellendi.');
                redirect('/settings/definitions#supplier-groups');
            }

            if ($action === 'create_payment_method') {
                $repo->createPaymentMethod($_POST);
                flash('success', 'Odeme yontemi kaydedildi.');
                redirect('/settings/definitions#payment-methods');
            }

            if ($action === 'delete_payment_method') {
                $repo->deletePaymentMethod((int) ($_POST['id'] ?? 0));
                flash('success', 'Odeme yontemi silindi.');
                redirect('/settings/definitions#payment-methods');
            }

            if ($action === 'update_payment_method') {
                $repo->updatePaymentMethod((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Odeme yontemi guncellendi.');
                redirect('/settings/definitions#payment-methods');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $definitions = $repo->renewalDefinitions();
    $periods = $repo->renewalPeriods();
    $supplierGroups = $repo->supplierGroups();
    $paymentMethods = $repo->paymentMethods();
    $backPath = Auth::can('settings.manage') ? '/settings' : (Auth::can('dashboard.view') ? '/' : '/settings/definitions');

    render_layout('Tanimlamalar', static function () use ($definitions, $periods, $supplierGroups, $paymentMethods, $error, $backPath): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Ayarlar</p>
                <h1>Tanimlamalar</h1>
            </div>
            <a href="<?= h(url($backPath)) ?>" class="button secondary">Geri don</a>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?= h($error) ?></div>
        <?php endif; ?>

        <section class="definitions-board">
            <article class="definition-card" id="definition-products">
                <div class="definition-card-head">
                    <div>
                        <p class="eyebrow">Ürün / hizmet</p>
                        <h2>Tanımlar</h2>
                    </div>
                    <span class="definition-count"><?= h((string) count($definitions)) ?></span>
                </div>
                <div class="definition-card-actions">
                    <button type="button" class="button small primary" data-dialog-open="definition-create-dialog">+ Yeni</button>
                </div>
                <details class="definition-card-details">
                    <summary>
                        <span>Detay</span>
                        <strong>Kayıtları göster</strong>
                    </summary>
                    <div class="definition-card-body">
                        <?php if ($definitions === []): ?>
                            <div class="empty">Tanım bulunmuyor.</div>
                        <?php else: ?>
                            <div class="definition-list">
                                <?php foreach ($definitions as $definition): ?>
                                    <div class="customer-row definition-row">
                                        <form method="post" class="definition-edit-form definition-info-form" onsubmit="return confirm('Bu tanım güncellensin mi?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= h($definition['id']) ?>">
                                            <label>
                                                Ad
                                                <input name="name" value="<?= h($definition['name']) ?>" required>
                                            </label>
                                            <label>
                                                Tip
                                                <select name="kind">
                                                    <?= option('service', 'Hizmet', (string) $definition['kind']) ?>
                                                    <?= option('product', 'Ürün', (string) $definition['kind']) ?>
                                                </select>
                                            </label>
                                            <label class="definition-info-field">
                                                Bilgilendirme metni
                                                <textarea name="notification_info" rows="4" required><?= h($definition['notification_info'] ?? '') ?></textarea>
                                            </label>
                                            <button type="submit" class="button small primary">Kaydet</button>
                                        </form>
                                        <form method="post" class="definition-delete-form" onsubmit="return confirm('Bu tanım silinsin mi? Eski kayıtlarda görünmeye devam eder.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= h($definition['id']) ?>">
                                            <button type="submit" class="button small danger">Sil</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            </article>

            <article class="definition-card" id="definition-periods">
                <div class="definition-card-head">
                    <div>
                        <p class="eyebrow">Zamanlama</p>
                        <h2>Periyotlar</h2>
                    </div>
                    <span class="definition-count"><?= h((string) count($periods)) ?></span>
                </div>
                <div class="definition-card-actions">
                    <button type="button" class="button small primary" data-dialog-open="period-create-dialog">+ Yeni</button>
                </div>
                <details class="definition-card-details">
                    <summary>
                        <span>Detay</span>
                        <strong>Kayıtları göster</strong>
                    </summary>
                    <div class="definition-card-body">
                        <?php if ($periods === []): ?>
                            <div class="empty">Periyot bulunmuyor.</div>
                        <?php else: ?>
                            <div class="definition-list">
                                <?php foreach ($periods as $period): ?>
                                    <div class="customer-row definition-row">
                                        <form method="post" class="definition-edit-form period-edit-form" onsubmit="return confirm('Bu periyot güncellensin mi?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_period">
                                            <input type="hidden" name="id" value="<?= h($period['id']) ?>">
                                            <label>
                                                Ad
                                                <input name="period_name" value="<?= h($period['name']) ?>">
                                            </label>
                                            <label>
                                                Sayı
                                                <input type="number" min="1" name="interval_count" value="<?= h($period['interval_count']) ?>" required>
                                            </label>
                                            <label>
                                                Birim
                                                <select name="interval_unit">
                                                    <?= option('day', 'Gün', (string) $period['interval_unit']) ?>
                                                    <?= option('month', 'Ay', (string) $period['interval_unit']) ?>
                                                    <?= option('year', 'Yıl', (string) $period['interval_unit']) ?>
                                                </select>
                                            </label>
                                            <button type="submit" class="button small primary">Kaydet</button>
                                        </form>
                                        <form method="post" class="definition-delete-form" onsubmit="return confirm('Bu periyot silinsin mi? Eski kayıtlarda görünmeye devam eder.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_period">
                                            <input type="hidden" name="id" value="<?= h($period['id']) ?>">
                                            <button type="submit" class="button small danger">Sil</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            </article>

            <article class="definition-card" id="supplier-groups">
                <div class="definition-card-head">
                    <div>
                        <p class="eyebrow">Tedarikçi</p>
                        <h2>Gruplar</h2>
                    </div>
                    <span class="definition-count"><?= h((string) count($supplierGroups)) ?></span>
                </div>
                <div class="definition-card-actions">
                    <button type="button" class="button small primary" data-dialog-open="supplier-group-create-dialog">+ Yeni</button>
                </div>
                <details class="definition-card-details">
                    <summary>
                        <span>Detay</span>
                        <strong>Kayıtları göster</strong>
                    </summary>
                    <div class="definition-card-body">
                        <?php if ($supplierGroups === []): ?>
                            <div class="empty">Aktif grup bulunmuyor.</div>
                        <?php else: ?>
                            <div class="definition-list">
                                <?php foreach ($supplierGroups as $group): ?>
                                    <div class="customer-row definition-row">
                                        <form method="post" class="definition-edit-form group-edit-form" onsubmit="return confirm('Bu tedarikçi grubu güncellensin mi?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_supplier_group">
                                            <input type="hidden" name="id" value="<?= h($group['id']) ?>">
                                            <label>
                                                Grup adı
                                                <input name="group_name" value="<?= h($group['name']) ?>" required>
                                            </label>
                                            <button type="submit" class="button small primary">Kaydet</button>
                                        </form>
                                        <form method="post" class="definition-delete-form" onsubmit="return confirm('Bu tedarikçi grubu silinsin mi? Eski kayıtlarda görünmeye devam eder.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_supplier_group">
                                            <input type="hidden" name="id" value="<?= h($group['id']) ?>">
                                            <button type="submit" class="button small danger">Sil</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            </article>

            <article class="definition-card" id="payment-methods">
                <div class="definition-card-head">
                    <div>
                        <p class="eyebrow">Ödeme</p>
                        <h2>Tanımları</h2>
                    </div>
                    <span class="definition-count"><?= h((string) count($paymentMethods)) ?></span>
                </div>
                <div class="definition-card-actions">
                    <button type="button" class="button small primary" data-dialog-open="payment-method-create-dialog">+ Yeni</button>
                </div>
                <details class="definition-card-details">
                    <summary>
                        <span>Detay</span>
                        <strong>Kayıtları göster</strong>
                    </summary>
                    <div class="definition-card-body">
                        <?php if ($paymentMethods === []): ?>
                            <div class="empty">Ödeme yöntemi bulunmuyor.</div>
                        <?php else: ?>
                            <div class="definition-list">
                                <?php foreach ($paymentMethods as $paymentMethod): ?>
                                    <div class="customer-row definition-row">
                                        <form method="post" class="definition-edit-form definition-info-form" onsubmit="return confirm('Bu ödeme tanımı güncellensin mi?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_payment_method">
                                            <input type="hidden" name="id" value="<?= h($paymentMethod['id']) ?>">
                                            <label>
                                                Ödeme yöntemi
                                                <input name="payment_name" value="<?= h($paymentMethod['name']) ?>" required>
                                            </label>
                                            <label>
                                                Sıra
                                                <input type="number" min="0" name="sort_order" value="<?= h($paymentMethod['sort_order']) ?>">
                                            </label>
                                            <label class="definition-info-field">
                                                Açıklama
                                                <textarea name="payment_description" rows="4"><?= h($paymentMethod['description'] ?? '') ?></textarea>
                                            </label>
                                            <button type="submit" class="button small primary">Kaydet</button>
                                        </form>
                                        <form method="post" class="definition-delete-form" onsubmit="return confirm('Bu ödeme tanımı silinsin mi? Eski kayıtlarda metin olarak kalır.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_payment_method">
                                            <input type="hidden" name="id" value="<?= h($paymentMethod['id']) ?>">
                                            <button type="submit" class="button small danger">Sil</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            </article>
        </section>

        <dialog class="app-dialog definition-dialog" id="definition-create-dialog">
            <div class="app-dialog-body">
                <div class="section-head dialog-head">
                    <div>
                        <p class="eyebrow">Yeni tanım</p>
                        <h2>Ürün / hizmet tanımı ekle</h2>
                        <span>Bu kayıt yeni yenileme formunda seçilebilir ve bilgilendirme metni mailde görünür.</span>
                    </div>
                    <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
                </div>
                <form method="post" class="form-grid definition-dialog-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <label>
                        Tip
                        <select name="kind">
                            <?= option('service', 'Hizmet', 'service') ?>
                            <?= option('product', 'Ürün', 'service') ?>
                        </select>
                    </label>
                    <label>
                        Ürün / hizmet adı
                        <input name="name" required>
                    </label>
                    <label>
                        Bilgilendirme metni
                        <textarea
                            name="notification_info"
                            rows="6"
                            required
                            placeholder="Bu ürün veya hizmet için müşteriye mailde gidecek açıklama metni"
                        ></textarea>
                        <span class="muted compact">Yenileme mailinde Bilgilendirme başlığı altında otomatik gönderilir.</span>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="button secondary" data-dialog-close>Vazgeç</button>
                        <button type="submit" class="button primary">Tanım ekle</button>
                    </div>
                </form>
            </div>
        </dialog>

        <dialog class="app-dialog definition-dialog" id="period-create-dialog">
            <div class="app-dialog-body">
                <div class="section-head dialog-head">
                    <div>
                        <p class="eyebrow">Yeni periyot</p>
                        <h2>Yenileme periyodu ekle</h2>
                        <span>Periyot seçildiğinde yenileme tarihi otomatik hesaplanır.</span>
                    </div>
                    <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
                </div>
                <form method="post" class="form-grid definition-dialog-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_period">
                    <label>
                        Periyot adı
                        <input name="period_name" placeholder="Örn: Yıllık">
                    </label>
                    <div class="form-grid two">
                        <label>
                            Sayı
                            <input type="number" min="1" name="interval_count" value="1" required>
                        </label>
                        <label>
                            Birim
                            <select name="interval_unit">
                                <?= option('day', 'Gün', 'year') ?>
                                <?= option('month', 'Ay', 'year') ?>
                                <?= option('year', 'Yıl', 'year') ?>
                            </select>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="button secondary" data-dialog-close>Vazgeç</button>
                        <button type="submit" class="button primary">Periyot ekle</button>
                    </div>
                </form>
            </div>
        </dialog>

        <dialog class="app-dialog definition-dialog" id="supplier-group-create-dialog">
            <div class="app-dialog-body">
                <div class="section-head dialog-head">
                    <div>
                        <p class="eyebrow">Yeni grup</p>
                        <h2>Tedarikçi grubu ekle</h2>
                        <span>Tedarikçileri gruplayıp yenileme kayıtlarında toplu seçim için kullanabilirsiniz.</span>
                    </div>
                    <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
                </div>
                <form method="post" class="form-grid definition-dialog-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_supplier_group">
                    <label>
                        Grup adı
                        <input name="group_name" placeholder="Örn: Yazılım" required>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="button secondary" data-dialog-close>Vazgeç</button>
                        <button type="submit" class="button primary">Grup ekle</button>
                    </div>
                </form>
            </div>
        </dialog>

        <dialog class="app-dialog definition-dialog" id="payment-method-create-dialog">
            <div class="app-dialog-body">
                <div class="section-head dialog-head">
                    <div>
                        <p class="eyebrow">Yeni ödeme</p>
                        <h2>Ödeme tanımı ekle</h2>
                        <span>Müşteri ödeme ekranında görünecek yöntem ve açıklama bilgisini belirleyin.</span>
                    </div>
                    <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
                </div>
                <form method="post" class="form-grid definition-dialog-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_payment_method">
                    <label>
                        Ödeme yöntemi
                        <input name="payment_name" placeholder="Örn: Kredi kartı" required>
                    </label>
                    <label>
                        Sıra
                        <input type="number" min="0" name="sort_order" value="10">
                    </label>
                    <label>
                        Açıklama
                        <textarea name="payment_description" rows="4" placeholder="Müşterinin ödeme seçimi ekranında göreceği açıklama"></textarea>
                    </label>
                    <div class="form-actions">
                        <button type="button" class="button secondary" data-dialog-close>Vazgeç</button>
                        <button type="submit" class="button primary">Ödeme tanımı ekle</button>
                    </div>
                </form>
            </div>
        </dialog>
        <?php
    });
}

function handle_users(string $method, ?int $id = null): void
{
    $repo = new UserRepository();
    $editing = $id !== null;
    $currentUser = Auth::user();
    $user = $editing ? $repo->find((int) $id) : null;

    if ($editing && !$user) {
        flash('error', 'Kullanici bulunamadi.');
        redirect('/settings/users');
    }

    $errors = [];
    $formData = $user ?: [
        'name' => '',
        'email' => '',
        'role' => 'staff',
        'is_active' => 1,
        'permissions' => default_staff_permissions(),
    ];

    if ($method === 'POST') {
        verify_csrf();
        $selectedPermissions = submitted_permissions();
        $formData = array_merge($formData, $_POST, ['permissions' => $selectedPermissions]);
        $errors = validate_user_form($_POST, $editing);

        $willBeAdmin = (string) ($_POST['role'] ?? 'staff') === 'admin';
        $willBeActive = !empty($_POST['is_active']);
        $isLastActiveAdmin = $editing
            && (int) ($user['is_active'] ?? 0) === 1
            && ($user['role'] ?? '') === 'admin'
            && $repo->activeAdminCount() <= 1;

        if ($editing && (int) ($currentUser['id'] ?? 0) === (int) $id && !$willBeActive) {
            $errors[] = 'Kendi kullanicinizi pasife alamazsiniz.';
        }

        if ($isLastActiveAdmin && (!$willBeAdmin || !$willBeActive)) {
            $errors[] = 'Sistemde en az bir aktif admin kullanici kalmali.';
        }

        if ($errors === []) {
            try {
                if ($editing) {
                    $repo->update((int) $id, $_POST, $selectedPermissions);
                    flash('success', 'Kullanici ve yetkileri guncellendi.');
                } else {
                    $repo->create($_POST, $selectedPermissions);
                    $mailResult = send_user_account_mail([
                        'name' => trim((string) ($_POST['name'] ?? '')),
                        'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
                        'role' => (string) ($_POST['role'] ?? 'staff'),
                        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                    ], (string) ($_POST['password'] ?? ''), 'created');

                    flash('success', 'Kullanici olusturuldu.');
                    if ($mailResult['ok']) {
                        flash('success', 'Kullanici bilgileri mail olarak gonderildi.');
                    } else {
                        flash('error', 'Kullanici olusturuldu ancak mail gonderilemedi: ' . (string) $mailResult['error']);
                    }
                }

                redirect('/settings/users');
            } catch (Throwable $e) {
                $errors[] = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Bu e-posta adresiyle kayitli kullanici var.'
                    : $e->getMessage();
            }
        }
    }

    $users = $repo->all();
    $backPath = Auth::can('settings.manage') ? '/settings' : (Auth::can('dashboard.view') ? '/' : '/settings/users');
    $currentUserId = (int) ($currentUser['id'] ?? 0);

    render_layout('Kullanicilar', static function () use ($users, $formData, $editing, $id, $errors, $backPath, $currentUserId): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Yetkilendirme</p>
                <h1>Kullanicilar</h1>
            </div>
            <div class="customer-actions">
                <?php if ($editing): ?>
                    <a href="<?= h(url('/settings/users')) ?>" class="button primary">Yeni kullanici</a>
                <?php endif; ?>
                <a href="<?= h(url('/settings/users/deleted')) ?>" class="button secondary">Silinen kullanicilar</a>
                <a href="<?= h(url($backPath)) ?>" class="button secondary">Geri don</a>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert error"><?= h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <section class="grid-2 user-board">
            <div class="panel">
                <h2><?= $editing ? 'Kullanici duzenle' : 'Yeni kullanici' ?></h2>
                <form method="post" class="form-grid settings-form">
                    <?= csrf_field() ?>
                    <label>
                        Ad soyad
                        <input name="name" value="<?= h($formData['name'] ?? '') ?>" required>
                    </label>
                    <label>
                        E-posta
                        <input type="email" name="email" value="<?= h($formData['email'] ?? '') ?>" required>
                    </label>
                    <label>
                        Sifre
                        <input type="password" name="password" value="" autocomplete="new-password" <?= $editing ? '' : 'required' ?> placeholder="<?= $editing ? 'Degistirmek istemiyorsaniz bos birakin' : 'En az 6 karakter' ?>">
                    </label>
                    <div class="form-grid two">
                        <label>
                            Rol
                            <select name="role">
                                <?= option('staff', 'Personel', (string) ($formData['role'] ?? 'staff')) ?>
                                <?= option('admin', 'Admin', (string) ($formData['role'] ?? 'staff')) ?>
                            </select>
                        </label>
                        <label class="checkline user-active-check">
                            <input type="checkbox" name="is_active" value="1" <?= !empty($formData['is_active']) ? 'checked' : '' ?>>
                            Kullanici aktif
                        </label>
                    </div>

                    <div class="permission-box">
                        <div class="section-head">
                            <h3>Yetkiler</h3>
                            <span class="badge active">Panel</span>
                        </div>
                        <p class="muted compact">Admin rolunde tum yetkiler otomatik aciktir. Personel icin goruntuleme, detay ve duzenleme izinlerini buradan secin.</p>
                        <?= render_permission_checkboxes((array) ($formData['permissions'] ?? []), (string) ($formData['role'] ?? 'staff')) ?>
                    </div>

                    <button type="submit" class="button primary"><?= $editing ? 'Kullaniciyi guncelle' : 'Kullanici olustur' ?></button>
                </form>
            </div>

            <div class="panel">
                <h2>Kayitli kullanicilar</h2>
                <div class="stack settings-form">
                    <?php foreach ($users as $row): ?>
                        <div class="customer-row">
                            <div class="customer-row-head">
                                <strong><?= h($row['name']) ?></strong>
                                <div class="customer-actions">
                                    <a href="<?= h(url('/settings/users/' . $row['id'] . '/edit')) ?>" class="button small secondary">Duzenle</a>
                                    <?php if ((int) $row['id'] !== $currentUserId): ?>
                                        <form method="post" action="<?= h(url('/settings/users/' . $row['id'] . '/delete')) ?>" onsubmit="return confirm('Bu kullanici silinenler havuzuna tasinsin mi?')">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="button small danger">Sil</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span><?= h($row['email']) ?></span>
                            <div class="user-badges">
                                <span class="badge <?= ((int) $row['is_active'] === 1) ? 'active' : 'cancelled' ?>">
                                    <?= ((int) $row['is_active'] === 1) ? 'Aktif' : 'Pasif' ?>
                                </span>
                                <span class="badge <?= $row['role'] === 'admin' ? 'active' : 'cancelled' ?>">
                                    <?= $row['role'] === 'admin' ? 'Admin' : 'Personel' ?>
                                </span>
                                <span class="muted compact">
                                    <?= $row['role'] === 'admin' ? 'Tum yetkiler' : count((array) $row['permissions']) . ' yetki' ?>
                                </span>
                            </div>
                            <?php if (!empty($row['last_login_at'])): ?>
                                <span>Son giris: <?= h(date('d.m.Y H:i', strtotime($row['last_login_at']))) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php
    });
}

function handle_user_delete(int $id): void
{
    verify_csrf();

    $repo = new UserRepository();
    $currentUser = Auth::user();
    $user = $repo->find($id);

    if (!$user) {
        flash('error', 'Kullanici bulunamadi.');
        redirect('/settings/users');
    }

    if ((int) ($currentUser['id'] ?? 0) === $id) {
        flash('error', 'Kendi kullanicinizi silemezsiniz.');
        redirect('/settings/users');
    }

    if (($user['role'] ?? '') === 'admin' && (int) ($user['is_active'] ?? 0) === 1 && $repo->activeAdminCount() <= 1) {
        flash('error', 'Sistemde en az bir aktif admin kullanici kalmali.');
        redirect('/settings/users');
    }

    $repo->delete($id);
    flash('success', 'Kullanici silinenler havuzuna tasindi.');
    redirect('/settings/users');
}

function handle_user_restore(int $id): void
{
    verify_csrf();

    $repo = new UserRepository();
    $user = $repo->find($id, true);

    if (!$user || empty($user['deleted_at'])) {
        flash('error', 'Silinen kullanici bulunamadi.');
        redirect('/settings/users/deleted');
    }

    $repo->restore($id);
    flash('success', 'Kullanici havuzdan geri alindi.');
    redirect('/settings/users/deleted');
}

function render_deleted_users(): void
{
    $repo = new UserRepository();
    $users = $repo->deleted();

    render_layout('Silinen Kullanicilar', static function () use ($users): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Havuz</p>
                <h1>Silinen Kullanicilar</h1>
            </div>
            <a href="<?= h(url('/settings/users')) ?>" class="button secondary">Kullanicilara don</a>
        </div>

        <section class="panel">
            <?php if ($users === []): ?>
                <div class="empty">Silinen kullanici bulunmuyor.</div>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($users as $user): ?>
                        <div class="customer-row">
                            <div class="customer-row-head">
                                <strong><?= h($user['name']) ?></strong>
                                <form method="post" action="<?= h(url('/settings/users/' . $user['id'] . '/restore')) ?>" onsubmit="return confirm('Bu kullanici havuzdan geri alinsin mi?')">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="button small secondary">Geri al</button>
                                </form>
                            </div>
                            <span><?= h($user['email']) ?></span>
                            <div class="user-badges">
                                <span class="badge cancelled">Silindi</span>
                                <span class="badge <?= $user['role'] === 'admin' ? 'active' : 'cancelled' ?>">
                                    <?= $user['role'] === 'admin' ? 'Admin' : 'Personel' ?>
                                </span>
                                <span class="muted compact">
                                    <?= $user['role'] === 'admin' ? 'Tum yetkiler' : count((array) $user['permissions']) . ' yetki' ?>
                                </span>
                            </div>
                            <span>Silinme tarihi: <?= h($user['deleted_at'] ? date('d.m.Y H:i', strtotime($user['deleted_at'])) : '-') ?></span>
                            <?php if (!empty($user['last_login_at'])): ?>
                                <span>Son giris: <?= h(date('d.m.Y H:i', strtotime($user['last_login_at']))) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    });
}

function render_logs(): void
{
    $files = log_files();
    $selectedFile = selected_log_file($files, (string) ($_GET['file'] ?? ''));
    $selectedLines = $selectedFile !== null ? tail_file_lines($selectedFile['path'], 350) : [];
    $mailLogs = recent_mail_logs();

    render_layout('Loglar', static function () use ($files, $selectedFile, $selectedLines, $mailLogs): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Sistem</p>
                <h1>Loglar</h1>
            </div>
            <a href="<?= h(url('/logs')) ?>" class="button secondary">Yenile</a>
        </div>

        <section class="grid-2 log-board">
            <div class="panel">
                <h2>Log dosyalari</h2>
                <?php if ($files === []): ?>
                    <div class="empty">Log dosyasi bulunmuyor.</div>
                <?php else: ?>
                    <div class="stack settings-form">
                        <?php foreach ($files as $file): ?>
                            <a class="log-file-row <?= $selectedFile && $selectedFile['name'] === $file['name'] ? 'active' : '' ?>" href="<?= h(url('/logs') . '?file=' . rawurlencode($file['name'])) ?>">
                                <strong><?= h($file['label']) ?></strong>
                                <span><?= h($file['size_label']) ?> - <?= h($file['modified_label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel log-detail-panel">
                <div class="section-head">
                    <h2><?= h($selectedFile['label'] ?? 'Log detayi') ?></h2>
                    <span class="badge active">Son 350 satir</span>
                </div>
                <?php if ($selectedFile === null): ?>
                    <div class="empty">Incelemek icin soldan bir log dosyasi secin.</div>
                <?php elseif ($selectedLines === []): ?>
                    <div class="empty">Bu log dosyasi bos.</div>
                <?php else: ?>
                    <pre class="log-lines"><?= h(implode("\n", $selectedLines)) ?></pre>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="section-head">
                <h2>Mail gonderim kayitlari</h2>
                <span class="badge <?= $mailLogs === [] ? 'cancelled' : 'active' ?>"><?= h((string) count($mailLogs)) ?> kayit</span>
            </div>
            <?php if ($mailLogs === []): ?>
                <div class="empty">Mail log kaydi bulunmuyor.</div>
            <?php else: ?>
                <div class="table-wrap settings-form">
                    <table>
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Alici</th>
                                <th>Konu</th>
                                <th>Durum</th>
                                <th>Okunma</th>
                                <th>Hata</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mailLogs as $row): ?>
                                <tr>
                                    <td><?= h(date('d.m.Y H:i', strtotime((string) $row['sent_at']))) ?></td>
                                    <td>
                                        <strong><?= h($row['recipient_email']) ?></strong>
                                        <?php if (!empty($row['recipient_name'])): ?>
                                            <br><span class="muted compact"><?= h((string) $row['recipient_name']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['subject']) ?></td>
                                    <td>
                                        <span class="badge <?= $row['status'] === 'sent' ? 'active' : 'cancelled' ?>">
                                            <?= h($row['status'] === 'sent' ? 'Gonderildi' : 'Hatali') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['read_at'])): ?>
                                            <span class="badge active">Okundu</span>
                                            <br><span class="muted compact"><?= h(date('d.m.Y H:i', strtotime((string) $row['read_at']))) ?></span>
                                        <?php elseif (!empty($row['tracking_status'])): ?>
                                            <span class="badge cancelled">Bekliyor</span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h((string) ($row['error_message'] ?: '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php
    });
}

function log_files(): array
{
    $dir = ROOT_PATH . '/storage/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $paths = glob($dir . '/*.log') ?: [];
    usort($paths, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return array_map(static function (string $path): array {
        $name = basename($path);

        return [
            'name' => $name,
            'label' => log_file_label($name),
            'path' => $path,
            'size_label' => log_size_label(filesize($path) ?: 0),
            'modified_label' => date('d.m.Y H:i', filemtime($path) ?: time()),
        ];
    }, $paths);
}

function selected_log_file(array $files, string $requested): ?array
{
    if ($files === []) {
        return null;
    }

    $requested = basename($requested);
    foreach ($files as $file) {
        if ($requested !== '' && hash_equals($file['name'], $requested)) {
            return $file;
        }
    }

    return $files[0];
}

function tail_file_lines(string $path, int $maxLines): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $maxBytes = 256 * 1024;
    $size = filesize($path) ?: 0;
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    if ($size > $maxBytes) {
        fseek($handle, -$maxBytes, SEEK_END);
    }

    $content = stream_get_contents($handle);
    fclose($handle);

    if (!is_string($content) || $content === '') {
        return [];
    }

    $lines = preg_split('/\R/', trim($content)) ?: [];

    return array_slice($lines, -$maxLines);
}

function recent_mail_logs(int $limit = 100): array
{
    try {
        new RenewalRepository();
        $stmt = Database::connection()->prepare(
            'SELECT ml.recipient_email,
                    ml.subject,
                    ml.status,
                    ml.error_message,
                    ml.sent_at,
                    (
                        SELECT rnd.recipient_name
                        FROM renewal_notification_deliveries rnd
                        WHERE rnd.mail_log_id = ml.id
                           OR (
                                rnd.mail_log_id IS NULL
                                AND rnd.renewal_id = ml.renewal_id
                                AND LOWER(rnd.recipient_email) = LOWER(ml.recipient_email)
                                AND ABS(TIMESTAMPDIFF(MINUTE, rnd.created_at, ml.sent_at)) <= 15
                           )
                        ORDER BY (rnd.mail_log_id = ml.id) DESC, rnd.created_at DESC
                        LIMIT 1
                    ) AS recipient_name,
                    (
                        SELECT rnd.status
                        FROM renewal_notification_deliveries rnd
                        WHERE rnd.mail_log_id = ml.id
                           OR (
                                rnd.mail_log_id IS NULL
                                AND rnd.renewal_id = ml.renewal_id
                                AND LOWER(rnd.recipient_email) = LOWER(ml.recipient_email)
                                AND ABS(TIMESTAMPDIFF(MINUTE, rnd.created_at, ml.sent_at)) <= 15
                           )
                        ORDER BY (rnd.mail_log_id = ml.id) DESC, rnd.created_at DESC
                        LIMIT 1
                    ) AS tracking_status,
                    (
                        SELECT rnd.read_at
                        FROM renewal_notification_deliveries rnd
                        WHERE rnd.mail_log_id = ml.id
                           OR (
                                rnd.mail_log_id IS NULL
                                AND rnd.renewal_id = ml.renewal_id
                                AND LOWER(rnd.recipient_email) = LOWER(ml.recipient_email)
                                AND ABS(TIMESTAMPDIFF(MINUTE, rnd.created_at, ml.sent_at)) <= 15
                           )
                        ORDER BY (rnd.mail_log_id = ml.id) DESC, rnd.created_at DESC
                        LIMIT 1
                    ) AS read_at
             FROM mail_logs ml
             ORDER BY ml.sent_at DESC
             LIMIT :limit_count'
        );
        $stmt->bindValue('limit_count', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

function log_file_label(string $name): string
{
    return match ($name) {
        'app-error.log' => 'PHP hata logu',
        'mail.log' => 'Mail dosya logu',
        'mail-test.log' => 'Test mail logu',
        default => $name,
    };
}

function log_size_label(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }

    return $bytes . ' B';
}

function validate_user_form(array $data, bool $editing): array
{
    $errors = [];

    if (trim((string) ($data['name'] ?? '')) === '') {
        $errors[] = 'Ad soyad zorunlu.';
    }

    if (!filter_var((string) ($data['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Gecerli bir e-posta adresi girin.';
    }

    $password = (string) ($data['password'] ?? '');
    if (!$editing && $password === '') {
        $errors[] = 'Yeni kullanici icin sifre zorunlu.';
    }

    if ($password !== '' && strlen($password) < 6) {
        $errors[] = 'Sifre en az 6 karakter olmali.';
    }

    if (!in_array((string) ($data['role'] ?? 'staff'), ['admin', 'staff'], true)) {
        $errors[] = 'Gecersiz rol secimi.';
    }

    return $errors;
}

function submitted_permissions(): array
{
    $permissions = $_POST['permissions'] ?? [];
    if (!is_array($permissions)) {
        return [];
    }

    $valid = permission_keys();

    return array_values(array_unique(array_filter(
        array_map('strval', $permissions),
        static fn (string $permission): bool => in_array($permission, $valid, true)
    )));
}

function default_staff_permissions(): array
{
    return [
        'dashboard.view',
        'dashboard.details',
        'renewals.view',
        'renewals.details',
    ];
}

function render_permission_checkboxes(array $selected, string $role): string
{
    $isAdmin = $role === 'admin';
    ob_start();
    ?>
    <div class="permission-groups">
        <?php foreach (permission_catalog() as $group => $permissions): ?>
            <fieldset class="permission-group">
                <legend><?= h($group) ?></legend>
                <?php foreach ($permissions as $key => $label): ?>
                    <label class="checkline">
                        <input
                            type="checkbox"
                            name="permissions[]"
                            value="<?= h($key) ?>"
                            <?= ($isAdmin || in_array($key, $selected, true)) ? 'checked' : '' ?>
                        >
                        <?= h($label) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function template_placeholders(): array
{
    return [
        [
            'key' => 'logo_url',
            'label' => 'Logo',
            'token' => '{{logo_url}}',
            'description' => 'Ayarlar bölümüne yüklenen logoyu mail şablonuna görsel olarak ekler.',
            'content' => '<img class="brand-logo" data-template-logo="1" src="{{logo_url}}" alt="{{app_name}}" style="width:auto;max-width:170px;max-height:62px;height:auto;object-fit:contain;display:block;margin-left:auto;margin-right:auto;">',
        ],
        [
            'key' => 'customer_name',
            'label' => 'Müşteri adı',
            'token' => '{{customer_name}}',
            'description' => 'Cari kartındaki firma veya müşteri adını yazar.',
        ],
        [
            'key' => 'contact_name',
            'label' => 'Yetkili adi',
            'token' => '{{contact_name}}',
            'description' => 'Bilgilendirme gonderilen yetkilinin adini yazar.',
        ],
        [
            'key' => 'title',
            'label' => 'Ürün / hizmet',
            'token' => '{{title}}',
            'description' => 'Yenileme kaydındaki ürün veya hizmet adını yazar.',
        ],
        [
            'key' => 'brand',
            'label' => 'Marka',
            'token' => '{{brand}}',
            'description' => 'Yenileme kaydına girilen marka bilgisini yazar.',
        ],
        [
            'key' => 'previous_invoice_number',
            'label' => 'Bir Önceki Fatura Numarası',
            'token' => '{{previous_invoice_number}}',
            'description' => 'Bir önceki dönemde kesilen fatura numarasını yazar.',
        ],
        [
            'key' => 'payment_method',
            'label' => 'Ödeme şekli',
            'token' => '{{payment_method}}',
            'description' => 'Yenileme kaydında belirtilen ödeme şeklini yazar.',
        ],
        [
            'key' => 'total_amount',
            'label' => 'Toplam tutar',
            'token' => '{{total_amount}}',
            'description' => 'Ürün satırlarının KDV dahil toplam tutarını yazar.',
        ],
        [
            'key' => 'items_table',
            'label' => 'Ürün satırları',
            'token' => '{{items_table}}',
            'description' => 'Birden fazla ürün varsa ürün, adet ve KDV dahil tutar tablosunu ekler.',
        ],
        [
            'key' => 'payment_action',
            'label' => 'Kredi kartı ödeme butonu',
            'token' => '{{payment_action}}',
            'description' => 'Güvenli kredi kartı ödeme sayfasına doğrudan yönlendiren premium butonu ekler.',
        ],
        [
            'key' => 'payment_choice_url',
            'label' => 'Kredi kartı ödeme linki',
            'token' => '{{payment_choice_url}}',
            'description' => 'Kredi kartı ödeme sayfasına giden güvenli bağlantıyı yazar.',
        ],
        [
            'key' => 'read_ack_action',
            'label' => 'Okudum butonu',
            'token' => '{{read_ack_action}}',
            'description' => 'Alıcıya özel okundu kaydı oluşturacak Okudum butonunu ekler.',
        ],
        [
            'key' => 'read_ack_url',
            'label' => 'Okudum linki',
            'token' => '{{read_ack_url}}',
            'description' => 'Hangi yetkilinin maili okuduğunu kaydeden kişiye özel bağlantıyı yazar.',
        ],
        [
            'key' => 'definition_notification_info',
            'label' => 'Bilgilendirme metni',
            'token' => '{{definition_notification_info}}',
            'description' => 'Tanımlamalar bölümünde ürün veya hizmet için girilen açıklamayı yazar.',
        ],
        [
            'key' => 'renewal_date',
            'label' => 'Yenileme tarihi',
            'token' => '{{renewal_date}}',
            'description' => 'Bitiş veya yenileme tarihini gün.ay.yıl formatında yazar.',
        ],
        [
            'key' => 'remaining_days',
            'label' => 'Kalan süre',
            'token' => '{{remaining_days}}',
            'description' => 'Kalan gün sayısını veya gecikme bilgisini yazar.',
            'content' => '<table class="metric-row" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;margin:18px 0;border-collapse:separate;border-spacing:0;table-layout:fixed;"><tr><td class="metric metric-remaining {{urgency_class}}" width="49%" bgcolor="{{urgency_bg}}" style="width:49% !important;padding:16px;background:{{urgency_bg}};border:1px solid {{urgency_border}};border-radius:8px;vertical-align:top;"><span style="display:block;color:{{urgency_text}};font-size:11px;font-weight:700;text-transform:uppercase;line-height:1.2;">Kalan süre</span><strong style="display:block;margin-top:7px;color:{{urgency_text}};font-size:26px;line-height:1.08;word-break:break-word;">{{remaining_days}}</strong></td><td class="metric-gap" width="2%" style="width:2%;font-size:0;line-height:0;">&nbsp;</td><td class="metric metric-date" width="49%" bgcolor="#eef6fb" style="width:49% !important;padding:16px;background:#eef6fb;border:1px solid #d5e7ef;border-radius:8px;vertical-align:top;"><span style="display:block;color:#66756f;font-size:11px;font-weight:700;text-transform:uppercase;line-height:1.2;">Yenileme tarihi</span><strong style="display:block;margin-top:7px;color:#0f625b;font-size:26px;line-height:1.08;word-break:break-word;">{{renewal_date}}</strong></td></tr></table>',
        ],
        [
            'key' => 'notes',
            'label' => 'Notlar',
            'token' => '{{notes}}',
            'description' => 'Yenileme kaydındaki not alanını yazar.',
        ],
        [
            'key' => 'record_type',
            'label' => 'Kayit tipi',
            'token' => '{{record_type}}',
            'description' => 'Lisans, uyelik veya hizmet tipini yazar.',
        ],
        [
            'key' => 'today',
            'label' => 'Bugünün tarihi',
            'token' => '{{today}}',
            'description' => 'Mailin hazırlandığı günün tarihini yazar.',
        ],
        [
            'key' => 'app_name',
            'label' => 'Sistem adi',
            'token' => '{{app_name}}',
            'description' => 'Uygulamanin sistem adini yazar.',
        ],
    ];
}

function template_test_row(): array
{
    return [
        'id' => 1,
        'kind' => 'product',
        'company_name' => 'Örnek Müşteri A.Ş.',
        'title' => 'Antivirüs / EDR Lisansı',
        'brand' => 'TrendMicro',
        'license_key' => 'DEMO-2026-EDR',
        'current_invoice_number' => 'BIGA-2025-1042',
        'previous_invoice_number' => 'BIGA-2025-1042',
        'item_count' => 1,
        'item_total' => 1800,
        'currency' => 'TRY',
        'payment_method' => '',
        'payment_customer_choice' => 1,
        'definition_notification_info' => 'Alan adının süresi bittikten sonraki 20 gün içinde alan adı normal ücretle yenilenebilir. Bu süre içinde alan adına bağlı web sitesi, e-mailler ve benzer servisler durabilir.',
        'renewal_date' => date('Y-m-d', strtotime('+15 days')),
        'notes' => 'Bu test maili şablonun kontrolü için gönderildi.',
    ];
}

function handle_grapesjs_template(string $method): void
{
    $settingsRepo = new SettingsRepository();
    $error = null;

    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'save_template');

        try {
            $templateHtml = (string) ($_POST['template_html'] ?? '');
            $templateCss = (string) ($_POST['template_css'] ?? '');

            $templateValues = [
                'template.renewal.enabled' => !empty($_POST['template_enabled']) ? '1' : '0',
                'template.renewal.html' => $templateHtml !== '' ? MailTemplate::normalizeTemplateHtml($templateHtml) : '',
                'template.renewal.css' => $templateCss !== '' ? MailTemplate::enforceLayoutCss($templateCss) : '',
                'template.renewal.project_json' => MailTemplate::normalizeTemplateProjectJson((string) ($_POST['template_project_json'] ?? '')),
            ];
            $settingsRepo->setMany($templateValues);

            if ($action === 'send_template_test') {
                $testEmail = trim((string) ($_POST['test_email'] ?? ''));
                if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Test maili icin gecerli bir e-posta adresi girin.');
                }
                $settingsRepo->setMany(['template.renewal.test_email' => $testEmail]);

                $settings = array_merge($settingsRepo->all(), $templateValues, ['template.renewal.enabled' => '1']);
                $sampleRow = template_test_row();
                $recipient = ['name' => 'Test Alıcı', 'email' => $testEmail, 'read_ack_url' => url('/renewals/1/read?token=' . str_repeat('0', 64))];
                $mail = MailTemplate::renderRenewal($settings, $sampleRow, $recipient, 'Kalan süre: 15 gün.', 15);
                $result = Mailer::sendWithResult(
                    $testEmail,
                    'Yenileme mail şablon testi',
                    (string) $mail['body'],
                    (bool) $mail['is_html'],
                    $mail['inline_attachments'] ?? []
                );

                if (!$result['ok']) {
                    throw new RuntimeException((string) $result['error']);
                }

                flash('success', 'Test maili gönderildi.');
                redirect('/settings/grapesjs');
            }

            flash('success', 'Mail şablonu kaydedildi.');
            redirect('/settings/grapesjs');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $settings = $settingsRepo->all();
    $html = MailTemplate::normalizeTemplateHtml((string) ($settings['template.renewal.html'] ?: MailTemplate::defaultHtml()));
    $css = MailTemplate::enforceLayoutCss((string) ($settings['template.renewal.css'] ?: MailTemplate::defaultCss()));
    $projectJson = MailTemplate::normalizeTemplateProjectJson((string) ($settings['template.renewal.project_json'] ?? ''));

    render_layout('Mail Şablon Tasarımı', static function () use ($settings, $html, $css, $projectJson, $error): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Ayarlar</p>
                <h1>Mail Şablon Tasarımı</h1>
            </div>
            <a href="<?= h(url('/settings')) ?>" class="button secondary">Ayarlara dön</a>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?= h($error) ?></div>
        <?php endif; ?>

        <section class="panel grapesjs-panel">
            <form method="post" class="grapesjs-form" data-grapesjs-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_template" data-grapesjs-action>
                <div class="section-head grapesjs-toolbar">
                    <div>
                        <h2>Müşteri yenileme mail şablonu</h2>
                        <p class="muted compact">Bu şablon otomatik yenileme hatırlatma maillerinde kullanılır.</p>
                    </div>
                    <div class="grapesjs-save-actions">
                        <label class="checkline grapesjs-enabled">
                            <input type="checkbox" name="template_enabled" value="1" <?= ($settings['template.renewal.enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            Şablonu aktif kullan
                        </label>
                        <button type="submit" class="button primary" data-grapesjs-submit-action="save_template">Şablonu kaydet</button>
                    </div>
                </div>

                <div class="grapesjs-test-card">
                    <div>
                        <strong>Test e-postası</strong>
                        <span>Şablonu kaydeder ve deneme mailini bu adrese gönderir.</span>
                    </div>
                    <label>
                        Alıcı e-posta
                        <input type="email" name="test_email" value="<?= h($settings['template.renewal.test_email'] ?? '') ?>" placeholder="ornek@firma.com">
                    </label>
                    <button type="submit" class="button secondary" data-grapesjs-submit-action="send_template_test">Test maili gönder</button>
                </div>

                <div data-grapesjs-fields hidden>
                    <?php foreach (template_placeholders() as $field): ?>
                        <button
                            type="button"
                            data-grapesjs-field
                            data-key="<?= h($field['key']) ?>"
                            data-label="<?= h($field['label']) ?>"
                            data-token="<?= h($field['token']) ?>"
                            data-description="<?= h($field['description']) ?>"
                            data-content="<?= h($field['content'] ?? '') ?>"
                        ></button>
                    <?php endforeach; ?>
                </div>

                <textarea name="template_html" data-grapesjs-html hidden><?= h($html) ?></textarea>
                <textarea name="template_css" data-grapesjs-css hidden><?= h($css) ?></textarea>
                <textarea name="template_project_json" data-grapesjs-project hidden><?= h($projectJson) ?></textarea>
                <div class="grapesjs-shell">
                    <div data-grapesjs-editor class="grapesjs-editor"></div>
                </div>
            </form>
        </section>
        <?php
    }, grapesjs_assets());
}

function handle_settings(string $method): void
{
    $settingsRepo = new SettingsRepository();
    $parasutClient = new ParasutClient();
    $error = null;

    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'save');

        try {
            if ($action === 'save') {
                $current = $settingsRepo->all();
                $driver = (string) ($_POST['mail_driver'] ?? 'log');
                if (!in_array($driver, ['log', 'smtp', 'microsoft365', 'mail'], true)) {
                    $driver = 'log';
                }

                $encryption = (string) ($_POST['smtp_encryption'] ?? 'tls');
                if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
                    $encryption = 'tls';
                }

                $settingsRepo->setMany([
                    'mail.driver' => $driver,
                    'mail.from_email' => trim((string) ($_POST['from_email'] ?? '')),
                    'mail.from_name' => trim((string) ($_POST['from_name'] ?? '')),
                    'smtp.host' => trim((string) ($_POST['smtp_host'] ?? '')),
                    'smtp.port' => (string) max(1, (int) ($_POST['smtp_port'] ?? 587)),
                    'smtp.encryption' => $encryption,
                    'smtp.username' => trim((string) ($_POST['smtp_username'] ?? '')),
                    'smtp.password' => (string) ((string) ($_POST['smtp_password'] ?? '') !== '' ? $_POST['smtp_password'] : ($current['smtp.password'] ?? '')),
                    'smtp.timeout' => (string) max(5, (int) ($_POST['smtp_timeout'] ?? 20)),
                    'm365.tenant_id' => trim((string) ($_POST['m365_tenant_id'] ?? 'common')) ?: 'common',
                    'm365.client_id' => trim((string) ($_POST['m365_client_id'] ?? '')),
                    'm365.client_secret' => (string) ((string) ($_POST['m365_client_secret'] ?? '') !== '' ? $_POST['m365_client_secret'] : ($current['m365.client_secret'] ?? '')),
                    'm365.redirect_uri' => trim((string) ($_POST['m365_redirect_uri'] ?? url('/settings/microsoft/callback'))),
                    'm365.from_user' => trim((string) ($_POST['m365_from_user'] ?? '')),
                ]);

                flash('success', 'Mail ayarlari kaydedildi.');
                redirect('/settings');
            }

            if ($action === 'test') {
                $testEmail = trim((string) ($_POST['test_email'] ?? ''));
                if ($testEmail === '') {
                    throw new RuntimeException('Test e-postasi icin alici adresi gerekli.');
                }

                $result = Mailer::sendWithResult(
                    $testEmail,
                    'Yenileme Takip Sistemi test e-postasi',
                    "Bu e-posta mail ayarlarinin test edilmesi icin gonderildi.\n\nTarih: " . date('d.m.Y H:i')
                );

                if (!$result['ok']) {
                    throw new RuntimeException((string) $result['error']);
                }

                flash('success', 'Test e-postasi gonderildi.');
                redirect('/settings');
            }

            if ($action === 'save_logo') {
                $current = $settingsRepo->all();
                $logoPath = save_branding_logo((string) ($current['branding.logo_path'] ?? ''));
                $settingsRepo->setMany(['branding.logo_path' => $logoPath]);
                flash('success', 'Logo kaydedildi.');
                redirect('/settings');
            }

            if ($action === 'remove_logo') {
                $current = $settingsRepo->all();
                remove_branding_logo((string) ($current['branding.logo_path'] ?? ''));
                $settingsRepo->setMany(['branding.logo_path' => '']);
                flash('success', 'Logo kaldirildi.');
                redirect('/settings');
            }

            if ($action === 'clear_m365') {
                $settingsRepo->clearMicrosoftToken();
                flash('success', 'Microsoft 365 baglantisi kaldirildi.');
                redirect('/settings');
            }

            if ($action === 'save_notifications') {
                $settingsRepo->setMany([
                    'notifications.send_time' => normalize_notification_time((string) ($_POST['notification_time'] ?? '09:00')),
                ]);
                flash('success', 'Bildirim saati kaydedildi.');
                redirect('/settings#notification-settings');
            }

            if ($action === 'save_backup') {
                $settingsRepo->setMany([
                    'backup.enabled' => !empty($_POST['backup_enabled']) ? '1' : '0',
                    'backup.email' => trim((string) ($_POST['backup_email'] ?? '')),
                    'backup.time' => normalize_notification_time((string) ($_POST['backup_time'] ?? '02:00')),
                    'backup.keep_days' => (string) max(1, (int) ($_POST['backup_keep_days'] ?? 14)),
                ]);
                flash('success', 'Yedekleme ayarlari kaydedildi.');
                redirect('/settings#backup-settings');
            }

            if ($action === 'save_iyzico') {
                $current = $settingsRepo->all();
                $mode = (string) ($_POST['iyzico_mode'] ?? 'sandbox');
                if (!in_array($mode, ['sandbox', 'live'], true)) {
                    $mode = 'sandbox';
                }

                $settingsRepo->setMany([
                    'iyzico.enabled' => !empty($_POST['iyzico_enabled']) ? '1' : '0',
                    'iyzico.mode' => $mode,
                    'iyzico.api_key' => trim((string) ($_POST['iyzico_api_key'] ?? '')),
                    'iyzico.secret_key' => (string) ((string) ($_POST['iyzico_secret_key'] ?? '') !== '' ? $_POST['iyzico_secret_key'] : ($current['iyzico.secret_key'] ?? '')),
                    'iyzico.installments' => normalize_iyzico_installments((string) ($_POST['iyzico_installments'] ?? '1')),
                ]);
                flash('success', 'iyzico ayarlari kaydedildi.');
                redirect('/settings#iyzico-settings');
            }

            if ($action === 'save_bank_transfer') {
                $settingsRepo->setMany([
                    'bank_transfer.iban_info' => trim((string) ($_POST['bank_transfer_iban_info'] ?? '')),
                    'bank_transfer.notify_emails' => normalize_email_list((string) ($_POST['bank_transfer_notify_emails'] ?? '')),
                ]);
                flash('success', 'Havale/EFT ayarlari kaydedildi.');
                redirect('/settings#bank-transfer-settings');
            }

            if ($action === 'run_backup') {
                $result = DatabaseBackup::runDaily($settingsRepo, true);
                $archive = $result['archive'] ?? [];
                $mail = $result['mail'] ?? [];
                if (!empty($mail['ok'])) {
                    flash('success', 'Yedek alindi ve mail olarak gonderildi: ' . basename((string) ($archive['zip_path'] ?? '')));
                } else {
                    flash('error', 'Yedek alindi fakat mail gonderilemedi: ' . (string) ($mail['error'] ?? 'Bilinmeyen hata'));
                }
                redirect('/settings#backup-settings');
            }

            if ($action === 'parasut_exchange') {
                $parasutClient->exchangeCode((string) ($_POST['code'] ?? ''), (string) ($_POST['company_id'] ?? ''));
                flash('success', 'Parasut baglantisi tamamlandi.');
                redirect('/settings#parasut-settings');
            }

            if ($action === 'parasut_disconnect') {
                $parasutClient->disconnect();
                flash('success', 'Parasut baglantisi kaldirildi.');
                redirect('/settings#parasut-settings');
            }

            if ($action === 'save_database') {
                $databaseConfig = submitted_database_config(app_config('database', []));
                test_database_config($databaseConfig, (int) ($_SESSION['user_id'] ?? 0));
                save_database_config($databaseConfig);
                flash('success', 'Veritabani ayarlari test edildi ve kaydedildi.');
                redirect('/settings#database-settings');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $settings = $settingsRepo->all();
    $token = $settingsRepo->microsoftToken();
    $parasutStatus = $parasutClient->status();
    $canDefinitions = Auth::can('definitions.manage');
    $canUsers = Auth::can('users.manage');
    $databaseConfig = app_config('database', []);

    render_layout('Ayarlar', static function () use ($settings, $token, $error, $parasutClient, $parasutStatus, $canDefinitions, $canUsers, $databaseConfig): void {
        $hasMicrosoftToken = !empty($token['refresh_token']);
        $expiresAt = !empty($token['expires_at']) ? date('d.m.Y H:i', (int) $token['expires_at']) : '-';
        $logoUrl = branding_logo_url($settings);
        $hasDatabasePassword = (string) ($databaseConfig['password'] ?? '') !== '';
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Sistem</p>
                <h1>Ayarlar</h1>
            </div>
            <div class="customer-actions">
                <?php if ($canUsers): ?>
                    <a href="<?= h(url('/settings/users')) ?>" class="button primary">Kullanicilar</a>
                <?php endif; ?>
                <a href="<?= h(url('/settings/grapesjs')) ?>" class="button primary">Mail şablon tasarımı</a>
                <?php if ($canDefinitions): ?>
                    <a href="<?= h(url('/settings/definitions')) ?>" class="button secondary">Tanimlamalar</a>
                <?php endif; ?>
                <a href="<?= h(url('/')) ?>" class="button secondary">Dashboard'a don</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?= h($error) ?></div>
        <?php endif; ?>

        <section class="settings-board">
            <div class="settings-card settings-card-brand">
                <div class="section-head">
                    <h2>Logo</h2>
                    <?php if ($logoUrl !== null): ?>
                        <span class="badge active">Yuklu</span>
                    <?php endif; ?>
                </div>

                <div class="logo-area">
                    <div class="logo-preview">
                        <?php if ($logoUrl !== null): ?>
                            <img src="<?= h($logoUrl) ?>" alt="Logo" width="72" height="72">
                            <span class="muted compact"><?= h(basename((string) ($settings['branding.logo_path'] ?? ''))) ?></span>
                        <?php else: ?>
                            <span class="brand-mark">Y</span>
                            <span class="muted compact">Logo yuklenmedi</span>
                        <?php endif; ?>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="logo-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_logo">
                        <label>
                            Logo dosyasi
                            <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.ico,image/png,image/jpeg,image/webp,image/x-icon,image/vnd.microsoft.icon" required>
                        </label>
                        <button type="submit" class="button primary">Logo yukle</button>
                    </form>

                    <?php if ($logoUrl !== null): ?>
                        <form method="post" class="logo-remove" onsubmit="return confirm('Logo kaldirilsin mi?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="remove_logo">
                            <button type="submit" class="button danger full">Logoyu kaldir</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" class="settings-card settings-card-mail">
                <div class="section-head">
                    <h2>Mail Ayarlari</h2>
                    <span class="badge active" data-mail-driver-badge><?= h((string) ($settings['mail.driver'] ?? 'log')) ?></span>
                </div>
                <div class="form-grid settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">

                    <div class="form-grid three">
                        <label>
                            Aktif gonderim tipi
                            <select name="mail_driver" data-mail-driver-select>
                                <?= option('log', 'Log / test modu', (string) ($settings['mail.driver'] ?? 'log')) ?>
                                <?= option('smtp', 'Normal SMTP', (string) ($settings['mail.driver'] ?? 'log')) ?>
                                <?= option('microsoft365', 'Microsoft 365 Exchange', (string) ($settings['mail.driver'] ?? 'log')) ?>
                                <?= option('mail', 'PHP mail()', (string) ($settings['mail.driver'] ?? 'log')) ?>
                            </select>
                        </label>
                        <label>
                            Gonderen adi
                            <input name="from_name" value="<?= h($settings['mail.from_name'] ?? '') ?>" required>
                        </label>
                        <label>
                            Gonderen e-posta
                            <input type="email" name="from_email" value="<?= h($settings['mail.from_email'] ?? '') ?>" required>
                        </label>
                    </div>

                    <div class="settings-section" data-mail-settings="log" <?= ($settings['mail.driver'] ?? 'log') === 'log' ? '' : 'hidden' ?>>
                        <div class="section-head">
                            <h3>Log / test modu</h3>
                            <span class="badge active">Log</span>
                        </div>
                        <div class="settings-note">
                            <strong>Mail gonderimi kapali</strong>
                            <span>E-postalar sunucuda log kaydina yazilir.</span>
                        </div>
                    </div>

                    <div class="settings-section" data-mail-settings="smtp" <?= ($settings['mail.driver'] ?? 'log') === 'smtp' ? '' : 'hidden' ?>>
                        <div class="section-head">
                            <h3>Normal SMTP</h3>
                            <span class="badge <?= ($settings['mail.driver'] ?? '') === 'smtp' ? 'active' : 'cancelled' ?>">SMTP</span>
                        </div>
                        <div class="form-grid two">
                            <label>
                                SMTP host
                                <input name="smtp_host" value="<?= h($settings['smtp.host'] ?? '') ?>" placeholder="smtp.office365.com">
                            </label>
                            <label>
                                Port
                                <input type="number" min="1" name="smtp_port" value="<?= h($settings['smtp.port'] ?? '587') ?>">
                            </label>
                            <label>
                                Guvenlik
                                <select name="smtp_encryption">
                                    <?= option('tls', 'STARTTLS', (string) ($settings['smtp.encryption'] ?? 'tls')) ?>
                                    <?= option('ssl', 'SSL', (string) ($settings['smtp.encryption'] ?? 'tls')) ?>
                                    <?= option('none', 'Yok', (string) ($settings['smtp.encryption'] ?? 'tls')) ?>
                                </select>
                            </label>
                            <label>
                                Zaman asimi (sn)
                                <input type="number" min="5" name="smtp_timeout" value="<?= h($settings['smtp.timeout'] ?? '20') ?>">
                            </label>
                            <label>
                                Kullanici adi
                                <input name="smtp_username" value="<?= h($settings['smtp.username'] ?? '') ?>">
                            </label>
                            <label>
                                Sifre
                                <input type="password" name="smtp_password" value="" placeholder="<?= ($settings['smtp.password'] ?? '') !== '' ? '********' : 'SMTP sifresi' ?>" autocomplete="new-password">
                            </label>
                        </div>
                    </div>

                    <div class="settings-section" data-mail-settings="microsoft365" <?= ($settings['mail.driver'] ?? 'log') === 'microsoft365' ? '' : 'hidden' ?>>
                        <div class="section-head">
                            <h3>Microsoft 365 Exchange</h3>
                            <span class="badge <?= $hasMicrosoftToken ? 'active' : 'cancelled' ?>"><?= $hasMicrosoftToken ? 'Bagli' : 'Bagli degil' ?></span>
                        </div>
                        <div class="form-grid two">
                            <label>
                                Tenant ID
                                <input name="m365_tenant_id" value="<?= h($settings['m365.tenant_id'] ?? 'common') ?>" placeholder="common veya tenant id">
                            </label>
                            <label>
                                Client ID
                                <input name="m365_client_id" value="<?= h($settings['m365.client_id'] ?? '') ?>">
                            </label>
                            <label>
                                Client Secret
                                <input type="password" name="m365_client_secret" value="" placeholder="<?= ($settings['m365.client_secret'] ?? '') !== '' ? '********' : 'Client secret' ?>" autocomplete="new-password">
                            </label>
                            <label>
                                Gonderen kullanici
                                <input name="m365_from_user" value="<?= h($settings['m365.from_user'] ?? '') ?>" placeholder="bos kalirsa oturum acan hesap">
                            </label>
                            <label class="span-2">
                                Callback URL
                                <input name="m365_redirect_uri" value="<?= h($settings['m365.redirect_uri'] ?? url('/settings/microsoft/callback')) ?>">
                            </label>
                        </div>
                        <div class="settings-actions">
                            <a class="button secondary" href="<?= h(url('/settings/microsoft/start')) ?>">Microsoft ile authenticate et</a>
                            <span class="muted">Token bitis: <?= h($expiresAt) ?></span>
                        </div>
                    </div>

                    <div class="settings-section" data-mail-settings="mail" <?= ($settings['mail.driver'] ?? 'log') === 'mail' ? '' : 'hidden' ?>>
                        <div class="section-head">
                            <h3>PHP mail()</h3>
                            <span class="badge active">PHP</span>
                        </div>
                        <div class="settings-note">
                            <strong>Sunucu mail servisi</strong>
                            <span>Mail gonderimi hosting uzerindeki PHP mail() fonksiyonu ile yapilir.</span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="button primary">Ayarlari kaydet</button>
                    </div>
                </div>
            </form>

            <div class="settings-side">
                <div class="settings-card" id="database-settings">
                    <div class="section-head">
                        <h2>Veritabani</h2>
                        <span class="badge active">MariaDB</span>
                    </div>
                    <div class="settings-meta">
                        <span>Aktif veritabani</span>
                        <strong><?= h($databaseConfig['database'] ?? '-') ?></strong>
                    </div>
                    <form method="post" class="form-grid settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_database">
                        <div class="form-grid two">
                            <label>
                                Host
                                <input name="db_host" value="<?= h($databaseConfig['host'] ?? '') ?>" required>
                            </label>
                            <label>
                                Port
                                <input type="number" min="1" max="65535" name="db_port" value="<?= h($databaseConfig['port'] ?? 3306) ?>" required>
                            </label>
                            <label>
                                Veritabani adi
                                <input name="db_database" value="<?= h($databaseConfig['database'] ?? '') ?>" required>
                            </label>
                            <label>
                                Kullanici adi
                                <input name="db_username" value="<?= h($databaseConfig['username'] ?? '') ?>" required>
                            </label>
                            <label>
                                Sifre
                                <input type="password" name="db_password" value="" placeholder="<?= $hasDatabasePassword ? '********' : 'Veritabani sifresi' ?>" autocomplete="new-password">
                                <span class="field-help">Bos birakirsaniz mevcut sifre korunur.</span>
                            </label>
                            <label>
                                Karakter seti
                                <input name="db_charset" value="<?= h($databaseConfig['charset'] ?? 'utf8mb4') ?>" required>
                            </label>
                        </div>
                        <div class="settings-note">
                            <strong>Kaydetmeden once test edilir</strong>
                            <span>Baglanti kurulamayan veya gerekli tablolar bulunmayan veritabani kaydedilmez.</span>
                        </div>
                        <button type="submit" class="button primary full">Baglantiyi test et ve kaydet</button>
                    </form>
                </div>

                <div class="settings-card" id="notification-settings">
                    <div class="section-head">
                        <h2>PWA ve Bildirim</h2>
                        <span class="badge cancelled" data-push-badge>Kapali</span>
                    </div>
                    <form method="post" class="form-grid settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_notifications">
                        <label>
                            Günlük bildirim saati
                            <input type="time" name="notification_time" value="<?= h(normalize_notification_time((string) ($settings['notifications.send_time'] ?? '09:00'))) ?>" required>
                            <span class="field-help">Müşteri hatırlatmaları, tedarikçi fiyat talepleri ve web push bildirimleri bu saatten sonra gönderilir.</span>
                        </label>
                        <button type="submit" class="button primary full">Bildirim saatini kaydet</button>
                    </form>
                    <div class="settings-note settings-form" data-push-status>
                        <strong>Tarayici bildirimi</strong>
                        <span>Bildirimleri acarak yenileme hatirlatmalarini bilgisayarinizda alabilirsiniz.</span>
                    </div>
                    <div class="settings-actions settings-form">
                        <button type="button" class="button primary" data-push-subscribe>Bildirimleri ac</button>
                        <button type="button" class="button secondary" data-push-test>Test bildirimi</button>
                    </div>
                </div>

                <div class="settings-card" id="backup-settings">
                    <div class="section-head">
                        <h2>Yedekleme</h2>
                        <span class="badge <?= ($settings['backup.enabled'] ?? '1') === '1' ? 'active' : 'cancelled' ?>">
                            <?= ($settings['backup.enabled'] ?? '1') === '1' ? 'Aktif' : 'Kapali' ?>
                        </span>
                    </div>
                    <form method="post" class="form-grid settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_backup">
                        <label class="checkline">
                            <input type="checkbox" name="backup_enabled" value="1" <?= ($settings['backup.enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                            Günlük otomatik DB yedeği al
                        </label>
                        <label>
                            Yedek mail alıcısı
                            <input type="email" name="backup_email" value="<?= h($settings['backup.email'] ?? ($settings['mail.from_email'] ?? '')) ?>" required>
                        </label>
                        <div class="form-grid two">
                            <label>
                                Günlük yedek saati
                                <input type="time" name="backup_time" value="<?= h(normalize_notification_time((string) ($settings['backup.time'] ?? '02:00'))) ?>" required>
                            </label>
                            <label>
                                Sunucuda saklama (gün)
                                <input type="number" min="1" name="backup_keep_days" value="<?= h($settings['backup.keep_days'] ?? '14') ?>" required>
                            </label>
                        </div>
                        <div class="settings-note">
                            <strong>ZIP içeriği</strong>
                            <span>backup.sql geri yükleme dosyası ve her tablo için CSV çıktısı mail eki olarak gönderilir.</span>
                        </div>
                        <button type="submit" class="button primary full">Yedekleme ayarlarını kaydet</button>
                    </form>
                    <div class="settings-meta">
                        <span>Son yedek</span>
                        <strong><?= h(($settings['backup.last_run_at'] ?? '') ?: '-') ?></strong>
                    </div>
                    <?php if (!empty($settings['backup.last_file'])): ?>
                        <div class="settings-note">
                            <strong><?= h(basename((string) $settings['backup.last_file'])) ?></strong>
                            <span><?= h(DatabaseBackup::humanSize((int) ($settings['backup.last_size'] ?? 0))) ?> - mail: <?= h(($settings['backup.last_mail_status'] ?? '') ?: '-') ?></span>
                            <?php if (!empty($settings['backup.last_error'])): ?>
                                <span><?= h($settings['backup.last_error']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" class="settings-form" onsubmit="return confirm('Şimdi veritabanı yedeği alınıp mail gönderilsin mi?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="run_backup">
                        <button type="submit" class="button secondary full">Şimdi yedek al ve mail gönder</button>
                    </form>
                    <div class="settings-note settings-form">
                        <strong>Geri dönüş komutu</strong>
                        <span>Sunucuda ihtiyaç olursa: php <?= h(ROOT_PATH . '/bin/restore-backup.php') ?> yedek.zip --yes</span>
                    </div>
                </div>

                <div class="settings-card" id="iyzico-settings">
                    <div class="section-head">
                        <h2>iyzico ödeme</h2>
                        <span class="badge <?= ($settings['iyzico.enabled'] ?? '0') === '1' ? 'active' : 'cancelled' ?>">
                            <?= ($settings['iyzico.enabled'] ?? '0') === '1' ? 'Aktif' : 'Kapalı' ?>
                        </span>
                    </div>
                    <form method="post" class="form-grid settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_iyzico">
                        <label class="checkline">
                            <input type="checkbox" name="iyzico_enabled" value="1" <?= ($settings['iyzico.enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            Kredi kartı ödeme linki oluşturmayı aç
                        </label>
                        <div class="form-grid two">
                            <label>
                                Ortam
                                <select name="iyzico_mode">
                                    <?= option('sandbox', 'Test / Sandbox', (string) ($settings['iyzico.mode'] ?? 'sandbox')) ?>
                                    <?= option('live', 'Canlı', (string) ($settings['iyzico.mode'] ?? 'sandbox')) ?>
                                </select>
                            </label>
                            <label>
                                Taksitler
                                <input name="iyzico_installments" value="<?= h($settings['iyzico.installments'] ?? '1') ?>" placeholder="1,2,3,6,9,12">
                            </label>
                        </div>
                        <label>
                            API Key
                            <input name="iyzico_api_key" value="<?= h($settings['iyzico.api_key'] ?? '') ?>" placeholder="sandbox-...">
                        </label>
                        <label>
                            Secret Key
                            <input type="password" name="iyzico_secret_key" value="" placeholder="<?= ($settings['iyzico.secret_key'] ?? '') !== '' ? '********' : 'Secret key' ?>" autocomplete="new-password">
                            <span class="field-help">Boş bırakırsanız mevcut secret korunur.</span>
                        </label>
                        <label>
                            Callback URL
                            <input value="<?= h(url('/payments/iyzico/callback')) ?>" readonly>
                        </label>
                        <div class="settings-note">
                            <strong>Güvenli yönlendirme</strong>
                            <span>Kart numarası bu panelde alınmaz; müşteri iyzico Checkout Form sayfasında ödeme yapar.</span>
                        </div>
                        <button type="submit" class="button primary full">iyzico ayarlarını kaydet</button>
                    </form>
                </div>

                <div class="settings-card" id="bank-transfer-settings">
                    <div class="section-head">
                        <h2>Havale / EFT</h2>
                        <span class="badge active">Makbuz</span>
                    </div>
                    <form method="post" class="form-grid settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_bank_transfer">
                        <label>
                            IBAN bilgileri
                            <textarea name="bank_transfer_iban_info" rows="6" placeholder="Banka adi&#10;Hesap sahibi&#10;IBAN: TR00 0000 0000 0000 0000 0000 00"><?= h($settings['bank_transfer.iban_info'] ?? '') ?></textarea>
                            <span class="field-help">Müşteri Havale / EFT seçtiğinde bu bilgi ödeme ekranında görünecek.</span>
                        </label>
                        <label>
                            Makbuz bildirimi alıcıları
                            <textarea name="bank_transfer_notify_emails" rows="3" placeholder="muhasebe@firma.com, finans@firma.com"><?= h($settings['bank_transfer.notify_emails'] ?? '') ?></textarea>
                            <span class="field-help">Birden fazla adresi virgül, noktalı virgül veya yeni satır ile yazabilirsiniz. Boşsa gönderen e-posta kullanılır.</span>
                        </label>
                        <button type="submit" class="button primary full">Havale / EFT ayarlarını kaydet</button>
                    </form>
                </div>

                <div class="settings-card">
                    <h2>Test E-postasi</h2>
                    <form method="post" class="form-grid settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test">
                    <label>
                        Test e-posta adresi
                        <input type="email" name="test_email" placeholder="ornek@firma.com" required>
                    </label>
                    <button type="submit" class="button primary">Test e-postasi gonder</button>
                    </form>
                </div>

                <div class="settings-card" id="parasut-settings">
                    <div class="section-head">
                        <h2>Parasut</h2>
                        <span class="badge <?= $parasutStatus['connected'] ? 'active' : 'cancelled' ?>"><?= $parasutStatus['connected'] ? 'Bagli' : 'Bekliyor' ?></span>
                    </div>
                    <div class="settings-meta">
                        <span>Firma ID</span>
                        <strong><?= h($parasutStatus['company_id'] ?: '-') ?></strong>
                    </div>
                    <a class="button secondary full settings-form" href="<?= h($parasutClient->authorizationUrl()) ?>" target="_blank" rel="noopener">Parasut izin ekranini ac</a>
                    <form method="post" class="form-grid settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="parasut_exchange">
                        <label>Firma ID <input name="company_id" value="<?= h($parasutStatus['company_id'] ?? '') ?>" required></label>
                        <label>
                            Onay kodu
                            <input type="password" name="code" placeholder="********" autocomplete="one-time-code" required>
                            <span class="field-help">Once izin ekranini acin, gelen kodu bu alana yazin.</span>
                        </label>
                        <button type="submit" class="button primary">Baglantiyi kaydet</button>
                    </form>
                    <?php if ($parasutStatus['connected']): ?>
                        <form method="post" class="disconnect-form" onsubmit="return confirm('Parasut baglantisi kaldirilsin mi?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="parasut_disconnect">
                            <button type="submit" class="button danger full">Parasut baglantisini kaldir</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="settings-card">
                    <div class="section-head">
                        <h2>Microsoft Durumu</h2>
                        <span class="badge <?= $hasMicrosoftToken ? 'active' : 'cancelled' ?>"><?= $hasMicrosoftToken ? 'Bagli' : 'Bagli degil' ?></span>
                    </div>
                    <div class="settings-meta">
                        <span>Token bitis</span>
                        <strong><?= h($expiresAt) ?></strong>
                    </div>
                    <form method="post" class="disconnect-form" onsubmit="return confirm('Microsoft 365 baglantisi kaldirilsin mi?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_m365">
                        <button type="submit" class="button danger full">Microsoft 365 baglantisini kaldir</button>
                    </form>
                    <div class="settings-note">
                        <strong>Mail.Send</strong>
                        <span>Azure/Entra callback URL tanimli olmali.</span>
                    </div>
                </div>
            </div>
        </section>
        <?php
    });
}

function database_settings_path(): string
{
    return ROOT_PATH . '/storage/config/database.php';
}

function normalize_notification_time(string $time): string
{
    $time = trim($time);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches) !== 1) {
        return '09:00';
    }

    return $matches[1] . ':' . $matches[2];
}

function normalize_email_list(string $value): string
{
    $parts = preg_split('/[\s,;]+/', $value) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = trim($part);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[strtolower($email)] = $email;
        }
    }

    return implode(', ', array_values($emails));
}

function normalize_iyzico_installments(string $value): string
{
    $allowed = [1, 2, 3, 6, 9, 12];
    $installments = [];
    foreach (preg_split('/[,\s]+/', $value) ?: [] as $part) {
        $number = (int) $part;
        if (in_array($number, $allowed, true)) {
            $installments[$number] = $number;
        }
    }

    return implode(',', array_values($installments ?: [1]));
}

function maybe_run_scheduled_backup(string $path, string $method): void
{
    if ($method !== 'GET' || str_starts_with($path, '/api/')) {
        return;
    }

    try {
        DatabaseBackup::runDaily(new SettingsRepository(), false);
    } catch (Throwable $e) {
        error_log('Otomatik yedekleme calisamadi: ' . $e->getMessage());
    }
}

function submitted_database_config(array $current): array
{
    $password = (string) ($_POST['db_password'] ?? '');
    $config = [
        'host' => trim((string) ($_POST['db_host'] ?? ($current['host'] ?? ''))),
        'port' => (int) ($_POST['db_port'] ?? ($current['port'] ?? 3306)),
        'database' => trim((string) ($_POST['db_database'] ?? ($current['database'] ?? ''))),
        'username' => trim((string) ($_POST['db_username'] ?? ($current['username'] ?? ''))),
        'password' => $password !== '' ? $password : (string) ($current['password'] ?? ''),
        'charset' => trim((string) ($_POST['db_charset'] ?? ($current['charset'] ?? 'utf8mb4'))),
    ];

    if ($config['host'] === '') {
        throw new RuntimeException('Veritabani host bilgisi gerekli.');
    }

    if ($config['port'] < 1 || $config['port'] > 65535) {
        throw new RuntimeException('Veritabani portu 1 ile 65535 arasinda olmali.');
    }

    if ($config['database'] === '') {
        throw new RuntimeException('Veritabani adi gerekli.');
    }

    if ($config['username'] === '') {
        throw new RuntimeException('Veritabani kullanici adi gerekli.');
    }

    if ($config['charset'] === '' || !preg_match('/^[A-Za-z0-9_]+$/', $config['charset'])) {
        throw new RuntimeException('Veritabani karakter seti gecersiz.');
    }

    return $config;
}

function test_database_config(array $config, int $currentUserId = 0): void
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        (int) $config['port'],
        $config['database'],
        $config['charset']
    );

    $pdo = new \PDO($dsn, (string) $config['username'], (string) $config['password'], [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => true,
    ]);

    $pdo->query('SELECT 1');
    $statement = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME IN ('users', 'app_settings', 'renewals')"
    );

    if ((int) $statement->fetchColumn() < 3) {
        throw new RuntimeException('Veritabani baglantisi kuruldu fakat gerekli sistem tablolari bulunamadi.');
    }

    if ($currentUserId > 0) {
        $userStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id AND is_active = 1');
        $userStatement->execute(['id' => $currentUserId]);
        if ((int) $userStatement->fetchColumn() < 1) {
            throw new RuntimeException('Veritabani baglantisi kuruldu fakat mevcut kullanici bu veritabaninda bulunamadi.');
        }
    }
}

function save_database_config(array $config): void
{
    $path = database_settings_path();
    $dir = dirname($path);

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Veritabani ayar klasoru olusturulamadi.');
    }

    $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Veritabani ayarlari kaydedilemedi.');
    }

    @chmod($path, 0640);
}

function save_branding_logo(string $currentPath): string
{
    $file = $_FILES['logo'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Logo dosyasi secin.');
    }

    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo yuklenemedi.');
    }

    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Logo dosyasi en fazla 2 MB olmali.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'ico' => ['image/x-icon', 'image/vnd.microsoft.icon', 'application/octet-stream'],
    ];

    if (!isset($allowed[$extension])) {
        throw new RuntimeException('Logo PNG, JPG, WEBP veya ICO formatinda olmali.');
    }

    $mime = '';
    if (is_file($tmpPath) && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string) finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
        }
    }

    if ($mime !== '' && !in_array($mime, $allowed[$extension], true)) {
        throw new RuntimeException('Logo dosya tipi gecersiz.');
    }

    $extension = $extension === 'jpeg' ? 'jpg' : $extension;
    $uploadDir = ROOT_PATH . '/public/uploads/branding';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Logo klasoru olusturulamadi.');
    }

    $filename = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $target)) {
        throw new RuntimeException('Logo kaydedilemedi.');
    }

    remove_branding_logo($currentPath);

    return '/uploads/branding/' . $filename;
}

function remove_branding_logo(string $path): void
{
    if (!str_starts_with($path, '/uploads/branding/')) {
        return;
    }

    $file = ROOT_PATH . '/public' . $path;
    if (is_file($file)) {
        unlink($file);
    }
}

function branding_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    try {
        $settings = (new SettingsRepository())->all();
    } catch (Throwable) {
        $settings = SettingsRepository::defaults();
    }

    return $settings;
}

function branding_logo_url(?array $settings = null): ?string
{
    $settings ??= branding_settings();
    $path = (string) ($settings['branding.logo_path'] ?? '');
    if (!str_starts_with($path, '/uploads/branding/')) {
        return null;
    }

    return url($path);
}

function asset_version(): string
{
    $cssTime = is_file(ROOT_PATH . '/public/assets/app.css') ? filemtime(ROOT_PATH . '/public/assets/app.css') : 0;
    $jsTime = is_file(ROOT_PATH . '/public/assets/app.js') ? filemtime(ROOT_PATH . '/public/assets/app.js') : 0;

    return app_version() . '-' . max($cssTime ?: 1, $jsTime ?: 1);
}

function grapesjs_assets(): string
{
    return '<link rel="stylesheet" href="https://unpkg.com/grapesjs@0.22.15/dist/css/grapes.min.css">' . "\n"
        . '<script defer src="https://unpkg.com/grapesjs@0.22.15/dist/grapes.min.js"></script>';
}

function handle_microsoft_start(): void
{
    $settings = (new SettingsRepository())->all();
    if (trim((string) ($settings['m365.client_id'] ?? '')) === '' || trim((string) ($settings['m365.client_secret'] ?? '')) === '') {
        flash('error', 'Once Microsoft 365 Client ID ve Client Secret bilgilerini kaydedin.');
        redirect('/settings');
    }

    $_SESSION['m365_oauth_state'] = bin2hex(random_bytes(16));
    $tenant = trim((string) ($settings['m365.tenant_id'] ?? 'common')) ?: 'common';
    $query = http_build_query([
        'client_id' => $settings['m365.client_id'],
        'response_type' => 'code',
        'redirect_uri' => $settings['m365.redirect_uri'] ?? url('/settings/microsoft/callback'),
        'response_mode' => 'query',
        'scope' => Mailer::microsoftScope(),
        'state' => $_SESSION['m365_oauth_state'],
    ]);

    header('Location: https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize?' . $query);
    exit;
}

function handle_microsoft_callback(): void
{
    $state = (string) ($_GET['state'] ?? '');
    if ($state === '' || !hash_equals((string) ($_SESSION['m365_oauth_state'] ?? ''), $state)) {
        flash('error', 'Microsoft 365 oturum dogrulamasi gecersiz.');
        redirect('/settings');
    }

    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        flash('error', 'Microsoft 365 onay kodu alinamadi.');
        redirect('/settings');
    }

    try {
        $settingsRepo = new SettingsRepository();
        $settings = $settingsRepo->all();
        $token = Mailer::microsoftTokenRequest($settings, [
            'client_id' => $settings['m365.client_id'] ?? '',
            'client_secret' => $settings['m365.client_secret'] ?? '',
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $settings['m365.redirect_uri'] ?? url('/settings/microsoft/callback'),
            'scope' => Mailer::microsoftScope(),
        ]);

        $settingsRepo->setMicrosoftToken($token);
        unset($_SESSION['m365_oauth_state']);
        flash('success', 'Microsoft 365 mail baglantisi tamamlandi.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/settings');
}

function validate_renewal(array $data): array
{
    $errors = [];
    if (empty($data['customer_id'])) {
        $errors[] = 'Musteri secimi zorunlu.';
    }
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $validItemCount = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $hasTitle = !empty($item['definition_id']) || trim((string) ($item['title'] ?? '')) !== '';
        $quantity = (float) str_replace(',', '.', (string) ($item['quantity'] ?? 0));
        $unitPriceRaw = trim((string) ($item['unit_price'] ?? ''));
        $vatRateRaw = trim((string) ($item['vat_rate'] ?? ''));
        if ($hasTitle) {
            $validItemCount++;
        }
        if ($hasTitle && $quantity <= 0) {
            $errors[] = 'Urun satirlarinda adet 0’dan buyuk olmali.';
            break;
        }
        if ($hasTitle && $unitPriceRaw !== '' && !is_numeric(str_replace(',', '.', $unitPriceRaw))) {
            $errors[] = 'Birim fiyat sayisal olmali.';
            break;
        }
        if ($hasTitle && $vatRateRaw !== '' && !is_numeric(str_replace(',', '.', $vatRateRaw))) {
            $errors[] = 'KDV orani sayisal olmali.';
            break;
        }
    }
    if ($validItemCount < 1 && empty($data['definition_id']) && trim((string) ($data['title'] ?? '')) === '') {
        $errors[] = 'En az bir urun veya hizmet satiri ekleyin.';
    }
    if (empty($data['renewal_period_id'])) {
        $errors[] = 'Yenileme periyodu zorunlu.';
    }
    if (!empty($data['old_entry_mode'])) {
        $startDate = trim((string) ($data['start_date'] ?? ''));
        if ($startDate === '') {
            $errors[] = 'Eski tarihli giriste satin alma / onay tarihi zorunlu.';
        } elseif (strtotime($startDate) === false) {
            $errors[] = 'Eski tarihli giriste tarih gecersiz.';
        } elseif (strtotime($startDate) > strtotime(date('Y-m-d'))) {
            $errors[] = 'Eski tarihli giriste tarih bugunden ileri olamaz.';
        }
    }
    if (!in_array(strtoupper((string) ($data['currency'] ?? 'TRY')), allowed_currency_options(), true)) {
        $errors[] = 'Para birimi gecersiz.';
    }
    if (!empty($data['supplier_price_request_enabled'])) {
        if ((int) ($data['supplier_price_request_days'] ?? 0) < 1) {
            $errors[] = 'Tedarikci fiyat talebi icin gun sayisi en az 1 olmali.';
        }
        if (empty($data['supplier_id']) && empty($data['supplier_group_id'])) {
            $errors[] = 'Tedarikci fiyat talebi icin tedarikci veya tedarikci grubu secin.';
        }
    }

    return $errors;
}

function render_layout(string $title, callable $content, string $headExtra = ''): void
{
    $user = Auth::user();
    $logoUrl = branding_logo_url();
    $assetVersion = asset_version();
    $settingsHref = settings_nav_href();
    $homePath = first_allowed_path() ?? '/';
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#147c72">
        <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
        <title><?= h($title) ?> | <?= h(app_config('app.name')) ?></title>
        <link rel="manifest" href="<?= h(url('/manifest.webmanifest')) ?>">
        <?php if ($logoUrl !== null): ?>
            <link rel="icon" href="<?= h($logoUrl) ?>">
            <link rel="apple-touch-icon" href="<?= h($logoUrl) ?>">
        <?php else: ?>
            <link rel="icon" href="<?= h(url('/assets/pwa-icon.svg')) ?>">
            <link rel="apple-touch-icon" href="<?= h(url('/assets/pwa-icon.svg')) ?>">
        <?php endif; ?>
        <?= $headExtra ?>
        <link rel="stylesheet" href="<?= h(url('/assets/app.css') . '?v=' . $assetVersion) ?>">
        <script defer src="<?= h(url('/assets/app.js') . '?v=' . $assetVersion) ?>"></script>
    </head>
    <body>
        <div class="app-shell">
            <aside class="sidebar">
                <div class="sidebar-head">
                    <a class="brand" href="<?= h(url($homePath)) ?>">
                        <?php if ($logoUrl !== null): ?>
                            <img class="brand-logo" src="<?= h($logoUrl) ?>" alt="Logo" width="36" height="36">
                        <?php else: ?>
                            <span class="brand-mark">Y</span>
                        <?php endif; ?>
                        <span>Yenileme Takibi</span>
                    </a>
                    <button type="button" class="mobile-menu-button" data-mobile-menu-toggle aria-controls="mobile-navigation" aria-expanded="false">
                        <span>Menü</span>
                    </button>
                </div>
                <div class="sidebar-menu" id="mobile-navigation" data-mobile-menu>
                <nav class="nav">
                    <?= Auth::can('dashboard.view') ? nav_link('/', 'Dashboard') : '' ?>
                    <?= Auth::can('customers.view') ? nav_link('/customers', 'Musteriler') : '' ?>
                    <?= Auth::can('suppliers.view') ? nav_link('/suppliers', 'Tedarikciler') : '' ?>
                    <?= Auth::can('logs.view') ? nav_link('/logs', 'Loglar') : '' ?>
                    <?= $settingsHref !== null ? nav_link($settingsHref, 'Ayarlar') : '' ?>
                </nav>
                <form method="post" action="<?= h(url('/logout')) ?>" class="logout">
                    <?= csrf_field() ?>
                    <small class="app-version">Sürüm <?= h(app_version_label()) ?></small>
                    <span><?= h($user['name'] ?? '') ?></span>
                    <button type="submit">Cikis</button>
                </form>
                </div>
            </aside>
            <main class="content">
                <?php foreach (flashes() as $message): ?>
                    <div class="alert <?= h($message['type']) ?>"><?= h($message['message']) ?></div>
                <?php endforeach; ?>
                <?php $content(); ?>
            </main>
        </div>
    </body>
    </html>
    <?php
}

function render_public_layout(string $title, callable $content): void
{
    $logoUrl = branding_logo_url();
    $assetVersion = asset_version();
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#147c72">
        <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
        <title><?= h($title) ?> | <?= h(app_config('app.name')) ?></title>
        <link rel="manifest" href="<?= h(url('/manifest.webmanifest')) ?>">
        <?php if ($logoUrl !== null): ?>
            <link rel="icon" href="<?= h($logoUrl) ?>">
            <link rel="apple-touch-icon" href="<?= h($logoUrl) ?>">
        <?php else: ?>
            <link rel="icon" href="<?= h(url('/assets/pwa-icon.svg')) ?>">
            <link rel="apple-touch-icon" href="<?= h(url('/assets/pwa-icon.svg')) ?>">
        <?php endif; ?>
        <link rel="stylesheet" href="<?= h(url('/assets/app.css') . '?v=' . $assetVersion) ?>">
        <script defer src="<?= h(url('/assets/app.js') . '?v=' . $assetVersion) ?>"></script>
    </head>
    <body class="public">
        <div class="public-stack">
            <?php foreach (flashes() as $message): ?>
                <div class="alert <?= h($message['type']) ?>"><?= h($message['message']) ?></div>
            <?php endforeach; ?>
            <?php $content(); ?>
            <div class="public-version">Sürüm <?= h(app_version_label()) ?></div>
        </div>
    </body>
    </html>
    <?php
}

function render_error(Throwable $e): void
{
    error_log((string) $e);

    render_layout('Hata', static function () use ($e): void {
        ?>
        <div class="alert error">
            Islem tamamlanamadi: <?= h($e->getMessage()) ?>
        </div>
        <?php
    });
}

function stat_card(string $label, int $value, string $tone = ''): string
{
    return sprintf(
        '<div class="stat %s"><span>%s</span><strong>%d</strong></div>',
        h($tone),
        h($label),
        $value
    );
}

function render_exchange_rates(array $exchangeRates, string $variant = 'default'): string
{
    $classes = 'exchange-rates' . ($variant === 'compact' ? ' compact-rates' : '');
    ob_start();
    ?>
    <section class="<?= h($classes) ?>">
        <div class="exchange-rates-head">
            <div>
                <span class="eyebrow">Döviz</span>
                <h2>Dolar / Euro</h2>
            </div>
            <span class="muted compact">
                <?= h(exchange_rate_date_label($exchangeRates)) ?>
            </span>
        </div>

        <?php if (empty($exchangeRates['ok'])): ?>
            <div class="exchange-rate-empty">Kur bilgisi alınamadı.</div>
        <?php else: ?>
            <div class="exchange-rate-grid">
                <?php foreach (['USD', 'EUR'] as $code): ?>
                    <?php $rate = $exchangeRates['rates'][$code] ?? null; ?>
                    <div class="exchange-rate-item">
                        <div>
                            <span><?= h($code) ?></span>
                            <strong><?= h($rate['name'] ?? $code) ?></strong>
                        </div>
                        <div class="exchange-rate-values">
                            <span>Alış <?= h(format_exchange_rate($rate['buying'] ?? null)) ?></span>
                            <strong>Satış <?= h(format_exchange_rate($rate['selling'] ?? null)) ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

function format_exchange_rate(mixed $rate): string
{
    return is_numeric($rate) ? number_format((float) $rate, 4, ',', '.') . ' TL' : '-';
}

function exchange_rate_date_label(array $exchangeRates): string
{
    if (empty($exchangeRates['ok'])) {
        return 'TCMB';
    }

    $parts = [];
    if (!empty($exchangeRates['source'])) {
        $parts[] = (string) $exchangeRates['source'];
    }

    if (!empty($exchangeRates['date'])) {
        $parts[] = (string) $exchangeRates['date'];
    }

    if (!empty($exchangeRates['stale'])) {
        $parts[] = 'cache';
    }

    return implode(' - ', $parts);
}

function render_renewal_table(array $rows, bool $withActions, bool $withReminderAck = false, bool $withManualNotify = false): string
{
    $canViewDetails = Auth::can('renewals.details');
    $canManage = $withActions && Auth::can('renewals.manage');
    $canDelete = $withActions && Auth::can('renewals.delete');
    $canNotify = $withManualNotify && Auth::can('renewals.manage');

    ob_start();
    if ($rows === []) {
        echo '<div class="empty">Kayit bulunamadi.</div>';
        return (string) ob_get_clean();
    }
    ?>
    <div class="renewal-list">
        <?php foreach ($rows as $row): ?>
            <?php
            $days = days_until($row['renewal_date']);
            $daysLabel = $days === null ? '-' : ($days < 0 ? abs($days) . ' gun gecikti' : $days . ' gun');
            $urgencyClass = renewal_urgency_class($row, $days);
            $urgencyLabel = renewal_urgency_label($row, $days);
            $canAcknowledge = $withReminderAck
                && ($row['status'] ?? '') === 'active'
                && renewal_can_acknowledge($row, $days);
            $canNotifyRow = $canNotify && ($row['status'] ?? '') === 'active';
            $readSummary = renewal_notification_read_summary($row);
            $hasRowActions = $canAcknowledge || $canNotifyRow || $canManage || $canDelete;
            ?>
            <?php if ($canViewDetails): ?>
                <details class="renewal-item <?= h($urgencyClass) ?>">
                    <summary class="renewal-summary">
            <?php else: ?>
                <div class="renewal-item <?= h($urgencyClass) ?>">
                    <div class="renewal-summary renewal-summary-readonly">
            <?php endif; ?>
                    <span class="renewal-company">
                        <small>Musteri</small>
                        <strong><?= h($row['company_name']) ?></strong>
                    </span>
                    <span class="renewal-title">
                        <small>Urun / Hizmet</small>
                        <strong><?= h($row['item_summary'] ?? $row['title']) ?></strong>
                    </span>
                    <span class="renewal-days">
                        <small>Kalan</small>
                        <strong><?= h($daysLabel) ?></strong>
                    </span>
                    <span>
                        <small>Durum</small>
                        <span class="badge <?= h($urgencyClass) ?>"><?= h($urgencyLabel) ?></span>
                    </span>
                    <span class="renewal-read-state <?= !empty($readSummary['complete']) ? 'complete' : '' ?>" title="<?= h((string) ($readSummary['readers'] ?? '')) ?>">
                        <small>Okunma</small>
                        <strong><?= h((string) ($readSummary['label'] ?? '-')) ?></strong>
                    </span>
                    <?php if ($canViewDetails): ?>
                        <span class="renewal-toggle">
                            <span class="closed">Göster</span>
                            <span class="open">Gizle</span>
                        </span>
                    <?php endif; ?>
            <?php if ($canViewDetails): ?>
                    </summary>

                    <div class="renewal-details">
                        <div class="detail-grid">
                            <div>
                                <span>Musteri yetkilisi</span>
                                <strong><?= h($row['contact_name'] ?: $row['customer_email'] ?: '-') ?></strong>
                            </div>
                            <div>
                                <span>Ürün sayısı</span>
                                <strong><?= h((string) max(1, (int) ($row['item_count'] ?? 1))) ?></strong>
                            </div>
                            <div>
                                <span>Yenileme</span>
                                <strong><?= h(date('d.m.Y', strtotime($row['renewal_date']))) ?></strong>
                                <?php if (!empty($row['renewal_period_name'])): ?>
                                    <em><?= h($row['renewal_period_name']) ?></em>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span>Toplam</span>
                                <strong><?= h(money_format_local($row['item_total'] ?? $row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'))) ?></strong>
                                <em>KDV dahil</em>
                            </div>
                            <div>
                                <span>Fatura no</span>
                                <strong><?= h($row['current_invoice_number'] ?: '-') ?></strong>
                            </div>
                            <div>
                                <span>Ödeme şekli</span>
                                <strong><?= h(renewal_payment_label($row)) ?></strong>
                            </div>
                            <div>
                                <span>Tedarikci</span>
                                <strong><?= h($row['supplier_display'] ?: '-') ?></strong>
                            </div>
                            <div>
                                <span>Tedarikci grubu</span>
                                <strong><?= h($row['supplier_group_name'] ?: '-') ?></strong>
                            </div>
                            <?php if (!empty($row['supplier_price_request_enabled'])): ?>
                                <div>
                                    <span>Fiyat talebi</span>
                                    <strong><?= h((string) $row['supplier_price_request_days']) ?> gun kala</strong>
                                    <?php if (!empty($row['supplier_price_requested_at'])): ?>
                                        <em><?= h(date('d.m.Y H:i', strtotime($row['supplier_price_requested_at']))) ?></em>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php
                        $detailItems = [];
                        try {
                            $detailItems = (new RenewalRepository())->renewalItems((int) $row['id']);
                        } catch (Throwable) {
                            $detailItems = [];
                        }
                        try {
                            $latestDecision = (new RenewalRepository())->latestRenewalDecision((int) $row['id']);
                        } catch (Throwable) {
                            $latestDecision = null;
                        }
                        ?>
                        <?php if ($latestDecision): ?>
                            <div class="decision-note">
                                <span>Son takip kararı</span>
                                <strong><?= h(renewal_decision_label((string) $latestDecision['decision'])) ?></strong>
                                <?php if (!empty($latestDecision['payment_terms'])): ?>
                                    <em>Ödeme şartı: <?= h((string) $latestDecision['payment_terms']) ?></em>
                                <?php elseif (!empty($latestDecision['reason'])): ?>
                                    <em>Sebep: <?= h((string) $latestDecision['reason']) ?></em>
                                <?php elseif (!empty($latestDecision['postponed_date'])): ?>
                                    <em>Yeni tarih: <?= h(date('d.m.Y', strtotime((string) $latestDecision['postponed_date']))) ?></em>
                                <?php elseif (!empty($latestDecision['note'])): ?>
                                    <em><?= h((string) $latestDecision['note']) ?></em>
                                <?php endif; ?>
                                <small><?= h(date('d.m.Y H:i', strtotime((string) $latestDecision['created_at']))) ?><?= !empty($latestDecision['created_by_name']) ? ' - ' . h((string) $latestDecision['created_by_name']) : '' ?></small>
                            </div>
                        <?php endif; ?>

                        <?php if ($detailItems): ?>
                            <div class="renewal-detail-items">
                                <span>Ürünler</span>
                                <?php foreach ($detailItems as $item): ?>
                                    <?php
                                    $quantity = (float) ($item['quantity'] ?? 1);
                                    $unitPrice = ($item['unit_price'] ?? null) === null ? null : (float) $item['unit_price'];
                                    $vatRate = (float) ($item['vat_rate'] ?? 0);
                                    $net = $unitPrice === null ? null : $quantity * $unitPrice;
                                    $lineTotal = $net === null ? null : $net + ($net * $vatRate / 100);
                                    ?>
                                    <div class="renewal-detail-item">
                                        <strong><?= h($item['title']) ?></strong>
                                        <span><?= h(($item['brand'] ?? '') ?: '-') ?></span>
                                        <span><?= h(number_format($quantity, 2, ',', '.')) ?> adet</span>
                                        <span><?= h(money_format_local($lineTotal, (string) ($row['currency'] ?? 'TRY'))) ?> KDV dahil</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($row['definition_notification_info'])): ?>
                            <div class="definition-info-note">
                                <span>Bilgilendirme</span>
                                <p><?= nl2br(h($row['definition_notification_info']), false) ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($readSummary['readers'])): ?>
                            <div class="notification-read-note">
                                <span>Okuyan yetkililer</span>
                                <?= render_notification_reader_chips((string) $readSummary['readers']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($hasRowActions): ?>
                            <?= render_renewal_actions($row, $canManage, $canDelete, $canAcknowledge, $canNotifyRow) ?>
                        <?php endif; ?>
                    </div>
                </details>
            <?php else: ?>
                    </div>
                    <?php if ($hasRowActions): ?>
                        <?= render_renewal_actions($row, $canManage, $canDelete, $canAcknowledge, $canNotifyRow) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

function render_renewal_actions(array $row, bool $canManage, bool $canDelete, bool $canAcknowledge, bool $canNotify = false): string
{
    ob_start();
    ?>
    <div class="renewal-actions">
        <?php if ($canNotify): ?>
            <form method="post" action="<?= h(url('/renewals/' . $row['id'] . '/notify')) ?>" onsubmit="return confirm('Bu yenileme icin bilgilendirme maili hemen gonderilsin mi?')">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? route_path()) ?>">
                <button type="submit" class="button small primary">Bildirim gönder</button>
            </form>
        <?php endif; ?>
        <?php if ($canAcknowledge): ?>
            <?php if (!empty($row['reminder_acknowledged_today'])): ?>
                <span class="badge active">Okundu</span>
            <?php else: ?>
                <form method="post" action="<?= h(url('/renewals/' . $row['id'] . '/ack')) ?>" onsubmit="return confirm('Bugunku bildirim durdurulsun mu?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? route_path()) ?>">
                    <button type="submit" class="button small secondary">Okudum</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <button type="button" class="button small secondary" data-dialog-open="renewal-mail-<?= h($row['id']) ?>">Mail olarak gönder</button>
            <button type="button" class="button small whatsapp" data-dialog-open="renewal-whatsapp-<?= h($row['id']) ?>">WhatsApp PDF gönder</button>
            <button type="button" class="button small supplier-price" data-dialog-open="supplier-price-<?= h($row['id']) ?>">Tedarikçiden fiyat al</button>
            <a href="<?= h(url('/renewals/' . $row['id'] . '/edit')) ?>" class="button small">Duzenle</a>
            <button type="button" class="button small primary" data-dialog-open="renewal-decision-approved-<?= h($row['id']) ?>">Onaylandı</button>
            <button type="button" class="button small danger" data-dialog-open="renewal-decision-rejected-<?= h($row['id']) ?>">Reddedildi</button>
            <button type="button" class="button small secondary" data-dialog-open="renewal-decision-postponed-<?= h($row['id']) ?>">Ertelendi</button>
            <button type="button" class="button small secondary" data-dialog-open="renewal-decision-revision-<?= h($row['id']) ?>">Revize istendi</button>
            <?= render_renewal_communication_dialogs($row) ?>
            <?= render_supplier_price_request_dialog($row) ?>
            <?= render_renewal_decision_dialogs($row) ?>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <form method="post" action="<?= h(url('/renewals/' . $row['id'] . '/delete')) ?>" onsubmit="return confirm('Bu kayit silinsin mi?')">
                <?= csrf_field() ?>
                <button type="submit" class="button small danger">Sil</button>
            </form>
        <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function render_renewal_communication_dialogs(array $row): string
{
    $id = (int) $row['id'];
    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());
    $contacts = renewal_customer_contacts($row);
    $subject = 'Yenileme bilgilendirmesi: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? ''));
    $defaultMessage = renewal_default_direct_message($row);
    $summaryUrl = renewal_summary_url($id);

    ob_start();
    ?>
    <dialog class="app-dialog communication-dialog" id="renewal-mail-<?= h($id) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <h2>Mail olarak gönder</h2>
                    <span><?= h($row['company_name'] ?? '-') ?> için PDF/özet bağlantılı mail hazırlayın.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>
            <form method="post" action="<?= h(url('/renewals/' . $id . '/mail')) ?>" class="form-grid">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                <div class="recipient-picker">
                    <strong>Alıcılar</strong>
                    <?php $hasMailRecipient = false; ?>
                    <?php foreach ($contacts as $contact): ?>
                        <?php
                        $email = trim((string) ($contact['email'] ?? ''));
                        if ($email === '') {
                            continue;
                        }
                        $hasMailRecipient = true;
                        ?>
                        <label class="recipient-card">
                            <input type="checkbox" name="mail_recipients[]" value="<?= h($email) ?>" <?= !empty($contact['notify_enabled']) ? 'checked' : '' ?>>
                            <span>
                                <b><?= h((string) (($contact['full_name'] ?? '') ?: $email)) ?></b>
                                <em><?= h($email) ?></em>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$hasMailRecipient): ?>
                        <p class="muted compact">Bu cari için kayıtlı e-posta yetkilisi yok. Aşağıya manuel alıcı yazabilirsiniz.</p>
                    <?php endif; ?>
                </div>
                <label>
                    Manuel alıcı e-postası
                    <input type="email" name="custom_email" placeholder="ornek@firma.com">
                </label>
                <label>
                    Konu
                    <input name="subject" value="<?= h($subject) ?>" required maxlength="240">
                </label>
                <label>
                    Mail metni
                    <textarea name="message" rows="7" required><?= h($defaultMessage) ?></textarea>
                </label>
                <a class="button secondary full" target="_blank" rel="noopener" href="<?= h($summaryUrl) ?>">PDF / özet sayfasını aç</a>
                <button type="submit" class="button primary full">Maili gönder</button>
            </form>
        </div>
    </dialog>

    <dialog class="app-dialog communication-dialog" id="renewal-whatsapp-<?= h($id) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <h2>WhatsApp PDF gönder</h2>
                    <span>Yetkili seçin; WhatsApp PDF/özet bağlantılı hazır mesajla açılacak.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>
            <div class="whatsapp-recipient-list">
                <?php $hasWhatsappRecipient = false; ?>
                <?php foreach ($contacts as $contact): ?>
                    <?php
                    $phone = trim((string) ($contact['phone'] ?? ''));
                    $waNumber = whatsapp_number_from_phone($phone);
                    if ($waNumber === null) {
                        continue;
                    }
                    $hasWhatsappRecipient = true;
                    $message = renewal_whatsapp_message($row, $contact);
                    ?>
                    <div class="whatsapp-contact-card">
                        <div>
                            <strong><?= h((string) (($contact['full_name'] ?? '') ?: $phone)) ?></strong>
                            <span><?= h($phone) ?><?= !empty($contact['email']) ? ' - ' . h((string) $contact['email']) : '' ?></span>
                        </div>
                        <a class="button small whatsapp" target="_blank" rel="noopener" href="https://wa.me/<?= h($waNumber) ?>?text=<?= h(rawurlencode($message)) ?>">WhatsApp aç</a>
                    </div>
                <?php endforeach; ?>
                <?php if (!$hasWhatsappRecipient): ?>
                    <div class="empty">Bu cari için WhatsApp'a uygun telefon numarası bulunamadı.</div>
                <?php endif; ?>
            </div>
        </div>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function render_supplier_price_request_dialog(array $row): string
{
    $id = (int) $row['id'];
    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());
    $repo = new RenewalRepository();
    $contacts = renewal_supplier_contacts($repo, $row);
    $subject = 'Lisans fiyat talebi: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Yenileme'));
    $defaultMessage = supplier_price_default_message($row);
    $generatedLinks = supplier_quote_generated_links($id);

    ob_start();
    ?>
    <dialog class="app-dialog communication-dialog supplier-price-dialog" id="supplier-price-<?= h($id) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <h2>Tedarikçiden fiyat al</h2>
                    <span><?= h((string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-'))) ?> için tedarikçiye mail veya WhatsApp ile güncel fiyat talebi gönderin.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <div class="supplier-price-layout">
                <form method="post" action="<?= h(url('/renewals/' . $id . '/supplier-price-mail')) ?>" class="form-grid supplier-price-mail-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                    <div class="recipient-picker">
                        <strong>Mail alıcıları</strong>
                        <?php $hasMailRecipient = false; ?>
                        <?php foreach ($contacts as $contact): ?>
                            <?php
                            $email = trim((string) ($contact['email'] ?? ''));
                            if ($email === '') {
                                continue;
                            }
                            $hasMailRecipient = true;
                            ?>
                            <label class="recipient-card">
                                <input type="checkbox" name="supplier_mail_recipients[]" value="<?= h($email) ?>" checked>
                                <span>
                                    <b><?= h((string) (($contact['name'] ?? '') ?: $email)) ?></b>
                                    <em><?= h((string) (($contact['supplier_name'] ?? '') ?: 'Tedarikçi')) ?> - <?= h($email) ?></em>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$hasMailRecipient): ?>
                            <p class="muted compact">Bilgilendirme kutusu açık ve e-posta adresi dolu tedarikçi yetkilisi bulunamadı. Manuel alıcı yazabilirsiniz.</p>
                        <?php endif; ?>
                    </div>
                    <label>
                        Manuel tedarikçi e-postası
                        <input type="email" name="custom_supplier_email" placeholder="tedarikci@firma.com">
                    </label>
                    <label>
                        Konu
                        <input name="subject" value="<?= h($subject) ?>" required maxlength="240">
                    </label>
                    <label>
                        Talep metni
                        <textarea name="message" rows="8" required><?= h($defaultMessage) ?></textarea>
                    </label>
                    <button type="submit" class="button primary full">Fiyat talebi maili gönder</button>
                </form>

                <div class="supplier-price-side">
                    <form method="post" action="<?= h(url('/renewals/' . $id . '/supplier-price-link')) ?>" class="supplier-direct-link-panel" data-supplier-link-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                        <input type="hidden" name="subject" value="<?= h($subject) ?>">
                        <input type="hidden" name="message" value="<?= h($defaultMessage) ?>">
                        <div class="section-head compact">
                            <div>
                                <h3>Manuel teklif linki</h3>
                                <span class="muted compact">Linki oluşturup isterseniz aynı anda tedarikçiye mail olarak gönderin.</span>
                            </div>
                        </div>
                        <div class="recipient-picker compact">
                            <?php $hasLinkRecipient = false; ?>
                            <?php foreach ($contacts as $index => $contact): ?>
                                <?php
                                $identity = trim((string) (($contact['email'] ?? '') ?: ($contact['phone'] ?? '')));
                                if ($identity === '') {
                                    continue;
                                }
                                $hasLinkRecipient = true;
                                ?>
                                <label class="recipient-card compact">
                                    <input type="checkbox" name="supplier_quote_contacts[]" value="<?= h((string) $index) ?>" checked>
                                    <span>
                                        <b><?= h((string) (($contact['name'] ?? '') ?: $identity)) ?></b>
                                        <em><?= h((string) (($contact['supplier_name'] ?? '') ?: 'Tedarikçi')) ?> - <?= h($identity) ?></em>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            <?php if (!$hasLinkRecipient): ?>
                                <p class="muted compact">Tedarikçi yetkilisi bulunamadı. Manuel e-posta ile link oluşturabilirsiniz.</p>
                            <?php endif; ?>
                        </div>
                        <label>
                            Manuel e-posta alıcısı
                            <input type="email" name="custom_supplier_email" placeholder="tedarikci@firma.com">
                        </label>
                        <label class="checkbox-line supplier-send-mail-option">
                            <input type="checkbox" name="send_quote_email" value="1" checked>
                            <span>Oluşturulan linki e-posta olarak da gönder</span>
                        </label>
                        <button type="submit" class="button primary full">Link oluştur ve mail gönder</button>
                        <p class="muted compact" data-supplier-link-status hidden></p>
                    </form>

                    <div class="supplier-link-results" data-supplier-link-results <?= $generatedLinks === [] ? 'hidden' : '' ?>>
                        <div class="section-head compact">
                            <div>
                                <h3>Oluşturulan linkler</h3>
                                <span class="muted compact">Tedarikçiye gönderebilir veya formu hemen açabilirsiniz.</span>
                            </div>
                        </div>
                        <div class="supplier-link-list" data-supplier-link-list>
                            <?php foreach ($generatedLinks as $link): ?>
                                <?php $linkUrl = (string) ($link['url'] ?? ''); ?>
                                <?php if ($linkUrl === '') { continue; } ?>
                                <div class="supplier-link-card">
                                    <div>
                                        <strong><?= h((string) ($link['label'] ?? 'Tedarikçi')) ?></strong>
                                        <span><?= h((string) ($link['created_at'] ?? '')) ?></span>
                                    </div>
                                    <input readonly value="<?= h($linkUrl) ?>" aria-label="Tedarikçi teklif linki">
                                    <div class="inline-actions">
                                        <button type="button" class="button small secondary" data-copy-value="<?= h($linkUrl) ?>">Linki kopyala</button>
                                        <a class="button small primary" href="<?= h($linkUrl) ?>" target="_blank" rel="noopener noreferrer">Formu aç</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="supplier-whatsapp-panel">
                        <div class="section-head compact">
                            <div>
                                <h3>WhatsApp ile gönder</h3>
                                <span class="muted compact">Yetkili seçildiğinde hazır fiyat talebi mesajı WhatsApp'ta açılır.</span>
                            </div>
                        </div>
                        <div class="whatsapp-recipient-list">
                            <?php $hasWhatsappRecipient = false; ?>
                            <?php foreach ($contacts as $contact): ?>
                                <?php
                                $phone = trim((string) ($contact['phone'] ?? ''));
                                $waNumber = whatsapp_number_from_phone($phone);
                                if ($waNumber === null) {
                                    continue;
                                }
                                $hasWhatsappRecipient = true;
                                $whatsappLink = url('/renewals/' . $id . '/supplier-price-whatsapp?contact_id=' . (int) ($contact['contact_id'] ?? 0) . '&return_to=' . rawurlencode($returnTo));
                                ?>
                                <div class="whatsapp-contact-card">
                                    <div>
                                        <strong><?= h((string) (($contact['name'] ?? '') ?: $phone)) ?></strong>
                                        <span><?= h((string) (($contact['supplier_name'] ?? '') ?: 'Tedarikçi')) ?> - <?= h($phone) ?></span>
                                    </div>
                                    <a class="button small whatsapp" target="_blank" rel="noopener" href="<?= h($whatsappLink) ?>">WhatsApp aç</a>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$hasWhatsappRecipient): ?>
                                <div class="empty">Bu tedarikçi için WhatsApp'a uygun telefon numarası bulunamadı.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function supplier_quote_generated_links(int $renewalId): array
{
    $links = $_SESSION['_supplier_quote_links'][$renewalId] ?? [];
    if (!is_array($links)) {
        return [];
    }

    return array_values(array_filter($links, static fn ($link): bool => is_array($link) && !empty($link['url'])));
}

function render_customer_offer_dialog(array $row, array $selectedQuotes): string
{
    $id = (int) ($row['id'] ?? 0);
    if ($id < 1 || $selectedQuotes === []) {
        return '';
    }

    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());
    $contacts = renewal_customer_contacts($row);
    $currency = normalize_allowed_currency($row['currency'] ?? 'TRY');
    $subject = 'Yenileme teklifiniz: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Ürün / hizmet'));
    $message = 'Seçilen tedarikçi teklifleri üzerinden yenileme teklifinizi hazırladık. Lütfen fiyatları inceleyip onay, revize veya red tercihinizi iletin.';

    ob_start();
    ?>
    <dialog class="app-dialog communication-dialog customer-offer-dialog" id="customer-offer-<?= h((string) $id) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <h2>Müşteriye teklif gönder</h2>
                    <span>Seçili tedarikçi fiyatlarından müşteriye onay/revize/red bağlantılı teklif hazırlayın.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <form method="post" action="<?= h(url('/renewals/' . $id . '/customer-offer/send')) ?>" class="form-grid customer-offer-form" data-customer-offer-form>
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                <div class="recipient-picker span-2">
                    <strong>Müşteri alıcıları</strong>
                    <?php $hasRecipient = false; ?>
                    <?php foreach ($contacts as $contact): ?>
                        <?php
                        $email = trim((string) ($contact['email'] ?? ''));
                        if ($email === '') {
                            continue;
                        }
                        $hasRecipient = true;
                        ?>
                        <label class="recipient-card">
                            <input type="checkbox" name="customer_offer_recipients[]" value="<?= h($email) ?>" <?= !empty($contact['notify_enabled']) ? 'checked' : '' ?>>
                            <span>
                                <b><?= h((string) (($contact['full_name'] ?? '') ?: $email)) ?></b>
                                <em><?= h($email) ?></em>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$hasRecipient): ?>
                        <p class="muted compact">Bu cari için kayıtlı e-posta yetkilisi yok. Manuel alıcı yazabilirsiniz.</p>
                    <?php endif; ?>
                </div>
                <label>
                    Manuel müşteri e-postası
                    <input type="email" name="custom_customer_offer_email" placeholder="musteri@firma.com">
                </label>
                <label>
                    Para birimi
                    <select name="currency" data-customer-offer-currency>
                        <?php foreach (allowed_currency_options() as $currencyOption): ?>
                            <?= option($currencyOption, $currencyOption, $currency) ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="span-2">
                    Konu
                    <input name="subject" value="<?= h($subject) ?>" maxlength="240" required>
                </label>
                <label class="span-2">
                    Teklif mesajı
                    <textarea name="message" rows="4" required><?= h($message) ?></textarea>
                </label>

                <div class="customer-offer-lines-editor span-2">
                    <div class="section-head compact">
                        <div>
                            <h3>Teklif satırları</h3>
                            <span>Birim fiyat, toplam ve KDV'li fiyat müşteriye bu şekilde gösterilir.</span>
                        </div>
                    </div>
                    <?php foreach ($selectedQuotes as $index => $selection): ?>
                        <?= render_customer_offer_line_editor((int) $index, $selection, $currency) ?>
                    <?php endforeach; ?>
                </div>

                <div class="customer-offer-total-preview span-2">
                    <span>Ara toplam: <b data-customer-offer-subtotal>-</b></span>
                    <span>KDV: <b data-customer-offer-vat>-</b></span>
                    <span>KDV'li toplam: <b data-customer-offer-total>-</b></span>
                </div>

                <button type="submit" class="button primary span-2">Müşteriye teklif gönder</button>
            </form>
        </div>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function render_customer_offer_history(array $offers): string
{
    if ($offers === []) {
        return '';
    }

    ob_start();
    ?>
    <div class="customer-offer-history">
        <div class="section-head compact">
            <div>
                <h3>Müşteri teklif geçmişi</h3>
                <span>Gönderilen müşteri teklifleri, yanıtları ve satır fiyatları.</span>
            </div>
        </div>
        <div class="customer-offer-history-list">
            <?php foreach ($offers as $offer): ?>
                <?php
                $currency = normalize_allowed_currency($offer['currency'] ?? 'TRY');
                $status = (string) ($offer['status'] ?? 'sent');
                $createdAt = !empty($offer['created_at']) ? date('d.m.Y H:i', strtotime((string) $offer['created_at'])) : '-';
                ?>
                <details class="customer-offer-history-card">
                    <summary>
                        <span>
                            <strong><?= h((string) (($offer['recipient_name'] ?? '') ?: ($offer['recipient_email'] ?? 'Müşteri'))) ?></strong>
                            <em><?= h((string) ($offer['recipient_email'] ?? '-')) ?> · <?= h($createdAt) ?></em>
                        </span>
                        <span class="badge <?= h(customer_offer_status_badge($status)) ?>"><?= h(customer_offer_status_label($status)) ?></span>
                        <b><?= h(money_format_local($offer['total'] ?? null, $currency)) ?></b>
                    </summary>
                    <div class="customer-offer-history-lines">
                        <?php foreach (($offer['lines'] ?? []) as $line): ?>
                            <?php
                            $quantity = (float) ($line['quantity'] ?? 1);
                            $unitPrice = (float) ($line['unit_price'] ?? 0);
                            $subtotal = (float) ($line['line_subtotal'] ?? ($quantity * $unitPrice));
                            $total = (float) ($line['line_total'] ?? $subtotal);
                            ?>
                            <div>
                                <strong><?= h((string) ($line['item_title'] ?? '-')) ?></strong>
                                <span><?= h(number_format($quantity, 2, ',', '.')) ?> adet</span>
                                <span>Birim: <?= h(money_format_local($unitPrice, $currency)) ?></span>
                                <span>Toplam: <?= h(money_format_local($subtotal, $currency)) ?></span>
                                <span>KDV dahil: <?= h(money_format_local($total, $currency)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($offer['response_note'])): ?>
                        <div class="settings-note compact"><?= nl2br(h((string) $offer['response_note']), false) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($offer['responded_at'])): ?>
                        <p class="muted compact">Yanıt tarihi: <?= h(date('d.m.Y H:i', strtotime((string) $offer['responded_at']))) ?></p>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function customer_offer_status_label(string $status): string
{
    return match ($status) {
        'opened' => 'Açıldı',
        'approved' => 'Onaylandı',
        'revision_requested' => 'Revize istendi',
        'rejected' => 'Reddedildi',
        'expired' => 'Süresi doldu',
        default => 'Gönderildi',
    };
}

function customer_offer_status_badge(string $status): string
{
    return match ($status) {
        'approved' => 'active',
        'revision_requested', 'opened' => 'urgency-warning',
        'rejected', 'expired' => 'overdue',
        default => 'renewed',
    };
}

function render_customer_offer_line_editor(int $index, array $selection, string $currency): string
{
    $quantity = (float) ($selection['quantity'] ?? 1);
    $unitPrice = (float) ($selection['selected_price'] ?? 0);
    $vatRate = (float) ($selection['vat_rate'] ?? 20);
    $lineSubtotal = round($quantity * $unitPrice, 2);
    $lineVat = round($lineSubtotal * $vatRate / 100, 2);
    $lineTotal = round($lineSubtotal + $lineVat, 2);
    $termLabel = supplier_quote_term_label((string) ($selection['selected_term'] ?? ''), (string) ($selection['custom_term'] ?? ''));

    ob_start();
    ?>
    <div class="customer-offer-line-editor" data-customer-offer-line>
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][renewal_item_id]" value="<?= h($selection['renewal_item_id'] ?? '') ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_quote_line_id]" value="<?= h($selection['quote_line_id'] ?? '') ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_name]" value="<?= h($selection['supplier_display'] ?? '') ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_recipient_email]" value="<?= h($selection['recipient_email'] ?? '') ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_contact_name]" value="<?= h($selection['contact_name'] ?? '') ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_term]" value="<?= h($selection['selected_term'] ?? '') ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_custom_term]" value="<?= h($selection['custom_term'] ?? '') ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_price]" value="<?= h($selection['selected_price'] ?? '') ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_currency]" value="<?= h(normalize_allowed_currency($selection['selected_currency'] ?? $currency)) ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_vat_included]" value="<?= !empty($selection['vat_included']) ? '1' : '0' ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_delivery_note]" value="<?= h($selection['delivery_note'] ?? '') ?>">
        <input type="hidden" name="offer_lines[<?= h((string) $index) ?>][supplier_note]" value="<?= h($selection['note'] ?? '') ?>">
        <div class="customer-offer-line-title">
            <strong><?= h((string) (($selection['item_title'] ?? '') ?: 'Ürün / hizmet')) ?></strong>
            <span><?= h((string) (($selection['supplier_display'] ?? '') ?: 'Tedarikçi')) ?> / <?= h($termLabel) ?> / Alış: <?= h(money_format_local($selection['selected_price'] ?? null, (string) ($selection['selected_currency'] ?? $currency))) ?></span>
        </div>
        <div class="customer-offer-line-fields">
            <label>
                Ürün / hizmet
                <input name="offer_lines[<?= h((string) $index) ?>][item_title]" value="<?= h((string) (($selection['item_title'] ?? '') ?: 'Ürün / hizmet')) ?>" required>
            </label>
            <label>
                Adet
                <input type="number" min="0.01" step="0.01" name="offer_lines[<?= h((string) $index) ?>][quantity]" value="<?= h(number_format($quantity, 2, '.', '')) ?>" data-customer-offer-qty>
            </label>
            <label>
                Birim fiyat
                <input type="number" min="0" step="0.01" name="offer_lines[<?= h((string) $index) ?>][unit_price]" value="<?= h(number_format($unitPrice, 2, '.', '')) ?>" data-customer-offer-unit>
            </label>
            <label>
                KDV %
                <input type="number" min="0" max="100" step="0.01" name="offer_lines[<?= h((string) $index) ?>][vat_rate]" value="<?= h(number_format($vatRate, 2, '.', '')) ?>" data-customer-offer-vat-rate>
            </label>
        </div>
        <div class="customer-offer-price-preview">
            <span>Birim fiyat: <b data-customer-offer-unit-preview><?= h(money_format_local($unitPrice, $currency)) ?></b></span>
            <span>Toplam: <b data-customer-offer-subtotal-preview><?= h(money_format_local($lineSubtotal, $currency)) ?></b></span>
            <span>KDV'li fiyat: <b data-customer-offer-total-preview><?= h(money_format_local($lineTotal, $currency)) ?></b></span>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function render_supplier_quote_comparison(RenewalRepository $repo, array $row): string
{
    $renewalId = (int) ($row['id'] ?? 0);
    if ($renewalId < 1) {
        return '';
    }

    $quotes = $repo->supplierQuotesForRenewal($renewalId);
    $requests = $quotes['requests'] ?? [];
    $lines = $quotes['lines'] ?? [];
    $attachments = $quotes['attachments'] ?? [];
    $selections = $quotes['selections'] ?? [];
    $selectedQuotes = $repo->selectedSupplierQuotesForRenewal($renewalId);
    $customerOffers = $repo->customerOffersForRenewal($renewalId);

    if ($requests === [] && $lines === [] && $customerOffers === []) {
        return '';
    }

    $items = [];
    foreach ($repo->renewalItems($renewalId) as $item) {
        $items[(int) $item['id']] = $item;
    }

    $selectionByItem = [];
    foreach ($selections as $selection) {
        $selectionByItem[(int) $selection['renewal_item_id']] = $selection;
    }

    $linesByItem = [];
    foreach ($lines as $line) {
        $linesByItem[(int) ($line['renewal_item_id'] ?? 0)][] = $line;
    }

    $submitted = count(array_filter($requests, static fn (array $request): bool => ($request['status'] ?? '') === 'submitted'));
    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());

    ob_start();
    ?>
    <div class="supplier-quotes-panel">
        <div class="section-head compact">
            <div>
                <h3>Tedarikçi teklifleri</h3>
                <span><?= h((string) $submitted) ?> / <?= h((string) count($requests)) ?> tedarikçi teklif verdi. Her kalemde ayrı tedarikçi seçebilirsiniz.</span>
            </div>
            <?php if ($selectedQuotes !== []): ?>
                <button type="button" class="button small primary" data-dialog-open="customer-offer-<?= h((string) $renewalId) ?>">Müşteriye teklif gönder</button>
            <?php endif; ?>
        </div>
        <?php if ($selectedQuotes !== []): ?>
            <?= render_customer_offer_dialog($row, $selectedQuotes) ?>
        <?php endif; ?>
        <?= render_customer_offer_history($customerOffers) ?>

        <?php if ($requests !== [] || $lines !== []): ?>
            <?php foreach ($items as $itemId => $item): ?>
                <div class="supplier-quote-compare-item">
                    <div class="supplier-quote-compare-head">
                        <div>
                            <span>Kalem</span>
                            <strong><?= h((string) (($item['title'] ?? '') ?: 'Ürün / hizmet')) ?></strong>
                        </div>
                        <?php if (isset($selectionByItem[$itemId])): ?>
                            <?php $selected = $selectionByItem[$itemId]; ?>
                            <em>
                                Seçilen: <?= h((string) ($selected['supplier_display'] ?? '-')) ?>
                                / <?= h(supplier_quote_term_label((string) $selected['selected_term'])) ?>
                                / <?= h(money_format_local($selected['selected_price'], (string) $selected['currency'])) ?>
                            </em>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($linesByItem[$itemId])): ?>
                        <p class="muted compact">Bu kalem için henüz fiyat girilmedi.</p>
                    <?php else: ?>
                        <div class="supplier-quote-offer-grid">
                            <?php foreach ($linesByItem[$itemId] as $line): ?>
                                <?= render_supplier_quote_offer_card($line, $selectionByItem[$itemId] ?? null, $returnTo, $item) ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($attachments !== []): ?>
            <div class="supplier-quote-files">
                <strong>Dosya ile gelen teklifler</strong>
                <?php foreach ($attachments as $file): ?>
                    <span><?= h((string) ($file['supplier_display'] ?? 'Tedarikçi')) ?> - <?= h((string) $file['original_name']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function render_supplier_quote_offer_card(array $line, ?array $selected, string $returnTo, array $item = []): string
{
    $terms = [
        'cash' => ['label' => 'Peşin', 'field' => 'price_cash'],
        '30' => ['label' => '30 gün', 'field' => 'price_30'],
        '60' => ['label' => '60 gün', 'field' => 'price_60'],
        'check' => ['label' => 'Çek / vade', 'field' => 'price_check'],
        'custom' => ['label' => supplier_quote_term_label('custom', (string) ($line['custom_term'] ?? '')), 'field' => 'price_custom'],
    ];
    $lineId = (int) $line['id'];
    $selectedLineId = (int) ($selected['quote_line_id'] ?? 0);
    $selectedTerm = (string) ($selected['selected_term'] ?? '');
    $quantity = max(1.0, (float) ($item['quantity'] ?? 1));
    $vatRate = max(0.0, (float) ($item['vat_rate'] ?? 20));
    $currency = normalize_allowed_currency($line['currency'] ?? 'TRY');

    ob_start();
    ?>
    <div class="supplier-quote-offer-card">
        <div class="supplier-quote-offer-head">
            <div>
                <strong><?= h((string) (($line['supplier_display'] ?? '') ?: 'Tedarikçi')) ?></strong>
                <span><?= h((string) (($line['contact_name'] ?? '') ?: ($line['recipient_email'] ?? ''))) ?></span>
            </div>
            <em><?= !empty($line['submitted_at']) ? h(date('d.m.Y H:i', strtotime((string) $line['submitted_at']))) : h((string) ($line['request_status'] ?? 'bekliyor')) ?></em>
        </div>
        <div class="supplier-quote-term-grid">
            <?php foreach ($terms as $term => $meta): ?>
                <?php
                $price = $line[$meta['field']] ?? null;
                if ($price === null || $price === '') {
                    continue;
                }
                $isSelected = $selectedLineId === $lineId && $selectedTerm === $term;
                $unitPrice = (float) $price;
                $lineSubtotal = round($unitPrice * $quantity, 2);
                $lineVatIncludedTotal = !empty($line['vat_included'])
                    ? $lineSubtotal
                    : round($lineSubtotal + ($lineSubtotal * $vatRate / 100), 2);
                ?>
                <form method="post" action="<?= h(url('/supplier-quotes/' . $lineId . '/select')) ?>" class="supplier-quote-term <?= $isSelected ? 'selected' : '' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                    <input type="hidden" name="term" value="<?= h($term) ?>">
                    <span><?= h((string) $meta['label']) ?></span>
                    <strong><?= h(money_format_local($unitPrice, $currency)) ?></strong>
                    <small>Birim fiyat</small>
                    <small>Toplam: <?= h(money_format_local($lineSubtotal, $currency)) ?></small>
                    <small>KDV'li: <?= h(money_format_local($lineVatIncludedTotal, $currency)) ?></small>
                    <small><?= !empty($line['vat_included']) ? 'Tedarikçi KDV dahil girdi' : 'Tedarikçi KDV hariç girdi' ?></small>
                    <button type="submit" class="button small <?= $isSelected ? 'primary' : 'secondary' ?>" <?= $isSelected ? 'disabled' : '' ?>><?= $isSelected ? 'Seçildi' : 'Seç' ?></button>
                </form>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($line['delivery_note']) || !empty($line['note'])): ?>
            <div class="supplier-quote-notes">
                <?php if (!empty($line['delivery_note'])): ?><span>Nakliye/teslim: <?= h((string) $line['delivery_note']) ?></span><?php endif; ?>
                <?php if (!empty($line['note'])): ?><span>Not: <?= h((string) $line['note']) ?></span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function renewal_customer_contacts(array $row): array
{
    $contacts = [];
    try {
        $customer = (new RenewalRepository())->findCustomer((int) ($row['customer_id'] ?? 0));
        if ($customer && !empty($customer['contacts']) && is_array($customer['contacts'])) {
            $contacts = $customer['contacts'];
        }
    } catch (Throwable) {
        $contacts = [];
    }

    $fallback = [
        'full_name' => (string) (($row['contact_name'] ?? '') ?: ($row['company_name'] ?? 'Cari yetkilisi')),
        'email' => (string) ($row['customer_email'] ?? ''),
        'phone' => (string) ($row['customer_phone'] ?? ''),
        'notify_enabled' => 1,
    ];
    if (trim($fallback['email']) !== '' || trim($fallback['phone']) !== '') {
        $contacts[] = $fallback;
    }

    $unique = [];
    foreach ($contacts as $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
        $phone = normalize_phone_number($contact['phone'] ?? '');
        $name = trim((string) ($contact['full_name'] ?? $contact['contact_name'] ?? ''));
        if ($email === '' && $phone === '' && $name === '') {
            continue;
        }

        $key = $email !== '' ? 'email:' . $email : 'phone:' . preg_replace('/\D+/', '', $phone);
        if (isset($unique[$key])) {
            continue;
        }

        $unique[$key] = [
            'full_name' => $name !== '' ? $name : ($email !== '' ? $email : $phone),
            'email' => $email,
            'phone' => $phone,
            'notify_enabled' => !empty($contact['notify_enabled']) ? 1 : 0,
        ];
    }

    return array_values($unique);
}

function renewal_default_direct_message(array $row, string $summaryEmail = ''): string
{
    $days = days_until($row['renewal_date'] ?? null);
    $daysLabel = $days === null ? '-' : ($days < 0 ? abs($days) . ' gün geçti' : $days . ' gün kaldı');
    $title = (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'yenileme kaydı'));
    $date = !empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-';
    $total = money_format_local($row['item_total'] ?? $row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'));
    $summaryLink = !empty($row['id']) ? "\nPDF / özet bağlantısı: " . renewal_summary_url((int) $row['id'], 60, $summaryEmail) : '';

    return "Merhaba,\n\n{$title} için yenileme süreci yaklaşmaktadır.\nYenileme tarihi: {$date}\nKalan süre: {$daysLabel}\nToplam: {$total} KDV dahil{$summaryLink}\n\nBilginize sunarız.";
}

function whatsapp_number_from_phone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0090')) {
        $digits = substr($digits, 4);
    }

    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = '90' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '90' . $digits;
    }

    if (strlen($digits) === 12 && str_starts_with($digits, '90')) {
        return $digits;
    }

    return null;
}

function renewal_whatsapp_message(array $row, array $contact): string
{
    $email = (string) ($contact['email'] ?? '');
    $message = renewal_default_direct_message($row, $email);
    $paymentLink = PaymentLink::urlForRenewal((int) $row['id'], 60, $email);

    return $message . "\n\nÖdeme / tercih linki:\n" . $paymentLink;
}

function render_renewal_decision_dialogs(array $row): string
{
    $id = (int) $row['id'];
    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());
    $action = url('/renewals/' . $id . '/decision');
    $paymentLabel = renewal_payment_label($row);
    $paymentDefault = $paymentLabel !== '-' && $paymentLabel !== 'Müşteri seçimine bırakıldı' ? $paymentLabel : '';

    ob_start();
    ?>
    <dialog class="decision-dialog" id="renewal-decision-approved-<?= h($id) ?>">
        <form method="post" action="<?= h($action) ?>" class="decision-form">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
            <input type="hidden" name="decision" value="approved">
            <div class="section-head">
                <h2>Onaylandı</h2>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>
            <p class="muted compact"><?= h($row['company_name']) ?> için ödeme şartını kaydedin.</p>
            <label>
                Ödeme şartları
                <textarea name="payment_terms" rows="3" required placeholder="Örn: 30 gün cari hesap / Peşin / Havale EFT"><?= h($paymentDefault) ?></textarea>
            </label>
            <button type="submit" class="button primary full">Onayla</button>
        </form>
    </dialog>

    <dialog class="decision-dialog" id="renewal-decision-rejected-<?= h($id) ?>">
        <form method="post" action="<?= h($action) ?>" class="decision-form">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
            <input type="hidden" name="decision" value="rejected">
            <div class="section-head">
                <h2>Reddedildi</h2>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>
            <p class="muted compact">Sebep kaydedilecek ve kayıt iptal durumuna alınacak.</p>
            <label>
                Red sebebi
                <textarea name="reason" rows="4" required placeholder="Müşteri neden reddetti?"></textarea>
            </label>
            <button type="submit" class="button danger full">Reddedildi olarak kaydet</button>
        </form>
    </dialog>

    <dialog class="decision-dialog" id="renewal-decision-postponed-<?= h($id) ?>">
        <form method="post" action="<?= h($action) ?>" class="decision-form" data-postpone-form>
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
            <input type="hidden" name="decision" value="postponed">
            <input type="hidden" name="postpone_quick" value="" data-postpone-quick-value>
            <div class="section-head">
                <h2>Farklı tarihe ertelendi</h2>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>
            <div class="quick-date-grid">
                <button type="button" class="button small secondary" data-postpone-quick="1m">1 ay</button>
                <button type="button" class="button small secondary" data-postpone-quick="2m">2 ay</button>
                <button type="button" class="button small secondary" data-postpone-quick="3m">3 ay</button>
                <button type="button" class="button small secondary" data-postpone-quick="year_end">Yıl sonu</button>
            </div>
            <label>
                Yeni takip tarihi
                <input type="date" name="postponed_date" data-postpone-date required>
            </label>
            <label>
                Not
                <textarea name="note" rows="3" placeholder="Erteleme sebebi veya takip notu"></textarea>
            </label>
            <button type="submit" class="button primary full">Ertele</button>
        </form>
    </dialog>

    <dialog class="decision-dialog" id="renewal-decision-revision-<?= h($id) ?>">
        <form method="post" action="<?= h($action) ?>" class="decision-form">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
            <input type="hidden" name="decision" value="revision_requested">
            <div class="section-head">
                <h2>Revize istendi</h2>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>
            <p class="muted compact">Tedarikçi yetkililerine fiyat indirimi / revize teklif talebi gönderilecek.</p>
            <label>
                Tedarikçiye not
                <textarea name="note" rows="4" placeholder="Örn: Müşteri bütçeyi yüksek buldu, fiyat revizesi rica ederiz."></textarea>
            </label>
            <button type="submit" class="button primary full">Tedarikçiye gönder</button>
        </form>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function renewal_decision_label(string $decision): string
{
    return match ($decision) {
        'approved' => 'Onaylandı',
        'rejected' => 'Reddedildi',
        'postponed' => 'Farklı tarihe ertelendi',
        'revision_requested' => 'Revize istendi',
        default => $decision,
    };
}

function supplier_quote_term_label(string $term, string $customTerm = ''): string
{
    return match ($term) {
        'cash' => 'Peşin',
        '30' => '30 gün',
        '60' => '60 gün',
        'check' => 'Çek / vade',
        'custom' => $customTerm !== '' ? $customTerm : 'Özel vade',
        default => $term,
    };
}

function state_label(string $state): string
{
    return match ($state) {
        'overdue' => 'Geciken',
        'due_soon' => 'Yaklasan',
        'active' => 'Aktif',
        'renewed' => 'Yenilendi',
        'cancelled' => 'Iptal',
        default => $state,
    };
}

function renewal_urgency_class(array $row, ?int $days): string
{
    if (($row['status'] ?? '') !== 'active') {
        return (string) ($row['status'] ?? 'cancelled');
    }

    if ($days === null) {
        return 'active';
    }

    if ($days < 0) {
        return 'urgency-overdue';
    }

    if ($days === 0) {
        return 'urgency-today';
    }

    if ($days <= 7) {
        return 'urgency-critical';
    }

    if ($days <= 30) {
        return 'urgency-warning';
    }

    if ($days <= 60) {
        return 'urgency-watch';
    }

    return 'active';
}

function renewal_countdown_class(array $row, ?int $days): string
{
    if (($row['status'] ?? '') !== 'active' || $days === null || $days > 14) {
        return '';
    }

    return $days <= 0 ? 'countdown-blood' : 'countdown-heat';
}

function renewal_countdown_style(array $row, ?int $days): string
{
    if (($row['status'] ?? '') !== 'active' || $days === null || $days > 14) {
        return '';
    }

    if ($days <= 0) {
        $vars = [
            '--countdown-color' => '#8b0000',
            '--countdown-soft' => '#fde8e8',
            '--countdown-pulse' => 'rgba(139, 0, 0, 0.42)',
            '--countdown-glow' => 'rgba(139, 0, 0, 0.18)',
        ];
    } else {
        $start = [250, 204, 21]; // #facc15
        $end = [220, 38, 38]; // #dc2626
        $ratio = max(0, min(1, (14 - $days) / 13));
        $rgb = [];
        foreach ([0, 1, 2] as $index) {
            $rgb[$index] = (int) round($start[$index] + (($end[$index] - $start[$index]) * $ratio));
        }
        $color = sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
        $vars = [
            '--countdown-color' => $color,
            '--countdown-soft' => sprintf('rgba(%d, %d, %d, 0.14)', $rgb[0], $rgb[1], $rgb[2]),
            '--countdown-pulse' => sprintf('rgba(%d, %d, %d, 0.34)', $rgb[0], $rgb[1], $rgb[2]),
            '--countdown-glow' => sprintf('rgba(%d, %d, %d, 0.16)', $rgb[0], $rgb[1], $rgb[2]),
        ];
    }

    $style = '';
    foreach ($vars as $name => $value) {
        $style .= $name . ':' . $value . ';';
    }

    return ' style="' . h($style) . '"';
}

function renewal_urgency_label(array $row, ?int $days): string
{
    if (($row['status'] ?? '') !== 'active') {
        return state_label((string) ($row['status'] ?? 'cancelled'));
    }

    if ($days === null) {
        return 'Aktif';
    }

    if ($days < 0) {
        return 'Gecikti';
    }

    if ($days === 0) {
        return 'Bugun';
    }

    if ($days <= 7) {
        return 'Acil';
    }

    if ($days <= 30) {
        return 'Yaklasiyor';
    }

    if ($days <= 60) {
        return '60 gun icinde';
    }

    return 'Aktif';
}

function renewal_notification_read_summary(array $row): array
{
    $sent = max(0, (int) ($row['notification_sent_count'] ?? 0));
    $recipients = max(0, (int) ($row['notification_recipient_count'] ?? 0));
    $total = max($sent, $recipients);
    $read = min(max(0, (int) ($row['notification_read_count'] ?? 0)), $total > 0 ? $total : PHP_INT_MAX);

    if ($total < 1 || $sent < 1) {
        return [
            'label' => '-',
            'readers' => '',
            'complete' => false,
        ];
    }

    return [
        'label' => sprintf('%d/%d yetkili okudu', $read, $total),
        'readers' => (string) ($row['notification_readers'] ?? ''),
        'complete' => $read >= $total,
    ];
}

function render_notification_reader_chips(string $readers): string
{
    $items = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $readers) ?: [])));
    if ($items === []) {
        return '';
    }

    $html = '<div class="reader-chip-list">';
    foreach ($items as $item) {
        [$name, $date] = array_pad(explode(' - ', $item, 2), 2, '');
        $html .= '<div class="reader-chip">'
            . '<strong>' . h(trim($name) ?: $item) . '</strong>';
        if (trim($date) !== '') {
            $html .= '<small>' . h(trim($date)) . '</small>';
        }
        $html .= '</div>';
    }

    return $html . '</div>';
}

function renewal_can_acknowledge(array $row, ?int $days): bool
{
    if ($days === null) {
        return false;
    }

    if ($days <= 7) {
        return true;
    }

    $ruleDays = array_filter(array_map('intval', explode(',', (string) ($row['reminder_rule_days'] ?? ''))));
    foreach ($ruleDays as $ruleDay) {
        if ($ruleDay > 0 && $days <= $ruleDay) {
            return true;
        }
    }

    return $days <= (int) ($row['reminder_days'] ?? 0);
}

function renewal_payment_label(array $row): string
{
    if (!empty($row['payment_customer_choice'])) {
        return 'Müşteri seçimine bırakıldı';
    }

    $paymentMethod = trim((string) ($row['payment_method'] ?? ''));

    return $paymentMethod !== '' ? $paymentMethod : '-';
}

function iyzico_status_label(string $status): string
{
    return match ($status) {
        'paid' => 'Ödendi',
        'pending' => 'Bekliyor',
        'review' => 'Kontrol',
        'failed' => 'Başarısız',
        default => $status,
    };
}

function iyzico_status_badge_class(string $status): string
{
    return match ($status) {
        'paid' => 'active',
        'pending' => 'due_soon',
        'review' => 'urgency-warning',
        'failed' => 'overdue',
        default => 'cancelled',
    };
}

function payment_method_options(): array
{
    return array_map(
        static fn (array $method): string => (string) $method['name'],
        payment_method_definitions()
    );
}

function payment_method_definitions(): array
{
    try {
        return (new RenewalRepository())->paymentMethods();
    } catch (Throwable) {
        return RenewalRepository::defaultPaymentMethods();
    }
}

function nav_link(string $href, string $label): string
{
    $active = route_path() === $href || ($href !== '/' && str_starts_with(route_path(), $href));
    return sprintf(
        '<a href="%s" class="%s">%s</a>',
        h(url($href)),
        $active ? 'active' : '',
        h($label)
    );
}

function settings_nav_href(): ?string
{
    if (Auth::can('settings.manage')) {
        return '/settings';
    }

    if (Auth::can('definitions.manage')) {
        return '/settings/definitions';
    }

    if (Auth::can('users.manage')) {
        return '/settings/users';
    }

    return null;
}

function first_allowed_path(): ?string
{
    $paths = [
        'dashboard.view' => '/',
        'renewals.view' => '/renewals',
        'customers.view' => '/customers',
        'suppliers.view' => '/suppliers',
        'logs.view' => '/logs',
        'settings.manage' => '/settings',
        'definitions.manage' => '/settings/definitions',
        'users.manage' => '/settings/users',
    ];

    foreach ($paths as $permission => $path) {
        if (Auth::can($permission)) {
            return $path;
        }
    }

    return null;
}

function option(string $value, string $label, string $selected): string
{
    return sprintf(
        '<option value="%s" %s>%s</option>',
        h($value),
        $value === $selected ? 'selected' : '',
        h($label)
    );
}

function period_label(array $period): string
{
    $unit = match ((string) ($period['interval_unit'] ?? 'year')) {
        'day' => 'Gun',
        'month' => 'Ay',
        'year' => 'Yil',
        default => 'Periyot',
    };
    $detail = (int) ($period['interval_count'] ?? 1) . ' ' . $unit;
    $name = trim((string) ($period['name'] ?? ''));

    return $name === '' || $name === $detail ? $detail : $name . ' (' . $detail . ')';
}

function safe_return_path(mixed $value): string
{
    $raw = (string) $value;
    $parts = parse_url($raw);
    if (!is_array($parts)) {
        return '/';
    }

    $path = (string) ($parts['path'] ?? '/');
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return '/';
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return $path . $query;
}
