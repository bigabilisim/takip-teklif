<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Core\DatabaseBackup;
use App\Core\ExchangeRates;
use App\Core\IyzicoClient;
use App\Core\InternalNotifier;
use App\Core\Mailer;
use App\Core\MailTemplate;
use App\Core\ParasutClient;
use App\Core\PaymentLink;
use App\Core\TaxCertificateAnalyzer;
use App\Core\WebPush;
use App\Models\CustomerInfoRequestRepository;
use App\Models\NotesRepository;
use App\Models\PaymentRequestRepository;
use App\Models\RenewalRepository;
use App\Models\SettingsRepository;
use App\Models\UserRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        error_log($message . ' in ' . $file . ':' . $line);
        return true;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    http_response_code(500);
    render_error($e);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!is_array($error) || !in_array((int) ($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    $exception = new ErrorException(
        (string) ($error['message'] ?? 'Fatal error'),
        0,
        (int) ($error['type'] ?? 0),
        (string) ($error['file'] ?? ''),
        (int) ($error['line'] ?? 0)
    );
    report_application_error($exception, 'shutdown');
});

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

if (preg_match('#^/cari-bilgi/([a-f0-9]{64})/hatirlatma-kapat$#', $path, $matches)) {
    handle_customer_info_reminder_opt_out($matches[1]);
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

if (preg_match('#^/tedarikci-listeden-cik/([a-f0-9]{64})$#', $path, $matches)) {
    handle_supplier_unsubscribe_public($method, $matches[1]);
    exit;
}

if (preg_match('#^/tedarikci-secim-okudum/([a-f0-9]{64})$#', $path, $matches)) {
    handle_supplier_quote_selection_read($matches[1]);
    exit;
}

if (preg_match('#^/musteri-teklif/([a-f0-9]{64})$#', $path, $matches)) {
    handle_customer_offer_public($method, $matches[1]);
    exit;
}

if (preg_match('#^/teklif/(\d+)$#', $path, $matches)) {
    handle_sales_offer_public($method, (int) $matches[1]);
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

if (preg_match('#^/pay/([A-Za-z0-9_-]{20,120})$#', $path, $matches)) {
    handle_manual_payment_public($method, (string) $matches[1]);
    exit;
}

if (preg_match('#^/pay/([A-Za-z0-9_-]{20,120})/email$#', $path, $matches) && $method === 'POST') {
    handle_manual_payment_public_email((string) $matches[1]);
    exit;
}

if (preg_match('#^/pay/([A-Za-z0-9_-]{20,120})/tax$#', $path, $matches) && $method === 'POST') {
    handle_manual_payment_public_tax((string) $matches[1]);
    exit;
}

if (preg_match('#^/pay/([A-Za-z0-9_-]{20,120})/card$#', $path, $matches) && $method === 'POST') {
    handle_manual_payment_card_create((string) $matches[1]);
    exit;
}

if ($path === '/logout' && $method === 'POST') {
    verify_csrf();
    Auth::logout();
    flash('success', 'Oturum kapatıldı.');
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
    } elseif ($path === '/reports/sales') {
        require_permission('reports.view');
        render_sales_report($repo);
    } elseif ($path === '/notes') {
        require_permission($method === 'POST' ? 'notes.manage' : 'notes.view');
        handle_notes_page($method);
    } elseif (preg_match('#^/notes/(\d+)/update$#', $path, $matches) && $method === 'POST') {
        require_permission('notes.manage');
        handle_note_update((int) $matches[1]);
    } elseif (preg_match('#^/notes/(\d+)/status$#', $path, $matches) && $method === 'POST') {
        require_permission('notes.manage');
        handle_note_status((int) $matches[1]);
    } elseif (preg_match('#^/notes/(\d+)/delete$#', $path, $matches) && $method === 'POST') {
        require_permission('notes.delete');
        handle_note_delete((int) $matches[1]);
    } elseif ($path === '/flows') {
        require_permission('flows.view');
        redirect('/settings/flows');
    } elseif ($path === '/settings/flows') {
        require_permission('flows.view');
        render_flows($repo);
    } elseif ($path === '/logs') {
        require_permission('logs.view');
        $fileParam = trim((string) ($_GET['file'] ?? ''));
        redirect('/settings/mail-logs' . ($fileParam !== '' ? '?file=' . rawurlencode($fileParam) : ''));
    } elseif ($path === '/settings/mail-logs') {
        require_permission('logs.view');
        render_logs();
    } elseif ($path === '/settings/security-logs') {
        require_permission('logs.view');
        render_security_logs();
    } elseif ($path === '/api/parasut/contacts' && $method === 'GET') {
        handle_parasut_contacts_api();
    } elseif ($path === '/api/stock-items' && $method === 'GET') {
        require_json_permission('renewals.manage');
        handle_stock_items_api($repo);
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
    } elseif ($path === '/offers/create') {
        require_permission('renewals.manage');
        handle_sales_offer_create($repo, $method);
    } elseif (preg_match('#^/offers/(\d+)/preview$#', $path, $matches) && $method === 'GET') {
        require_permission('renewals.view');
        handle_sales_offer_preview($repo, (int) $matches[1], false);
    } elseif (preg_match('#^/offers/(\d+)/pdf$#', $path, $matches) && $method === 'GET') {
        require_permission('renewals.view');
        handle_sales_offer_preview($repo, (int) $matches[1], true);
    } elseif (preg_match('#^/offers/(\d+)/whatsapp$#', $path, $matches) && $method === 'GET') {
        require_permission('renewals.manage');
        handle_sales_offer_whatsapp($repo, (int) $matches[1]);
    } elseif (preg_match('#^/offers/(\d+)/send-email$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_sales_offer_mail($repo, (int) $matches[1], 'view');
    } elseif (preg_match('#^/offers/(\d+)/send-pdf$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_sales_offer_mail($repo, (int) $matches[1], 'pdf');
    } elseif (preg_match('#^/offers/(\d+)/collect-balance$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_sales_offer_collect_balance($repo, (int) $matches[1]);
    } elseif (preg_match('#^/offers/(\d+)/match-payment$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_sales_offer_payment_match($repo, (int) $matches[1]);
    } elseif (preg_match('#^/offers/(\d+)/parasut-invoice$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_sales_offer_parasut_invoice($repo, (int) $matches[1]);
    } elseif (preg_match('#^/offers/(\d+)/operation$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_sales_offer_operation($repo, (int) $matches[1]);
    } elseif (preg_match('#^/offers/(\d+)/edit$#', $path, $matches)) {
        require_permission('renewals.manage');
        handle_sales_offer_create($repo, $method, (int) $matches[1]);
    } elseif (preg_match('#^/offers/(\d+)/delete$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.delete');
        verify_csrf();
        $repo->deleteSalesOffer((int) $matches[1]);
        flash('success', 'Teklif kaydı silindi.');
        redirect('/');
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
        flash('success', 'Yenileme kaydı silindi.');
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
        flash('success', 'Kayıt yeni yenileme tarihiyle güncellendi.');
        redirect('/renewals');
    } elseif (preg_match('#^/renewals/(\d+)/payments/iyzico/create$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_iyzico_payment_create($repo, (int) $matches[1]);
    } elseif ($path === '/collections') {
        require_permission('collections.view');
        render_collections($repo);
    } elseif (preg_match('#^/collections/(\d+)/mail$#', $path, $matches) && $method === 'POST') {
        require_permission('collections.manage');
        handle_collection_mail($repo, (int) $matches[1]);
    } elseif ($path === '/payment-requests') {
        require_permission($method === 'POST' ? 'collections.manage' : 'collections.view');
        handle_payment_requests_page($method);
    } elseif (preg_match('#^/payment-requests/(\d+)/update$#', $path, $matches) && $method === 'POST') {
        require_permission('collections.manage');
        handle_payment_request_update((int) $matches[1]);
    } elseif (preg_match('#^/payment-requests/(\d+)/delete$#', $path, $matches) && $method === 'POST') {
        require_permission('collections.manage');
        handle_payment_request_delete((int) $matches[1]);
    } elseif (preg_match('#^/payment-requests/(\d+)/refund$#', $path, $matches) && $method === 'POST') {
        require_permission('collections.manage');
        handle_payment_request_refund((int) $matches[1]);
    } elseif (preg_match('#^/payment-requests/(\d+)/mail$#', $path, $matches) && $method === 'POST') {
        require_permission('collections.manage');
        handle_payment_request_mail((int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/notify$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_manual_renewal_notification($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/mail$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_manual_renewal_mail($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/whatsapp$#', $path, $matches) && $method === 'GET') {
        require_permission('renewals.manage');
        handle_renewal_customer_whatsapp($repo, (int) $matches[1]);
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
    } elseif (preg_match('#^/supplier-quotes/(\d+)/delete$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_supplier_quote_delete($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/manual-price$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_manual_customer_price($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/customer-offer/send$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_customer_offer_send($repo, (int) $matches[1]);
    } elseif (preg_match('#^/customer-offers/(\d+)/parasut-invoice$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_customer_offer_parasut_invoice($repo, (int) $matches[1]);
    } elseif (preg_match('#^/customer-offers/(\d+)/delete$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_customer_offer_delete($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/decision$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.manage');
        handle_renewal_decision($repo, (int) $matches[1]);
    } elseif (preg_match('#^/renewals/(\d+)/ack$#', $path, $matches) && $method === 'POST') {
        require_permission('renewals.view');
        verify_csrf();
        $repo->acknowledgeReminder((int) $matches[1]);
        flash('success', 'Bugünkü bildirim okundu olarak işaretlendi.');
        redirect(safe_return_path($_POST['return_to'] ?? '/renewals'));
    } elseif ($path === '/customer-info/request' && $method === 'POST') {
        require_permission('customers.manage');
        handle_customer_info_request_submit();
    } elseif (preg_match('#^/customers/(\d+)/parasut-contact$#', $path, $matches) && $method === 'POST') {
        require_permission('customers.manage');
        handle_customer_parasut_contact_send($repo, (int) $matches[1]);
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
        flash('success', 'Müşteri silinenler havuzuna taşındı.');
        redirect('/customers');
    } elseif (preg_match('#^/customers/(\d+)/restore$#', $path, $matches) && $method === 'POST') {
        require_permission('customers.delete');
        verify_csrf();
        $repo->restoreCustomer((int) $matches[1]);
        flash('success', 'Müşteri havuzdan geri alındı.');
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
        flash('success', 'Tedarikçi silinenler havuzuna taşındı.');
        redirect('/suppliers');
    } elseif (preg_match('#^/suppliers/(\d+)/restore$#', $path, $matches) && $method === 'POST') {
        require_permission('suppliers.delete');
        verify_csrf();
        $repo->restoreSupplier((int) $matches[1]);
        flash('success', 'Tedarikçi havuzdan geri alındı.');
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
    } elseif ($path === '/settings/definitions') {
        require_permission('definitions.manage');
        handle_definitions($repo, $method);
    } elseif ($path === '/settings/offer-templates') {
        require_permission('settings.manage');
        handle_offer_templates($repo, $method);
    } elseif ($path === '/settings/stock-items') {
        require_permission('settings.manage');
        handle_stock_items($repo, $method);
    } elseif ($path === '/settings/grapesjs') {
        require_permission('settings.manage');
        handle_grapesjs_template($method);
    } elseif ($path === '/settings') {
        require_permission('settings.manage');
        handle_settings($repo, $method);
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
        render_layout('Sayfa bulunamadı', static function (): void {
            echo '<div class="empty">Aradığınız sayfa bulunamadı.</div>';
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
        echo '<div class="alert error">Bu işlem için yetkiniz yok.</div>';
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
    echo json_encode(['ok' => false, 'message' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
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

function handle_stock_items_api(RenewalRepository $repo): void
{
    $items = $repo->stockItems((string) ($_GET['q'] ?? ''), 25);
    json_response([
        'ok' => true,
        'data' => array_map(static fn (array $item): array => stock_item_payload($item), $items),
    ]);
}

function require_json_any_permission(array $permissions): void
{
    if (Auth::canAny($permissions)) {
        return;
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'message' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
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
        flash('error', 'Yenileme kaydı bulunamadı.');
        redirect('/renewals');
    }

    $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
    $currency = normalize_allowed_currency($_POST['currency'] ?? 'TRY');
    if ($amount <= 0) {
        flash('error', 'iyzico ödemesi için tutar girin.');
        redirect('/renewals/' . $renewalId . '/edit#card-payment');
    }

    $client = new IyzicoClient(new SettingsRepository());
    if (!$client->isEnabled() || !$client->isConfigured()) {
        flash('error', 'iyzico ayarları aktif ve eksiksiz olmalı.');
        redirect('/renewals/' . $renewalId . '/edit#card-payment');
    }

    $conversationId = 'renewal-' . $renewalId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

    try {
        $charge = iyzico_charge_payload($amount, $currency);
        $result = $client->initializeCheckout($renewal, $charge['amount'], $charge['currency'], $conversationId);
        $response = $result['response'];
        $paymentPageUrl = trim((string) ($response['paymentPageUrl'] ?? ''));
        $token = trim((string) ($response['token'] ?? ''));
        $ok = (string) ($response['status'] ?? '') === 'success' && $paymentPageUrl !== '' && $token !== '';

        $repo->createIyzicoPayment([
            'renewal_id' => $renewalId,
            'conversation_id' => $conversationId,
            'token' => $token,
            'amount' => $charge['amount'],
            'currency' => $charge['currency'],
            'status' => $ok ? 'pending' : 'failed',
            'payment_page_url' => $paymentPageUrl,
            'error_message' => $ok ? '' : ((string) ($response['errorMessage'] ?? 'iyzico ödeme linki oluşturulamadı.')),
            'raw_request' => iyzico_raw_request_with_original($result['request'], $charge),
            'raw_response' => $response,
            'created_by' => (int) ($_SESSION['user_id'] ?? 0),
        ]);

        if (!$ok) {
            flash('error', (string) ($response['errorMessage'] ?? 'iyzico ödeme linki oluşturulamadı.'));
            redirect('/renewals/' . $renewalId . '/edit#card-payment');
        }

        redirect($paymentPageUrl);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/renewals/' . $renewalId . '/edit#card-payment');
    }
}

function handle_collection_mail(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $result = send_collection_reminder_mail($repo, $renewalId);
        $sent = (int) ($result['sent'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);

        if ($sent > 0 && $failed < 1) {
            flash('success', 'Tahsilat maili gönderildi. Alıcı sayısı: ' . $sent);
        } elseif ($sent > 0) {
            flash('error', 'Tahsilat maili kısmen gönderildi. Başarılı: ' . $sent . ', başarısız: ' . $failed);
        } else {
            flash('error', (string) ($result['message'] ?? 'Tahsilat maili gönderilemedi.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/collections'));
}

function handle_payment_requests_page(string $method): void
{
    if ($method === 'POST') {
        handle_manual_payment_request_create();
        return;
    }

    render_payment_requests();
}

function handle_notes_page(string $method): void
{
    if ($method === 'POST') {
        handle_note_create();
        return;
    }

    render_notes();
}

function handle_note_create(): void
{
    verify_csrf();

    $repo = new NotesRepository();

    try {
        $data = note_payload_from_post();
        if ($data['title'] === '') {
            throw new RuntimeException('Not başlığı yazın.');
        }
        if ($data['note'] === '') {
            throw new RuntimeException('Görüşme notunu yazın.');
        }

        $note = $repo->create($data + ['user_id' => (int) ($_SESSION['user_id'] ?? 0)]);
        flash('success', 'Görüşme notu kaydedildi.');
        redirect('/notes?focus=' . (int) ($note['id'] ?? 0));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/notes');
    }
}

function handle_note_update(int $noteId): void
{
    verify_csrf();

    $repo = new NotesRepository();

    try {
        $data = note_payload_from_post();
        if ($data['title'] === '') {
            throw new RuntimeException('Not başlığı yazın.');
        }
        if ($data['note'] === '') {
            throw new RuntimeException('Görüşme notunu yazın.');
        }

        $repo->update($noteId, $data + ['user_id' => (int) ($_SESSION['user_id'] ?? 0)]);
        flash('success', 'Görüşme notu güncellendi.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/notes?focus=' . $noteId);
}

function handle_note_status(int $noteId): void
{
    verify_csrf();

    try {
        (new NotesRepository())->updateStatus(
            $noteId,
            (string) ($_POST['status'] ?? 'open'),
            (int) ($_SESSION['user_id'] ?? 0)
        );
        flash('success', 'Not durumu güncellendi.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/notes?focus=' . $noteId);
}

function handle_note_delete(int $noteId): void
{
    verify_csrf();

    try {
        (new NotesRepository())->delete($noteId, (int) ($_SESSION['user_id'] ?? 0));
        flash('success', 'Görüşme notu silindi.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/notes');
}

function note_payload_from_post(): array
{
    return [
        'customer_id' => (int) ($_POST['customer_id'] ?? 0),
        'contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
        'channel' => NotesRepository::normalizeChannel((string) ($_POST['channel'] ?? 'meeting')),
        'title' => trim((string) ($_POST['title'] ?? '')),
        'note' => trim((string) ($_POST['note'] ?? '')),
        'quoted_amount' => trim((string) ($_POST['quoted_amount'] ?? '')),
        'currency' => NotesRepository::normalizeCurrency((string) ($_POST['currency'] ?? 'TRY')),
        'status' => NotesRepository::normalizeStatus((string) ($_POST['status'] ?? 'open')),
        'follow_up_date' => trim((string) ($_POST['follow_up_date'] ?? '')),
        'follow_up_time' => trim((string) ($_POST['follow_up_time'] ?? '')),
    ];
}

function handle_manual_payment_request_create(): void
{
    verify_csrf();

    $repo = new PaymentRequestRepository();

    try {
        $title = trim((string) ($_POST['title'] ?? ''));
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $currency = PaymentRequestRepository::normalizeCurrency((string) ($_POST['currency'] ?? 'TRY'));

        if ($title === '') {
            throw new RuntimeException('Ödeme talebi başlığı yazın.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Tahsil edilecek tutar sıfırdan büyük olmalı.');
        }

        $emailRecipients = manual_payment_parse_email_list((string) ($_POST['customer_email'] ?? ''));
        $email = $emailRecipients[0]['email'] ?? '';

        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $recipients = manual_payment_selected_recipients($customerId, $_POST['selected_contact_ids'] ?? []);
        if ($recipients !== []) {
            $first = $recipients[0];
            if ($email === '' && !empty($first['email'])) {
                $email = (string) $first['email'];
            }
            if (trim((string) ($_POST['customer_phone'] ?? '')) === '' && !empty($first['phone'])) {
                $_POST['customer_phone'] = (string) $first['phone'];
            }
        }
        $recipients = manual_payment_merge_email_recipients($recipients, $emailRecipients);

        $request = $repo->create([
            'customer_id' => $customerId > 0 ? $customerId : null,
            'title' => $title,
            'description' => trim((string) ($_POST['description'] ?? '')),
            'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
            'customer_email' => $email,
            'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
            'customer_tax_number' => trim((string) ($_POST['customer_tax_number'] ?? '')),
            'recipients' => $recipients,
            'amount' => $amount,
            'currency' => $currency,
            'payment_due_date' => (string) ($_POST['payment_due_date'] ?? ''),
            'reminder_time' => (string) ($_POST['reminder_time'] ?? '09:00'),
            'reminder_start_days_before' => (int) ($_POST['reminder_start_days_before'] ?? 3),
            'reminder_repeat_daily' => !empty($_POST['reminder_repeat_daily']),
            'reminder_until_paid' => !empty($_POST['reminder_until_paid']),
            'created_by' => (int) ($_SESSION['user_id'] ?? 0),
        ]);

        notify_manual_payment_request_created($request);

        flash('success', 'Manuel ödeme talebi oluşturuldu.');
        redirect('/payment-requests?created=' . (int) ($request['id'] ?? 0));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/payment-requests');
    }
}

function handle_payment_request_update(int $requestId): void
{
    verify_csrf();

    $repo = new PaymentRequestRepository();

    try {
        $request = $repo->find($requestId);
        if (!$request) {
            throw new RuntimeException('Manuel ödeme talebi bulunamadı.');
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $emailRecipients = manual_payment_parse_email_list((string) ($_POST['customer_email'] ?? ''));
        $email = $emailRecipients[0]['email'] ?? '';
        if ($title === '') {
            throw new RuntimeException('Ödeme talebi başlığı yazın.');
        }
        if (!in_array((string) ($request['status'] ?? ''), ['paid', 'refunded'], true) && $amount <= 0) {
            throw new RuntimeException('Tahsil edilecek tutar sıfırdan büyük olmalı.');
        }

        $repo->update($requestId, [
            'title' => $title,
            'description' => trim((string) ($_POST['description'] ?? '')),
            'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
            'customer_email' => $email,
            'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
            'customer_tax_number' => trim((string) ($_POST['customer_tax_number'] ?? '')),
            'recipients' => manual_payment_merge_email_recipients([], $emailRecipients),
            'amount' => $amount,
            'currency' => PaymentRequestRepository::normalizeCurrency((string) ($_POST['currency'] ?? 'TRY')),
            'payment_due_date' => (string) ($_POST['payment_due_date'] ?? ''),
            'reminder_time' => (string) ($_POST['reminder_time'] ?? ''),
            'reminder_start_days_before' => (int) ($_POST['reminder_start_days_before'] ?? 3),
            'reminder_repeat_daily' => !empty($_POST['reminder_repeat_daily']),
            'reminder_until_paid' => !empty($_POST['reminder_until_paid']),
        ]);

        flash('success', 'Ödeme talebi güncellendi.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/payment-requests?created=' . $requestId);
}

function handle_payment_request_delete(int $requestId): void
{
    verify_csrf();

    try {
        $repo = new PaymentRequestRepository();
        $deleted = $repo->cancel($requestId);
        flash($deleted ? 'success' : 'error', $deleted ? 'Ödeme talebi silindi.' : 'Ödeme talebi bulunamadı.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/payment-requests');
}

function handle_payment_request_refund(int $requestId): void
{
    verify_csrf();

    try {
        $repo = new PaymentRequestRepository();
        $note = trim((string) ($_POST['refund_note'] ?? ''));
        $refunded = $repo->refund($requestId, $note);
        flash($refunded ? 'success' : 'error', $refunded ? 'Ödeme talebi iade edildi olarak işaretlendi.' : 'Ödeme talebi bulunamadı.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/payment-requests?created=' . $requestId);
}

function handle_payment_request_mail(int $requestId): void
{
    verify_csrf();

    $repo = new PaymentRequestRepository();

    try {
        $request = $repo->find($requestId);
        if (!$request) {
            throw new RuntimeException('Manuel ödeme talebi bulunamadı.');
        }

        $email = trim(mb_strtolower((string) ($_POST['recipient_email'] ?? ($request['customer_email'] ?? ''))));
        $message = trim((string) ($_POST['message'] ?? ''));
        $targets = $email === '__all__'
            ? manual_payment_request_email_recipients($request)
            : [$email];

        $targets = array_values(array_unique(array_filter($targets, static function (string $target): bool {
            return filter_var($target, FILTER_VALIDATE_EMAIL) !== false;
        })));

        if ($targets === []) {
            throw new RuntimeException('Mail göndermek için geçerli en az bir e-posta adresi yazın.');
        }

        $sent = 0;
        $failed = 0;
        $lastError = null;
        foreach ($targets as $target) {
            $result = send_payment_request_mail_message($repo, $request, $target, $message);
            if (!empty($result['ok'])) {
                $sent++;
            } else {
                $failed++;
                $lastError = (string) ($result['error'] ?? 'transport-failed');
            }
        }

        if ($sent > 0 && $failed < 1) {
            flash('success', 'Ödeme talebi mail olarak gönderildi. Alıcı sayısı: ' . $sent);
        } elseif ($sent > 0) {
            flash('error', 'Ödeme talebi kısmen gönderildi. Başarılı: ' . $sent . ', başarısız: ' . $failed);
        } else {
            flash('error', 'Ödeme talebi maili gönderilemedi: ' . ($lastError ?: 'Alıcı sunucusu kabul etmedi.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/payment-requests?created=' . $requestId);
}

function send_payment_request_mail_message(PaymentRequestRepository $repo, array $request, string $email = '', string $message = ''): array
{
    $requestId = (int) ($request['id'] ?? 0);
    $email = trim(mb_strtolower($email !== '' ? $email : (string) ($request['customer_email'] ?? '')));
    if ($requestId <= 0) {
        throw new RuntimeException('Ödeme talebi kaydı okunamadı.');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Mail göndermek için geçerli bir e-posta adresi yazın.');
    }

    $paymentUrl = manual_payment_request_url($request);
    $message = trim($message) !== '' ? trim($message) : payment_request_default_message($request);
    $subject = mb_substr('Ödeme talebi: ' . (string) ($request['title'] ?? 'Manuel ödeme'), 0, 240);
    $body = payment_request_mail_body($request, $message, $paymentUrl);
    $result = Mailer::sendWithResult($email, $subject, $body, true);
    $ok = !empty($result['ok']);
    $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');

    $repo->logDelivery($requestId, 'mail', $email, $subject, (string) ($result['body'] ?? $body), $ok ? 'sent' : 'failed', $error);

    return [
        'ok' => $ok,
        'error' => $error,
        'recipient' => $email,
        'subject' => $subject,
        'body' => (string) ($result['body'] ?? $body),
        'payment_url' => $paymentUrl,
    ];
}

function handle_manual_renewal_notification(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $result = send_manual_renewal_notification($repo, $renewalId);
        $sent = (int) $result['sent'];
        $failed = (int) $result['failed'];

        if ($sent > 0 && $failed < 1) {
            flash('success', 'Bilgilendirme maili gönderildi. Alıcı sayısı: ' . $sent);
        } elseif ($sent > 0) {
            flash('error', 'Bilgilendirme kısmen gönderildi. Başarılı: ' . $sent . ', başarısız: ' . $failed);
        } else {
            flash('error', (string) ($result['message'] ?? 'Bilgilendirme gönderilemedi.'));
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
            throw new RuntimeException('Yenileme kaydı bulunamadı.');
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($subject === '') {
            $subject = 'Yenileme bilgilendirmesi: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? ''));
        }
        $subject = mb_substr($subject, 0, 240);

        if ($message === '') {
            throw new RuntimeException('Mail metni boş olamaz.');
        }

        $recipients = renewal_mail_recipients_from_request($repo, $row);
        if ($recipients === []) {
            throw new RuntimeException('Mail gönderilecek en az bir alıcı seçin veya manuel e-posta yazın.');
        }

        $sent = 0;
        $failed = 0;
        $lastError = '';

        foreach ($recipients as $recipient) {
            $delivery = $repo->createNotificationDelivery((int) $row['id'], $recipient);
            $summaryUrl = renewal_tracked_summary_url((int) $row['id'], (string) $delivery['token'], 60, (string) ($recipient['email'] ?? ''));
            $personalMessage = renewal_personalized_summary_message($message, $summaryUrl);
            $body = manual_renewal_mail_body($row, $personalMessage, $summaryUrl);
            $result = Mailer::sendWithResult((string) $recipient['email'], $subject, $body, true);
            $ok = !empty($result['ok']);
            $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');
            $mailLogId = $repo->logMail(
                (int) $row['id'],
                (string) $recipient['email'],
                $subject,
                $body,
                $ok ? 'sent' : 'failed',
                $error,
                false
            );
            $repo->updateNotificationDeliveryStatus(
                (int) $delivery['id'],
                $mailLogId,
                $ok ? 'sent' : 'failed',
                $error
            );

            if ($ok) {
                $sent++;
            } else {
                $failed++;
                $lastError = $error ?? '';
            }
        }

        if ($sent > 0 && $failed < 1) {
            flash('success', 'Mail gönderildi. Alıcı sayısı: ' . $sent);
        } elseif ($sent > 0) {
            flash('error', 'Mail kısmen gönderildi. Başarılı: ' . $sent . ', başarısız: ' . $failed);
        } else {
            flash('error', 'Mail gönderilemedi: ' . ($lastError ?: 'Alıcı sunucusu kabul etmedi.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/renewals'));
}

function handle_renewal_customer_whatsapp(RenewalRepository $repo, int $renewalId): void
{
    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydı bulunamadı.');
        }

        $contactKey = trim((string) ($_GET['contact'] ?? ''));
        $contact = renewal_contact_by_tracking_key($row, $contactKey);
        if (!$contact) {
            throw new RuntimeException('WhatsApp gönderilecek yetkili bulunamadı.');
        }

        $waNumber = whatsapp_number_from_phone((string) ($contact['phone'] ?? ''));
        if ($waNumber === null) {
            throw new RuntimeException('Seçilen yetkilinin WhatsApp için geçerli telefon numarası yok.');
        }

        $recipient = renewal_tracking_recipient_from_contact($contact, 'WhatsApp alıcısı');
        $delivery = $repo->createNotificationDelivery($renewalId, $recipient);
        $repo->updateNotificationDeliveryStatus((int) $delivery['id'], null, 'sent');
        $summaryUrl = renewal_tracked_summary_url($renewalId, (string) $delivery['token'], 60, (string) ($contact['email'] ?? ''));
        $message = renewal_whatsapp_message($row, $contact, $summaryUrl);

        redirect(whatsapp_web_url($waNumber, $message));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect(safe_return_path($_GET['return_to'] ?? '/renewals'));
    }
}

function handle_supplier_price_request_mail(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydı bulunamadı.');
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
            throw new RuntimeException('Fiyat talebi gönderilecek en az bir tedarikçi yetkilisi seçin veya manuel e-posta yazın.');
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
            flash('success', 'Tedarikçi fiyat talebi maili gönderildi. Alıcı sayısı: ' . $sent);
        } elseif ($sent > 0) {
            flash('error', 'Tedarikçi fiyat talebi kısmen gönderildi. Başarılı: ' . $sent . ', başarısız: ' . $failed);
        } else {
            flash('error', 'Tedarikçi fiyat talebi gönderilemedi: ' . ($lastError ?: 'Alıcı sunucusu kabul etmedi.'));
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
            'Kalem için tedarikçi teklifi onaylandı: '
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

function handle_supplier_quote_delete(RenewalRepository $repo, int $requestId): void
{
    verify_csrf();

    try {
        $request = $repo->deleteSupplierQuoteRequest($requestId);
        if (!$request) {
            throw new RuntimeException('Tedarikçi teklif talebi bulunamadı.');
        }

        flash(
            'success',
            'Tedarikçi teklif talebi sessizce silindi: '
            . (string) (($request['supplier_display'] ?? '') ?: ($request['recipient_email'] ?? 'Tedarikçi'))
            . '. Tedarikçiye bilgi maili gönderilmedi.'
        );
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function handle_manual_customer_price(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydı bulunamadı.');
        }

        $currency = normalize_allowed_currency($_POST['manual_price_currency'] ?? ($row['currency'] ?? 'TRY'));
        $result = $repo->selectManualCustomerPrices(
            $renewalId,
            $_POST['manual_prices'] ?? [],
            (int) ($_SESSION['user_id'] ?? 0),
            $currency
        );

        flash(
            'success',
            (int) ($result['count'] ?? 0)
            . ' kalem için manuel fiyat kaydedildi. Müşteriye teklif gönder butonundan fiyatı iletebilirsiniz.'
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
        (trim((string) ($selected['quote_number'] ?? '')) !== '' ? '[' . trim((string) $selected['quote_number']) . '] ' : '')
        . 'Onaylandı: ' . supplier_customer_label($row) . ' - ' . $itemTitle,
        0,
        240
    );
    $delivery = $repo->createSupplierQuoteSelectionDelivery(array_merge($selected, ['item_title' => $itemTitle]));
    $body = supplier_quote_selection_body($row, $selected, $itemTitle, (string) ($delivery['read_url'] ?? ''));
    $result = Mailer::sendWithResult($email, $subject, $body, true);
    $ok = !empty($result['ok']);
    $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');

    $mailLogId = $repo->logMail(
        (int) $row['id'],
        $email,
        $subject,
        $body,
        $ok ? 'sent' : 'failed',
        $error,
        false
    );
    $repo->updateSupplierQuoteSelectionDeliveryStatus((int) ($delivery['id'] ?? 0), $mailLogId, $ok ? 'sent' : 'failed', $error);

    return ['ok' => $ok, 'status' => $ok ? 'sent' : 'failed', 'error' => $error, 'read_url' => (string) ($delivery['read_url'] ?? '')];
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
            $message = 'Seçilen fiyatlar üzerinden yenileme teklifinizi hazırladık. Lütfen fiyatları inceleyip onay, revize veya red tercihinizi iletin.';
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
            $offerNumber = trim((string) ($offer['offer_number'] ?? ''));
            $mailSubject = $offerNumber !== '' && !str_contains($subject, $offerNumber)
                ? '[' . $offerNumber . '] ' . $subject
                : $subject;
            $result = Mailer::sendWithResult((string) $recipient['email'], $mailSubject, $body, true);
            $ok = !empty($result['ok']);
            $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');
            $repo->logMail(
                (int) $row['id'],
                (string) $recipient['email'],
                $mailSubject,
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

function handle_customer_offer_delete(RenewalRepository $repo, int $offerId): void
{
    verify_csrf();

    try {
        $offer = $repo->deleteCustomerOffer($offerId);
        if (!$offer) {
            throw new RuntimeException('Müşteri teklif geçmişi bulunamadı.');
        }

        $recipient = trim((string) (($offer['recipient_name'] ?? '') ?: ($offer['recipient_email'] ?? 'Müşteri')));
        flash('success', 'Müşteri teklif geçmişi silindi: ' . $recipient . '. Müşteriye bilgi maili gönderilmedi.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function handle_customer_offer_parasut_invoice(RenewalRepository $repo, int $offerId): void
{
    verify_csrf();
    $result = create_parasut_invoice_for_customer_offer($repo, $offerId);

    if (!empty($result['ok'])) {
        $invoice = (array) ($result['invoice'] ?? []);
        $label = trim((string) ($invoice['invoice_no'] ?? '')) ?: trim((string) ($invoice['id'] ?? ''));
        flash('success', $label !== '' ? 'Paraşüt faturası oluşturuldu: ' . $label : 'Paraşüt faturası oluşturuldu.');
    } else {
        flash('error', 'Paraşüt faturası oluşturulamadı: ' . (string) ($result['error'] ?? 'Bilinmeyen hata'));
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function create_parasut_invoice_for_customer_offer(RenewalRepository $repo, int $offerId): array
{
    $offer = $repo->findCustomerOfferById($offerId);
    if (!$offer) {
        return ['ok' => false, 'error' => 'Müşteri teklifi bulunamadı.'];
    }

    if ((string) ($offer['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'error' => 'Sadece onaylanan teklifler Paraşüt faturası oluşturabilir.'];
    }

    if (trim((string) ($offer['parasut_invoice_id'] ?? '')) !== '') {
        $noteUpdate = sync_parasut_invoice_note_for_offer($repo, $offer);

        return [
            'ok' => true,
            'already_created' => true,
            'invoice' => [
                'id' => (string) ($offer['parasut_invoice_id'] ?? ''),
                'invoice_no' => (string) ($offer['parasut_invoice_no'] ?? ''),
            ],
            'note_update' => $noteUpdate,
        ];
    }

    try {
        $lines = $repo->customerOfferLines($offerId);
        $payment = $repo->latestPaidPayment((int) ($offer['renewal_id'] ?? 0));
        $client = new ParasutClient();
        $offer = ensure_customer_offer_parasut_contact($repo, $client, $offer);
        $invoice = $client->createSalesInvoiceFromOffer($offer, $lines, $payment);
        if (trim((string) ($invoice['id'] ?? '')) === '') {
            throw new RuntimeException('Paraşüt fatura ID dönmedi.');
        }

        $repo->markCustomerOfferParasutInvoice($offerId, $invoice);

        return ['ok' => true, 'invoice' => $invoice];
    } catch (Throwable $e) {
        $repo->markCustomerOfferParasutInvoiceError($offerId, $e->getMessage());

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function ensure_customer_offer_parasut_contact(RenewalRepository $repo, ParasutClient $client, array $offer): array
{
    if (trim((string) ($offer['parasut_contact_id'] ?? '')) !== '') {
        return $offer;
    }

    $companyName = trim((string) ($offer['company_name'] ?? ''));
    if ($companyName === '') {
        throw new RuntimeException('Müşteri Paraşüt carisi eşleşmemiş ve firma adı boş.');
    }

    $contacts = $client->searchContacts($companyName, 8, 'customer');
    $matched = parasut_contact_match_for_customer($contacts, $companyName, (string) ($offer['customer_tax_number'] ?? ''));
    if ($matched === null || trim((string) ($matched['id'] ?? '')) === '') {
        throw new RuntimeException('Müşterinin Paraşüt cari ID bilgisi yok. Önce müşteriyi Paraşüt carisiyle eşleştirin.');
    }

    $contactId = trim((string) $matched['id']);
    $repo->setCustomerParasutContactId((int) ($offer['customer_id'] ?? 0), $contactId);
    $offer['parasut_contact_id'] = $contactId;

    return $offer;
}

function parasut_contact_match_for_customer(array $contacts, string $companyName, string $taxNumber = ''): ?array
{
    if ($contacts === []) {
        return null;
    }

    $taxNumber = preg_replace('/\D+/', '', $taxNumber) ?? '';
    if ($taxNumber !== '') {
        foreach ($contacts as $contact) {
            $contactTax = preg_replace('/\D+/', '', (string) ($contact['tax_number'] ?? '')) ?? '';
            if ($contactTax !== '' && $contactTax === $taxNumber) {
                return $contact;
            }
        }
    }

    $needle = normalized_match_key($companyName);
    foreach ($contacts as $contact) {
        if (normalized_match_key((string) ($contact['name'] ?? '')) === $needle) {
            return $contact;
        }
    }

    return count($contacts) === 1 ? $contacts[0] : null;
}

function normalized_match_key(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, [
        'ı' => 'i',
        'ğ' => 'g',
        'ü' => 'u',
        'ş' => 's',
        'ö' => 'o',
        'ç' => 'c',
        'İ' => 'i',
        'Ğ' => 'g',
        'Ü' => 'u',
        'Ş' => 's',
        'Ö' => 'o',
        'Ç' => 'c',
    ]);

    return (string) preg_replace('/[^a-z0-9]+/u', '', $value);
}

function sync_parasut_invoice_note_for_offer(RenewalRepository $repo, array $offer): ?array
{
    $invoiceId = trim((string) ($offer['parasut_invoice_id'] ?? ''));
    $renewalId = (int) ($offer['renewal_id'] ?? 0);
    if ($invoiceId === '' || $renewalId < 1) {
        return null;
    }

    $payment = $repo->latestPaidPayment($renewalId);
    if (!$payment) {
        return null;
    }

    try {
        $client = new ParasutClient();
        $note = $client->salesInvoiceNote($offer, $payment);

        return $client->updateSalesInvoiceNote($invoiceId, $note);
    } catch (Throwable $e) {
        error_log('Paraşüt fatura notu güncellenemedi: ' . $e->getMessage());

        return [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function sync_parasut_invoice_note_for_latest_paid_renewal(RenewalRepository $repo, int $renewalId): ?array
{
    if ($renewalId < 1) {
        return null;
    }

    $offer = $repo->latestApprovedCustomerOfferWithParasutInvoice($renewalId);
    if (!$offer) {
        return null;
    }

    return sync_parasut_invoice_note_for_offer($repo, $offer);
}

function finalize_paid_renewal_after_card_payment(RenewalRepository $repo, array $payment): array
{
    $renewalId = (int) ($payment['renewal_id'] ?? 0);
    if ($renewalId < 1) {
        return ['ok' => false, 'error' => 'Yenileme kaydı bulunamadı.'];
    }

    $renewal = $repo->find($renewalId);
    if (!$renewal) {
        return ['ok' => false, 'error' => 'Yenileme kaydı bulunamadı.'];
    }

    $alreadyInvoicedOffer = $repo->latestApprovedCustomerOfferWithParasutInvoice($renewalId);
    if ($alreadyInvoicedOffer) {
        $invoiceNo = trim((string) (($alreadyInvoicedOffer['parasut_invoice_no'] ?? '') ?: ($alreadyInvoicedOffer['parasut_invoice_id'] ?? '')));
        if (empty($renewal['renewed_at']) && $invoiceNo !== '') {
            $repo->markRenewed($renewalId, $invoiceNo);
        }

        return ['ok' => true, 'already_created' => true, 'invoice_no' => $invoiceNo];
    }

    $offer = $repo->latestApprovedCustomerOfferWaitingParasut($renewalId);
    if (!$offer) {
        $offer = $repo->latestCustomerOfferForPayment(
            $renewalId,
            (string) ($renewal['payment_selected_email'] ?? '')
        );
        if ($offer) {
            $repo->markCustomerOfferApprovedByPayment(
                (int) $offer['id'],
                'Kredi kartı ödemesi tamamlandığı için sistem tarafından onaylandı. Ödeme no: ' . trim((string) ($payment['payment_id'] ?? '-'))
            );
            $repo->applyCustomerOfferToRenewal($renewalId, (int) $offer['id']);
        }
    }

    if (!$offer) {
        return ['ok' => false, 'skipped' => true, 'error' => 'Ödeme başarılı ancak müşteriye gönderilmiş teklif kaydı bulunamadı.'];
    }

    $invoiceResult = create_parasut_invoice_for_customer_offer($repo, (int) $offer['id']);
    if (empty($invoiceResult['ok'])) {
        return $invoiceResult + ['offer_id' => (int) $offer['id']];
    }

    $invoice = (array) ($invoiceResult['invoice'] ?? []);
    $invoiceNo = trim((string) (($invoice['invoice_no'] ?? '') ?: ($invoice['id'] ?? '')));
    if ($invoiceNo !== '') {
        $repo->markRenewed($renewalId, $invoiceNo);
    }

    return $invoiceResult + ['offer_id' => (int) $offer['id'], 'renewed' => $invoiceNo !== ''];
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
            json_response(['ok' => false, 'message' => 'Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.'], 419);
        }
        verify_csrf();
    }

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydı bulunamadı.');
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
            throw new RuntimeException('Teklif linki oluşturmak için en az bir tedarikçi yetkilisi seçin veya manuel e-posta yazın.');
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
                'number' => (string) ($quoteRequest['quote_number'] ?? ''),
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

    $body = supplier_price_request_body(
        $row,
        $message,
        (string) ($quoteRequest['url'] ?? ''),
        (string) ($quoteRequest['unsubscribe_url'] ?? ''),
        (string) ($quoteRequest['quote_number'] ?? '')
    );
    $quoteNumber = trim((string) ($quoteRequest['quote_number'] ?? ''));
    $mailSubject = $quoteNumber !== '' && !str_contains($subject, $quoteNumber)
        ? '[' . $quoteNumber . '] ' . $subject
        : $subject;
    $result = Mailer::sendWithResult($email, $mailSubject, $body, true);
    $ok = !empty($result['ok']);
    $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');

    $repo->logMail(
        (int) $row['id'],
        $email,
        $mailSubject,
        $body,
        $ok ? 'sent' : 'failed',
        $error,
        false
    );

    return ['ok' => $ok, 'error' => $error];
}

function close_supplier_quote_requests_if_ready(RenewalRepository $repo, int $renewalId): array
{
    $summary = $repo->closeOpenSupplierQuoteRequestsIfThresholdReached($renewalId, 3);
    $submittedCount = (int) ($summary['submitted_count'] ?? 0);
    $closed = is_array($summary['closed'] ?? null) ? $summary['closed'] : [];
    if ($closed === []) {
        return ['submitted_count' => $submittedCount, 'closed' => 0, 'mail_sent' => 0, 'mail_failed' => 0];
    }

    $mailSent = 0;
    $mailFailed = 0;
    foreach ($closed as $request) {
        $email = trim((string) ($request['recipient_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $quoteNumber = trim((string) ($request['quote_number'] ?? ''));
        $subject = ($quoteNumber !== '' ? '[' . $quoteNumber . '] ' : '') . 'Teklif talebi kapatıldı: ' . $submittedCount . ' teklif alındı';
        $body = supplier_quote_closed_body($request, $submittedCount);
        $result = Mailer::sendWithResult($email, $subject, $body, true);
        $ok = !empty($result['ok']);
        $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');

        $repo->logMail(
            (int) ($request['renewal_id'] ?? $renewalId),
            $email,
            $subject,
            $body,
            $ok ? 'sent' : 'failed',
            $error,
            false
        );

        $ok ? $mailSent++ : $mailFailed++;
    }

    return [
        'submitted_count' => $submittedCount,
        'closed' => count($closed),
        'mail_sent' => $mailSent,
        'mail_failed' => $mailFailed,
    ];
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

    return 'Tedarikçi teklif linki oluşturuldu fakat mail gönderilemedi: ' . ($lastError ?: 'Alıcı sunucusu kabul etmedi.');
}

function handle_supplier_price_request_whatsapp(RenewalRepository $repo, int $renewalId): void
{
    $wantsJson = wants_json_response();

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydı bulunamadı.');
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
            throw new RuntimeException('Tedarikçi yetkilisi bulunamadı.');
        }

        $waNumber = whatsapp_number_from_phone((string) ($contact['phone'] ?? ''));
        if ($waNumber === null) {
            throw new RuntimeException('Tedarikçi telefon numarası WhatsApp için uygun değil.');
        }

        $subject = 'Lisans fiyat talebi: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Yenileme'));
        $message = supplier_price_default_message($row);
        $quoteRequest = $repo->createSupplierQuoteRequest($renewalId, $contact, $subject, $message, 'whatsapp');
        $whatsappMessage = supplier_price_whatsapp_message($row, $contact, (string) $quoteRequest['url'], (string) ($quoteRequest['quote_number'] ?? ''));
        $whatsappUrl = whatsapp_web_url($waNumber, $whatsappMessage);

        if ($wantsJson) {
            json_response(['ok' => true, 'whatsapp_url' => $whatsappUrl]);
        }

        header('Location: ' . $whatsappUrl);
        exit;
    } catch (Throwable $e) {
        if ($wantsJson) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
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
            'supplier_group_id' => empty($contact['supplier_group_id']) ? null : (int) $contact['supplier_group_id'],
            'supplier_group_name' => (string) ($contact['supplier_group_name'] ?? ''),
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
            throw new RuntimeException('Manuel tedarikçi e-posta adresi geçersiz.');
        }

        $supplierGroupId = empty($row['supplier_group_id']) ? null : (int) $row['supplier_group_id'];
        if ($repo->isSupplierEmailUnsubscribed($customEmail, $supplierGroupId)) {
            throw new RuntimeException('Manuel tedarikçi e-posta adresi tedarik listesinden çıkmış.');
        }

        $recipients[$customEmail] = [
            'supplier_id' => empty($row['supplier_id']) ? 0 : (int) $row['supplier_id'],
            'supplier_group_id' => $supplierGroupId,
            'supplier_group_name' => (string) ($row['supplier_group_name'] ?? ''),
            'name' => 'Manuel tedarikçi alıcısı',
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
            'supplier_group_id' => empty($contact['supplier_group_id']) ? null : (int) $contact['supplier_group_id'],
            'supplier_group_name' => (string) ($contact['supplier_group_name'] ?? ''),
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
            throw new RuntimeException('Manuel tedarikçi e-posta adresi geçersiz.');
        }

        $supplierGroupId = empty($row['supplier_group_id']) ? null : (int) $row['supplier_group_id'];
        if ($repo->isSupplierEmailUnsubscribed($customEmail, $supplierGroupId)) {
            throw new RuntimeException('Manuel tedarikçi e-posta adresi tedarik listesinden çıkmış.');
        }

        $recipients['email:' . $customEmail] = [
            'supplier_id' => empty($row['supplier_id']) ? 0 : (int) $row['supplier_id'],
            'supplier_group_id' => $supplierGroupId,
            'supplier_group_name' => (string) ($row['supplier_group_name'] ?? ''),
            'name' => 'Manuel tedarikçi alıcısı',
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

    return implode(' - ', $parts) ?: 'Tedarikçi teklif linki';
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
            throw new RuntimeException('Manuel e-posta adresi geçersiz.');
        }

        $recipients[$customEmail] = [
            'name' => 'Manuel alıcı',
            'email' => $customEmail,
        ];
    }

    return array_values($recipients);
}

function renewal_personalized_summary_message(string $message, string $summaryUrl): string
{
    $message = trim($message);
    $replacement = 'PDF / özet bağlantısı: ' . $summaryUrl;
    $updated = preg_replace('/^PDF\s*\/\s*özet bağlantısı:\s*\S+\s*$/miu', $replacement, $message);
    if (is_string($updated) && $updated !== $message) {
        return trim($updated);
    }

    return trim($message . "\n" . $replacement);
}

function mail_message_without_prices(string $message): string
{
    $lines = preg_split('/\R/', trim($message)) ?: [];
    $filtered = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '' && preg_match('/(?:₺|€|\\$|\\b(?:TRY|TL|USD|EUR)\\b|Toplam\\s*:|Ara\\s+toplam|Birim\\s+fiyat|KDV\\s+dahil\\s+toplam|Toplam\\s+tutar)/iu', $trimmed) === 1) {
            continue;
        }

        $filtered[] = $line;
    }

    $clean = trim(implode("\n", $filtered));

    return $clean !== '' ? $clean : 'Detaylı teklif ve fiyat bilgilerini güvenli bağlantıdan inceleyebilirsiniz.';
}

function manual_renewal_mail_body(array $row, string $message, string $summaryLink = ''): string
{
    $days = days_until($row['renewal_date'] ?? null);
    $daysLabel = $days === null ? '-' : ($days < 0 ? abs($days) . ' gün geçti' : $days . ' gün');
    $summaryLink = $summaryLink !== '' ? $summaryLink : renewal_summary_url((int) $row['id'], 60);

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Yenileme bilgilendirmesi</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">' . h((string) ($row['company_name'] ?? '-')) . '</h1>'
        . '<div style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . nl2br(h(mail_message_without_prices($message)), false) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . manual_mail_row('Kayıt', (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-')))
        . manual_mail_row('Yenileme tarihi', !empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-')
        . manual_mail_row('Kalan süre', $daysLabel)
        . manual_mail_row('Ödeme şekli', renewal_payment_label($row))
        . manual_mail_row('Teklif detayı', 'Güvenli bağlantıdan görüntülenir')
        . '</table>'
        . '<p style="margin:20px 0 0;"><a href="' . h($summaryLink) . '" style="display:inline-block;background:#eef6f3;color:#0f625b;text-decoration:none;border:1px solid #cfe1db;border-radius:8px;padding:13px 18px;font-weight:700;">PDF / özet sayfasını aç</a></p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function manual_mail_row(string $label, string $value): string
{
    return '<tr>'
        . '<td style="padding:12px 14px;border-bottom:1px solid #d8e0dd;color:#61726c;font-weight:700;width:38%;">' . h($label) . '</td>'
        . '<td style="padding:12px 14px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . h($value) . '</td>'
        . '</tr>';
}

function send_collection_reminder_mail(RenewalRepository $repo, int $renewalId): array
{
    $row = $repo->find($renewalId);
    if (!$row) {
        throw new RuntimeException('Tahsilat kaydı bulunamadı.');
    }

    $recipients = collection_mail_recipients($row);
    if ($recipients === []) {
        return [
            'sent' => 0,
            'failed' => 1,
            'message' => 'Tahsilat maili için e-posta adresi dolu yetkili bulunamadı.',
        ];
    }

    $subject = mb_substr('Tahsilat hatırlatması: ' . (string) ($row['company_name'] ?? 'Müşteri'), 0, 240);
    $sent = 0;
    $failed = 0;
    $lastError = '';

    foreach ($recipients as $recipient) {
        $body = collection_reminder_mail_body($row, $recipient);
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

    return [
        'sent' => $sent,
        'failed' => $failed,
        'message' => $sent > 0 ? '' : ('Tahsilat maili gönderilemedi: ' . ($lastError ?: 'Alıcı sunucusu kabul etmedi.')),
    ];
}

function collection_mail_recipients(array $row): array
{
    $recipients = [];
    foreach (renewal_customer_contacts($row) as $contact) {
        if (empty($contact['notify_enabled'])) {
            continue;
        }

        $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $recipients[$email] = [
            'name' => trim((string) ($contact['full_name'] ?? '')),
            'email' => $email,
        ];
    }

    return array_values($recipients);
}

function collection_reminder_mail_body(array $row, array $recipient): string
{
    $total = money_format_local($row['item_total'] ?? $row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'));
    $paymentLink = PaymentLink::urlForRenewal((int) $row['id'], 60, (string) ($recipient['email'] ?? ''));
    $summaryLink = renewal_summary_url((int) $row['id'], 60, (string) ($recipient['email'] ?? ''));
    $paymentState = collection_payment_state($row);
    $recipientName = trim((string) ($recipient['name'] ?? ''));
    $greeting = $recipientName !== '' ? 'Merhaba ' . $recipientName . ',' : 'Merhaba,';

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Tahsilat hatırlatması</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">' . h((string) ($row['company_name'] ?? '-')) . '</h1>'
        . '<p style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . h($greeting) . '<br>Aşağıdaki ürün / hizmet yenilemesine ait ödeme süreci beklemede görünmektedir. Ödeme tercihinizi tamamlayabilir veya mevcut ödeme şartınıza göre işlem durumunu bizimle paylaşabilirsiniz.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . manual_mail_row('Kayıt', (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-')))
        . manual_mail_row('Yenileme tarihi', !empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-')
        . manual_mail_row('Toplam', $total . ' KDV dahil')
        . manual_mail_row('Ödeme şartı', renewal_payment_label($row))
        . manual_mail_row('Tahsilat durumu', (string) $paymentState['label'])
        . '</table>'
        . '<p style="margin:20px 0 0;"><a href="' . h($paymentLink) . '" style="display:inline-block;background:#101b18;color:#ffffff;text-decoration:none;border-radius:8px;padding:13px 18px;font-weight:700;">Ödeme / tercih ekranını aç</a></p>'
        . '<p style="margin:10px 0 0;"><a href="' . h($summaryLink) . '" style="display:inline-block;background:#eef6f3;color:#0f625b;text-decoration:none;border:1px solid #cfe1db;border-radius:8px;padding:12px 16px;font-weight:700;">PDF / özet sayfasını aç</a></p>'
        . '<p style="margin:18px 0 0;color:#61726c;font-size:13px;line-height:1.5;">Ödemenizi yaptıysanız bu maili yanıtlayarak dekont veya işlem bilgisini iletebilirsiniz.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function payment_request_default_message(array $row): string
{
    $customer = trim((string) ($row['customer_name'] ?? ''));
    $greeting = $customer !== '' ? 'Merhaba ' . $customer . ',' : 'Merhaba,';
    $title = (string) ($row['title'] ?? 'ödeme talebi');
    $description = trim((string) ($row['description'] ?? ''));
    $total = money_format_local($row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'));
    $detail = $description !== '' ? "\nAçıklama: {$description}" : '';
    $dueDate = payment_request_due_date_value($row) !== '' ? "\nÖdeme günü: " . payment_request_due_date_label($row) : '';

    return "{$greeting}\n\n{$title} için ödeme talebiniz oluşturuldu.{$detail}{$dueDate}\nToplam tutar: {$total}\n\nAşağıdaki güvenli bağlantıdan ödemenizi tamamlayabilirsiniz.";
}

function payment_request_whatsapp_message(array $row, string $message, string $paymentUrl): string
{
    $message = trim($message) !== '' ? trim($message) : payment_request_default_message($row);

    return $message . "\n\nÖdeme linki:\n" . $paymentUrl;
}

function payment_request_mail_body(array $row, string $message, string $paymentUrl): string
{
    $total = money_format_local($row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'));

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Ödeme talebi</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">' . h((string) ($row['title'] ?? 'Manuel ödeme talebi')) . '</h1>'
        . '<div style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . nl2br(h($message), false) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . manual_mail_row('Talep no', manual_payment_request_number($row))
        . manual_mail_row('Müşteri', (string) (($row['customer_name'] ?? '') ?: '-'))
        . (payment_request_due_date_value($row) !== '' ? manual_mail_row('Ödeme günü', payment_request_due_date_label($row)) : '')
        . manual_mail_row('Toplam', $total)
        . '</table>'
        . '<p style="margin:20px 0 0;"><a href="' . h($paymentUrl) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:14px 20px;font-weight:700;">Ödeme ekranını aç</a></p>'
        . '<p style="margin:18px 0 0;color:#61726c;font-size:13px;line-height:1.5;">Bu bağlantı size özel oluşturulmuştur. Ödeme tamamlandığında sistemde talep durumu güncellenecektir.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function manual_payment_request_url(array $row): string
{
    return url('/pay/' . rawurlencode((string) ($row['public_token'] ?? '')));
}

function manual_payment_request_number(array $row): string
{
    $id = max(0, (int) ($row['id'] ?? 0));
    $createdAt = !empty($row['created_at']) ? strtotime((string) $row['created_at']) : time();

    return 'MT-' . date('Y', $createdAt ?: time()) . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

function payment_request_reminder_time_value(array $row): string
{
    $time = trim((string) ($row['reminder_time'] ?? ''));
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', $time, $matches) === 1) {
        return $matches[1] . ':' . $matches[2];
    }

    return '09:00';
}

function payment_request_due_date_value(array $row): string
{
    $date = trim((string) ($row['payment_due_date'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
        return $date;
    }

    return '';
}

function payment_request_due_date_label(array $row): string
{
    $date = payment_request_due_date_value($row);
    if ($date === '') {
        return 'Ödeme günü seçilmedi';
    }

    return date('d.m.Y', strtotime($date));
}

function payment_request_reminder_summary(array $row): string
{
    $repeatDaily = !empty($row['reminder_repeat_daily']);
    $untilPaid = !empty($row['reminder_until_paid']);
    if (!$repeatDaily && !$untilPaid) {
        return 'Otomatik tekrar kapalı';
    }

    $time = payment_request_reminder_time_value($row);
    $startDays = max(0, (int) ($row['reminder_start_days_before'] ?? 3));
    $window = payment_request_due_date_value($row) !== ''
        ? ($startDays === 0 ? 'ödeme günü başlar' : $startDays . ' gün kala başlar')
        : 'hemen başlar';

    if ($untilPaid) {
        return $time . ' · ' . $window . ' · ödeme alınana kadar her gün';
    }

    return $time . ' · ' . $window . ' · her gün tekrar';
}

function payment_request_status_label(string $status): string
{
    return match ($status) {
        'paid' => 'Ödendi',
        'refunded' => 'İade edildi',
        'cancelled' => 'İptal',
        default => 'Bekliyor',
    };
}

function payment_request_status_class(string $status): string
{
    return match ($status) {
        'paid' => 'active',
        'refunded' => 'cancelled',
        'cancelled' => 'cancelled',
        default => 'warning',
    };
}

function manual_payment_request_recipients(array $row): array
{
    $decoded = json_decode((string) ($row['recipients_json'] ?? ''), true);
    $recipients = [];
    $seenEmails = [];
    if (is_array($decoded)) {
        foreach ($decoded as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }

            $email = trim(mb_strtolower((string) ($recipient['email'] ?? '')));
            $phone = normalize_phone_number((string) ($recipient['phone'] ?? ''));
            if ($email === '' && $phone === '') {
                continue;
            }

            if ($email !== '') {
                $seenEmails[$email] = true;
            }

            $recipients[] = [
                'contact_id' => (int) ($recipient['contact_id'] ?? 0),
                'name' => trim((string) ($recipient['name'] ?? 'Yetkili')),
                'email' => $email,
                'phone' => $phone,
            ];
        }
    }

    $fallbackEmail = trim(mb_strtolower((string) ($row['customer_email'] ?? '')));
    if ($fallbackEmail !== '' && filter_var($fallbackEmail, FILTER_VALIDATE_EMAIL) && empty($seenEmails[$fallbackEmail])) {
        $recipients[] = [
            'contact_id' => 0,
            'name' => trim((string) (($row['customer_name'] ?? '') ?: 'E-posta alıcısı')),
            'email' => $fallbackEmail,
            'phone' => '',
        ];
    }

    return $recipients;
}

function manual_payment_request_email_recipients(array $row): array
{
    $emails = [];
    foreach (manual_payment_request_recipients($row) as $recipient) {
        $email = trim(mb_strtolower((string) ($recipient['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = $email;
        }
    }

    return array_values($emails);
}

function manual_payment_email_input_value(array $row): string
{
    return implode(', ', manual_payment_request_email_recipients($row));
}

function manual_payment_parse_email_list(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/[\s,;]+/', $value) ?: [];
    $recipients = [];
    $seen = [];
    foreach ($parts as $part) {
        $email = trim(mb_strtolower($part));
        if ($email === '') {
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Geçerli bir e-posta adresi yazın: ' . $email);
        }
        if (isset($seen[$email])) {
            continue;
        }
        $seen[$email] = true;
        $recipients[] = [
            'contact_id' => 0,
            'name' => 'E-posta alıcısı',
            'email' => $email,
            'phone' => '',
        ];
    }

    return $recipients;
}

function manual_payment_merge_email_recipients(array $recipients, array $emailRecipients): array
{
    $seen = [];
    foreach ($recipients as $recipient) {
        $email = trim(mb_strtolower((string) ($recipient['email'] ?? '')));
        if ($email !== '') {
            $seen[$email] = true;
        }
    }

    foreach ($emailRecipients as $recipient) {
        $email = trim(mb_strtolower((string) ($recipient['email'] ?? '')));
        if ($email === '' || isset($seen[$email])) {
            continue;
        }
        $seen[$email] = true;
        $recipients[] = $recipient;
    }

    return $recipients;
}

function manual_payment_selected_recipients(int $customerId, mixed $selectedIds): array
{
    if ($customerId < 1) {
        return [];
    }

    $ids = is_array($selectedIds) ? $selectedIds : [$selectedIds];
    $selected = [];
    foreach ($ids as $id) {
        $contactId = (int) $id;
        if ($contactId > 0) {
            $selected[$contactId] = true;
        }
    }

    if ($selected === []) {
        return [];
    }

    $customer = (new RenewalRepository())->findCustomer($customerId);
    if (!$customer) {
        return [];
    }

    $recipients = [];
    foreach (($customer['contacts'] ?? []) as $contact) {
        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId < 1 || empty($selected[$contactId])) {
            continue;
        }

        $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
        $phone = normalize_phone_number((string) ($contact['phone'] ?? ''));
        if ($email === '' && $phone === '') {
            continue;
        }

        $recipients[] = [
            'contact_id' => $contactId,
            'name' => trim((string) ($contact['full_name'] ?? 'Yetkili')),
            'email' => $email,
            'phone' => $phone,
        ];
    }

    return $recipients;
}

function manual_payment_customer_choices(array $customers): array
{
    $choices = [];
    foreach ($customers as $customer) {
        $contacts = [];
        foreach (($customer['contacts'] ?? []) as $contact) {
            $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
            $phone = normalize_phone_number((string) ($contact['phone'] ?? ''));
            if ($email === '' && $phone === '') {
                continue;
            }

            $contacts[] = [
                'id' => (int) ($contact['id'] ?? 0),
                'name' => trim((string) ($contact['full_name'] ?? 'Yetkili')),
                'email' => $email,
                'phone' => $phone,
                'notify' => !empty($contact['notify_enabled']),
            ];
        }

        $choices[] = [
            'id' => (int) ($customer['id'] ?? 0),
            'name' => (string) ($customer['company_name'] ?? ''),
            'email' => trim(mb_strtolower((string) ($customer['email'] ?? ''))),
            'phone' => normalize_phone_number((string) ($customer['phone'] ?? '')),
            'tax' => (string) ($customer['tax_number'] ?? ''),
            'contacts' => $contacts,
        ];
    }

    return $choices;
}

function send_manual_renewal_notification(RenewalRepository $repo, int $renewalId): array
{
    $row = $repo->find($renewalId);
    if (!$row) {
        throw new RuntimeException('Yenileme kaydı bulunamadı.');
    }

    if (($row['status'] ?? '') !== 'active') {
        throw new RuntimeException('Sadece aktif yenilemeler için bilgilendirme gönderilebilir.');
    }

    $recipients = $repo->notificationRecipients((int) $row['customer_id']);
    if ($recipients === []) {
        $repo->logMail(
            (int) $row['id'],
            'missing-approved-recipient',
            'Eksik bilgilendirme yetkilisi',
            'Manuel bilgilendirme için bilgilendirme kutusu onaylı ve e-posta adresi dolu yetkili bulunamadı.',
            'failed',
            'missing-approved-recipient'
        );

        return [
            'sent' => 0,
            'failed' => 1,
            'message' => 'Bilgilendirme kutusu onaylı ve e-posta adresi dolu yetkili bulunamadı.',
        ];
    }

    $settings = (new SettingsRepository())->all();
    $days = days_until($row['renewal_date']);
    [$subject, $statusLine] = renewal_notification_subject_and_status($row, $days);
    $sent = 0;
    $failed = 0;

    foreach ($recipients as $recipient) {
        $delivery = $repo->createNotificationDelivery((int) $row['id'], $recipient);
        $recipientForMail = array_merge($recipient, [
            'read_ack_url' => $delivery['read_url'],
            'summary_url' => renewal_tracked_summary_url((int) $row['id'], (string) $delivery['token'], 60, (string) ($recipient['email'] ?? '')),
        ]);
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
        'message' => $sent > 0 ? 'Bilgilendirme gönderildi.' : 'Bilgilendirme gönderilemedi.',
    ];
}

function handle_renewal_decision(RenewalRepository $repo, int $renewalId): void
{
    verify_csrf();

    try {
        $row = $repo->find($renewalId);
        if (!$row) {
            throw new RuntimeException('Yenileme kaydı bulunamadı.');
        }

        $decision = (string) ($_POST['decision'] ?? '');
        $createdBy = (int) ($_SESSION['user_id'] ?? 0);

        if ($decision === 'approved') {
            $paymentTerms = trim((string) ($_POST['payment_terms'] ?? ''));
            if ($paymentTerms === '') {
                throw new RuntimeException('Onay için ödeme şartlarını yazın.');
            }

            $repo->markDecisionApproved($renewalId, $paymentTerms);
            $repo->recordRenewalDecision([
                'renewal_id' => $renewalId,
                'decision' => 'approved',
                'payment_terms' => $paymentTerms,
                'created_by' => $createdBy,
            ]);
            flash('success', 'Yenileme onaylandı ve ödeme şartları kaydedildi.');
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
            flash('success', 'Yenileme reddedildi olarak kaydedildi ve iptal durumuna alındı.');
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
                flash('success', 'Revize talebi tedarikçiye gönderildi.');
            } elseif ((int) ($mailResult['sent'] ?? 0) > 0) {
                flash('error', 'Revize talebi kısmen gönderildi: ' . (string) ($mailResult['error'] ?? ''));
            } else {
                flash('error', 'Revize talebi gönderilemedi: ' . (string) ($mailResult['error'] ?? 'Alıcı bulunamadı.'));
            }
        } else {
            throw new RuntimeException('Geçersiz takip karari.');
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
        throw new RuntimeException('Erteleme için tarih seçin.');
    }

    if ($date < $today) {
        throw new RuntimeException('Erteleme tarihi bugünden önce olamaz.');
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
            'error' => 'Bilgilendirme kutusu açık tedarikçi yetkilisi bulunamadı.',
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
        'Müşteri' => supplier_customer_label($row),
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
            'supplier_group_id' => empty($contact['supplier_group_id']) ? null : (int) $contact['supplier_group_id'],
            'supplier_group_name' => (string) ($contact['supplier_group_name'] ?? ''),
            'contact_id' => (int) ($contact['contact_id'] ?? 0),
            'supplier_name' => $supplierName,
            'name' => $name !== '' ? $name : ($supplierName !== '' ? $supplierName : ($email ?: $phone)),
            'email' => $email,
            'phone' => $phone,
        ];
    }

    return array_values($unique);
}

function supplier_customer_info_shared(array $row): bool
{
    return (string) ($row['supplier_share_customer_info'] ?? '1') !== '0';
}

function supplier_customer_label(array $row): string
{
    if (!supplier_customer_info_shared($row)) {
        return 'Cari bilgisi gizli';
    }

    return (string) (($row['company_name'] ?? '') ?: '-');
}

function supplier_price_default_message(array $row): string
{
    $days = days_until($row['renewal_date'] ?? null);
    $statusLine = $days !== null && $days < 0
        ? 'Yenileme tarihi ' . abs($days) . ' gün önce geçti.'
        : 'Yenilemeye kalan süre: ' . max(0, (int) $days) . ' gün.';
    $title = (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'yenileme kaydı'));
    $customerLine = supplier_customer_info_shared($row)
        ? (string) ($row['company_name'] ?? '-') . ' müşterimiz için aşağıdaki ürün / hizmet yenilemesi yaklaşmaktadır.'
        : 'Cari bilgisi gizli tutulan bir müşterimiz için aşağıdaki ürün / hizmet yenilemesi yaklaşmaktadır.';

    return implode("\n", [
        'Merhaba,',
        '',
        $customerLine,
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

function supplier_price_request_body(array $row, string $message, string $quoteUrl = '', string $unsubscribeUrl = '', string $quoteNumber = ''): string
{
    $rows = [];
    $quoteNumber = trim($quoteNumber);
    if ($quoteNumber !== '') {
        $rows['Teklif no'] = $quoteNumber;
    }
    $rows += [
        'Müşteri' => supplier_customer_label($row),
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
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;">TEDARİKÇİ FİYAT TALEBİ</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">Güncel yenileme fiyatı rica ederiz.</h1>'
        . '<div style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . nl2br(h($message), false) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . ($quoteUrl !== '' ? '<p style="margin:20px 0 0;"><a href="' . h($quoteUrl) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:14px 20px;font-weight:700;">Teklif formunu aç</a></p>' : '')
        . '<p style="margin:14px 0 0;color:#607069;font-size:13px;line-height:1.5;">Formda nakliye, KDV, vade ve teklif notu onayı zorunludur. Fiyat yazmak istemezseniz teklifinizi dosya veya not olarak iletebilirsiniz.</p>'
        . ($unsubscribeUrl !== '' ? '<p style="margin:18px 0 0;color:#8a9792;font-size:12px;line-height:1.5;">Bu tür fiyat talebi e-postalarını almak istemiyorsanız <a href="' . h($unsubscribeUrl) . '" style="color:#0f625b;text-decoration:underline;font-weight:700;">tedarik listesinden çıkabilirsiniz</a>.</p>' : '')
        . '</td></tr></table></td></tr></table></body></html>';
}

function supplier_quote_closed_body(array $request, int $submittedCount): string
{
    $rows = [];
    $quoteNumber = trim((string) ($request['quote_number'] ?? ''));
    if ($quoteNumber !== '') {
        $rows['Teklif no'] = $quoteNumber;
    }
    $rows += [
        'Müşteri' => supplier_customer_label($request),
        'Kayıt' => (string) ($request['title'] ?? '-'),
        'Alınan teklif sayısı' => (string) $submittedCount,
        'Yenileme tarihi' => !empty($request['renewal_date']) ? date('d.m.Y', strtotime((string) $request['renewal_date'])) : '-',
    ];

    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;width:38%;">' . h($label) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . h($value) . '</td>'
            . '</tr>';
    }

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:24px;background:#f2f6f4;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d9e3df;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Tedarikçi teklif süreci</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">Bu teklif talebi kapatıldı.</h1>'
        . '<p style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">Merhaba, ilgili yenileme için ' . h((string) $submittedCount) . ' teklif alınmıştır. Bu nedenle size gönderilen teklif formu kapatılmıştır; ayrıca işlem yapmanıza gerek yoktur.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function customer_offer_mail_body(array $row, array $offer, string $message): string
{
    $rows = [];
    $offerNumber = trim((string) ($offer['offer_number'] ?? ''));
    if ($offerNumber !== '') {
        $rows['Teklif no'] = $offerNumber;
    }
    $rows += [
        'Müşteri' => (string) ($row['company_name'] ?? '-'),
        'Kayıt' => (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-')),
        'Teklif detayı' => 'Güvenli bağlantıdan görüntülenir',
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
        $lineRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . h((string) ($line['item_title'] ?? '-')) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;text-align:center;">' . h(number_format($quantity, 2, ',', '.')) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;text-align:right;">Linkte görüntülenir</td>'
            . '</tr>';
    }
    $lineTable = $lineRows === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;border-collapse:collapse;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr>'
        . '<th align="left" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Ürün / hizmet</th>'
        . '<th align="center" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Adet</th>'
        . '<th align="right" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Detay</th>'
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
        . '<div style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . nl2br(h(mail_message_without_prices($message)), false) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . $lineTable
        . '<p style="margin:20px 0 0;"><a href="' . h((string) ($offer['url'] ?? '')) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:14px 20px;font-weight:700;">Teklifi incele ve yanıtla</a></p>'
        . '<p style="margin:14px 0 0;color:#607069;font-size:13px;line-height:1.5;">Bu bağlantı üzerinden onay, revize isteği veya red tercihinizi iletebilirsiniz.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function supplier_quote_selection_body(array $row, array $selected, string $itemTitle, string $readUrl = ''): string
{
    $contactName = trim((string) ($selected['contact_name'] ?? ''));
    $termLabel = supplier_quote_term_label((string) ($selected['term'] ?? ''), (string) ($selected['custom_term'] ?? ''));
    $price = money_format_local($selected['price'] ?? 0, (string) ($selected['currency'] ?? 'TRY'));
    $rows = [];
    $quoteNumber = trim((string) ($selected['quote_number'] ?? ''));
    if ($quoteNumber !== '') {
        $rows['Teklif no'] = $quoteNumber;
    }
    $rows += [
        'Müşteri' => supplier_customer_label($row),
        'Ürün / hizmet' => $itemTitle,
        'Onaylanan vade' => $termLabel,
        'Onaylanan fiyat' => $price,
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

    $readAction = $readUrl !== ''
        ? '<p style="margin:20px 0 0;"><a href="' . h($readUrl) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:13px 20px;font-weight:800;">Okudum</a></p>'
            . '<p style="margin:10px 0 0;color:#607069;font-size:13px;line-height:1.5;">Bu bildirimi aldığınızı kaydetmek için Okudum butonuna tıklayın.</p>'
        : '';

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:24px;background:#f2f6f4;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d9e3df;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Tedarikçi teklif onayı</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">Onaylandı, işleme alabilirsiniz.</h1>'
        . '<p style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">'
        . h($contactName !== '' ? 'Merhaba ' . $contactName . ',' : 'Merhaba,')
        . '<br>Paylaştığınız teklif aşağıdaki şartlarla onaylanmıştır. Lütfen ilgili işlem / yenileme sürecini başlatabilirsiniz.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . $readAction
        . '<p style="margin:18px 0 0;color:#607069;font-size:13px;line-height:1.5;">Herhangi bir değişiklik gerekiyorsa lütfen bizimle iletişime geçiniz.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function supplier_price_whatsapp_message(array $row, array $contact, string $quoteUrl = '', string $quoteNumber = ''): string
{
    $name = trim((string) ($contact['name'] ?? ''));
    $message = supplier_price_default_message($row);
    $quoteNumber = trim($quoteNumber);
    if ($quoteNumber !== '') {
        $message .= "\n\nTeklif no: " . $quoteNumber;
    }
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

    if (renewal_payment_choice_completed($renewal, $repo)) {
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
    $allowOtherPaymentMethod = !empty($renewal['payment_customer_choice']);
    $selectedMethod = trim((string) ($renewal['payment_method'] ?? ''));
    $otherPaymentMethod = '';
    $directCreditCard = $method === 'GET' && (string) ($_GET['method'] ?? '') === 'credit_card';

    if ($selectedMethod !== '' && payment_method_is_credit_card($selectedMethod) && !renewal_has_paid_card_payment($repo, $renewalId, (string) ($renewal['payment_selected_at'] ?? ''))) {
        $notice = 'Kredi kartı ödeme adımı henüz tamamlanmamış görünüyor. Ödemeyi tamamlamak için devam edebilirsiniz.';
    }

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
            $checkoutUrl = create_credit_card_checkout_url($repo, $renewal, $totalAmount, $currency, null, 'mail');
            redirect($checkoutUrl);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    if ($method === 'POST') {
        verify_csrf();
        $selectedMethod = trim((string) ($_POST['payment_method'] ?? ''));
        $isOtherPaymentMethod = $selectedMethod === payment_method_other_value();
        $otherPaymentMethod = trim((string) ($_POST['other_payment_method'] ?? ''));

        try {
            if ($isOtherPaymentMethod) {
                if (!$allowOtherPaymentMethod) {
                    throw new RuntimeException('Diğer ödeme şartı yalnızca müşteri ödeme şeklini kendi seçecekse kullanılabilir.');
                }
                if ($otherPaymentMethod === '') {
                    throw new RuntimeException('Lütfen diğer ödeme şartını yazın.');
                }

                $selectedMethod = mb_substr($otherPaymentMethod, 0, 120);
            } else {
                $methodRow = payment_method_row_by_name($paymentMethods, $selectedMethod);
                if (!$methodRow) {
                    throw new RuntimeException('Lütfen geçerli bir ödeme yöntemi seçin.');
                }
            }

            if (payment_method_is_credit_card($selectedMethod)) {
                $amount = (float) (($renewal['item_total'] ?? 0) ?: ($renewal['amount'] ?? 0));
                if ($amount <= 0) {
                    throw new RuntimeException('Kredi kartı ödemesi için tutar tanımlı değil. Lütfen firma yetkilisiyle iletişime geçin.');
                }

                $checkoutUrl = create_credit_card_checkout_url($repo, $renewal, $amount, (string) ($renewal['currency'] ?? 'TRY'), null, 'public');
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
            if ($isOtherPaymentMethod) {
                $selectedMethod = payment_method_other_value();
            }
            $error = $e->getMessage();
        }
    }

    render_public_layout('Ödeme seçimi', static function () use ($renewal, $items, $totalAmount, $currency, $exchangeRates, $settings, $paymentMethods, $expires, $signature, $linkEmail, $error, $notice, $selectedMethod, $allowOtherPaymentMethod, $otherPaymentMethod): void {
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
                        <?php if ($allowOtherPaymentMethod): ?>
                            <?= option(payment_method_other_value(), 'Diğer', $selectedMethod) ?>
                        <?php endif; ?>
                    </select>
                </label>
                <?php if ($allowOtherPaymentMethod): ?>
                    <div class="other-payment-public" data-other-payment-panel hidden>
                        <label>
                            Diğer ödeme şartı
                            <input
                                name="other_payment_method"
                                maxlength="120"
                                value="<?= h($otherPaymentMethod) ?>"
                                placeholder="Örn: 45 gün vade, iki taksit, özel mutabakat"
                                data-other-payment-input
                            >
                            <span class="field-help">Bu metin ödeme tercihiniz olarak kaydedilir ve tahsilat ekranında aynen görünür.</span>
                        </label>
                    </div>
                <?php endif; ?>
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
                        <?php if ($allowOtherPaymentMethod): ?>
                            <div>
                                <strong>Diğer</strong>
                                <span>Listede olmayan ödeme şartını kendiniz yazabilirsiniz.</span>
                            </div>
                        <?php endif; ?>
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

    if (!renewal_payment_choice_completed($renewal, $repo)) {
        redirect(url('/renewals/' . $renewalId . '/payment?' . public_payment_link_query($expires, $signature, $linkEmail)));
    }

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

    $trackingToken = trim((string) ($_GET['track'] ?? ''));
    if ($trackingToken !== '') {
        $read = $repo->markNotificationDeliveryRead(
            $renewalId,
            $trackingToken,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        if ($read && (string) ($read['already_read'] ?? '0') !== '1') {
            notify_renewal_notification_read($read);
        }
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

    if ((string) ($read['already_read'] ?? '0') !== '1') {
        notify_renewal_notification_read($read);
    }

    render_public_layout('Okundu bilgisi', static function () use ($read): void {
        ?>
        <section class="public-card payment-result-card success">
            <p class="eyebrow">Bildirim okundu</p>
            <h1>Teşekkür ederiz.</h1>
            <p>Yenileme bildirimi okundu olarak kaydedildi.</p>
            <div class="payment-result-summary">
                <span>Müşteri</span>
                <strong><?= h(supplier_customer_label($read)) ?></strong>
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

function handle_supplier_quote_selection_read(string $token): void
{
    $repo = new RenewalRepository();
    $read = $repo->markSupplierQuoteSelectionRead(
        $token,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    if (!$read) {
        http_response_code(404);
        render_public_layout('Tedarikçi okundu bilgisi', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Tedarikçi teklif onayı</p>
                <h1>Bağlantı bulunamadı.</h1>
                <p class="muted">Bu okundu bağlantısı geçersiz olabilir. Lütfen size gelen son onay mailindeki bağlantıyı kullanın.</p>
            </section>
            <?php
        });
        return;
    }

    if ((string) ($read['already_read'] ?? '0') !== '1') {
        try {
            send_internal_push_notification(
                'Tedarikçi onayı okundu',
                trim((string) (($read['supplier_name'] ?? '') ?: ($read['recipient_name'] ?? '') ?: ($read['recipient_email'] ?? 'Tedarikçi')))
                    . ' onaylanan teklifi okudu: '
                    . trim((string) (($read['display_item_title'] ?? '') ?: ($read['title'] ?? 'Yenileme'))),
                '/',
                'supplier-selection-read-' . (int) ($read['id'] ?? 0)
            );
        } catch (Throwable $e) {
            error_log('Tedarikçi onay okundu push gönderilemedi: ' . $e->getMessage());
        }
    }

    render_public_layout('Tedarikçi okundu bilgisi', static function () use ($read): void {
        ?>
        <section class="public-card payment-result-card success">
            <p class="eyebrow">Tedarikçi teklif onayı</p>
            <h1>Teşekkür ederiz.</h1>
            <p>Onaylanan teklif bildirimi okundu olarak kaydedildi.</p>
            <div class="payment-result-summary">
                <span>Müşteri</span>
                <strong><?= h((string) ($read['company_name'] ?? '-')) ?></strong>
                <span>Ürün / hizmet</span>
                <strong><?= h((string) (($read['display_item_title'] ?? '') ?: ($read['item_title'] ?? '-'))) ?></strong>
                <span>Tedarikçi</span>
                <strong><?= h((string) (($read['supplier_name'] ?? '') ?: ($read['recipient_email'] ?? '-'))) ?></strong>
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

function renewal_tracked_summary_url(int $renewalId, string $trackingToken, int $ttlDays = 60, string $recipientEmail = ''): string
{
    $url = renewal_summary_url($renewalId, $ttlDays, $recipientEmail);
    if (!preg_match('/^[a-f0-9]{64}$/i', $trackingToken)) {
        return $url;
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . 'track=' . rawurlencode($trackingToken);
}

function customer_offer_view_count(array $offer): int
{
    return max(0, (int) ($offer['view_count'] ?? 0));
}

function customer_offer_revision_allowed(array $offer): bool
{
    return customer_offer_view_count($offer) >= 4;
}

function renewal_payment_choice_completed(array $renewal, ?RenewalRepository $repo = null): bool
{
    $paymentMethod = trim((string) ($renewal['payment_method'] ?? ''));
    if ($paymentMethod === '' || !empty($renewal['payment_customer_choice'])) {
        return false;
    }

    if (payment_method_is_credit_card($paymentMethod)) {
        return $repo !== null && renewal_has_paid_card_payment($repo, (int) ($renewal['id'] ?? 0), (string) ($renewal['payment_selected_at'] ?? ''));
    }

    return true;
}

function renewal_has_paid_card_payment(RenewalRepository $repo, int $renewalId, string $since = ''): bool
{
    if ($renewalId < 1) {
        return false;
    }

    $sinceTs = trim($since) !== '' ? strtotime($since) : false;
    $payments = $repo->cardPayments($renewalId);
    if ($sinceTs === false) {
        return $payments !== [] && card_payment_is_confirmed((array) $payments[0]);
    }

    foreach ($payments as $payment) {
        if (!card_payment_is_confirmed($payment, $sinceTs !== false ? $sinceTs : null)) {
            continue;
        }

        return true;
    }

    return false;
}

function card_payment_is_confirmed(array $payment, ?int $sinceTs = null): bool
{
    if ((string) ($payment['status'] ?? '') !== 'paid') {
        return false;
    }

    if (trim((string) ($payment['payment_id'] ?? '')) === '') {
        return false;
    }

    if ($sinceTs === null) {
        return true;
    }

    $paidAt = trim((string) (($payment['paid_at'] ?? '') ?: ($payment['updated_at'] ?? '') ?: ($payment['created_at'] ?? '')));
    $paidTs = $paidAt !== '' ? strtotime($paidAt) : false;

    return $paidTs !== false && $paidTs >= $sinceTs;
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

function payment_method_other_value(): string
{
    return '__other_payment_terms__';
}

function payment_method_is_removed_30_day(string $name): bool
{
    $normalized = strtr(mb_strtolower(trim($name)), [
        'ı' => 'i',
        'ğ' => 'g',
        'ü' => 'u',
        'ş' => 's',
        'ö' => 'o',
        'ç' => 'c',
    ]);

    return str_contains($normalized, '30') && str_contains($normalized, 'cari');
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

function render_card_payment_create_form(int $renewalId, string $buttonLabel, array $renewal): string
{
    $action = url('/renewals/' . $renewalId . '/payments/iyzico/create');
    $confirm = 'iyzico ödeme sayfası oluşturulsun mu?';
    $selectedCurrency = normalize_allowed_currency((string) ($renewal['currency'] ?? 'TRY'));
    $amount = (float) ($renewal['item_total'] ?? $renewal['amount'] ?? 0);
    $exchangeRates = ExchangeRates::latest();
    $chargePreview = null;
    if ($selectedCurrency !== 'TRY') {
        $rate = payment_exchange_rate($exchangeRates, $selectedCurrency);
        $chargePreview = $rate !== null && $amount > 0
            ? money_format_local($amount * $rate, 'TRY') . ' · ' . $selectedCurrency . ' satış kuru: ' . format_exchange_rate($rate)
            : null;
    }

    ob_start();
    ?>
    <form method="post" action="<?= h($action) ?>" class="payment-create-form" onsubmit="return confirm('<?= h($confirm) ?>')">
        <?= csrf_field() ?>
        <label>
            Tahsil edilecek tutar
            <input type="number" min="0.01" step="0.01" name="amount" value="<?= h($renewal['item_total'] ?? $renewal['amount'] ?? '') ?>" placeholder="Örn: 1500.00" required>
        </label>
        <label>
            Para birimi
            <select name="currency">
                <?php foreach (allowed_currency_options() as $currency): ?>
                    <?= option($currency, $currency, $selectedCurrency) ?>
                <?php endforeach; ?>
            </select>
            <span class="field-help">iyzico POS tahsilatı TL ile açılır. USD/EUR seçerseniz sistem TCMB satış kuruyla TL ödeme linki oluşturur.</span>
        </label>
        <button type="submit" class="button secondary"><?= h($buttonLabel) ?></button>
    </form>
    <?php if ($selectedCurrency !== 'TRY'): ?>
        <div class="settings-note payment-note">
            <strong>Kart tahsilatı TL oluşturulacak</strong>
            <span><?= h($chargePreview ?? ($selectedCurrency . ' için kur alınamazsa sistem ödeme linkini oluşturmaz.')) ?></span>
        </div>
    <?php endif; ?>
    <?php

    return (string) ob_get_clean();
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
        throw new RuntimeException('Havale / EFT için makbuz veya dekont yükleyin.');
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Makbuz yüklenemedi. Lütfen dosyayı kontrol edin.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Makbuz dosyası okunamadı.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > 8 * 1024 * 1024) {
        throw new RuntimeException('Makbuz dosyası en fazla 8 MB olabilir.');
    }

    $originalName = sanitize_uploaded_filename((string) ($file['name'] ?? 'makbuz'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Makbuz dosyası PDF, JPG, PNG veya WebP olmalıdır.');
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
        throw new RuntimeException('Makbuz dosyası kaydedilemedi.');
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
        return ['status' => 'failed', 'error' => 'Havale/EFT bildirim alıcısı tanımlı değil.'];
    }

    $subject = 'Havale/EFT makbuzu: ' . (string) ($renewal['company_name'] ?? 'Müşteri');
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
            'renewal_item_id' => (int) ($line['renewal_item_id'] ?? 0),
            'quote_line_id' => (int) ($line['supplier_quote_line_id'] ?? 0),
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
            'quote_number' => (string) ($line['supplier_quote_number'] ?? ''),
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
    $charge = iyzico_charge_payload($amount, $currency);

    $conversationId = $source . '-renewal-' . $renewalId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $result = $client->initializeCheckout($renewal, $charge['amount'], $charge['currency'], $conversationId);
    $response = $result['response'];
    $paymentPageUrl = trim((string) ($response['paymentPageUrl'] ?? ''));
    $token = trim((string) ($response['token'] ?? ''));
    $ok = (string) ($response['status'] ?? '') === 'success' && $paymentPageUrl !== '' && $token !== '';

    $repo->createIyzicoPayment([
        'renewal_id' => $renewalId,
        'conversation_id' => $conversationId,
        'token' => $token,
        'amount' => $charge['amount'],
        'currency' => $charge['currency'],
        'status' => $ok ? 'pending' : 'failed',
        'payment_page_url' => $paymentPageUrl,
        'error_message' => $ok ? '' : ((string) ($response['errorMessage'] ?? 'iyzico ödeme linki oluşturulamadı.')),
        'raw_request' => iyzico_raw_request_with_original($result['request'], $charge),
        'raw_response' => $response,
        'created_by' => $createdBy,
    ]);

    if (!$ok) {
        throw new RuntimeException((string) ($response['errorMessage'] ?? 'iyzico ödeme linki oluşturulamadı.'));
    }

    return $paymentPageUrl;
}

function create_credit_card_checkout_url(RenewalRepository $repo, array $renewal, float $amount, string $currency, ?int $createdBy, string $source): string
{
    return create_iyzico_checkout_url($repo, $renewal, $amount, $currency, $createdBy, $source);
}

function iyzico_charge_payload(float $amount, string $currency): array
{
    $currency = normalize_allowed_currency($currency);
    $amount = max(0.01, $amount);
    $charge = [
        'amount' => round($amount, 2),
        'currency' => 'TRY',
        'original_amount' => round($amount, 2),
        'original_currency' => $currency,
        'exchange_rate' => 1.0,
        'converted' => $currency !== 'TRY',
    ];

    if ($currency === 'TRY') {
        return $charge;
    }

    $exchangeRates = ExchangeRates::latest();
    $rate = payment_exchange_rate($exchangeRates, $currency);
    if ($rate === null || $rate <= 0) {
        throw new RuntimeException($currency . ' için TCMB satış kuru alınamadı. Kredi kartı tahsilatı TL olarak oluşturulamadı.');
    }

    $charge['amount'] = round($amount * $rate, 2);
    $charge['exchange_rate'] = $rate;

    return $charge;
}

function iyzico_raw_request_with_original(array $request, array $charge): array
{
    if (empty($charge['converted'])) {
        return $request;
    }

    $request['_system_original_amount'] = $charge['original_amount'];
    $request['_system_original_currency'] = $charge['original_currency'];
    $request['_system_exchange_rate'] = $charge['exchange_rate'];
    $request['_system_note'] = 'iyzico kart tahsilatı TRY POS üzerinden oluşturuldu.';

    return $request;
}

function create_manual_iyzico_checkout_url(PaymentRequestRepository $repo, array $request, ?int $createdBy, string $source): string
{
    $client = new IyzicoClient(new SettingsRepository());
    if (!$client->isEnabled() || !$client->isConfigured()) {
        throw new RuntimeException('Kredi kartı ödemesi şu anda aktif değil. Lütfen firma yetkilisiyle iletişime geçin.');
    }

    $request = manual_payment_autofill_tax_number($repo, $request);
    $requestId = (int) $request['id'];
    $amount = max(0.01, (float) ($request['amount'] ?? 0));
    $currency = normalize_allowed_currency((string) ($request['currency'] ?? 'TRY'));
    $charge = iyzico_charge_payload($amount, $currency);
    $conversationId = $source . '-manual-payment-' . $requestId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $result = $client->initializeManualPayment($request, $charge['amount'], $charge['currency'], $conversationId);
    $response = $result['response'];
    $paymentPageUrl = trim((string) ($response['paymentPageUrl'] ?? ''));
    $token = trim((string) ($response['token'] ?? ''));
    $ok = (string) ($response['status'] ?? '') === 'success' && $paymentPageUrl !== '' && $token !== '';

    $repo->createIyzicoPayment([
        'request_id' => $requestId,
        'conversation_id' => $conversationId,
        'token' => $token,
        'amount' => $charge['amount'],
        'currency' => $charge['currency'],
        'status' => $ok ? 'pending' : 'failed',
        'payment_page_url' => $paymentPageUrl,
        'error_message' => $ok ? '' : ((string) ($response['errorMessage'] ?? 'iyzico ödeme linki oluşturulamadı.')),
        'raw_request' => iyzico_raw_request_with_original($result['request'], $charge),
        'raw_response' => $response,
        'created_by' => $createdBy,
    ]);

    if (!$ok) {
        throw new RuntimeException((string) ($response['errorMessage'] ?? 'iyzico ödeme linki oluşturulamadı.'));
    }

    return $paymentPageUrl;
}

function handle_manual_payment_card_create(string $token): void
{
    verify_csrf();

    $repo = new PaymentRequestRepository();
    $request = $repo->findByToken($token);
    if (!$request) {
        http_response_code(404);
        render_public_layout('Ödeme talebi', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Ödeme talebi</p>
                <h1>Bağlantı bulunamadı.</h1>
                <p class="muted">Bu ödeme bağlantısı sistemde bulunamadı.</p>
            </section>
            <?php
        });
        return;
    }

    try {
        if (in_array((string) ($request['status'] ?? 'pending'), ['paid', 'refunded'], true)) {
            redirect('/pay/' . rawurlencode($token));
        }
        if ((string) ($request['status'] ?? 'pending') === 'cancelled') {
            throw new RuntimeException('Bu ödeme talebi iptal edilmiş.');
        }

        if (manual_payment_needs_email($request)) {
            $_SESSION['manual_payment_error'] = 'Kredi kartı ödemesine devam etmek için e-posta adresinizi girin.';
            redirect('/pay/' . rawurlencode($token) . '#email-required');
        }

        $request = manual_payment_autofill_tax_number($repo, $request);
        if (manual_payment_needs_tax_number($request)) {
            $_SESSION['manual_payment_error'] = 'Kredi kartı ödemesine devam etmek için vergi no / TC kimlik no bilgisini tamamlayın.';
            redirect('/pay/' . rawurlencode($token) . '#tax-required');
        }

        $checkoutUrl = create_manual_iyzico_checkout_url($repo, $request, null, 'manual-public');
        redirect($checkoutUrl);
    } catch (Throwable $e) {
        $_SESSION['manual_payment_error'] = handle_caught_error($e, 'manual_payment_card_create');
        redirect('/pay/' . rawurlencode($token));
    }
}

function handle_manual_payment_public_email(string $token): void
{
    verify_csrf();

    $repo = new PaymentRequestRepository();
    $request = $repo->findByToken($token);
    if (!$request) {
        http_response_code(404);
        render_public_layout('Ödeme talebi', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Ödeme talebi</p>
                <h1>Bağlantı bulunamadı.</h1>
                <p class="muted">Bu ödeme bağlantısı sistemde bulunamadı.</p>
            </section>
            <?php
        });
        return;
    }

    try {
        if (in_array((string) ($request['status'] ?? 'pending'), ['paid', 'refunded'], true)) {
            redirect('/pay/' . rawurlencode($token));
        }
        if ((string) ($request['status'] ?? 'pending') === 'cancelled') {
            throw new RuntimeException('Bu ödeme talebi iptal edilmiş.');
        }

        $email = trim(mb_strtolower((string) ($_POST['customer_email'] ?? '')));
        $request = $repo->updatePublicEmail((int) $request['id'], $email);
        if (!$request) {
            throw new RuntimeException('Ödeme talebi güncellenemedi.');
        }

        $request = manual_payment_autofill_tax_number($repo, $request);
        if (manual_payment_needs_tax_number($request)) {
            $_SESSION['manual_payment_error'] = 'Kredi kartı ödemesine devam etmek için vergi no / TC kimlik no bilgisini tamamlayın.';
            redirect('/pay/' . rawurlencode($token) . '#tax-required');
        }

        $checkoutUrl = create_manual_iyzico_checkout_url($repo, $request, null, 'manual-public');
        redirect($checkoutUrl);
    } catch (Throwable $e) {
        $_SESSION['manual_payment_error'] = handle_caught_error($e, 'manual_payment_email');
        redirect('/pay/' . rawurlencode($token) . '#email-required');
    }
}

function handle_manual_payment_public_tax(string $token): void
{
    verify_csrf();

    $repo = new PaymentRequestRepository();
    $request = $repo->findByToken($token);
    if (!$request) {
        http_response_code(404);
        render_public_layout('Ödeme talebi', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Ödeme talebi</p>
                <h1>Bağlantı bulunamadı.</h1>
                <p class="muted">Bu ödeme bağlantısı sistemde bulunamadı.</p>
            </section>
            <?php
        });
        return;
    }

    try {
        if (in_array((string) ($request['status'] ?? 'pending'), ['paid', 'refunded'], true)) {
            redirect('/pay/' . rawurlencode($token));
        }
        if ((string) ($request['status'] ?? 'pending') === 'cancelled') {
            throw new RuntimeException('Bu ödeme talebi iptal edilmiş.');
        }

        $taxNumber = trim((string) ($_POST['customer_tax_number'] ?? ''));
        $request = $repo->updatePublicTaxNumber((int) $request['id'], $taxNumber);
        if (!$request) {
            throw new RuntimeException('Ödeme talebi güncellenemedi.');
        }

        if (manual_payment_needs_email($request)) {
            $_SESSION['manual_payment_error'] = 'Kredi kartı ödemesine devam etmek için e-posta adresinizi girin.';
            redirect('/pay/' . rawurlencode($token) . '#email-required');
        }

        $checkoutUrl = create_manual_iyzico_checkout_url($repo, $request, null, 'manual-public');
        redirect($checkoutUrl);
    } catch (Throwable $e) {
        $_SESSION['manual_payment_error'] = handle_caught_error($e, 'manual_payment_tax');
        redirect('/pay/' . rawurlencode($token) . '#tax-required');
    }
}

function handle_manual_payment_public(string $method, string $token): void
{
    $repo = new PaymentRequestRepository();
    $request = $repo->findByToken($token);
    if (!$request) {
        http_response_code(404);
        render_public_layout('Ödeme talebi', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Ödeme talebi</p>
                <h1>Bağlantı bulunamadı.</h1>
                <p class="muted">Bu ödeme bağlantısı sistemde bulunamadı veya kaldırıldı.</p>
            </section>
            <?php
        });
        return;
    }

    if ((string) ($request['status'] ?? 'pending') === 'cancelled') {
        http_response_code(410);
        render_public_layout('Ödeme talebi', static function (): void {
            ?>
            <section class="public-card payment-result-card error">
                <p class="eyebrow">Ödeme talebi</p>
                <h1>Bu ödeme bağlantısı iptal edildi.</h1>
                <p class="muted">Yeni ödeme linki için firma yetkilisiyle iletişime geçebilirsiniz.</p>
            </section>
            <?php
        });
        return;
    }

    if ((string) ($request['status'] ?? 'pending') === 'refunded') {
        render_public_layout('Ödeme talebi', static function () use ($request): void {
            ?>
            <section class="public-card payment-result-card warning">
                <p class="eyebrow">Ödeme talebi</p>
                <h1>Bu ödeme iade edildi.</h1>
                <p class="muted"><?= h((string) ($request['title'] ?? 'Manuel ödeme talebi')) ?></p>
                <div class="payment-choice-summary">
                    <div>
                        <span>Talep no</span>
                        <strong><?= h(manual_payment_request_number($request)) ?></strong>
                    </div>
                    <div>
                        <span>İade edilen tutar</span>
                        <strong><?= h(money_format_local($request['amount'] ?? null, (string) ($request['currency'] ?? 'TRY'))) ?></strong>
                    </div>
                </div>
                <?php if (trim((string) ($request['refund_note'] ?? '')) !== ''): ?>
                    <div class="settings-note">
                        <strong>İade notu</strong>
                        <span><?= nl2br(h((string) $request['refund_note']), false) ?></span>
                    </div>
                <?php endif; ?>
            </section>
            <?php
        });
        return;
    }

    $error = (string) ($_SESSION['manual_payment_error'] ?? '');
    unset($_SESSION['manual_payment_error']);
    $exchangeRates = ExchangeRates::latest();
    $settings = (new SettingsRepository())->all();
    $iyzicoReady = (string) ($settings['iyzico.enabled'] ?? '0') === '1'
        && trim((string) ($settings['iyzico.api_key'] ?? '')) !== ''
        && trim((string) ($settings['iyzico.secret_key'] ?? '')) !== '';
    if ((string) ($request['status'] ?? 'pending') !== 'paid' && $iyzicoReady) {
        $request = manual_payment_autofill_tax_number($repo, $request);
    }

    render_public_layout('Ödeme talebi', static function () use ($request, $error, $exchangeRates, $iyzicoReady): void {
        $amount = (float) ($request['amount'] ?? 0);
        $currency = (string) ($request['currency'] ?? 'TRY');
        $isPaid = (string) ($request['status'] ?? 'pending') === 'paid';
        $needsEmail = !$isPaid && $iyzicoReady && manual_payment_needs_email($request);
        $needsTax = !$isPaid && $iyzicoReady && manual_payment_needs_tax_number($request);
        $emailError = manual_payment_error_is_missing_email($error);
        $taxError = manual_payment_error_is_missing_tax($error);
        $visibleError = ($emailError || $taxError) ? '' : $error;
        ?>
        <section class="login-panel customer-info-public payment-choice-public manual-payment-public">
            <div class="login-heading">
                <p class="eyebrow">Ödeme talebi</p>
                <h1><?= $isPaid ? 'Ödemeniz alınmıştır.' : 'Ödeme talebinizi tamamlayın.' ?></h1>
                <p class="muted compact"><?= h((string) ($request['title'] ?? 'Manuel ödeme talebi')) ?></p>
            </div>

            <?php if ($visibleError !== ''): ?>
                <div class="alert error"><?= h($visibleError) ?></div>
            <?php endif; ?>

            <?php if ($isPaid): ?>
                <div class="alert success">Bu ödeme talebi başarıyla tahsil edilmiş görünüyor.</div>
            <?php elseif (!$iyzicoReady): ?>
                <div class="alert error">Kredi kartı ödeme altyapısı şu anda hazır değil. Lütfen firma yetkilisiyle iletişime geçin.</div>
            <?php elseif ($needsEmail || $emailError): ?>
                <div class="alert warning" id="email-required">Kredi kartı ödemesine devam etmek için e-posta adresinizi girin.</div>
            <?php elseif ($needsTax || $taxError): ?>
                <div class="alert warning" id="tax-required">Kredi kartı ödemesine devam etmek için vergi no / TC kimlik no bilgisini tamamlayın.</div>
            <?php endif; ?>

            <div class="payment-choice-summary">
                <div>
                    <span>Talep no</span>
                    <strong><?= h(manual_payment_request_number($request)) ?></strong>
                </div>
                <div>
                    <span>Toplam tutar</span>
                    <strong><?= h(money_format_local($amount, $currency)) ?></strong>
                </div>
            </div>

            <?= render_payment_exchange_panel($amount, $currency, $exchangeRates) ?>

            <?php if (trim((string) ($request['description'] ?? '')) !== ''): ?>
                <div class="settings-note manual-payment-description">
                    <strong>Açıklama</strong>
                    <span><?= nl2br(h((string) $request['description']), false) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$isPaid && ($needsEmail || $emailError)): ?>
                <form method="post" action="<?= h(url('/pay/' . rawurlencode((string) $request['public_token']) . '/email')) ?>" class="manual-payment-email-form">
                    <?= csrf_field() ?>
                    <label>
                        E-posta adresiniz
                        <input type="email" name="customer_email" value="<?= h((string) ($request['customer_email'] ?? '')) ?>" placeholder="ornek@firma.com" required>
                    </label>
                    <button type="submit" class="button primary full">E-postayı kaydet ve kredi kartı ile öde</button>
                </form>
            <?php elseif (!$isPaid && ($needsTax || $taxError)): ?>
                <form method="post" action="<?= h(url('/pay/' . rawurlencode((string) $request['public_token']) . '/tax')) ?>" class="manual-payment-email-form">
                    <?= csrf_field() ?>
                    <label>
                        Vergi no / TC kimlik no
                        <input name="customer_tax_number" value="<?= h((string) ($request['customer_tax_number'] ?? '')) ?>" inputmode="numeric" minlength="10" maxlength="11" placeholder="10 veya 11 hane" required>
                    </label>
                    <button type="submit" class="button primary full">Bilgiyi kaydet ve kredi kartı ile öde</button>
                </form>
            <?php elseif (!$isPaid): ?>
                <form method="post" action="<?= h(url('/pay/' . rawurlencode((string) $request['public_token']) . '/card')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="button primary full" <?= $iyzicoReady ? '' : 'disabled' ?>>Kredi kartı ile öde</button>
                </form>
            <?php endif; ?>
        </section>
        <?php
    });
}

function manual_payment_needs_email(array $request): bool
{
    $email = trim((string) ($request['customer_email'] ?? ''));

    return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false;
}

function manual_payment_tax_number(array $request): string
{
    $taxNumber = PaymentRequestRepository::normalizeTaxNumber((string) ($request['customer_tax_number'] ?? ''));
    if ($taxNumber !== '') {
        return $taxNumber;
    }

    return PaymentRequestRepository::extractTaxNumberFromText(implode(' ', [
        (string) ($request['customer_name'] ?? ''),
        (string) ($request['company_name'] ?? ''),
        (string) ($request['title'] ?? ''),
        (string) ($request['description'] ?? ''),
    ]));
}

function manual_payment_autofill_tax_number(PaymentRequestRepository $repo, array $request): array
{
    if (PaymentRequestRepository::normalizeTaxNumber((string) ($request['customer_tax_number'] ?? '')) !== '') {
        return $request;
    }

    $taxNumber = $repo->resolveTaxNumberForRequest($request);
    if ($taxNumber === '') {
        return $request;
    }

    return $repo->updatePublicTaxNumber((int) ($request['id'] ?? 0), $taxNumber) ?? $request;
}

function manual_payment_needs_tax_number(array $request): bool
{
    return manual_payment_tax_number($request) === '';
}

function manual_payment_error_is_missing_email(string $error): bool
{
    $error = mb_strtolower(trim($error), 'UTF-8');
    if ($error === '') {
        return false;
    }

    return str_contains($error, 'e-posta')
        || str_contains($error, 'e post')
        || str_contains($error, 'email')
        || str_contains($error, 'e-postasi');
}

function manual_payment_error_is_missing_tax(string $error): bool
{
    $error = mb_strtolower(trim($error), 'UTF-8');
    if ($error === '') {
        return false;
    }

    return str_contains($error, 'vergi')
        || str_contains($error, 'tc kimlik')
        || str_contains($error, 'kimlik no')
        || str_contains($error, 'identity');
}

function handle_iyzico_callback(string $method): void
{
    $token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(422);
        render_public_layout('Ödeme sonucu', static function (): void {
            ?>
            <section class="public-card payment-result-card">
                <p class="eyebrow">iyzico</p>
                <h1>Ödeme sonucu okunamadı.</h1>
                <p class="muted">iyzico token bilgisi gelmedi. Ödeme sayfasindan tekrar deneyin.</p>
            </section>
            <?php
        });
        return;
    }

    $repo = new RenewalRepository();
    $payment = $repo->findIyzicoPaymentByToken($token);
    if (!$payment) {
        $manualRepo = new PaymentRequestRepository();
        $manualPayment = $manualRepo->findIyzicoPaymentByToken($token);
        if ($manualPayment) {
            handle_manual_iyzico_callback_result($manualRepo, $manualPayment, $token);
            return;
        }

        http_response_code(404);
        render_public_layout('Ödeme sonucu', static function (): void {
            ?>
            <section class="public-card payment-result-card">
                <p class="eyebrow">iyzico</p>
                <h1>Ödeme kaydı bulunamadı.</h1>
                <p class="muted">Bu token için sistemde kayıt yok. Lütfen firma yetkilisine bilgi verin.</p>
            </section>
            <?php
        });
        return;
    }

    $request = [];
    $response = [];
    $localStatus = 'failed';
    $message = 'Ödeme sonucu alınamadı.';

    try {
        $client = new IyzicoClient(new SettingsRepository());
        $result = $client->retrieveCheckout($token, (string) $payment['conversation_id']);
        $request = $result['request'];
        $response = $result['response'];
        $localStatus = iyzico_local_status($response, (float) ($payment['amount'] ?? 0));
        $message = iyzico_result_message($localStatus, $response);
        $repo->updateIyzicoPaymentResult((int) $payment['id'], $request, $response, $localStatus);
        if ($localStatus === 'paid') {
            $notificationResult = notify_payment_received(
                $payment,
                $response,
                'renewal',
                empty($payment['internal_push_sent_at']),
                empty($payment['internal_mail_sent_at'])
            );
            $repo->markPaymentInternalNotification(
                (int) $payment['id'],
                (int) ($notificationResult['push_sent'] ?? 0) > 0,
                !empty($notificationResult['mail_ok']),
                payment_internal_notification_error($notificationResult)
            );
        }
        if ($localStatus === 'paid' && (string) ($payment['status'] ?? '') !== 'paid') {
            notify_customer_payment_received($payment, $response, 'renewal');
        } elseif ($localStatus !== 'paid') {
            notify_payment_failed($payment, $response, 'renewal', $message, $localStatus);
        }
        if ($localStatus === 'paid') {
            mark_paid_renewal_card_payment_choice($repo, $payment);
            try {
                $finalizeResult = finalize_paid_renewal_after_card_payment($repo, $payment);
                if (empty($finalizeResult['ok'])) {
                    error_log('Ödeme sonrası yenileme/fatura tamamlanamadı: ' . (string) ($finalizeResult['error'] ?? 'Bilinmeyen hata'));
                }
            } catch (Throwable $finalizeError) {
                error_log('Ödeme sonrası yenileme/fatura tamamlanamadı: ' . $finalizeError->getMessage());
            }
            sync_parasut_invoice_note_for_latest_paid_renewal($repo, (int) ($payment['renewal_id'] ?? 0));
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $repo->updateIyzicoPaymentResult((int) $payment['id'], $request, ['errorMessage' => $message], 'failed');
        notify_payment_failed($payment, ['errorMessage' => $message], 'renewal', $message, 'failed');
    }

    $exchangeRates = ExchangeRates::latest();

    render_public_layout('Ödeme sonucu', static function () use ($payment, $localStatus, $message, $exchangeRates): void {
        $tone = $localStatus === 'paid' ? 'success' : ($localStatus === 'review' ? 'warning' : 'error');
        $amount = (float) ($payment['amount'] ?? 0);
        $currency = (string) ($payment['currency'] ?? 'TRY');
        ?>
        <section class="public-card payment-result-card <?= h($tone) ?>">
            <p class="eyebrow">iyzico ödeme sonucu</p>
            <h1><?= $localStatus === 'paid' ? 'Ödeme alındı.' : 'Ödeme tamamlanamadı.' ?></h1>
            <p><?= h($message) ?></p>
            <div class="payment-result-summary">
                <span>Müşteri</span>
                <strong><?= h($payment['company_name'] ?? '-') ?></strong>
                <span>Kayıt</span>
                <strong><?= h($payment['title'] ?? '-') ?></strong>
                <span>Tutar</span>
                <strong><?= h(money_format_local($payment['amount'] ?? null, $currency)) ?></strong>
            </div>
            <?= render_payment_exchange_panel($amount, $currency, $exchangeRates) ?>
        </section>
        <?php
    });
}

function handle_manual_iyzico_callback_result(PaymentRequestRepository $repo, array $payment, string $token): void
{
    $request = [];
    $response = [];
    $localStatus = 'failed';
    $message = 'Ödeme sonucu alınamadı.';

    try {
        $client = new IyzicoClient(new SettingsRepository());
        $result = $client->retrieveCheckout($token, (string) $payment['conversation_id']);
        $request = $result['request'];
        $response = $result['response'];
        $localStatus = iyzico_local_status($response, (float) ($payment['amount'] ?? 0));
        $message = iyzico_result_message($localStatus, $response);
        $repo->updateIyzicoPaymentResult((int) $payment['id'], $request, $response, $localStatus);
        if ($localStatus === 'paid') {
            $notificationResult = notify_payment_received(
                $payment,
                $response,
                'manual',
                empty($payment['internal_push_sent_at']),
                empty($payment['internal_mail_sent_at'])
            );
            $repo->markPaymentInternalNotification(
                (int) $payment['id'],
                (int) ($notificationResult['push_sent'] ?? 0) > 0,
                !empty($notificationResult['mail_ok']),
                payment_internal_notification_error($notificationResult)
            );
        }
        if ($localStatus === 'paid' && (string) ($payment['status'] ?? '') !== 'paid') {
            notify_customer_payment_received($payment, $response, 'manual');
        } elseif ($localStatus !== 'paid') {
            notify_payment_failed($payment, $response, 'manual', $message, $localStatus);
        }
        if ($localStatus === 'paid') {
            finalize_sales_offer_after_manual_payment((int) ($payment['request_id'] ?? 0));
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $repo->updateIyzicoPaymentResult((int) $payment['id'], $request, ['errorMessage' => $message], 'failed');
        notify_payment_failed($payment, ['errorMessage' => $message], 'manual', $message, 'failed');
    }

    $exchangeRates = ExchangeRates::latest();

    render_public_layout('Ödeme sonucu', static function () use ($payment, $localStatus, $message, $exchangeRates): void {
        $tone = $localStatus === 'paid' ? 'success' : ($localStatus === 'review' ? 'warning' : 'error');
        $amount = (float) ($payment['amount'] ?? 0);
        $currency = (string) ($payment['currency'] ?? 'TRY');
        ?>
        <section class="public-card payment-result-card <?= h($tone) ?>">
            <p class="eyebrow">iyzico ödeme sonucu</p>
            <h1><?= $localStatus === 'paid' ? 'Ödeme alındı.' : 'Ödeme tamamlanamadı.' ?></h1>
            <p><?= h($message) ?></p>
            <div class="payment-result-summary">
                <span>Talep no</span>
                <strong><?= h(manual_payment_request_number(['id' => $payment['request_id'] ?? 0])) ?></strong>
                <span>Kayıt</span>
                <strong><?= h($payment['title'] ?? '-') ?></strong>
                <span>Tutar</span>
                <strong><?= h(money_format_local($payment['amount'] ?? null, $currency)) ?></strong>
            </div>
            <?= render_payment_exchange_panel($amount, $currency, $exchangeRates) ?>
        </section>
        <?php
    });
}

function mark_paid_renewal_card_payment_choice(RenewalRepository $repo, array $payment): void
{
    $renewalId = (int) ($payment['renewal_id'] ?? 0);
    if ($renewalId < 1) {
        return;
    }

    $method = 'Kredi kartı';
    foreach ($repo->paymentMethods() as $paymentMethod) {
        if (payment_method_is_credit_card((string) ($paymentMethod['name'] ?? ''))) {
            $method = (string) $paymentMethod['name'];
            break;
        }
    }

    $repo->setRenewalPaymentMethod($renewalId, $method, (string) ($payment['customer_email'] ?? ''));
}

function finalize_sales_offer_after_manual_payment(int $paymentRequestId): void
{
    if ($paymentRequestId < 1) {
        return;
    }

    try {
        $repo = new RenewalRepository();
        $offer = $repo->markSalesOfferApprovedAfterPaymentRequest($paymentRequestId);
        if ($offer) {
            notify_sales_offer_response($offer, 'approved');
        }
    } catch (Throwable $e) {
        error_log('Ödeme sonrası teklif onayı tamamlanamadı: ' . $e->getMessage());
    }
}

function iyzico_local_status(array $response, ?float $expectedAmount = null): string
{
    if ((string) ($response['status'] ?? '') !== 'success') {
        return 'failed';
    }

    $paymentStatus = strtoupper((string) ($response['paymentStatus'] ?? ''));
    $fraudStatus = (string) ($response['fraudStatus'] ?? '');
    $paymentId = trim((string) ($response['paymentId'] ?? ''));
    $paidPriceRaw = $response['paidPrice'] ?? $response['price'] ?? null;
    $amountMatches = true;

    if ($expectedAmount !== null && $expectedAmount > 0 && is_numeric($paidPriceRaw)) {
        $amountMatches = abs((float) $paidPriceRaw - $expectedAmount) < 0.01;
    }

    if ($paymentStatus === 'SUCCESS' && $paymentId !== '' && $amountMatches && ($fraudStatus === '' || $fraudStatus === '1')) {
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
        return 'Kredi kartı ödemesi başarıyla tamamlandı.';
    }

    if ($status === 'review') {
        return 'Ödeme alındı fakat iyzico tarafında kontrol bekliyor.';
    }

    $errorMessage = trim((string) ($response['errorMessage'] ?? ''));
    $errorCode = trim((string) ($response['errorCode'] ?? ''));
    $errorGroup = trim((string) ($response['errorGroup'] ?? ''));
    $normalizedError = mb_strtolower($errorMessage . ' ' . $errorCode . ' ' . $errorGroup, 'UTF-8');

    if (
        str_contains($normalizedError, 'kategori kodu')
        || str_contains($normalizedError, '10208')
        || str_contains($normalizedError, 'invalid_merchant_or_sp')
    ) {
        return 'Kart ödeme altyapısında üye işyeri kategori tanımı kontrol gerektiriyor. Kartınızdan tahsilat alınmadı. Lütfen firma yetkilisiyle iletişime geçin veya farklı bir ödeme yöntemi deneyin.';
    }

    if ($errorMessage !== '') {
        return $errorMessage;
    }

    return 'Ödeme tamamlanamadı veya iptal edildi.';
}

function notify_manual_payment_request_created(array $request): void
{
    try {
        $amount = money_format_local($request['amount'] ?? null, (string) ($request['currency'] ?? 'TRY'));
        $customer = trim((string) (($request['customer_name'] ?? '') ?: ($request['customer_email'] ?? '') ?: 'Müşteri bilgisi yok'));
        send_internal_push_notification(
            'Ödeme talebi oluşturuldu',
            trim((string) ($request['title'] ?? 'Ödeme talebi')) . ' · ' . $amount . ' · ' . $customer,
            '/payment-requests?created=' . (int) ($request['id'] ?? 0),
            'manual-payment-request-' . (int) ($request['id'] ?? 0)
        );
    } catch (Throwable $e) {
        error_log('Ödeme talebi push bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function notify_payment_received(array $payment, array $response, string $source, bool $sendPush = true, bool $sendMail = true): array
{
    $result = [
        'push_sent' => 0,
        'push_failed' => 0,
        'push_total' => 0,
        'push_error' => '',
        'mail_ok' => false,
        'mail_error' => '',
    ];

    try {
        $context = payment_received_notification_context($payment, $response, $source);
        if ($sendPush) {
            $pushResult = send_internal_push_notification(
                'Ödeme geldi',
                $context['customer'] . ' · ' . $context['amount'],
                $context['url'],
                'payment-received-' . $context['source'] . '-' . $context['record_id'] . '-' . hash('sha1', (string) ($context['payment_id'] ?? '') . '|' . (string) ($payment['id'] ?? ''))
            );
            $result['push_sent'] = (int) ($pushResult['sent'] ?? 0);
            $result['push_failed'] = (int) ($pushResult['failed'] ?? 0);
            $result['push_total'] = (int) ($pushResult['total'] ?? 0);
            $result['push_error'] = (string) ($pushResult['last_error'] ?? '');
        }
        if ($sendMail) {
            $mailResult = send_internal_mail_notification(
                'Ödeme geldi: ' . $context['title'],
                payment_received_mail_body($context)
            );
            $result['mail_ok'] = !empty($mailResult['ok']);
            $result['mail_error'] = (string) ($mailResult['error'] ?? '');
        } else {
            $result['mail_ok'] = true;
        }
        if (!$sendPush) {
            $result['push_sent'] = 1;
        }
    } catch (Throwable $e) {
        $result['push_error'] = $e->getMessage();
        error_log('Ödeme alındı bildirimi gönderilemedi: ' . $e->getMessage());
    }

    return $result;
}

function notify_customer_payment_received(array $payment, array $response, string $source): void
{
    try {
        $context = payment_received_notification_context($payment, $response, $source);
        $recipients = payment_customer_confirmation_recipients($payment, $source);
        if ($recipients === []) {
            return;
        }

        $subject = 'Ödeme alındı: ' . $context['title'];
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $body = customer_payment_received_mail_body($context, $recipient, $payment, $response);
            $result = Mailer::sendWithResult($email, $subject, $body, true);
            log_customer_payment_confirmation(
                $source,
                $payment,
                $email,
                $subject,
                (string) ($result['body'] ?? $body),
                $result
            );
        }
    } catch (Throwable $e) {
        error_log('Müşteri ödeme alındı bilgilendirmesi gönderilemedi: ' . $e->getMessage());
    }
}

function payment_internal_notification_error(array $result): string
{
    $errors = [];
    if ((int) ($result['push_sent'] ?? 0) < 1) {
        $errors[] = 'push: ' . ((string) ($result['push_error'] ?? '') ?: (((int) ($result['push_total'] ?? 0) < 1) ? 'aktif abonelik yok' : 'gönderilemedi'));
    }
    if (array_key_exists('mail_ok', $result) && empty($result['mail_ok'])) {
        $errors[] = 'mail: ' . ((string) ($result['mail_error'] ?? '') ?: 'gönderilemedi');
    }

    return mb_substr(implode(' | ', $errors), 0, 1000);
}

function payment_customer_confirmation_recipients(array $payment, string $source): array
{
    $recipients = [];

    if ($source === 'renewal') {
        try {
            $renewalId = (int) ($payment['renewal_id'] ?? 0);
            $renewal = $renewalId > 0 ? (new RenewalRepository())->find($renewalId) : null;
            if ($renewal) {
                foreach (renewal_customer_contacts($renewal) as $contact) {
                    payment_add_customer_confirmation_recipient(
                        $recipients,
                        (string) ($contact['email'] ?? ''),
                        (string) ($contact['full_name'] ?? $contact['name'] ?? '')
                    );
                }
            }
        } catch (Throwable) {
            // Fallback e-posta aşağıda yine değerlendirilecek.
        }
    } else {
        try {
            $customerId = (int) ($payment['customer_id'] ?? 0);
            $customer = $customerId > 0 ? (new RenewalRepository())->findCustomer($customerId) : null;
            if ($customer) {
                payment_add_customer_confirmation_recipient(
                    $recipients,
                    (string) ($customer['email'] ?? ''),
                    (string) (($customer['contact_name'] ?? '') ?: ($customer['company_name'] ?? ''))
                );
                foreach (($customer['contacts'] ?? []) as $contact) {
                    if (!is_array($contact)) {
                        continue;
                    }
                    payment_add_customer_confirmation_recipient(
                        $recipients,
                        (string) ($contact['email'] ?? ''),
                        (string) ($contact['full_name'] ?? '')
                    );
                }
            }
        } catch (Throwable) {
            // Manuel talebin kendi alıcıları aşağıda kullanılmaya devam eder.
        }

        foreach (manual_payment_request_recipients($payment) as $recipient) {
            payment_add_customer_confirmation_recipient(
                $recipients,
                (string) ($recipient['email'] ?? ''),
                (string) ($recipient['name'] ?? '')
            );
        }
    }

    payment_add_customer_confirmation_recipient(
        $recipients,
        (string) ($payment['customer_email'] ?? ''),
        (string) (($payment['contact_name'] ?? '') ?: ($payment['customer_name'] ?? $payment['company_name'] ?? ''))
    );

    return array_values($recipients);
}

function payment_add_customer_confirmation_recipient(array &$recipients, string $email, string $name = ''): void
{
    $email = trim(mb_strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $name = trim($name);
    if (isset($recipients[$email])) {
        if ($name !== '' && (string) ($recipients[$email]['name'] ?? '') === $email) {
            $recipients[$email]['name'] = $name;
        }
        return;
    }

    $recipients[$email] = [
        'name' => $name !== '' ? $name : $email,
        'email' => $email,
    ];
}

function customer_payment_received_mail_body(array $context, array $recipient, array $payment, array $response): string
{
    $recipientName = trim((string) ($recipient['name'] ?? ''));
    $greeting = $recipientName !== '' && !str_contains($recipientName, '@')
        ? 'Merhaba ' . $recipientName . ','
        : 'Merhaba,';
    $paymentId = trim((string) (($response['paymentId'] ?? '') ?: ($payment['payment_id'] ?? '')));
    $paidAt = !empty($payment['paid_at']) ? strtotime((string) $payment['paid_at']) : time();
    $referenceLabel = $context['source'] === 'manual' ? 'Talep no' : 'Kayıt no';
    $referenceValue = $context['source'] === 'manual'
        ? manual_payment_request_number(['id' => $context['record_id'] ?? 0, 'created_at' => $payment['created_at'] ?? ''])
        : '#' . (string) ($context['record_id'] ?? 0);

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;">Ödeme bilgilendirmesi</p>'
        . '<h1 style="margin:0 0 12px;font-size:26px;line-height:1.18;color:#17201c;">Ödemeniz başarıyla alındı.</h1>'
        . '<p style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . h($greeting) . '<br>'
        . h($context['customer']) . ' adına yapılan ödeme sistemimize başarılı olarak ulaşmıştır. Bu bilgilendirme, cari kartında kayıtlı yetkililerin süreci aynı anda takip edebilmesi için gönderilmiştir.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . manual_mail_row('Durum', 'Ödeme alındı')
        . manual_mail_row($referenceLabel, $referenceValue)
        . manual_mail_row('Müşteri', (string) $context['customer'])
        . manual_mail_row('Kayıt', (string) $context['title'])
        . manual_mail_row('Tutar', (string) $context['amount'])
        . manual_mail_row('Ödeme tarihi', date('d.m.Y H:i', $paidAt ?: time()))
        . manual_mail_row('Ödeme referansı', $paymentId !== '' ? $paymentId : '-')
        . '</table>'
        . '<p style="margin:18px 0 0;color:#61726c;font-size:13px;line-height:1.5;">Bu mail bilgi amaçlıdır; ayrıca işlem yapmanız gerekmez.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function log_customer_payment_confirmation(string $source, array $payment, string $email, string $subject, string $body, array $result): void
{
    try {
        $status = !empty($result['ok']) ? 'sent' : 'failed';
        $error = !empty($result['ok']) ? null : (string) ($result['error'] ?? 'Mail gönderilemedi.');

        if ($source === 'manual') {
            $requestId = (int) ($payment['request_id'] ?? 0);
            if ($requestId > 0) {
                (new PaymentRequestRepository())->logDelivery($requestId, 'payment-confirmation', $email, $subject, $body, $status, $error);
            }
            return;
        }

        $renewalId = (int) ($payment['renewal_id'] ?? 0);
        if ($renewalId > 0) {
            (new RenewalRepository())->logMail($renewalId, $email, $subject, $body, $status, $error, false);
        }
    } catch (Throwable $e) {
        error_log('Müşteri ödeme bilgilendirme logu yazılamadı: ' . $e->getMessage());
    }
}

function notify_payment_failed(array $payment, array $response, string $source, string $message, string $status): void
{
    try {
        $context = payment_received_notification_context($payment, $response, $source);
        $reason = trim($message);
        if ($reason === '') {
            $reason = trim((string) ($response['errorMessage'] ?? 'Ödeme tamamlanamadı.'));
        }
        $reason = payment_failure_public_reason($reason, $response);
        $title = $status === 'review' ? 'Ödeme kontrol bekliyor' : 'Ödeme başarısız';
        $tagHash = hash('sha1', $context['source'] . '|' . $context['record_id'] . '|' . (string) ($payment['id'] ?? '') . '|' . $reason);

        send_internal_push_notification(
            $title,
            mb_substr($context['customer'] . ' · ' . $context['amount'] . ' · ' . $reason, 0, 180),
            $context['url'],
            'payment-failed-' . $tagHash
        );
        send_internal_mail_notification(
            $title . ': ' . $context['title'],
            payment_failed_mail_body($context, $reason, $response)
        );
    } catch (Throwable $e) {
        error_log('Ödeme hata bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function notify_renewal_notification_read(array $read): void
{
    try {
        $renewalId = (int) ($read['renewal_id'] ?? 0);
        $reader = trim((string) (($read['recipient_name'] ?? '') ?: ($read['recipient_email'] ?? 'Müşteri yetkilisi')));
        $company = trim((string) (($read['company_name'] ?? '') ?: supplier_customer_label($read)));
        $item = trim((string) (($read['item_summary'] ?? '') ?: ($read['title'] ?? 'Yenileme')));
        send_internal_push_notification(
            'Yenileme bildirimi okundu',
            $reader . ' · ' . $company . ' · ' . $item,
            $renewalId > 0 ? '/renewals/' . $renewalId . '/edit' : '/',
            'renewal-read-' . $renewalId . '-' . hash('sha1', (string) ($read['recipient_email'] ?? ''))
        );
    } catch (Throwable $e) {
        error_log('Yenileme okundu push bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function notify_supplier_quote_opened(array $request): void
{
    try {
        $renewalId = (int) ($request['renewal_id'] ?? 0);
        $supplier = trim((string) (($request['supplier_display'] ?? '') ?: ($request['supplier_name'] ?? '') ?: ($request['recipient_email'] ?? 'Tedarikçi')));
        $company = supplier_customer_label($request);
        $item = trim((string) (($request['item_summary'] ?? '') ?: ($request['title'] ?? 'Yenileme')));
        $quoteNo = trim((string) ($request['quote_number'] ?? ''));
        send_internal_push_notification(
            'Tedarikçi teklif formunu açtı',
            trim(($quoteNo !== '' ? $quoteNo . ' · ' : '') . $supplier . ' · ' . $company . ' · ' . $item),
            $renewalId > 0 ? '/renewals/' . $renewalId . '/edit#supplier-quotes' : '/',
            'supplier-quote-opened-' . (int) ($request['id'] ?? 0)
        );
    } catch (Throwable $e) {
        error_log('Tedarikçi teklif açıldı push bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function notify_supplier_quote_submitted(array $request): void
{
    try {
        $renewalId = (int) ($request['renewal_id'] ?? 0);
        $supplier = trim((string) (($request['supplier_display'] ?? '') ?: ($request['supplier_name'] ?? '') ?: ($request['recipient_email'] ?? 'Tedarikçi')));
        $company = supplier_customer_label($request);
        $quoteNo = trim((string) ($request['quote_number'] ?? ''));
        send_internal_push_notification(
            'Tedarikçi teklif verdi',
            trim(($quoteNo !== '' ? $quoteNo . ' · ' : '') . $supplier . ' · ' . $company),
            $renewalId > 0 ? '/renewals/' . $renewalId . '/edit#supplier-quotes' : '/',
            'supplier-quote-submitted-' . (int) ($request['id'] ?? 0)
        );
    } catch (Throwable $e) {
        error_log('Tedarikçi teklif gönderildi push bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function notify_customer_offer_opened(array $offer): void
{
    try {
        $renewalId = (int) ($offer['renewal_id'] ?? 0);
        $company = trim((string) (($offer['company_name'] ?? '') ?: ($offer['recipient_email'] ?? 'Müşteri')));
        $offerNo = trim((string) ($offer['offer_number'] ?? ''));
        $total = money_format_local($offer['total'] ?? null, (string) ($offer['currency'] ?? 'TRY'));
        $viewCount = (int) ($offer['view_count'] ?? 0);
        send_internal_push_notification(
            'Müşteri teklifi okudu',
            trim(($offerNo !== '' ? $offerNo . ' · ' : '') . $company . ' · ' . $total . ($viewCount > 0 ? ' · ' . $viewCount . '. görüntüleme' : '')),
            $renewalId > 0 ? '/renewals/' . $renewalId . '/edit#customer-offers' : '/',
            'customer-offer-opened-' . (int) ($offer['id'] ?? 0) . '-' . max(1, $viewCount)
        );
    } catch (Throwable $e) {
        error_log('Müşteri teklifi okundu push bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function notify_customer_offer_response(array $offer, string $decision, string $note = ''): void
{
    try {
        $renewalId = (int) ($offer['renewal_id'] ?? 0);
        $company = trim((string) (($offer['company_name'] ?? '') ?: ($offer['recipient_email'] ?? 'Müşteri')));
        $offerNo = trim((string) ($offer['offer_number'] ?? ''));
        $total = money_format_local($offer['total'] ?? null, (string) ($offer['currency'] ?? 'TRY'));
        $decisionLabel = match ($decision) {
            'approved' => 'onayladı',
            'revision_requested' => 'revize istedi',
            'rejected' => 'reddetti',
            default => 'yanıtladı',
        };
        $note = trim($note);
        send_internal_push_notification(
            'Müşteri teklifi ' . $decisionLabel,
            trim(($offerNo !== '' ? $offerNo . ' · ' : '') . $company . ' · ' . $total . ($note !== '' ? ' · ' . mb_substr($note, 0, 80) : '')),
            $renewalId > 0 ? '/renewals/' . $renewalId . '/edit#customer-offers' : '/',
            'customer-offer-response-' . (int) ($offer['id'] ?? 0) . '-' . $decision
        );
    } catch (Throwable $e) {
        error_log('Müşteri teklifi yanıt push bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function notify_sales_offer_opened(array $offer, ?array $delivery = null): void
{
    try {
        $offerId = (int) ($offer['id'] ?? 0);
        $customer = trim((string) (($offer['customer_name'] ?? '') ?: ($offer['customer_email'] ?? 'Müşteri')));
        $reader = $delivery ? sales_offer_delivery_recipient_label($delivery) : '';
        $total = money_format_local($offer['total'] ?? null, (string) ($offer['currency'] ?? 'TRY'));
        $viewCount = max(1, (int) ($delivery['view_count'] ?? 1));
        send_internal_push_notification(
            'Teklif açıldı',
            sales_offer_number($offer) . ' · ' . ($reader !== '' ? $reader . ' · ' : '') . $customer . ' · ' . $total . ' · ' . $viewCount . '. görüntüleme',
            '/',
            'sales-offer-opened-' . $offerId . '-' . (int) ($delivery['id'] ?? 0) . '-' . $viewCount
        );
    } catch (Throwable $e) {
        error_log('Satış teklifi açıldı push bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function notify_sales_offer_response(array $offer, string $decision): void
{
    try {
        $offerId = (int) ($offer['id'] ?? 0);
        $customer = trim((string) (($offer['customer_name'] ?? '') ?: ($offer['customer_email'] ?? 'Müşteri')));
        $total = money_format_local($offer['total'] ?? null, (string) ($offer['currency'] ?? 'TRY'));
        $decisionLabel = $decision === 'approved' ? 'onaylandı' : 'yanıtlandı';
        send_internal_push_notification(
            'Teklif ' . $decisionLabel,
            sales_offer_number($offer) . ' · ' . $customer . ' · ' . $total,
            '/',
            'sales-offer-response-' . $offerId . '-' . $decision
        );
    } catch (Throwable $e) {
        error_log('Satış teklifi yanıt push bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function payment_received_notification_context(array $payment, array $response, string $source): array
{
    $isManual = $source === 'manual';
    $id = (int) ($isManual ? ($payment['request_id'] ?? 0) : ($payment['renewal_id'] ?? 0));
    $title = trim((string) ($payment['title'] ?? ($isManual ? 'Manuel ödeme talebi' : 'Yenileme')));
    $customer = trim((string) (
        $isManual
            ? (($payment['customer_name'] ?? '') ?: ($payment['customer_email'] ?? ''))
            : ($payment['company_name'] ?? '')
    ));
    $currency = normalize_allowed_currency((string) ($payment['currency'] ?? 'TRY'));
    $amount = money_format_local($payment['amount'] ?? null, $currency);
    $paymentId = trim((string) (($response['paymentId'] ?? '') ?: ($payment['payment_id'] ?? '')));

    return [
        'title' => $title !== '' ? $title : ($isManual ? 'Manuel ödeme talebi' : 'Yenileme'),
        'customer' => $customer !== '' ? $customer : 'Müşteri bilgisi yok',
        'amount' => $amount,
        'currency' => $currency,
        'payment_id' => $paymentId !== '' ? $paymentId : '-',
        'conversation_id' => (string) ($payment['conversation_id'] ?? '-'),
        'source_label' => $isManual ? 'Manuel ödeme talebi' : 'Yenileme kaydı',
        'source' => $isManual ? 'manual' : 'renewal',
        'record_id' => $id,
        'url' => $isManual ? '/payment-requests?created=' . $id : '/renewals/' . $id . '/edit#card-payment',
    ];
}

function payment_received_mail_body(array $context): string
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
        . '<p style="margin:0 0 20px;color:#607069;font-size:16px;line-height:1.5;">Sistemde başarılı kredi kartı ödemesi kaydedildi.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 22px;">' . $htmlRows . '</table>'
        . '<a href="' . h(url((string) $context['url'])) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:8px;">Panelde görüntüle</a>'
        . '</td></tr></table>'
        . '</td></tr></table></body></html>';
}

function payment_failure_public_reason(string $message, array $response): string
{
    $message = trim($message);
    $errorCode = trim((string) ($response['errorCode'] ?? ''));
    $errorGroup = trim((string) ($response['errorGroup'] ?? ''));
    $normalized = mb_strtolower($message . ' ' . $errorCode . ' ' . $errorGroup, 'UTF-8');

    if (
        str_contains($normalized, 'kategori kodu')
        || str_contains($normalized, '10208')
        || str_contains($normalized, 'invalid_merchant_or_sp')
    ) {
        return 'iyzico üye işyeri kategori tanımı kontrol gerektiriyor.';
    }

    return $message !== '' ? $message : 'Ödeme tamamlanamadı veya iptal edildi.';
}

function payment_failed_mail_body(array $context, string $reason, array $response): string
{
    $rows = [
        'Kaynak' => $context['source_label'],
        'Müşteri' => $context['customer'],
        'Kayıt' => $context['title'],
        'Tutar' => $context['amount'],
        'Hata' => $reason,
        'iyzico hata kodu' => trim((string) ($response['errorCode'] ?? '')) ?: '-',
        'iyzico grup' => trim((string) ($response['errorGroup'] ?? '')) ?: '-',
        'Conversation ID' => $context['conversation_id'],
    ];
    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 0;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;width:40%;">' . h($label) . '</td>'
            . '<td style="padding:10px 0;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:800;">' . h((string) $value) . '</td>'
            . '</tr>';
    }

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#b42318;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:28px;">'
        . '<p style="margin:0 0 8px;color:#b42318;font-size:13px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;">Ödeme hata bildirimi</p>'
        . '<h1 style="margin:0 0 12px;font-size:30px;line-height:1.1;color:#17201c;">Ödeme tamamlanamadı.</h1>'
        . '<p style="margin:0 0 20px;color:#607069;font-size:16px;line-height:1.5;">Müşteri kredi kartı ödeme adımında hata aldı. Karttan tahsilat alınmadı.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 22px;">' . $htmlRows . '</table>'
        . '<a href="' . h(url((string) $context['url'])) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:8px;">Panelde görüntüle</a>'
        . '</td></tr></table>'
        . '</td></tr></table></body></html>';
}

function send_internal_push_notification(string $title, string $body, string $urlPath, string $tag = ''): array
{
    return InternalNotifier::push($title, $body, $urlPath, $tag);
}

function send_internal_mail_notification(string $subject, string $body): array
{
    return InternalNotifier::mail($subject, $body);
}

function internal_notification_recipient(): ?array
{
    return InternalNotifier::recipient();
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
            ? 'Test bildirimi gönderildi.'
            : ($result['total'] < 1
                ? 'Aktif web push aboneliği bulunamadı.'
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
        echo json_encode(['ok' => false, 'message' => 'Oturum doğrulaması başarısız.'], JSON_UNESCAPED_UNICODE);
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
    $showChallenge = !empty($_SESSION['login_challenge_required']);
    $challengeSettings = login_security_settings();

    if ($method === 'POST') {
        verify_csrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $emailKey = normalize_login_email($email);

        try {
            $ipAddress = login_client_ip();
            $ipLock = login_ip_lock_status($ipAddress);
            $emailLock = $emailKey !== '' ? login_email_lock_status($emailKey) : null;

            if ($ipLock !== null) {
                $error = 'Bu IP adresi geçici olarak kilitlendi. Kalan süre: ' . human_login_lock_remaining($ipLock) . '.';
                log_security_event('login_blocked_ip', 'warning', 'Kilitli IP adresinden giriş denemesi engellendi.', [
                    'ip_address' => $ipAddress,
                    'email' => $emailKey,
                    'locked_until' => $ipLock->format('Y-m-d H:i:s'),
                ], true);
            } elseif ($emailLock !== null) {
                $error = 'Bu e-posta adresi için çok fazla hatalı deneme var. Kalan süre: ' . human_login_lock_remaining($emailLock) . '.';
                log_security_event('login_blocked_email', 'warning', 'Kilitli e-posta adresiyle giriş denemesi engellendi.', [
                    'ip_address' => $ipAddress,
                    'email' => $emailKey,
                    'locked_until' => $emailLock->format('Y-m-d H:i:s'),
                ], true);
            } elseif ($showChallenge && login_security_challenge_enabled($challengeSettings) && !verify_login_security_challenge($ipAddress, $challengeSettings)) {
                $error = 'Güvenlik doğrulamasını doğru tamamlayın.';
                require_login_security_challenge(true);
                log_security_event('login_challenge_failed', 'warning', 'Şüpheli girişte güvenlik doğrulaması başarısız oldu.', [
                    'ip_address' => $ipAddress,
                    'email' => $emailKey,
                ], true);
            } elseif (Auth::attempt($email, $password)) {
                clear_login_ip_attempts($ipAddress);
                if ($emailKey !== '') {
                    clear_login_email_attempts($emailKey);
                }
                clear_login_security_challenge();
                log_security_event('login_success', 'info', 'Kullanıcı panele giriş yaptı.', [
                    'ip_address' => $ipAddress,
                    'email' => $emailKey,
                ], false);
                flash('success', 'Hos geldiniz.');
                redirect(first_allowed_path() ?? '/');
            } else {
                $ipAttempt = record_failed_login_ip_attempt($ipAddress, $email);
                $emailAttempt = $emailKey !== ''
                    ? record_failed_login_email_attempt($emailKey, $ipAddress)
                    : ['failed_count' => 0, 'locked' => false, 'locked_until' => null];
                require_login_security_challenge();

                log_security_event('login_failed', 'warning', 'Hatalı kullanıcı girişi denemesi.', [
                    'ip_address' => $ipAddress,
                    'email' => $emailKey,
                    'ip_failed_count' => $ipAttempt['failed_count'],
                    'email_failed_count' => $emailAttempt['failed_count'],
                ], false);

                if ($ipAttempt['locked']) {
                    log_security_event('login_ip_locked', 'critical', 'IP adresi hatalı girişler nedeniyle kilitlendi.', [
                        'ip_address' => $ipAddress,
                        'email' => $emailKey,
                        'failed_count' => $ipAttempt['failed_count'],
                        'locked_until' => $ipAttempt['locked_until'],
                    ], true);
                    $error = (int) $ipAttempt['failed_count'] >= 10
                        ? '10 hatalı giriş nedeniyle bu IP adresi 1 gün kilitlendi.'
                        : '2 hatalı giriş nedeniyle bu IP adresi 10 dakika kilitlendi.';
                } elseif ($emailAttempt['locked']) {
                    log_security_event('login_email_locked', 'critical', 'E-posta adresi çoklu hatalı girişler nedeniyle kilitlendi.', [
                        'ip_address' => $ipAddress,
                        'email' => $emailKey,
                        'failed_count' => $emailAttempt['failed_count'],
                        'locked_until' => $emailAttempt['locked_until'],
                    ], true);
                    $error = (int) $emailAttempt['failed_count'] >= 10
                        ? '10 hatalı giriş nedeniyle bu e-posta adresi 1 gün kilitlendi.'
                        : '5 hatalı giriş nedeniyle bu e-posta adresi 30 dakika kilitlendi.';
                } else {
                    $error = 'E-posta veya şifre hatalı.';
                }
            }
        } catch (Throwable $e) {
            $error = 'Veritabanı bağlantısı kurulamadı. Kurulum adımlarını kontrol edin.';
        }

        $showChallenge = !empty($_SESSION['login_challenge_required']);
    }

    render_public_layout('Giris', static function () use ($error, $exchangeRates, $showChallenge, $challengeSettings): void {
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
                <?php if ($showChallenge && login_security_challenge_enabled($challengeSettings)): ?>
                    <?= render_login_security_challenge($challengeSettings) ?>
                <?php endif; ?>
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

function normalize_login_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    return substr($email, 0, 190);
}

function login_security_settings(): array
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

function login_security_challenge_enabled(array $settings): bool
{
    return (string) ($settings['security.challenge.enabled'] ?? '1') === '1';
}

function login_turnstile_configured(array $settings): bool
{
    return trim((string) ($settings['security.turnstile.site_key'] ?? '')) !== ''
        && trim((string) ($settings['security.turnstile.secret_key'] ?? '')) !== '';
}

function require_login_security_challenge(bool $refresh = false): void
{
    $_SESSION['login_challenge_required'] = 1;
    if ($refresh) {
        unset($_SESSION['login_math_challenge']);
    }
}

function clear_login_security_challenge(): void
{
    unset($_SESSION['login_challenge_required'], $_SESSION['login_math_challenge']);
}

function login_math_challenge(): array
{
    $challenge = $_SESSION['login_math_challenge'] ?? null;
    if (
        !is_array($challenge)
        || (int) ($challenge['expires_at'] ?? 0) < time()
        || trim((string) ($challenge['question'] ?? '')) === ''
        || trim((string) ($challenge['answer'] ?? '')) === ''
    ) {
        $a = random_int(4, 14);
        $b = random_int(3, 12);
        $challenge = [
            'question' => $a . ' + ' . $b,
            'answer' => (string) ($a + $b),
            'expires_at' => time() + 600,
        ];
        $_SESSION['login_math_challenge'] = $challenge;
    }

    return $challenge;
}

function render_login_security_challenge(array $settings): string
{
    ob_start();
    ?>
    <div class="login-security-box">
        <strong>Güvenlik doğrulaması</strong>
        <span>Hatalı deneme algılandı. Devam etmek için doğrulamayı tamamlayın.</span>
        <?php if (login_turnstile_configured($settings)): ?>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            <div class="cf-turnstile" data-sitekey="<?= h((string) $settings['security.turnstile.site_key']) ?>"></div>
        <?php else: ?>
            <?php $challenge = login_math_challenge(); ?>
            <label>
                <?= h((string) $challenge['question']) ?> sonucu
                <input name="security_answer" inputmode="numeric" autocomplete="off" required>
            </label>
        <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function verify_login_security_challenge(string $ipAddress, array $settings): bool
{
    if (login_turnstile_configured($settings)) {
        return verify_turnstile_challenge((string) ($_POST['cf-turnstile-response'] ?? ''), $ipAddress, (string) $settings['security.turnstile.secret_key']);
    }

    $challenge = $_SESSION['login_math_challenge'] ?? [];
    if (!is_array($challenge) || (int) ($challenge['expires_at'] ?? 0) < time()) {
        return false;
    }

    $posted = preg_replace('/\D+/', '', (string) ($_POST['security_answer'] ?? '')) ?? '';
    $expected = preg_replace('/\D+/', '', (string) ($challenge['answer'] ?? '')) ?? '';

    return $posted !== '' && $expected !== '' && hash_equals($expected, $posted);
}

function verify_turnstile_challenge(string $token, string $ipAddress, string $secret): bool
{
    $token = trim($token);
    $secret = trim($secret);
    if ($token === '' || $secret === '') {
        return false;
    }

    $payload = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $ipAddress,
    ]);

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
            if ($ch === false) {
                return false;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $payload,
                    'timeout' => 8,
                ],
            ]);
            $response = file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        }
    } catch (Throwable) {
        return false;
    }

    $decoded = json_decode((string) $response, true);

    return is_array($decoded) && !empty($decoded['success']);
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

    Database::connection()->exec(
        "CREATE TABLE IF NOT EXISTS login_email_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email_address VARCHAR(190) NOT NULL,
            last_ip VARCHAR(45) NULL,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            first_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_login_email_attempts_email (email_address),
            INDEX idx_login_email_attempts_locked_until (locked_until),
            INDEX idx_login_email_attempts_last_failed_at (last_failed_at),
            INDEX idx_login_email_attempts_last_ip (last_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    Database::connection()->exec(
        "CREATE TABLE IF NOT EXISTS security_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(80) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            ip_address VARCHAR(45) NULL,
            email VARCHAR(190) NULL,
            user_id INT UNSIGNED NULL,
            message TEXT NOT NULL,
            context_json MEDIUMTEXT NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_security_events_created (created_at),
            INDEX idx_security_events_type_created (event_type, created_at),
            INDEX idx_security_events_ip_created (ip_address, created_at),
            INDEX idx_security_events_email_created (email, created_at),
            INDEX idx_security_events_severity (severity, created_at)
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

function login_email_lock_status(string $email): ?DateTimeImmutable
{
    ensure_login_ip_attempts_schema();

    $stmt = Database::connection()->prepare(
        'SELECT locked_until FROM login_email_attempts WHERE email_address = :email_address AND locked_until > NOW() LIMIT 1'
    );
    $stmt->execute(['email_address' => $email]);
    $lockedUntil = $stmt->fetchColumn();

    if (!$lockedUntil) {
        return null;
    }

    return new DateTimeImmutable((string) $lockedUntil);
}

function record_failed_login_email_attempt(string $email, string $ipAddress): array
{
    ensure_login_ip_attempts_schema();

    $stmt = Database::connection()->prepare(
        "INSERT INTO login_email_attempts
            (email_address, last_ip, failed_count, locked_until, first_failed_at, last_failed_at)
         VALUES
            (:email_address, :last_ip, 1, NULL, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            last_ip = VALUES(last_ip),
            failed_count = failed_count + 1,
            locked_until = CASE
                WHEN failed_count + 1 >= 10 THEN DATE_ADD(NOW(), INTERVAL 1 DAY)
                WHEN failed_count + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                ELSE locked_until
            END,
            last_failed_at = NOW(),
            updated_at = NOW()"
    );
    $stmt->execute([
        'email_address' => $email,
        'last_ip' => $ipAddress,
    ]);

    $status = Database::connection()->prepare(
        'SELECT failed_count, locked_until FROM login_email_attempts WHERE email_address = :email_address LIMIT 1'
    );
    $status->execute(['email_address' => $email]);
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

function clear_login_email_attempts(string $email): void
{
    ensure_login_ip_attempts_schema();

    $stmt = Database::connection()->prepare('DELETE FROM login_email_attempts WHERE email_address = :email_address');
    $stmt->execute(['email_address' => $email]);
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

function log_security_event(string $eventType, string $severity, string $message, array $context = [], bool $notify = false): void
{
    try {
        ensure_login_ip_attempts_schema();

        $ipAddress = substr(trim((string) ($context['ip_address'] ?? login_client_ip())), 0, 45);
        $email = normalize_login_email((string) ($context['email'] ?? ''));
        $userId = isset($context['user_id'])
            ? (int) $context['user_id']
            : (!empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
        $contextJson = $context !== []
            ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $stmt = Database::connection()->prepare(
            'INSERT INTO security_events
                (event_type, severity, ip_address, email, user_id, message, context_json, user_agent, created_at)
             VALUES
                (:event_type, :severity, :ip_address, :email, :user_id, :message, :context_json, :user_agent, NOW())'
        );
        $stmt->execute([
            'event_type' => substr($eventType, 0, 80),
            'severity' => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info',
            'ip_address' => $ipAddress !== '' ? $ipAddress : null,
            'email' => $email !== '' ? $email : null,
            'user_id' => $userId,
            'message' => $message,
            'context_json' => $contextJson,
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        if ($notify && security_notifications_enabled() && security_notification_allowed($eventType, $ipAddress, $email)) {
            notify_security_event($eventType, $severity, $message, $ipAddress, $email, $context);
        }
    } catch (Throwable $e) {
        error_log('Güvenlik olayı kaydedilemedi: ' . $e->getMessage());
    }
}

function security_notifications_enabled(): bool
{
    $settings = login_security_settings();

    return (string) ($settings['security.notify.enabled'] ?? '1') === '1';
}

function security_notification_allowed(string $eventType, string $ipAddress, string $email): bool
{
    try {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM security_events
             WHERE event_type = :event_type
               AND COALESCE(ip_address, "") = :ip_address
               AND COALESCE(email, "") = :email
               AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $stmt->execute([
            'event_type' => $eventType,
            'ip_address' => $ipAddress,
            'email' => $email,
        ]);

        return (int) $stmt->fetchColumn() <= 1;
    } catch (Throwable) {
        return true;
    }
}

function notify_security_event(string $eventType, string $severity, string $message, string $ipAddress, string $email, array $context): void
{
    $label = security_event_label($eventType);
    $emailLabel = $email !== '' ? $email : 'e-posta yok';
    $body = $label . ' · ' . $emailLabel . ' · IP: ' . ($ipAddress !== '' ? $ipAddress : '-');

    try {
        send_internal_push_notification('Şüpheli giriş denemesi', $body, '/settings/security-logs');
    } catch (Throwable $e) {
        error_log('Güvenlik push bildirimi gönderilemedi: ' . $e->getMessage());
    }

    try {
        send_internal_mail_notification(
            'Şüpheli giriş denemesi: ' . $label,
            security_event_mail_body($label, $severity, $message, $ipAddress, $email, $context)
        );
    } catch (Throwable $e) {
        error_log('Güvenlik mail bildirimi gönderilemedi: ' . $e->getMessage());
    }
}

function security_event_mail_body(string $label, string $severity, string $message, string $ipAddress, string $email, array $context): string
{
    $rows = [
        'Olay' => $label,
        'Seviye' => security_severity_label($severity),
        'E-posta' => $email !== '' ? $email : '-',
        'IP adresi' => $ipAddress !== '' ? $ipAddress : '-',
        'Tarih' => date('d.m.Y H:i'),
        'Mesaj' => $message,
    ];
    if (!empty($context['failed_count'])) {
        $rows['Hatalı deneme'] = (string) $context['failed_count'];
    }
    if (!empty($context['locked_until'])) {
        $rows['Kilit bitişi'] = (string) $context['locked_until'];
    }

    $htmlRows = '';
    foreach ($rows as $key => $value) {
        $htmlRows .= '<tr><td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;">' . h($key) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:800;">' . h((string) $value) . '</td></tr>';
    }

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;color:#17201c;font-family:Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="680" cellpadding="0" cellspacing="0" style="max-width:680px;width:100%;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="padding:28px;">'
        . '<p style="margin:0 0 8px;color:#b42318;font-size:13px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;">Güvenlik bildirimi</p>'
        . '<h1 style="margin:0 0 12px;font-size:30px;line-height:1.1;color:#17201c;">Şüpheli giriş denemesi.</h1>'
        . '<p style="margin:0 0 20px;color:#607069;font-size:16px;line-height:1.5;">Panel girişinde otomatik güvenlik kuralı tetiklendi.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 22px;">' . $htmlRows . '</table>'
        . '<a href="' . h(url('/settings/security-logs')) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:8px;">Güvenlik loglarını aç</a>'
        . '</td></tr></table></td></tr></table></body></html>';
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
            $error = 'Geçerli bir e-posta adresi girin.';
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

                        $error = 'Mail gönderilemedi, şifre değiştirilmedi: ' . (string) $result['error'];
                    }
                }

                if ($error === null) {
                    flash('success', 'E-posta sistemde kayıtlı ve aktifse geçici şifre mail olarak gönderildi.');
                    redirect('/login');
                }
            } catch (Throwable $e) {
                $error = 'Şifre yenileme işlemi tamamlanamadı: ' . $e->getMessage();
            }
        }
    }

    render_public_layout('Şifremi Unuttum', static function () use ($error): void {
        ?>
        <section class="login-panel">
            <div class="login-heading">
                <?= login_fish_icon() ?>
                <p class="eyebrow">Şifre yenileme</p>
                <h1>Geçici yeni şifre gönderelim.</h1>
                <p class="muted compact">Kayıtlı aktif kullanıcı için yeni şifre mail olarak gönderilir.</p>
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
                <button type="submit" class="button primary">Geçici şifre gönder</button>
                <a class="button secondary" href="<?= h(url('/login')) ?>">Giriş paneline dön</a>
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
    $values = [];
    $extracted = [];

    if (!$request) {
        render_public_layout('Cari Bilgi Formu', static function (): void {
            echo '<section class="login-panel"><div class="alert error">Cari bilgi talebi bulunamadı veya bağlantı geçersiz.</div></section>';
        });
        return;
    }

    if (($request['status'] ?? '') !== 'pending') {
        render_public_layout('Cari Bilgi Formu', static function () use ($request): void {
            $message = ($request['status'] ?? '') === 'submitted'
                ? 'Cari bilgileriniz alınmıştır. Teşekkür ederiz.'
                : 'Bu bağlantının süresi dolmuş.';
            echo '<section class="login-panel"><div class="alert success">' . h($message) . '</div></section>';
        });
        return;
    }

    $values = customer_info_initial_values($requestRepo, $request);

    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'submit_request');
        $values = customer_info_form_values($_POST, (string) $request['recipient_email'], (string) ($request['recipient_name'] ?? ''));

        try {
            if ($action === 'analyze_certificate') {
                TaxCertificateAnalyzer::assertValidUpload($_FILES['tax_certificate'] ?? []);
                $extracted = TaxCertificateAnalyzer::analyze((string) ($_FILES['tax_certificate']['tmp_name'] ?? ''));
                if (!tax_certificate_has_customer_fields($extracted)) {
                    throw new RuntimeException('Vergi levhasındaki bilgiler otomatik okunamadı. PDF metin tabanlı değilse alanları manuel doldurabilirsiniz.');
                }
                $values = merge_customer_info_values($values, $extracted);
                $notice = 'Vergi levhası analiz edildi. Lütfen bilgileri kontrol edip gönderin.';
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
                    error_log('Cari bilgi tamamlandı bildirimi gönderilemedi: ' . (string) $notificationResult['error']);
                }
                render_public_layout('Cari Bilgi Formu', static function () use ($customerId): void {
                    ?>
                    <section class="login-panel">
                        <div class="alert success">Cari bilgileriniz alındı. Teşekkür ederiz.</div>
                        <p class="muted compact">Kayıt numarası: <?= h((string) $customerId) ?></p>
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
                <h1>Firma bilgilerinizi tamamlayın.</h1>
                <p class="muted compact">Vergi levhanızı yükleyerek alanları otomatik doldurabilir veya bilgileri manuel girebilirsiniz.</p>
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
                    Vergi levhası
                    <input type="file" name="tax_certificate" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg">
                    <span class="field-help">PDF vergi levhasında unvan, VKN, vergi dairesi ve adres alanları otomatik okunur.</span>
                </label>
                <button type="submit" name="action" value="analyze_certificate" class="button tax-analyze span-2" formnovalidate>Vergi levhasını analiz et</button>

                <label>Firma adı <input name="company_name" value="<?= h($values['company_name']) ?>" required></label>
                <div class="contact-editor customer-info-contact-editor span-2" data-contact-editor data-contact-role-options="<?= contact_role_options_json() ?>" data-next-index="<?= h((string) next_contact_index($values['contacts'])) ?>">
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
                <label>İl <input name="city" value="<?= h($values['city']) ?>"></label>
                <label>İlçe <input name="district" value="<?= h($values['district']) ?>"></label>
                <label class="span-2">Adres <textarea name="address" rows="3"><?= h($values['address']) ?></textarea></label>
                <label class="span-2">Not <textarea name="notes" rows="3"><?= h($values['notes']) ?></textarea></label>
                <button type="submit" name="action" value="submit_request" class="button primary span-2">Bilgileri gönder</button>
            </form>

            <p class="muted compact">Bağlantı geçerlilik süresi: <?= h(date('d.m.Y H:i', strtotime((string) $request['expires_at']))) ?></p>
        </section>
        <?php
    });
}

function handle_customer_info_reminder_opt_out(string $token): void
{
    $request = (new CustomerInfoRequestRepository())->optOutRemindersByToken($token);
    render_public_layout('Cari Bilgi Hatırlatması', static function () use ($request): void {
        if (!$request) {
            echo '<section class="login-panel"><div class="alert error">Cari bilgi talebi bulunamadı veya bağlantı geçersiz.</div></section>';
            return;
        }

        $message = ($request['status'] ?? '') === 'submitted'
            ? 'Cari bilgileriniz zaten alınmış. Teşekkür ederiz.'
            : 'Bu cari bilgi talebi için tekrar hatırlatma gönderilmeyecek.';
        ?>
        <section class="login-panel">
            <div class="alert success"><?= h($message) ?></div>
            <?php if (($request['status'] ?? '') === 'pending'): ?>
                <p class="muted compact">Dilerseniz aynı bağlantıdan cari bilgilerini daha sonra yine doldurabilirsiniz.</p>
            <?php endif; ?>
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
        render_public_layout('Tedarikçi Teklif Formu', static function (): void {
            echo '<section class="login-panel"><div class="alert error">Teklif talebi bulunamadı veya bağlantı geçersiz.</div></section>';
        });
        return;
    }

    if (($request['status'] ?? '') === 'expired') {
        render_public_layout('Tedarikçi Teklif Formu', static function () use ($request): void {
            ?>
            <section class="login-panel supplier-quote-public">
                <div class="alert success">Bu teklif süreci kapatılmış.</div>
                <?php if (!empty($request['quote_number'])): ?>
                    <p class="muted compact">Teklif no: <?= h((string) $request['quote_number']) ?></p>
                <?php endif; ?>
                <p class="muted compact"><?= h(supplier_customer_label($request)) ?> için yeterli teklif alındığı için bu bağlantı artık teklif kabul etmiyor.</p>
            </section>
            <?php
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
        render_public_layout('Tedarikçi Teklif Formu', static function () use ($request): void {
            ?>
            <section class="login-panel supplier-quote-public">
                <div class="alert error">Bu teklif bağlantısının süresi dolmuş.</div>
                <?php if (!empty($request['quote_number'])): ?>
                    <p class="muted compact">Teklif no: <?= h((string) $request['quote_number']) ?></p>
                <?php endif; ?>
                <p class="muted compact">Talep: <?= h(supplier_customer_label($request)) ?></p>
            </section>
            <?php
        });
        return;
    }

    if (($request['status'] ?? '') === 'submitted' && $method !== 'POST') {
        render_public_layout('Tedarikçi Teklif Formu', static function () use ($request): void {
            ?>
            <section class="login-panel supplier-quote-public">
                <div class="alert success">Teklifiniz alınmış. Teşekkür ederiz.</div>
                <?php if (!empty($request['quote_number'])): ?>
                    <p class="muted compact">Teklif no: <?= h((string) $request['quote_number']) ?></p>
                <?php endif; ?>
                <p class="muted compact"><?= h(supplier_customer_label($request)) ?> için teklif kaydınız panele işlendi.</p>
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
    if ($method === 'GET') {
        notify_supplier_quote_opened($request);
    }

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
            notify_supplier_quote_submitted($request);
            close_supplier_quote_requests_if_ready($repo, (int) $request['renewal_id']);

            render_public_layout('Tedarikçi Teklif Formu', static function () use ($request): void {
                ?>
                <section class="login-panel supplier-quote-public">
                    <div class="alert success">Teklifiniz alınmıştır. Teşekkür ederiz.</div>
                    <?php if (!empty($request['quote_number'])): ?>
                        <p class="muted compact">Teklif no: <?= h((string) $request['quote_number']) ?></p>
                    <?php endif; ?>
                    <p class="muted compact"><?= h(supplier_customer_label($request)) ?> için gönderdiğiniz teklif sistemde kayıt altına alındı.</p>
                </section>
                <?php
            });
            return;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    render_public_layout('Tedarikçi Teklif Formu', static function () use ($request, $items, $error): void {
        ?>
        <section class="login-panel supplier-quote-public">
            <div class="login-heading">
                <p class="eyebrow">Tedarikçi teklif formu</p>
                <h1>Teklifinizi kolayca girin.</h1>
                <p class="muted compact">
                    Para birimini seçin, her ödeme vadesi için birim fiyat yazın.
                    Toplam tutarlar adet bilgisine göre otomatik hesaplanır.
                </p>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="supplier-quote-context">
                <?php if (!empty($request['quote_number'])): ?>
                    <div><span>Teklif no</span><strong><?= h((string) $request['quote_number']) ?></strong></div>
                <?php endif; ?>
                <div><span>Müşteri</span><strong><?= h(supplier_customer_label($request)) ?></strong></div>
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

function handle_supplier_unsubscribe_public(string $method, string $token): void
{
    $repo = new RenewalRepository();
    $context = $repo->supplierUnsubscribeContext($token);
    $error = null;

    if (!$context) {
        render_public_layout('Tedarik Listesinden Çık', static function (): void {
            echo '<section class="public-card supplier-unsubscribe-public"><div class="alert error">Liste çıkış bağlantısı bulunamadı veya geçersiz.</div></section>';
        });
        return;
    }

    if ($method === 'POST') {
        verify_csrf();

        try {
            $scope = (string) ($_POST['scope'] ?? 'group');
            $result = $repo->recordSupplierUnsubscribe(
                $token,
                $scope,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );

            render_public_layout('Tedarik Listesinden Çık', static function () use ($result): void {
                $scope = (string) ($result['unsubscribed_scope'] ?? 'group');
                $email = (string) ($result['unsubscribed_email'] ?? ($result['recipient_email'] ?? '-'));
                $groupName = trim((string) ($result['supplier_group_name'] ?? ''));
                $message = $scope === 'all'
                    ? 'Bu e-posta adresi tüm tedarik listelerinden çıkarıldı.'
                    : 'Bu e-posta adresi ' . ($groupName !== '' ? $groupName : 'ilgili kategori') . ' listesinden çıkarıldı.';
                ?>
                <section class="public-card supplier-unsubscribe-public success">
                    <p class="eyebrow">Tedarik listesi</p>
                    <h1>Tercihiniz kaydedildi.</h1>
                    <div class="alert success"><?= h($message) ?></div>
                    <div class="supplier-unsubscribe-summary">
                        <div><span>E-posta</span><strong><?= h($email) ?></strong></div>
                        <div><span>Kapsam</span><strong><?= h($scope === 'all' ? 'Tüm kategoriler' : ($groupName ?: 'İlgili kategori')) ?></strong></div>
                    </div>
                    <p class="muted compact">Bu işlem sadece yeni fiyat talebi e-postalarını durdurur. Daha önce oluşturulan teklif bağlantılarınız kendi geçerlilik süresine kadar çalışabilir.</p>
                </section>
                <?php
            });
            return;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    render_public_layout('Tedarik Listesinden Çık', static function () use ($context, $error): void {
        $email = (string) (($context['recipient_email'] ?? '') ?: '-');
        $supplier = (string) (($context['supplier_display'] ?? '') ?: ($context['supplier_name'] ?? 'Tedarikçi'));
        $company = (string) (($context['company_name'] ?? '') ?: '-');
        $groupName = trim((string) ($context['supplier_group_name'] ?? ''));
        $canLeaveGroup = !empty($context['supplier_group_id']);
        ?>
        <section class="public-card supplier-unsubscribe-public">
            <p class="eyebrow">Tedarik listesi</p>
            <h1>Fiyat talebi e-postalarını durdurun.</h1>
            <p class="muted compact">
                Aşağıdaki e-posta adresi için yalnızca bu tedarikçi kategorisinden veya tüm tedarik listelerinden çıkış yapabilirsiniz.
            </p>

            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="supplier-unsubscribe-summary">
                <div><span>E-posta</span><strong><?= h($email) ?></strong></div>
                <div><span>Tedarikçi</span><strong><?= h($supplier) ?></strong></div>
                <div><span>Kategori</span><strong><?= h($groupName !== '' ? $groupName : 'Kategori yok') ?></strong></div>
                <div><span>Talep</span><strong><?= h($company) ?></strong></div>
            </div>

            <form method="post" class="supplier-unsubscribe-actions">
                <?= csrf_field() ?>
                <?php if ($canLeaveGroup): ?>
                    <button type="submit" name="scope" value="group" class="button secondary full">Sadece bu kategoriden çık</button>
                <?php endif; ?>
                <button type="submit" name="scope" value="all" class="button danger full">Tüm tedarik listelerinden çık</button>
            </form>

            <p class="muted compact">Bu işlem teklif formu linkinizi iptal etmez; sadece bundan sonraki fiyat talebi e-postalarını etkiler.</p>
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

    if ($method === 'GET' && in_array((string) ($offer['status'] ?? ''), ['sent', 'opened'], true)) {
        $offer['view_count'] = $repo->markCustomerOfferOpened((int) $offer['id']);
        $offer['status'] = 'opened';
        notify_customer_offer_opened($offer);
    }

    if (in_array((string) ($offer['status'] ?? ''), ['approved', 'revision_requested', 'rejected'], true)) {
        $renewal = (string) ($offer['status'] ?? '') === 'approved' ? $repo->find((int) $offer['renewal_id']) : null;
        $paymentCompleted = $renewal ? renewal_payment_choice_completed($renewal, $repo) : false;
        $paymentUrl = $renewal && !$paymentCompleted
            ? PaymentLink::urlForRenewal((int) $offer['renewal_id'], 60, (string) ($offer['recipient_email'] ?? ''))
            : '';
        render_public_layout('Müşteri Teklifi', static function () use ($offer, $renewal, $paymentCompleted, $paymentUrl): void {
            $status = (string) ($offer['status'] ?? '');
            ?>
            <section class="public-card payment-result-card success">
                <p class="eyebrow">Teklif yanıtı</p>
                <h1><?= $status === 'approved' ? 'Teklifiniz onaylandı.' : ($status === 'revision_requested' ? 'Bu teklif için revize istendi.' : 'Bu teklif reddedildi.') ?></h1>
                <?php if (!empty($offer['offer_number'])): ?>
                    <p class="muted compact">Teklif no: <?= h((string) $offer['offer_number']) ?></p>
                <?php endif; ?>
                <?php if ($status === 'approved' && !$paymentCompleted && $paymentUrl !== ''): ?>
                    <p>Ödeme adımı henüz tamamlanmamış görünüyor. Bağlantıyı tekrar açtığınızda buradan devam edebilirsiniz.</p>
                    <a class="button primary" href="<?= h($paymentUrl) ?>">Ödeme yöntemine geç</a>
                <?php elseif ($status === 'approved' && $renewal): ?>
                    <p><?= h(renewal_payment_selected_notice($renewal, (string) ($offer['recipient_email'] ?? ''))) ?></p>
                <?php endif; ?>
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
            if ($decision === 'revision_requested' && !customer_offer_revision_allowed($offer)) {
                throw new RuntimeException('Revize talebi, teklif dördüncü kez incelendikten sonra açılır.');
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
            notify_customer_offer_response($offer, $decision, $note);

            $supplierMailSummary = ['sent' => 0, 'failed' => 0];
            $parasutInvoiceSummary = ['ok' => false, 'skipped' => true];
            if ($decision === 'approved') {
                $repo->applyCustomerOfferToRenewal((int) $offer['renewal_id'], (int) $offer['id']);
                $supplierMailSummary = send_supplier_customer_offer_approval_emails($repo, $offer, $lines);
                $parasutInvoiceSummary = create_parasut_invoice_for_customer_offer($repo, (int) $offer['id']);
            }

            $paymentUrl = $decision === 'approved'
                ? PaymentLink::urlForRenewal((int) $offer['renewal_id'], 60, (string) ($offer['recipient_email'] ?? ''))
                : '';

            render_public_layout('Müşteri Teklifi', static function () use ($decision, $paymentUrl, $supplierMailSummary, $parasutInvoiceSummary, $offer): void {
                ?>
                <section class="public-card payment-result-card success">
                    <p class="eyebrow">Teklif yanıtı</p>
                    <h1><?= $decision === 'approved' ? 'Teklif onaylandı.' : ($decision === 'revision_requested' ? 'Revize talebiniz alındı.' : 'Red yanıtınız alındı.') ?></h1>
                    <?php if (!empty($offer['offer_number'])): ?>
                        <p class="muted compact">Teklif no: <?= h((string) $offer['offer_number']) ?></p>
                    <?php endif; ?>
                    <?php if ($decision === 'approved'): ?>
                        <p>Teşekkür ederiz. Seçilen tedarikçilere işlem bilgisi iletildi.</p>
                        <p class="muted compact">Tedarikçi mail durumu: <?= h((string) $supplierMailSummary['sent']) ?> gönderildi, <?= h((string) $supplierMailSummary['failed']) ?> başarısız.</p>
                        <?php if (!empty($parasutInvoiceSummary['ok'])): ?>
                            <?php $invoice = (array) ($parasutInvoiceSummary['invoice'] ?? []); ?>
                            <p class="muted compact">Fatura aktarımı: Paraşüt faturası oluşturuldu<?= !empty($invoice['invoice_no']) ? ' (' . h((string) $invoice['invoice_no']) . ')' : '' ?>.</p>
                        <?php else: ?>
                            <p class="muted compact">Fatura aktarımı firma yetkilisi tarafından kontrol edilecek; panelden Manuel Paraşüt'e gönder ile tekrar denenebilir.</p>
                        <?php endif; ?>
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
        $revisionAllowed = customer_offer_revision_allowed($offer);
        $viewCount = customer_offer_view_count($offer);
        ?>
        <section class="login-panel customer-offer-public">
            <div class="summary-print-actions customer-offer-print-actions">
                <button type="button" class="button secondary" onclick="window.print()">Teklifi PDF olarak yazdır</button>
            </div>

            <?= render_company_letterhead() ?>

            <div class="login-heading customer-offer-hero">
                <p class="customer-offer-kicker">MÜŞTERİ YENİLEME TEKLİFİ</p>
                <h1>Teklifinizi inceleyiniz.</h1>
                <?php if (!empty($offer['offer_number'])): ?>
                    <p class="muted compact">Teklif no: <?= h((string) $offer['offer_number']) ?></p>
                <?php endif; ?>
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

            <form method="post" class="form-grid customer-offer-response-form">
                <?= csrf_field() ?>
                <label class="span-2">
                    Yanıt notu
                    <textarea name="response_note" rows="3" placeholder="<?= h($revisionAllowed ? 'Revize veya red için açıklamanızı yazın.' : 'Red için açıklamanızı yazın.') ?>"><?= h((string) ($_POST['response_note'] ?? '')) ?></textarea>
                </label>
                <?php if (!$revisionAllowed): ?>
                    <p class="muted compact span-2">Revize talebi, teklif dördüncü kez incelendiğinde açılır. Görüntüleme: <?= h((string) min($viewCount, 4)) ?>/4</p>
                <?php endif; ?>
                <div class="inline-actions span-2">
                    <button type="submit" name="decision" value="approved" class="button primary">Teklifi onayla</button>
                    <?php if ($revisionAllowed): ?>
                        <button type="submit" name="decision" value="revision_requested" class="button secondary">Revize iste</button>
                    <?php endif; ?>
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
    $selectedCurrency = normalize_allowed_currency($posted['currency'] ?? $currency);
    $quantity = max(0.01, (float) ($item['quantity'] ?? 1));
    $quantityLabel = number_format($quantity, 2, ',', '.');
    $priceFields = [
        ['field' => 'price_cash', 'label' => 'Peşin', 'hint' => 'Nakit / hemen ödeme'],
        ['field' => 'price_30', 'label' => '30 gün', 'hint' => '30 gün vade'],
        ['field' => 'price_60', 'label' => '60 gün', 'hint' => '60 gün vadeli'],
        ['field' => 'price_check', 'label' => 'Çek / vade', 'hint' => 'Çek veya vadeli ödeme'],
    ];

    ob_start();
    ?>
    <div class="supplier-quote-card supplier-quote-workbench" data-supplier-quote-card data-quantity="<?= h(number_format($quantity, 2, '.', '')) ?>">
        <div class="supplier-quote-item-head">
            <div>
                <span>Teklif kalemi</span>
                <strong><?= h($title) ?></strong>
                <em>
                    <?= h(trim((string) (($item['brand'] ?? '') ?: '-'))) ?>
                    <?= !empty($item['license_key']) ? ' / ' . h((string) $item['license_key']) : '' ?>
                </em>
            </div>
            <b class="supplier-quantity-pill"><?= h($quantityLabel) ?> adet</b>
        </div>
        <input type="hidden" name="lines[<?= h($key) ?>][item_title]" value="<?= h($title) ?>">
        <div class="supplier-quote-priçing-head">
            <label class="supplier-currency-control">
                <span>Para birimi</span>
                <select name="lines[<?= h($key) ?>][currency]" data-supplier-price-currency>
                    <?php foreach (allowed_currency_options() as $currencyOption): ?>
                        <?= option($currencyOption, $currencyOption, $selectedCurrency) ?>
                    <?php endforeach; ?>
                </select>
                <span class="supplier-priçing-help">
                    <span>Fiyat girişi</span>
                    <strong>Birim fiyat yazın</strong>
                    <em><?= h($quantityLabel) ?> adet için toplamlar otomatik hesaplanır. Para birimi: <b data-supplier-currency-label><?= h($selectedCurrency) ?></b></em>
                </span>
            </label>
        </div>
        <div class="supplier-quote-price-grid">
            <?php foreach ($priceFields as $priceField): ?>
                <label class="supplier-price-label">
                    <span><?= h($priceField['label']) ?></span>
                    <small><?= h($priceField['hint']) ?> · <b data-supplier-currency-label><?= h($selectedCurrency) ?></b> / adet</small>
                    <div class="supplier-price-input-wrap">
                        <input type="number" min="0" step="0.01" name="lines[<?= h($key) ?>][<?= h($priceField['field']) ?>]" value="<?= h($posted[$priceField['field']] ?? '') ?>" placeholder="0.00" data-supplier-unit-price>
                        <b data-supplier-currency-label><?= h($selectedCurrency) ?></b>
                    </div>
                    <em data-supplier-total-preview>Toplam: -</em>
                </label>
            <?php endforeach; ?>
            <div class="supplier-price-label supplier-price-custom">
                <span>Özel vade</span>
                <small>Kendi ödeme şartınızı yazabilirsiniz.</small>
                <input name="lines[<?= h($key) ?>][custom_term]" value="<?= h($posted['custom_term'] ?? '') ?>" placeholder="Örn: 90 gün / 3 taksit">
                <div class="supplier-price-input-wrap">
                    <input type="number" min="0" step="0.01" name="lines[<?= h($key) ?>][price_custom]" value="<?= h($posted['price_custom'] ?? '') ?>" placeholder="0.00" data-supplier-unit-price>
                    <b data-supplier-currency-label><?= h($selectedCurrency) ?></b>
                </div>
                <em data-supplier-total-preview>Toplam: -</em>
            </div>
        </div>
        <div class="supplier-quote-extra-grid supplier-quote-extra-stack">
            <div class="supplier-vat-toggle" role="radiogroup" aria-label="KDV durumu">
                <span>KDV durumu</span>
                <div>
                    <label>
                        <input type="radio" name="lines[<?= h($key) ?>][vat_included]" value="1" <?= !empty($posted['vat_included']) ? 'checked' : '' ?>>
                        <b>KDV Dahil</b>
                    </label>
                    <label>
                        <input type="radio" name="lines[<?= h($key) ?>][vat_included]" value="0" <?= empty($posted['vat_included']) ? 'checked' : '' ?>>
                        <b>KDV Hariç</b>
                    </label>
                </div>
            </div>
            <label class="supplier-quote-note-field">
                Nakliye / teslim şartı
                <textarea name="lines[<?= h($key) ?>][delivery_note]" rows="2" placeholder="Nakliye dahil mi, teslim süresi nedir?"><?= h($posted['delivery_note'] ?? '') ?></textarea>
            </label>
            <label class="supplier-quote-note-field">
                Kalem notu
                <textarea name="lines[<?= h($key) ?>][note]" rows="2" placeholder="Stok, muadil ürün, garanti veya özel şartlar"><?= h($posted['note'] ?? '') ?></textarea>
            </label>
        </div>
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

function customer_info_initial_values(CustomerInfoRequestRepository $repo, array $request): array
{
    $customerId = (int) ($request['customer_id'] ?? 0);
    $fallbackEmail = (string) ($request['recipient_email'] ?? '');
    $fallbackName = (string) ($request['recipient_name'] ?? '');

    if ($customerId > 0) {
        return $repo->customerFormDefaults($customerId, $fallbackEmail, $fallbackName);
    }

    return customer_info_form_values([], $fallbackEmail, $fallbackName);
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
                'role_title' => trim((string) ($row['role_title'] ?? '')),
                'email' => trim((string) ($row['email'] ?? '')),
                'phone' => normalize_phone_number($row['phone'] ?? ''),
                'notify_enabled' => !empty($row['notify_enabled']) ? 1 : 0,
            ];
        }

        return $contacts !== [] ? $contacts : default_contact_rows();
    }

    return default_contact_rows([
        'full_name' => trim((string) ($source['contact_name'] ?? $fallbackContactName)),
        'email' => trim((string) ($source['email'] ?? $fallbackEmail)),
        'phone' => normalize_phone_number($source['phone'] ?? ''),
    ]);
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
        $errors[] = 'Firma adı zorunlu.';
    }

    if (($values['email'] ?? '') !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-posta adresi geçersiz.';
    }

    foreach (($values['contacts'] ?? []) as $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $email = trim((string) ($contact['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Yetkili e-posta adreslerinden biri geçersiz.';
            break;
        }

        if (!empty($contact['notify_enabled']) && $email === '') {
            $errors[] = 'Bilgilendirme gönderilecek yetkililer için e-posta zorunlu.';
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
    $salesOffers = $repo->dashboardSalesOffers();
    $canManageRenewals = Auth::can('renewals.manage');
    $canDeleteRenewals = Auth::can('renewals.delete');
    $canRequestCustomerInfo = Auth::can('customers.manage');
    $canViewRenewals = Auth::can('renewals.view');
    $showDetails = Auth::can('dashboard.details');
    $exchangeRates = ExchangeRates::latest();
    $customersForRequest = $canRequestCustomerInfo ? $repo->customers() : [];

    render_layout('Dashboard', static function () use ($stats, $upcoming, $salesOffers, $canManageRenewals, $canDeleteRenewals, $canRequestCustomerInfo, $canViewRenewals, $showDetails, $exchangeRates, $customersForRequest): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Bugün: <?= h(date('d.m.Y')) ?></p>
                <h1>Hızlı Takip ve Teklif Paneli</h1>
            </div>
            <?php if ($canRequestCustomerInfo || $canManageRenewals): ?>
                <div class="page-actions">
                    <?php if ($canRequestCustomerInfo): ?>
                        <button type="button" class="button secondary" data-dialog-open="customer-info-request-dialog">Cari bilgi talep et</button>
                    <?php endif; ?>
                    <?php if ($canManageRenewals): ?>
                        <a href="<?= h(url('/offers/create')) ?>" class="button primary">Yeni Teklif</a>
                        <details class="action-menu">
                            <summary class="button primary">Yeni Takip</summary>
                            <div class="action-menu-list">
                                <a href="<?= h(url('/renewals/create')) ?>">Yeni Yenileme</a>
                                <a href="<?= h(url('/renewals/create?old=1')) ?>">Eski Yenileme</a>
                            </div>
                        </details>
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
            <?= stat_card('Yaklaşan (60 gün)', $stats['due_soon'], 'warning') ?>
            <?= stat_card('Geciken', $stats['overdue'], 'danger') ?>
            <?= stat_card('Müşteri', $stats['customers']) ?>
        </section>

        <?php if ($showDetails && $canViewRenewals): ?>
            <section class="dashboard-split">
                <div class="dashboard-lane dashboard-lane-renewals">
                    <div class="lane-head">
                        <div>
                            <p class="eyebrow">Yenilenen ürünler</p>
                            <h2>Takipteki ürün ve hizmetler</h2>
                            <span class="lane-subtitle">Tarih, okuma ve ödeme bilgisiyle kontrol edilir.</span>
                        </div>
                        <span class="lane-count"><?= h((string) count($upcoming)) ?></span>
                    </div>
                    <?= render_dashboard_lane($upcoming, 'renewals', $canManageRenewals, false, true, 'Takipte ürün veya hizmet bulunmuyor.') ?>
                </div>

                <div class="dashboard-lane dashboard-lane-offers">
                    <div class="lane-head">
                        <div>
                            <p class="eyebrow">Teklifler</p>
                            <h2>Hazırlanan teklifler</h2>
                            <span class="lane-subtitle">Teklif no, durum ve tahsilat adımı birlikte izlenir.</span>
                        </div>
                        <div class="lane-head-actions">
                            <span class="lane-count"><?= h((string) count($salesOffers)) ?></span>
                            <?php if ($canManageRenewals): ?>
                                <a class="button small primary" href="<?= h(url('/offers/create')) ?>">Yeni teklif</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?= render_sales_offer_lane($salesOffers, $canManageRenewals, $canDeleteRenewals) ?>
                </div>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="empty">Bu kullanıcı için panel detayları kapalı.</div>
            </section>
        <?php endif; ?>

        <div class="dashboard-fx-footer">
            <?= render_exchange_rates($exchangeRates, 'compact') ?>
        </div>
        <?php
    });
}

function render_sales_report(RenewalRepository $repo): void
{
    $years = $repo->salesReportYears();
    $requestedYear = (int) ($_GET['year'] ?? date('Y'));
    $year = in_array($requestedYear, $years, true) ? $requestedYear : (int) ($years[0] ?? date('Y'));
    $query = trim((string) ($_GET['q'] ?? ''));
    $report = $repo->monthlySalesReport($year, $query);
    $itemOptions = $repo->salesReportItemOptions();
    $maxMonthlySales = max(1, ...array_map(static fn (array $month): int => (int) $month['sale_count'], $report['months']));

    render_layout('Satış Raporları', static function () use ($years, $year, $query, $report, $itemOptions, $maxMonthlySales): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Raporlar</p>
                <h1>Satış analizi</h1>
            </div>
            <div class="page-actions">
                <a href="<?= h(url('/')) ?>" class="button secondary">Dashboard'a dön</a>
            </div>
        </div>

        <section class="sales-report-hero">
            <div>
                <p class="eyebrow">Ay bazlı takip</p>
                <h2><?= h($query !== '' ? $query . ' satışları' : 'Tüm ürün ve hizmet satışları') ?></h2>
                <p>Onaylanan müşteri teklifleri, onaylı teklif kayıtları ve bağımsız tahsil edilmiş ödeme talepleri üzerinden hesaplanır. Ürün adına göre filtreleyerek “Aylık Bakım Anlaşması” gibi kalemleri yıl içinde kaç kere sattığınızı görebilirsiniz.</p>
            </div>
            <form method="get" class="sales-report-filter">
                <label>
                    Yıl
                    <select name="year">
                        <?php foreach ($years as $optionYear): ?>
                            <?= option((string) $optionYear, (string) $optionYear, (string) $year) ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Ürün / hizmet
                    <input name="q" list="sales-report-items" value="<?= h($query) ?>" placeholder="Örn: Aylık Bakım Anlaşması">
                    <datalist id="sales-report-items">
                        <?php foreach ($itemOptions as $item): ?>
                            <option value="<?= h($item) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <div class="sales-report-actions">
                    <button class="button primary" type="submit">Raporla</button>
                    <?php if ($query !== ''): ?>
                        <a class="button secondary" href="<?= h(url('/reports/sales?year=' . $year)) ?>">Temizle</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="sales-report-summary">
            <article>
                <span>Yıllık satış</span>
                <strong><?= h((string) $report['totals']['sale_count']) ?></strong>
                <small>onaylı / tahsil edilmiş işlem</small>
            </article>
            <article>
                <span>Satılan adet</span>
                <strong><?= h(decimal_format_local($report['totals']['quantity'])) ?></strong>
                <small>toplam miktar</small>
            </article>
            <article>
                <span>Kalem sayısı</span>
                <strong><?= h((string) $report['totals']['line_count']) ?></strong>
                <small>teklif satırı</small>
            </article>
            <article>
                <span>KDV dahil toplam</span>
                <strong><?= h(report_amounts_text((array) $report['totals']['amounts'])) ?></strong>
                <small>para birimine göre</small>
            </article>
        </section>

        <section class="sales-month-grid">
            <?php foreach ($report['months'] as $month): ?>
                <?php
                $saleCount = (int) $month['sale_count'];
                $barWidth = (int) round(($saleCount / $maxMonthlySales) * 100);
                ?>
                <article class="sales-month-card <?= $saleCount > 0 ? 'has-sales' : '' ?>">
                    <div class="sales-month-head">
                        <span><?= h(turkish_month_name((int) $month['month'])) ?></span>
                        <strong><?= h((string) $saleCount) ?></strong>
                    </div>
                    <div class="sales-month-bar" aria-hidden="true">
                        <span style="width: <?= h((string) $barWidth) ?>%"></span>
                    </div>
                    <div class="sales-month-meta">
                        <span>Adet <b><?= h(decimal_format_local($month['quantity'])) ?></b></span>
                        <span>Kalem <b><?= h((string) $month['line_count']) ?></b></span>
                    </div>
                    <em><?= h(report_amounts_text((array) $month['amounts'])) ?></em>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="panel sales-report-table-panel">
            <div class="section-head">
                <div>
                    <h2>En çok satılan kalemler</h2>
                    <span><?= h((string) $year) ?> yılı için onaylı satış özeti</span>
                </div>
            </div>
            <?php if ($report['items'] === []): ?>
                <div class="empty">Bu filtreyle onaylı satış bulunmadı.</div>
            <?php else: ?>
                <div class="sales-report-table">
                    <div class="sales-report-row head">
                        <span>Ürün / hizmet</span>
                        <span>Satış</span>
                        <span>Adet</span>
                        <span>Kalem</span>
                    </div>
                    <?php foreach ($report['items'] as $item): ?>
                        <div class="sales-report-row">
                            <strong><?= h((string) $item['item_title']) ?></strong>
                            <span><?= h((string) ((int) $item['sale_count'])) ?></span>
                            <span><?= h(decimal_format_local($item['quantity'] ?? 0)) ?></span>
                            <span><?= h((string) ((int) $item['line_count'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    });
}

function turkish_month_name(int $month): string
{
    return [
        1 => 'Ocak',
        2 => 'Şubat',
        3 => 'Mart',
        4 => 'Nisan',
        5 => 'Mayıs',
        6 => 'Haziran',
        7 => 'Temmuz',
        8 => 'Ağustos',
        9 => 'Eylül',
        10 => 'Ekim',
        11 => 'Kasım',
        12 => 'Aralık',
    ][$month] ?? '-';
}

function decimal_format_local(mixed $value): string
{
    return number_format((float) $value, 2, ',', '.');
}

function report_amounts_text(array $amounts): string
{
    if ($amounts === []) {
        return '-';
    }

    ksort($amounts);

    return implode(' · ', array_map(
        static fn (string $currency, mixed $amount): string => money_format_local($amount, $currency),
        array_keys($amounts),
        $amounts
    ));
}

function handle_sales_offer_public(string $method, int $offerId): void
{
    $mode = sales_offer_public_mode($_GET['mode'] ?? 'view');
    $expires = (string) ($_GET['expires'] ?? '');
    $readerToken = trim((string) ($_GET['r'] ?? ''));
    $signature = (string) ($_GET['sig'] ?? '');
    if (!sales_offer_public_signature_valid($offerId, $expires, $mode, $signature, $readerToken)) {
        render_public_layout('Teklif', static function (): void {
            echo '<section class="login-panel"><div class="alert error">Teklif bağlantısı geçersiz veya süresi dolmuş.</div></section>';
        });
        return;
    }

    if ($method === 'POST') {
        verify_csrf();
    }

    $repo = new RenewalRepository();
    $offer = $repo->findSalesOffer($offerId);
    if (!$offer) {
        render_public_layout('Teklif', static function (): void {
            echo '<section class="login-panel"><div class="alert error">Teklif bulunamadı.</div></section>';
        });
        return;
    }

    $reader = $readerToken !== '' ? $repo->findSalesOfferDeliveryByToken($offerId, $readerToken) : null;
    if ($readerToken !== '' && $reader === null) {
        render_public_layout('Teklif', static function (): void {
            echo '<section class="login-panel"><div class="alert error">Bu teklif bağlantısı güncellendiği için geçersiz oldu. Lütfen size gönderilen yeni teklif bağlantısını kullanın.</div></section>';
        });
        return;
    }

    if ($method === 'GET') {
        $viewRecord = $repo->recordSalesOfferView(
            $offerId,
            $readerToken,
            $mode,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        notify_sales_offer_opened($offer, $viewRecord);
        $reader = $viewRecord ?: $reader;
        $offer = $repo->findSalesOffer($offerId) ?: $offer;
    }

    if ($method === 'POST' && (string) ($_POST['action'] ?? '') === 'approve_sales_offer') {
        try {
            if (sales_offer_has_recorded_approval($offer)) {
                render_sales_offer_document($offer, false, true, sales_offer_payment_request($offer), '', $reader);
                return;
            }

            $approval = sales_offer_approval_payload($reader, $readerToken);
            $paymentUrl = approve_sales_offer_and_payment_request($repo, $offer, $approval);
            $offer = $repo->findSalesOffer($offerId) ?: $offer;
            if ((string) ($offer['status'] ?? '') === 'approved') {
                notify_sales_offer_response($offer, 'approved');
            }
            if ($paymentUrl !== '') {
                redirect($paymentUrl);
            }

        } catch (Throwable $e) {
            render_sales_offer_document($offer, false, true, null, $e->getMessage(), $reader);
            return;
        }
    }

    $paymentRequest = sales_offer_payment_request($offer);
    render_sales_offer_document($offer, $mode === 'pdf', true, $paymentRequest, '', $reader);
}

function handle_sales_offer_preview(RenewalRepository $repo, int $offerId, bool $autoPrint): void
{
    $offer = $repo->findSalesOffer($offerId);
    if (!$offer) {
        flash('error', 'Teklif bulunamadı.');
        redirect('/');
    }

    render_sales_offer_document($offer, $autoPrint, false);
}

function handle_sales_offer_whatsapp(RenewalRepository $repo, int $offerId): void
{
    $wantsJson = wants_json_response();
    $offer = $repo->findSalesOffer($offerId);
    if (!$offer) {
        if ($wantsJson) {
            json_response(['ok' => false, 'message' => 'Teklif bulunamadı.'], 404);
        }
        flash('error', 'Teklif bulunamadı.');
        redirect('/');
    }

    $mode = sales_offer_public_mode($_GET['mode'] ?? 'view');
    $phone = whatsapp_number_from_phone((string) ($_GET['phone'] ?? ($offer['customer_phone'] ?? '')));
    if ($phone === null) {
        if ($wantsJson) {
            json_response(['ok' => false, 'message' => 'WhatsApp göndermek için seçili yetkilinin telefon numarası eksik veya geçersiz.'], 422);
        }
        flash('error', 'WhatsApp göndermek için seçili yetkilinin telefon numarası eksik veya geçersiz.');
        redirect(safe_return_path($_GET['return_to'] ?? '/'));
    }

    $delivery = $repo->createSalesOfferDelivery($offerId, [
        'recipient_name' => trim((string) ($_GET['name'] ?? '')),
        'recipient_email' => trim((string) ($_GET['email'] ?? '')),
        'recipient_phone' => $phone,
        'channel' => 'whatsapp',
        'mode' => $mode,
    ]);
    $repo->markSalesOfferDeliverySent((int) ($delivery['id'] ?? 0), true);
    $repo->markSalesOfferSent($offerId);
    $whatsappUrl = whatsapp_web_url($phone, sales_offer_whatsapp_message($offer, sales_offer_public_url($offerId, $mode, 14, (string) ($delivery['token'] ?? ''))));
    if ($wantsJson) {
        json_response(['ok' => true, 'whatsapp_url' => $whatsappUrl]);
    }

    header('Location: ' . $whatsappUrl);
    exit;
}

function handle_sales_offer_mail(RenewalRepository $repo, int $offerId, string $mode): void
{
    verify_csrf();
    $mode = sales_offer_public_mode($_POST['offer_send_mode'] ?? $mode);
    $offer = $repo->findSalesOffer($offerId);
    if (!$offer) {
        flash('error', 'Teklif bulunamadı.');
        redirect('/');
    }

    $recipients = sales_offer_mail_recipients_from_request($offer);
    if ($recipients === []) {
        flash('error', 'E-posta göndermek için en az bir alıcı seçin veya manuel alıcı yazın.');
        redirect(safe_return_path($_POST['return_to'] ?? '/'));
    }

    $offerNumber = sales_offer_number($offer);
    $defaultSubject = '[' . $offerNumber . '] ' . ($mode === 'pdf' ? 'PDF teklif çıktınız' : 'Teklifiniz hazır') . ': ' . (string) ($offer['title'] ?? 'Teklif');
    $subject = trim((string) ($_POST['subject'] ?? $defaultSubject));
    if ($subject === '') {
        $subject = $defaultSubject;
    }
    $subject = mb_substr($subject, 0, 240);
    $defaultMessage = $mode === 'pdf'
        ? 'Teklif çıktınızı PDF olarak kaydedebilmeniz için bağlantıyı paylaşıyoruz.'
        : 'Hazırlanan teklifinizi inceleyebilmeniz için bağlantıyı paylaşıyoruz.';
    $message = trim((string) ($_POST['message'] ?? $defaultMessage));
    if ($message === '') {
        $message = $defaultMessage;
    }
    $sent = 0;
    $failed = [];

    foreach ($recipients as $email) {
        $delivery = $repo->createSalesOfferDelivery($offerId, [
            'recipient_name' => sales_offer_contact_name_by_email($offer, $email),
            'recipient_email' => $email,
            'channel' => 'mail',
            'mode' => $mode,
        ]);
        $url = sales_offer_public_url($offerId, $mode, 14, (string) ($delivery['token'] ?? ''));
        $body = sales_offer_mail_body($offer, $url, $mode, $message);
        $result = Mailer::sendWithResult($email, $subject, $body, true);
        $ok = !empty($result['ok']);
        $error = $ok ? null : (string) ($result['error'] ?? 'transport-failed');
        $repo->markSalesOfferDeliverySent((int) ($delivery['id'] ?? 0), $ok, $error);

        $repo->logMail(
            null,
            $email,
            $subject,
            (string) ($result['body'] ?? $body),
            $ok ? 'sent' : 'failed',
            $error,
            false
        );

        if ($ok) {
            $sent++;
        } else {
            $failed[] = $email . ': ' . ($error ?: 'Alıcı sunucusu kabul etmedi.');
        }
    }

    if ($sent > 0) {
        $repo->markSalesOfferSent($offerId);
        $message = $mode === 'pdf'
            ? $sent . ' alıcıya PDF teklif bağlantısı e-posta ile gönderildi.'
            : $sent . ' alıcıya teklif e-posta ile gönderildi.';
        if ($failed !== []) {
            $message .= ' Gönderilemeyen: ' . implode(' | ', $failed);
        }
        flash('success', $message);
    } else {
        flash('error', 'Teklif e-postası gönderilemedi: ' . implode(' | ', $failed));
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function sales_offer_mail_recipients_from_request(array $offer): array
{
    $hasExplicitFields = array_key_exists('offer_mail_recipients', $_POST) || array_key_exists('custom_email', $_POST);
    $selected = array_map('trim', (array) ($_POST['offer_mail_recipients'] ?? []));
    $custom = trim((string) ($_POST['custom_email'] ?? ''));
    if ($custom !== '') {
        $selected[] = $custom;
    }

    if (!$hasExplicitFields && $selected === []) {
        $fallback = trim((string) ($offer['customer_email'] ?? ''));
        if ($fallback !== '') {
            $selected[] = $fallback;
        }
    }

    $unique = [];
    foreach ($selected as $email) {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $unique[$email] = $email;
    }

    return array_values($unique);
}

function sales_offer_contact_name_by_email(array $offer, string $email): string
{
    $email = mb_strtolower(trim($email));
    if ($email === '') {
        return '';
    }

    foreach (sales_offer_contacts($offer) as $contact) {
        if (mb_strtolower(trim((string) ($contact['email'] ?? ''))) !== $email) {
            continue;
        }

        return trim((string) ($contact['full_name'] ?? ''));
    }

    return '';
}

function handle_sales_offer_collect_balance(RenewalRepository $repo, int $offerId): void
{
    verify_csrf();
    $returnTo = safe_return_path($_POST['return_to'] ?? '/');

    try {
        $offer = $repo->findSalesOffer($offerId);
        if (!$offer) {
            throw new RuntimeException('Teklif bulunamadı.');
        }

        $paymentRepo = new PaymentRequestRepository();
        $advanceRequest = sales_offer_payment_request($offer, $paymentRepo);
        $balanceRequest = sales_offer_balance_payment_request($offer, $paymentRepo);
        if (!$advanceRequest || (string) ($advanceRequest['status'] ?? 'pending') !== 'paid') {
            throw new RuntimeException('Kalan bakiye talebi için önce ön ödeme tahsil edilmiş olmalı.');
        }

        if ($balanceRequest && (string) ($balanceRequest['status'] ?? 'pending') === 'paid') {
            flash('success', 'Kalan bakiye zaten tahsil edilmiş.');
            redirect($returnTo);
        }

        $recipientEmail = trim(mb_strtolower((string) (($balanceRequest['customer_email'] ?? '') ?: ($offer['customer_email'] ?? ''))));
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Kalan bakiye maili için teklif üzerinde geçerli müşteri e-posta adresi olmalı.');
        }

        if (!$balanceRequest) {
            $balanceAmount = sales_offer_remaining_balance_amount($offer, $advanceRequest, null);
            if ($balanceAmount <= 0) {
                throw new RuntimeException('Bu teklif için tahsil edilecek kalan bakiye görünmüyor.');
            }

            $currency = normalize_allowed_currency((string) ($offer['currency'] ?? 'TRY'));
            $offerNumber = sales_offer_number($offer);
            $balanceRequest = $paymentRepo->create([
                'title' => $offerNumber . ' kalan bakiye tahsilatı',
                'description' => sales_offer_balance_payment_description($offer, $advanceRequest, $balanceAmount),
                'customer_name' => (string) ($offer['customer_name'] ?? ''),
                'customer_email' => $recipientEmail,
                'customer_phone' => (string) ($offer['customer_phone'] ?? ''),
                'amount' => $balanceAmount,
                'currency' => $currency,
                'created_by' => (int) ($_SESSION['user_id'] ?? 0),
            ]);
            $repo->markSalesOfferBalancePaymentRequest($offerId, (int) ($balanceRequest['id'] ?? 0));
            notify_manual_payment_request_created($balanceRequest);
        }

        $message = sales_offer_balance_payment_message($offer, $advanceRequest, $balanceRequest);
        $result = send_payment_request_mail_message($paymentRepo, $balanceRequest, $recipientEmail, $message);
        if (!empty($result['ok'])) {
            flash('success', 'Kalan bakiye ödeme talebi müşteriye mail olarak gönderildi.');
        } else {
            flash('error', 'Kalan bakiye maili gönderilemedi: ' . ((string) ($result['error'] ?? '') ?: 'Alıcı sunucusu kabul etmedi.'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect($returnTo);
}

function handle_sales_offer_payment_match(RenewalRepository $repo, int $offerId): void
{
    verify_csrf();
    $returnTo = safe_return_path($_POST['return_to'] ?? '/');

    try {
        new PaymentRequestRepository();
        $paymentRequestId = (int) ($_POST['payment_request_id'] ?? 0);
        $balancePaymentRequestId = (int) ($_POST['balance_payment_request_id'] ?? 0);
        $repo->matchSalesOfferPaymentRequests(
            $offerId,
            $paymentRequestId > 0 ? $paymentRequestId : null,
            $balancePaymentRequestId > 0 ? $balancePaymentRequestId : null
        );
        flash('success', 'Teklif ile ödeme talebi eşleştirildi.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect($returnTo);
}

function handle_sales_offer_parasut_invoice(RenewalRepository $repo, int $offerId): void
{
    verify_csrf();
    $transferMode = (string) ($_POST['parasut_transfer_mode'] ?? 'with_collection');
    $includePayment = $transferMode !== 'invoice_only';
    $result = create_parasut_invoice_for_sales_offer($repo, $offerId, $includePayment);

    if (!empty($result['ok'])) {
        $invoice = (array) ($result['invoice'] ?? []);
        $label = trim((string) ($invoice['invoice_no'] ?? '')) ?: trim((string) ($invoice['id'] ?? ''));
        $modeLabel = $includePayment ? 'tahsilat bilgisiyle' : 'sadece fatura olarak';
        flash('success', $label !== '' ? 'Teklif faturası Paraşüt’e ' . $modeLabel . ' aktarıldı: ' . $label : 'Teklif faturası Paraşüt’e ' . $modeLabel . ' aktarıldı.');
    } else {
        flash('error', 'Teklif faturası Paraşüt’te oluşturulamadı: ' . (string) ($result['error'] ?? 'Bilinmeyen hata'));
    }

    redirect(safe_return_path($_POST['return_to'] ?? '/'));
}

function handle_sales_offer_operation(RenewalRepository $repo, int $offerId): void
{
    verify_csrf();
    $returnTo = safe_return_path($_POST['return_to'] ?? '/');

    try {
        $status = (string) ($_POST['operation_status'] ?? 'approved');
        $note = (string) ($_POST['operation_note'] ?? '');
        $repo->updateSalesOfferOperation($offerId, $status, $note);
        flash('success', 'Onaylı teklif operasyon durumu güncellendi.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect($returnTo);
}

function create_parasut_invoice_for_sales_offer(RenewalRepository $repo, int $offerId, bool $includePayment = true): array
{
    $offer = $repo->findSalesOffer($offerId);
    if (!$offer) {
        return ['ok' => false, 'error' => 'Teklif bulunamadı.'];
    }

    if (!sales_offer_can_create_parasut_invoice($offer)) {
        return ['ok' => false, 'error' => 'Reddedilen, revize bekleyen veya süresi dolan teklifler için Paraşüt faturası oluşturulamaz.'];
    }

    if (trim((string) ($offer['parasut_invoice_id'] ?? '')) !== '') {
        $noteUpdate = sync_parasut_invoice_note_for_sales_offer($offer, $includePayment);

        return [
            'ok' => true,
            'already_created' => true,
            'invoice' => [
                'id' => (string) ($offer['parasut_invoice_id'] ?? ''),
                'invoice_no' => (string) ($offer['parasut_invoice_no'] ?? ''),
            ],
            'note_update' => $noteUpdate,
        ];
    }

    try {
        $client = new ParasutClient();
        $offer = ensure_sales_offer_parasut_contact($repo, $client, $offer);
        $invoiceOffer = sales_offer_invoice_payload_offer($offer, $includePayment);
        $invoice = $client->createSalesInvoiceFromOffer(
            $invoiceOffer,
            sales_offer_invoice_lines((array) ($offer['items'] ?? [])),
            $includePayment ? sales_offer_latest_paid_payment($offer) : null
        );
        if (trim((string) ($invoice['id'] ?? '')) === '') {
            throw new RuntimeException('Paraşüt fatura ID dönmedi.');
        }

        $repo->markSalesOfferParasutInvoice($offerId, $invoice);

        return ['ok' => true, 'invoice' => $invoice];
    } catch (Throwable $e) {
        $repo->markSalesOfferParasutInvoiceError($offerId, $e->getMessage());

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function ensure_sales_offer_parasut_contact(RenewalRepository $repo, ParasutClient $client, array $offer): array
{
    $customer = sales_offer_customer_context($repo, $offer);
    if ($customer) {
        $offer['customer_id'] = (int) ($customer['id'] ?? 0);
        $offer['company_name'] = (string) ($customer['company_name'] ?? $offer['customer_name'] ?? '');
        $offer['customer_tax_number'] = (string) ($customer['tax_number'] ?? '');
        $offer['customer_email'] = (string) (($offer['customer_email'] ?? '') ?: ($customer['email'] ?? ''));
        $offer['customer_phone'] = (string) (($offer['customer_phone'] ?? '') ?: ($customer['phone'] ?? ''));

        $contactId = trim((string) ($customer['parasut_contact_id'] ?? ''));
        if ($contactId !== '') {
            $offer['parasut_contact_id'] = $contactId;

            return $offer;
        }
    }

    $companyName = trim((string) (($offer['company_name'] ?? '') ?: ($offer['customer_name'] ?? '')));
    if ($companyName === '') {
        throw new RuntimeException('Teklif müşteri adı boş olduğu için Paraşüt carisi eşleştirilemedi.');
    }

    $contacts = $client->searchContacts($companyName, 8, 'customer');
    $matched = parasut_contact_match_for_customer($contacts, $companyName, (string) ($offer['customer_tax_number'] ?? ''));
    if ($matched === null || trim((string) ($matched['id'] ?? '')) === '') {
        throw new RuntimeException('Müşterinin Paraşüt cari ID bilgisi yok. Önce müşteriyi Paraşüt carisiyle eşleştirin.');
    }

    $contactId = trim((string) $matched['id']);
    if (!empty($offer['customer_id'])) {
        $repo->setCustomerParasutContactId((int) $offer['customer_id'], $contactId);
    }
    $offer['parasut_contact_id'] = $contactId;

    return $offer;
}

function sales_offer_customer_context(RenewalRepository $repo, array $offer): ?array
{
    $customerId = (int) ($offer['customer_id'] ?? 0);
    if ($customerId > 0) {
        $customer = $repo->findCustomer($customerId);
        if ($customer) {
            return $customer;
        }
    }

    $customerName = normalized_match_key((string) ($offer['customer_name'] ?? ''));
    $customerEmail = trim(mb_strtolower((string) ($offer['customer_email'] ?? '')));
    if ($customerName === '' && $customerEmail === '') {
        return null;
    }

    foreach ($repo->customersWithContacts() as $customer) {
        if ($customerName !== '' && normalized_match_key((string) ($customer['company_name'] ?? '')) === $customerName) {
            return $customer;
        }
        if ($customerEmail !== '' && trim(mb_strtolower((string) ($customer['email'] ?? ''))) === $customerEmail) {
            return $customer;
        }
    }

    return null;
}

function sales_offer_invoice_payload_offer(array $offer, bool $includePaymentInfo = true): array
{
    $offer['subject'] = (string) (($offer['title'] ?? '') ?: ('Teklif ' . sales_offer_number($offer)));
    $offer['payment_method'] = trim((string) ($offer['payment_method'] ?? ''));
    if (!$includePaymentInfo) {
        $offer['payment_method'] = '';
        $offer['renewal_payment_method'] = '';
    }

    return $offer;
}

function sales_offer_invoice_lines(array $items): array
{
    $lines = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $lines[] = [
            'item_title' => (string) (($item['title'] ?? '') ?: 'Ürün / hizmet'),
            'quantity' => (float) ($item['quantity'] ?? 1),
            'unit_price' => (float) ($item['unit_price'] ?? 0),
            'vat_rate' => (float) ($item['vat_rate'] ?? 20),
        ];
    }

    return $lines;
}

function sales_offer_latest_paid_payment(array $offer): ?array
{
    $requestIds = [
        (int) ($offer['payment_request_id'] ?? 0),
        (int) ($offer['balance_payment_request_id'] ?? 0),
    ];

    return (new PaymentRequestRepository())->latestPaidTransactionForRequests($requestIds);
}

function sync_parasut_invoice_note_for_sales_offer(array $offer, bool $includePayment = true): ?array
{
    $invoiceId = trim((string) ($offer['parasut_invoice_id'] ?? ''));
    if ($invoiceId === '') {
        return null;
    }

    try {
        $client = new ParasutClient();
        $note = $client->salesInvoiceNote(
            sales_offer_invoice_payload_offer($offer, $includePayment),
            $includePayment ? sales_offer_latest_paid_payment($offer) : null
        );

        return $client->updateSalesInvoiceNote($invoiceId, $note);
    } catch (Throwable $e) {
        error_log('Satış teklifi Paraşüt fatura notu güncellenemedi: ' . $e->getMessage());

        return [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function sales_offer_can_create_parasut_invoice(array $offer): bool
{
    if (trim((string) ($offer['parasut_invoice_id'] ?? '')) !== '') {
        return false;
    }

    return in_array((string) ($offer['status'] ?? 'draft'), ['draft', 'sent', 'approved'], true);
}

function sales_offer_public_url(int $offerId, string $mode = 'view', int $ttlDays = 14, string $readerToken = ''): string
{
    $mode = sales_offer_public_mode($mode);
    $expires = time() + (max(1, $ttlDays) * 86400);
    $readerToken = trim($readerToken);
    $query = [
        'mode' => $mode,
        'expires' => $expires,
    ];
    if ($readerToken !== '') {
        $query['r'] = $readerToken;
    }
    $query['sig'] = sales_offer_public_signature($offerId, $expires, $mode, $readerToken);

    return url('/teklif/' . $offerId) . '?' . http_build_query($query);
}

function sales_offer_public_mode(mixed $mode): string
{
    $mode = (string) $mode;

    return in_array($mode, ['view', 'pdf'], true) ? $mode : 'view';
}

function sales_offer_public_signature_valid(int $offerId, string $expires, string $mode, string $signature, string $readerToken = ''): bool
{
    if (!ctype_digit($expires) || (int) $expires < time()) {
        return false;
    }

    $expected = sales_offer_public_signature($offerId, (int) $expires, sales_offer_public_mode($mode), $readerToken);

    return $signature !== '' && hash_equals($expected, $signature);
}

function sales_offer_public_signature(int $offerId, int $expires, string $mode, string $readerToken = ''): string
{
    $payload = $offerId . '|' . $expires . '|' . sales_offer_public_mode($mode);
    $readerToken = trim($readerToken);
    if ($readerToken !== '') {
        $payload .= '|' . $readerToken;
    }

    return hash_hmac('sha256', $payload, app_link_secret());
}

function app_link_secret(): string
{
    $path = ROOT_PATH . '/storage/payment_link_secret.key';
    if (is_file($path)) {
        $secret = trim((string) file_get_contents($path));
        if ($secret !== '') {
            return $secret;
        }
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $secret = bin2hex(random_bytes(32));
    file_put_contents($path, $secret, LOCK_EX);
    @chmod($path, 0640);

    return $secret;
}

function approve_sales_offer_and_payment_request(RenewalRepository $repo, array $offer, array $approval = []): string
{
    $offerId = (int) ($offer['id'] ?? 0);
    if ($offerId <= 0) {
        throw new RuntimeException('Teklif kaydı okunamadı.');
    }

    if (empty($offer['payment_request_enabled'])) {
        $repo->markSalesOfferApproved($offerId, null, $approval);
        return '';
    }

    $paymentRepo = new PaymentRequestRepository();
    $paymentRequest = sales_offer_payment_request($offer, $paymentRepo);
    if (!$paymentRequest) {
        $percent = sales_offer_payment_percent($offer);
        $amount = sales_offer_advance_payment_amount($offer);
        $currency = normalize_allowed_currency((string) ($offer['currency'] ?? 'TRY'));
        $offerNumber = sales_offer_number($offer);
        $paymentRequest = $paymentRepo->create([
            'customer_id' => empty($offer['customer_id']) ? null : (int) $offer['customer_id'],
            'title' => $offerNumber . ' teklif ön ödemesi',
            'description' => $offerNumber . ' numaralı teklif onaylandı. KDV dahil toplam '
                . money_format_local($offer['total'] ?? 0, $currency)
                . ' üzerinden %' . number_format($percent, 2, ',', '.')
                . ' ön ödeme talep edildi.',
            'customer_name' => (string) ($offer['customer_name'] ?? ''),
            'customer_email' => (string) ($offer['customer_email'] ?? ''),
            'customer_phone' => (string) ($offer['customer_phone'] ?? ''),
            'customer_tax_number' => (string) ($offer['customer_tax_number'] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
            'created_by' => null,
        ]);
        $repo->markSalesOfferPaymentPending($offerId, (int) ($paymentRequest['id'] ?? 0), $approval);
        notify_manual_payment_request_created($paymentRequest);
    } elseif ((string) ($paymentRequest['status'] ?? 'pending') === 'paid') {
        $repo->markSalesOfferApproved($offerId, (int) ($paymentRequest['id'] ?? 0), $approval);
    } else {
        $repo->markSalesOfferPaymentPending($offerId, (int) ($paymentRequest['id'] ?? 0), $approval);
    }

    if ((string) ($paymentRequest['status'] ?? 'pending') === 'paid') {
        return manual_payment_request_url($paymentRequest);
    }

    if (manual_payment_needs_email($paymentRequest)) {
        $_SESSION['manual_payment_error'] = 'Kredi kartı ödemesine devam etmek için e-posta adresinizi girin.';
        return manual_payment_request_url($paymentRequest) . '#email-required';
    }

    try {
        return create_manual_iyzico_checkout_url($paymentRepo, $paymentRequest, null, 'sales-offer');
    } catch (Throwable $e) {
        $_SESSION['manual_payment_error'] = handle_caught_error($e, 'sales_offer_payment');
        return manual_payment_request_url($paymentRequest);
    }
}

function sales_offer_payment_request(array $offer, ?PaymentRequestRepository $paymentRepo = null): ?array
{
    $requestId = (int) ($offer['payment_request_id'] ?? 0);
    if ($requestId <= 0) {
        return null;
    }

    return ($paymentRepo ?? new PaymentRequestRepository())->find($requestId);
}

function sales_offer_balance_payment_request(array $offer, ?PaymentRequestRepository $paymentRepo = null): ?array
{
    $requestId = (int) ($offer['balance_payment_request_id'] ?? 0);
    if ($requestId <= 0) {
        return null;
    }

    return ($paymentRepo ?? new PaymentRequestRepository())->find($requestId);
}

function sales_offer_paid_request_amount(?array $request): float
{
    if (!$request || (string) ($request['status'] ?? 'pending') !== 'paid') {
        return 0.0;
    }

    return max(0.0, (float) ($request['amount'] ?? 0));
}

function sales_offer_remaining_balance_amount(array $offer, ?array $advanceRequest = null, ?array $balanceRequest = null): float
{
    $total = max(0.0, (float) ($offer['total'] ?? 0));
    $paid = sales_offer_paid_request_amount($advanceRequest) + sales_offer_paid_request_amount($balanceRequest);

    return max(0.0, round($total - $paid, 2));
}

function sales_offer_balance_payment_description(array $offer, array $advanceRequest, float $balanceAmount): string
{
    $currency = normalize_allowed_currency((string) ($offer['currency'] ?? 'TRY'));
    $offerNumber = sales_offer_number($offer);

    return $offerNumber . ' numaralı teklif için iş tamamlandıktan sonra kalan bakiye tahsilatı. '
        . 'Teklif toplamı: ' . money_format_local($offer['total'] ?? 0, $currency)
        . '. Alınan ön ödeme: ' . money_format_local($advanceRequest['amount'] ?? 0, $currency)
        . '. Kalan bakiye: ' . money_format_local($balanceAmount, $currency) . '.';
}

function sales_offer_balance_payment_message(array $offer, array $advanceRequest, array $balanceRequest): string
{
    $currency = normalize_allowed_currency((string) ($offer['currency'] ?? 'TRY'));
    $customer = trim((string) ($offer['customer_name'] ?? ''));
    $greeting = $customer !== '' ? 'Merhaba ' . $customer . ',' : 'Merhaba,';
    $offerNumber = sales_offer_number($offer);

    return $greeting
        . "\n\n" . $offerNumber . ' numaralı teklif kapsamındaki işlem tamamlanmıştır.'
        . "\nTeklif toplamı: " . money_format_local($offer['total'] ?? 0, $currency)
        . "\nAlınan ön ödeme: " . money_format_local($advanceRequest['amount'] ?? 0, $currency)
        . "\nKalan bakiye: " . money_format_local($balanceRequest['amount'] ?? 0, $currency)
        . "\n\nAşağıdaki güvenli bağlantıdan kalan bakiye ödemenizi tamamlayabilirsiniz.";
}

function sales_offer_payment_percent(array $offer): float
{
    $percent = (float) ($offer['payment_request_percent'] ?? 20);

    return max(1.0, min(100.0, $percent > 0 ? $percent : 20.0));
}

function sales_offer_advance_payment_amount(array $offer): float
{
    $total = max(0.0, (float) ($offer['total'] ?? 0));

    return max(0.01, round($total * sales_offer_payment_percent($offer) / 100, 2));
}

function sales_offer_approval_payload(?array $reader, string $readerToken): array
{
    $name = trim((string) ($reader['recipient_name'] ?? ''));
    $email = mb_strtolower(trim((string) ($reader['recipient_email'] ?? '')));
    $phone = trim((string) ($reader['recipient_phone'] ?? ''));

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }
    if ($name === '' && $email !== '') {
        $name = $email;
    }
    if ($name === '') {
        $name = 'Genel bağlantı';
    }

    return [
        'name' => mb_substr($name, 0, 190),
        'email' => mb_substr($email, 0, 190),
        'phone' => $phone,
        'delivery_token' => $readerToken,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ];
}

function sales_offer_reader_identity_label(?array $reader): string
{
    if (!$reader) {
        return 'Bu teklif bağlantısı';
    }

    $parts = array_values(array_filter([
        trim((string) ($reader['recipient_name'] ?? '')),
        trim((string) ($reader['recipient_email'] ?? '')),
        trim((string) ($reader['recipient_phone'] ?? '')),
    ], static fn (string $value): bool => $value !== ''));

    return $parts !== [] ? implode(' · ', $parts) : 'Bu teklif bağlantısı';
}

function sales_offer_has_recorded_approval(array $offer): bool
{
    if ((string) ($offer['status'] ?? '') === 'approved') {
        return true;
    }

    foreach (['approved_at', 'approved_name', 'approved_email', 'approved_phone'] as $field) {
        if (trim((string) ($offer[$field] ?? '')) !== '') {
            return true;
        }
    }

    if ((int) ($offer['approved_delivery_id'] ?? 0) > 0) {
        return true;
    }

    foreach ((array) ($offer['deliveries'] ?? []) as $delivery) {
        if (!empty($delivery['approved_at'])) {
            return true;
        }
    }

    return false;
}

function sales_offer_approval_label(array $offer): string
{
    $name = trim((string) ($offer['approved_name'] ?? ''));
    $email = trim((string) ($offer['approved_email'] ?? ''));
    $phone = trim((string) ($offer['approved_phone'] ?? ''));
    $approvedAt = sales_offer_delivery_date((string) ($offer['approved_at'] ?? ''));

    if ($name === '' && $email === '' && $phone === '') {
        foreach ((array) ($offer['deliveries'] ?? []) as $delivery) {
            if (!empty($delivery['approved_at']) || (int) ($delivery['id'] ?? 0) === (int) ($offer['approved_delivery_id'] ?? 0)) {
                $name = trim((string) (($delivery['approval_name'] ?? '') ?: ($delivery['recipient_name'] ?? '')));
                $email = trim((string) (($delivery['approval_email'] ?? '') ?: ($delivery['recipient_email'] ?? '')));
                $phone = trim((string) (($delivery['approval_phone'] ?? '') ?: ($delivery['recipient_phone'] ?? '')));
                $approvedAt = $approvedAt ?: sales_offer_delivery_date((string) ($delivery['approved_at'] ?? ''));
                break;
            }
        }
    }

    if ($name === '' && $email === '' && $phone === '') {
        foreach ((array) ($offer['deliveries'] ?? []) as $delivery) {
            if (empty($delivery['first_viewed_at'])) {
                continue;
            }
            $name = trim((string) ($delivery['recipient_name'] ?? ''));
            $email = trim((string) ($delivery['recipient_email'] ?? ''));
            $phone = trim((string) ($delivery['recipient_phone'] ?? ''));
            break;
        }
    }

    $parts = array_values(array_filter([$name, $email, $phone], static fn (string $value): bool => trim($value) !== ''));
    if ($parts === []) {
        return '';
    }

    return implode(' · ', $parts) . ($approvedAt !== '' ? ' · ' . $approvedAt : '');
}

function render_sales_offer_document(array $offer, bool $autoPrint = false, bool $publicLink = false, ?array $paymentRequest = null, string $error = '', ?array $reader = null): void
{
    render_public_layout('Teklif', static function () use ($offer, $autoPrint, $publicLink, $paymentRequest, $error, $reader): void {
        $currency = normalize_allowed_currency($offer['currency'] ?? 'TRY');
        $isApproved = sales_offer_has_recorded_approval($offer);
        $paymentEnabled = !empty($offer['payment_request_enabled']);
        $paymentAmount = sales_offer_advance_payment_amount($offer);
        $paymentPercent = sales_offer_payment_percent($offer);
        $readerIdentityLabel = sales_offer_reader_identity_label($reader);
        $approvalLabel = sales_offer_approval_label($offer);
        ?>
        <section class="login-panel customer-offer-public sales-offer-public">
            <div class="summary-print-actions customer-offer-print-actions">
                <button type="button" class="button secondary" onclick="window.print()">PDF olarak kaydet / yazdır</button>
            </div>

            <?= render_company_letterhead() ?>

            <div class="login-heading customer-offer-hero">
                <p class="customer-offer-kicker"><?= $publicLink ? 'MÜŞTERİ TEKLİFİ' : 'TEKLİF TASLAĞI' ?></p>
                <h1>Teklifinizi inceleyiniz.</h1>
                <p class="muted compact">Teklif no: <?= h(sales_offer_number($offer)) ?></p>
                <p class="muted compact"><?= h((string) ($offer['customer_name'] ?? '-')) ?> için hazırlanan teklif çıktısıdır.</p>
            </div>

            <?php if (!empty($offer['notes'])): ?>
                <div class="settings-note"><?= nl2br(h((string) $offer['notes']), false) ?></div>
            <?php endif; ?>

            <?= render_sales_offer_lines_public((array) ($offer['items'] ?? []), $currency) ?>

            <div class="payment-choice-summary customer-offer-totals">
                <div><span>Ara toplam</span><strong><?= h(money_format_local($offer['subtotal'] ?? 0, $currency)) ?></strong></div>
                <div><span>KDV</span><strong><?= h(money_format_local($offer['vat_total'] ?? 0, $currency)) ?></strong></div>
                <div><span>KDV dahil toplam</span><strong><?= h(money_format_local($offer['total'] ?? 0, $currency)) ?></strong></div>
            </div>

            <?php if ($publicLink): ?>
                <?php if ($error !== ''): ?>
                    <div class="alert error"><?= h($error) ?></div>
                <?php endif; ?>
                <div class="sales-offer-approval-box">
                    <?php if ($isApproved): ?>
                        <div>
                            <p class="eyebrow">Onay durumu</p>
                            <h2>Bu teklif onaylandı.</h2>
                            <?php if ($approvalLabel !== ''): ?>
                                <p>Bu teklif <?= h($approvalLabel) ?> tarafından onaylandı.</p>
                            <?php endif; ?>
                            <?php if ($paymentEnabled): ?>
                                <p>Ön ödeme talebi: <?= h(money_format_local($paymentRequest['amount'] ?? $paymentAmount, $currency)) ?>.</p>
                            <?php else: ?>
                                <p>Onayınız alınmıştır. Ekibimiz süreç için sizinle iletişime geçecektir.</p>
                            <?php endif; ?>
                        </div>
                        <?php if ($paymentRequest): ?>
                            <?php if ((string) ($paymentRequest['status'] ?? 'pending') === 'paid'): ?>
                                <span class="badge active">Ödeme alındı</span>
                            <?php else: ?>
                                <a class="button primary" href="<?= h(manual_payment_request_url($paymentRequest)) ?>">Ödeme ekranına geç</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <div>
                            <p class="eyebrow">Teklif onayı</p>
                            <h2>Teklifi onayla</h2>
                            <?php if ($paymentEnabled): ?>
                                <p>Onaydan sonra <?= h('%' . number_format($paymentPercent, 2, ',', '.')) ?> ön ödeme olarak <?= h(money_format_local($paymentAmount, $currency)) ?> kredi kartı ödeme ekranına yönlendirilir.</p>
                            <?php else: ?>
                                <p>Onayladığınızda teklif kayda alınır; ödeme talebi oluşturulmaz.</p>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="sales-offer-approval-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve_sales_offer">
                            <div class="sales-offer-approval-person-card">
                                <span>Onay bu alıcı adına kaydedilecek</span>
                                <strong><?= h($readerIdentityLabel) ?></strong>
                            </div>
                            <button type="submit" class="button primary">Teklifi onayla<?= $paymentEnabled ? ' ve ödemeye geç' : '' ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php if ($autoPrint): ?>
            <script>
                window.addEventListener('load', function () {
                    window.setTimeout(function () { window.print(); }, 300);
                });
            </script>
        <?php endif; ?>
        <?php
    });
}

function render_sales_offer_lines_public(array $items, string $currency): string
{
    if ($items === []) {
        return '<div class="empty">Teklif kalemi bulunamadı.</div>';
    }

    ob_start();
    ?>
    <div class="public-payment-items customer-offer-lines">
        <h2>Teklif kalemleri</h2>
        <?php foreach ($items as $item): ?>
            <?php
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $subtotal = (float) ($item['line_subtotal'] ?? ($quantity * $unitPrice));
            $vatRate = (float) ($item['vat_rate'] ?? 0);
            $total = (float) ($item['line_total'] ?? ($subtotal + ($subtotal * $vatRate / 100)));
            ?>
            <div class="public-payment-item customer-offer-line">
                <div>
                    <strong><?= h((string) ($item['title'] ?? '-')) ?></strong>
                    <?php if (!empty($item['brand'])): ?>
                        <span><?= h((string) $item['brand']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($item['description'])): ?>
                        <em><?= h((string) $item['description']) ?></em>
                    <?php endif; ?>
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

function sales_offer_number(array $offer): string
{
    $number = trim((string) ($offer['offer_number'] ?? ''));
    if ($number !== '') {
        return $number;
    }

    return 'TK-' . date('Y', strtotime((string) ($offer['created_at'] ?? 'now'))) . '-' . str_pad((string) (int) ($offer['id'] ?? 0), 6, '0', STR_PAD_LEFT);
}

function sales_offer_whatsapp_message(array $offer, string $url): string
{
    $lines = [
        'Merhaba,',
        '',
        sales_offer_number($offer) . ' numaralı teklifinizi incelemeniz için paylaşıyoruz.',
        'Firma: ' . (string) ($offer['customer_name'] ?? '-'),
        'Teklif: ' . (string) ($offer['title'] ?? '-'),
        '',
        'Teklif / PDF çıktısı: ' . $url,
    ];

    return implode("\n", $lines);
}

function sales_offer_mail_body(array $offer, string $url, string $mode, string $customIntro = ''): string
{
    $rows = [
        'Teklif no' => sales_offer_number($offer),
        'Firma' => (string) ($offer['customer_name'] ?? '-'),
        'Teklif' => (string) ($offer['title'] ?? '-'),
        'Teklif detayı' => 'Güvenli bağlantıdan görüntülenir',
    ];

    $htmlRows = '';
    foreach ($rows as $label => $value) {
        $htmlRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;width:34%;">' . h($label) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . h($value) . '</td>'
            . '</tr>';
    }

    $lineRows = '';
    foreach ((array) ($offer['items'] ?? []) as $item) {
        $quantity = (float) ($item['quantity'] ?? 1);
        $lineRows .= '<tr>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:700;">' . h((string) ($item['title'] ?? '-')) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;text-align:center;">' . h(number_format($quantity, 2, ',', '.')) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;text-align:right;">Linkte görüntülenir</td>'
            . '</tr>';
    }

    $lineTable = $lineRows === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;border-collapse:collapse;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr>'
        . '<th align="left" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Ürün / hizmet</th>'
        . '<th align="center" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Adet</th>'
        . '<th align="right" style="padding:9px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:12px;text-transform:uppercase;">Detay</th>'
        . '</tr>'
        . $lineRows
        . '</table>';

    $buttonLabel = $mode === 'pdf' ? 'PDF / teklif çıktısını aç' : 'Teklifi görüntüle';
    $intro = trim($customIntro);
    if ($intro === '') {
        $intro = $mode === 'pdf'
            ? 'Teklif çıktınızı PDF olarak kaydedebilmeniz için bağlantıyı paylaşıyoruz.'
            : 'Hazırlanan teklifinizi inceleyebilmeniz için bağlantıyı paylaşıyoruz.';
    }
    $intro = mail_message_without_prices($intro);

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:24px;background:#f2f6f4;font-family:Arial,sans-serif;color:#17201c;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d9e3df;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Müşteri teklifi</p>'
        . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;">Teklifinizi inceleyebilirsiniz.</h1>'
        . '<p style="margin:0 0 18px;color:#26322e;font-size:15px;line-height:1.6;">' . h($intro) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $htmlRows . '</table>'
        . $lineTable
        . '<p style="margin:20px 0 0;"><a href="' . h($url) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:14px 20px;font-weight:700;">' . h($buttonLabel) . '</a></p>'
        . '<p style="margin:14px 0 0;color:#607069;font-size:13px;line-height:1.5;">Bağlantı süreli olarak oluşturulmuştur. PDF almak için açılan sayfada “PDF olarak kaydet / yazdır” butonunu kullanabilirsiniz.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function handle_sales_offer_create(RenewalRepository $repo, string $method, ?int $id = null): void
{
    $templates = $repo->offerTemplates();
    $stockItems = $repo->stockItems('', 250);
    $customerChoices = manual_payment_customer_choices($repo->customersWithContacts());
    $editingOffer = $id !== null ? $repo->findSalesOffer($id) : null;
    if ($id !== null && $editingOffer === null) {
        flash('error', 'Düzenlenecek teklif bulunamadı.');
        redirect('/');
    }

    $templateId = $editingOffer !== null ? (int) ($editingOffer['template_id'] ?? 0) : max(0, (int) ($_GET['template_id'] ?? 0));
    $blankMode = $editingOffer !== null || !empty($_GET['blank']);
    $selectedTemplate = $editingOffer === null && $templateId > 0 ? $repo->findOfferTemplate($templateId) : null;
    $errors = [];
    if ($editingOffer !== null) {
            $formData = [
                'template_id' => $editingOffer['template_id'] ?? null,
                'customer_id' => (string) ($editingOffer['customer_id'] ?? ''),
                'offer_title' => (string) $editingOffer['title'],
                'customer_name' => (string) $editingOffer['customer_name'],
            'customer_email' => (string) ($editingOffer['customer_email'] ?? ''),
            'customer_phone' => (string) ($editingOffer['customer_phone'] ?? ''),
            'currency' => (string) ($editingOffer['currency'] ?? 'TRY'),
            'notes' => (string) ($editingOffer['notes'] ?? ''),
            'payment_request_enabled' => (int) ($editingOffer['payment_request_enabled'] ?? 0),
            'payment_request_percent' => (string) ($editingOffer['payment_request_percent'] ?? '20.00'),
            'items' => $editingOffer['items'] ?? [['title' => '', 'brand' => '', 'description' => '', 'quantity' => '1.00', 'unit_price' => '0.00', 'vat_rate' => '20.00']],
        ];
    } else {
            $formData = [
                'template_id' => $selectedTemplate['id'] ?? null,
                'customer_id' => '',
                'offer_title' => $selectedTemplate ? (string) $selectedTemplate['name'] : '',
            'customer_name' => '',
            'customer_email' => '',
            'customer_phone' => '',
            'currency' => $selectedTemplate ? (string) $selectedTemplate['currency'] : 'TRY',
            'notes' => $selectedTemplate ? (string) ($selectedTemplate['description'] ?? '') : '',
            'payment_request_enabled' => 0,
            'payment_request_percent' => '20.00',
            'items' => $selectedTemplate['items'] ?? [['title' => '', 'brand' => '', 'description' => '', 'quantity' => '1.00', 'unit_price' => '0.00', 'vat_rate' => '20.00']],
        ];
    }

    if ($method === 'POST') {
        verify_csrf();
        $formData = $_POST;
        $formData['created_by'] = (int) ($_SESSION['user_id'] ?? 0);

        try {
            if ($editingOffer !== null) {
                $repo->updateSalesOffer((int) $editingOffer['id'], $formData);
                flash('success', 'Teklif güncellendi ve taslağa alındı. Eski mail/WhatsApp linkleri iptal edildi; müşteriye yeniden göndermeniz gerekiyor: ' . (string) (($editingOffer['offer_number'] ?? '') ?: ('TK-' . date('Y', strtotime((string) $editingOffer['created_at'])) . '-' . str_pad((string) (int) $editingOffer['id'], 6, '0', STR_PAD_LEFT))));
                redirect('/');
            }

            $offerId = $repo->createSalesOffer($formData);
            $createdOffer = $repo->findSalesOffer($offerId);
            flash('success', 'Yeni teklif taslak olarak oluşturuldu: ' . (string) (($createdOffer['offer_number'] ?? '') ?: ('TK-' . date('Y') . '-' . str_pad((string) $offerId, 6, '0', STR_PAD_LEFT))));
            redirect('/');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    render_layout($editingOffer !== null ? 'Teklifi Düzenle' : 'Yeni Teklif', static function () use ($templates, $stockItems, $customerChoices, $selectedTemplate, $blankMode, $errors, $formData, $editingOffer): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Teklifler</p>
                <h1><?= $editingOffer !== null ? 'Teklifi düzenle' : 'Yeni teklif oluştur' ?></h1>
            </div>
            <div class="page-actions">
                <a href="<?= h(url('/')) ?>" class="button secondary">Dashboard'a dön</a>
            </div>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= h($error) ?></div>
        <?php endforeach; ?>

        <?= render_stock_item_datalist($stockItems) ?>
        <script type="application/json" id="offer-builder-customers-json"><?= json_encode($customerChoices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?></script>

        <?php if (!$blankMode && !$selectedTemplate && $errors === []): ?>
            <section class="offer-start-grid">
                <article class="offer-start-card blank">
                    <span>Boş teklif</span>
                    <h2>Şablonsuz yeni teklif aç</h2>
                    <p>Müşteri, kalem ve fiyatları sıfırdan girerek teklif hazırlayın.</p>
                    <a class="button primary" href="<?= h(url('/offers/create?blank=1')) ?>">Boş teklif aç</a>
                </article>
                <article class="offer-start-card">
                    <span>Şablondan oluştur</span>
                    <h2>Hazır teklif şablonu getir</h2>
                    <p>Daha önce hazırladığınız kamera sistemi, lisans paketi veya hizmet tekliflerini tek tıkla başlatın.</p>
                    <a class="button secondary" href="<?= h(url('/settings/offer-templates')) ?>">Şablonları yönet</a>
                </article>
                <?php foreach ($templates as $template): ?>
                    <article class="offer-template-pick-card">
                        <strong><?= h((string) $template['name']) ?></strong>
                        <span><?= h((string) count((array) ($template['items'] ?? []))) ?> kalem · <?= h((string) $template['currency']) ?></span>
                        <?php if (!empty($template['description'])): ?>
                            <p><?= h((string) $template['description']) ?></p>
                        <?php endif; ?>
                        <a class="button small primary" href="<?= h(url('/offers/create?template_id=' . (int) $template['id'])) ?>">Şablonu getir</a>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <section class="panel offer-builder-panel">
                <div class="section-head">
                    <div>
                        <?php if ($editingOffer !== null): ?>
                            <h2>Teklif bilgilerini güncelle</h2>
                            <span><?= h((string) (($editingOffer['offer_number'] ?? '') ?: ('TK-' . date('Y', strtotime((string) $editingOffer['created_at'])) . '-' . str_pad((string) (int) $editingOffer['id'], 6, '0', STR_PAD_LEFT)))) ?> numaralı teklif üzerinde çalışıyorsunuz.</span>
                        <?php else: ?>
                            <h2><?= $selectedTemplate ? 'Şablondan teklif' : 'Boş teklif' ?></h2>
                            <span><?= $selectedTemplate ? h((string) $selectedTemplate['name']) . ' şablonu ile başlatıldı.' : 'Kalemleri ve fiyatları kendiniz belirleyin.' ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($editingOffer === null): ?>
                        <a class="button small secondary" href="<?= h(url('/offers/create')) ?>">Şablon seçimine dön</a>
                    <?php endif; ?>
                </div>
                <form method="post" class="form-grid offer-builder-form" data-offer-builder-form data-stock-search-url="<?= h(url('/api/stock-items')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="template_id" value="<?= h((string) ($formData['template_id'] ?? '')) ?>">
                    <input type="hidden" name="customer_id" value="<?= h((string) ($formData['customer_id'] ?? '')) ?>" data-offer-customer-id>
                    <div class="form-grid three span-2">
                        <label>
                            Teklif başlığı
                            <input name="offer_title" value="<?= h((string) ($formData['offer_title'] ?? '')) ?>" placeholder="Örn: 4 kameralı güvenlik sistemi" required>
                        </label>
                        <div class="offer-customer-field">
                            <label>
                            Firma / müşteri
                                <input name="customer_name" value="<?= h((string) ($formData['customer_name'] ?? '')) ?>" placeholder="Cari unvanı yazın veya seçin" autocomplete="off" data-offer-customer-input required>
                            </label>
                            <div class="offer-customer-results" data-offer-customer-results hidden></div>
                        </div>
                        <label>
                            Para birimi
                            <select name="currency" data-offer-builder-currency>
                                <?= option('TRY', 'TRY', (string) ($formData['currency'] ?? 'TRY')) ?>
                                <?= option('USD', 'USD', (string) ($formData['currency'] ?? 'TRY')) ?>
                                <?= option('EUR', 'EUR', (string) ($formData['currency'] ?? 'TRY')) ?>
                            </select>
                        </label>
                    </div>
                    <div class="form-grid two span-2">
                        <label>
                            E-posta
                            <input type="email" name="customer_email" value="<?= h((string) ($formData['customer_email'] ?? '')) ?>" placeholder="musteri@firma.com" data-offer-customer-email>
                        </label>
                        <label>
                            Telefon
                            <input name="customer_phone" value="<?= h((string) ($formData['customer_phone'] ?? '')) ?>" placeholder="0549 576 05 49" data-offer-customer-phone>
                        </label>
                    </div>
                    <label class="span-2">
                        Not
                        <textarea name="notes" rows="3" placeholder="Teklif özel notu, teslim süresi veya kapsam bilgisi"><?= h((string) ($formData['notes'] ?? '')) ?></textarea>
                    </label>

                    <div class="offer-advance-payment span-2">
                        <label class="checkline choice-line">
                            <input
                                type="checkbox"
                                name="payment_request_enabled"
                                value="1"
                                <?= !empty($formData['payment_request_enabled']) ? 'checked' : '' ?>
                            >
                            Teklif onaylanınca ön ödeme talep et
                        </label>
                        <label>
                            Ön ödeme oranı %
                            <input
                                type="number"
                                min="1"
                                max="100"
                                step="0.01"
                                name="payment_request_percent"
                                value="<?= h(number_format((float) ($formData['payment_request_percent'] ?? 20), 2, '.', '')) ?>"
                            >
                        </label>
                        <p>Standart %20 gelir. Örneğin %50 yazarsanız müşteri teklifi onayladığında toplamın yarısı için kredi kartı ödeme ekranına yönlendirilir.</p>
                    </div>

                    <div class="offer-line-editor span-2">
                        <div class="section-head compact">
                            <div>
                                <h3>Teklif kalemleri</h3>
                                <span>Şablondan gelen kalemleri değiştirebilir veya yeni kalem ekleyebilirsiniz.</span>
                            </div>
                            <button type="button" class="button small secondary" data-offer-add-line>+ Kalem ekle</button>
                        </div>
                        <div class="offer-line-list" data-offer-line-list>
                            <?php foreach (array_values((array) ($formData['items'] ?? [])) as $index => $item): ?>
                                <?= render_offer_builder_item_row((int) $index, (array) $item) ?>
                            <?php endforeach; ?>
                        </div>
                        <template data-offer-line-template>
                            <?= render_offer_builder_item_row('__INDEX__', []) ?>
                        </template>
                    </div>

                    <div class="customer-offer-total-preview offer-builder-total span-2">
                        <span>Ara toplam: <b data-offer-subtotal>-</b></span>
                        <span>KDV: <b data-offer-vat>-</b></span>
                        <span>KDV dahil: <b data-offer-total>-</b></span>
                    </div>

                    <div class="form-actions span-2">
                        <a href="<?= h(url('/')) ?>" class="button secondary">Vazgeç</a>
                        <button type="submit" class="button primary"><?= $editingOffer !== null ? 'Teklifi güncelle' : 'Teklifi taslak oluştur' ?></button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
        <?php
    });
}

function handle_stock_items(RenewalRepository $repo, string $method): void
{
    $errors = [];

    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'sync_parasut_products') {
                $products = (new ParasutClient())->fetchAllProducts();
                $summary = $repo->syncStockItemsFromParasut($products);
                flash(
                    'success',
                    'Paraşüt ürünleri içeri alındı. Yeni: ' . (int) $summary['created']
                    . ', güncellenen: ' . (int) $summary['updated']
                    . ', pasife alınan: ' . (int) $summary['inactive']
                    . '.'
                );
                redirect('/settings/stock-items');
            }

            if ($action === 'create_stock_item') {
                $repo->createStockItem($_POST);
                flash('success', 'Manuel stok / teklif kalemi eklendi.');
                redirect('/settings/stock-items');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    $stats = $repo->stockItemStats();
    $items = $repo->stockItems($query, 250);

    render_layout('Stok / Teklif Kalemleri', static function () use ($errors, $query, $stats, $items): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Ayarlar</p>
                <h1>Stok / teklif kalemleri</h1>
            </div>
            <div class="page-actions">
                <a href="#stock-manual-entry" class="button primary">Manuel kalem ekle</a>
                <a href="<?= h(url('/settings/offer-templates')) ?>" class="button secondary">Teklif şablonları</a>
                <a href="<?= h(url('/settings')) ?>" class="button secondary">Ayarlara dön</a>
            </div>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= h($error) ?></div>
        <?php endforeach; ?>

        <section class="stock-sync-panel">
            <div>
                <p class="eyebrow">Paraşüt ürün kataloğu</p>
                <h2>Ürünleri bir kere içeri alın, teklifleri yerel stoktan hazırlayın.</h2>
                <p>Teklif ve şablon ekranları Paraşüt’e tekrar tekrar sorgu atmaz; buradaki yerel katalogdan beslenir. Paraşüt’te değişiklik yaptığınızda bu senkronizasyonu tekrar çalıştırmanız yeterli.</p>
            </div>
            <form method="post" onsubmit="return confirm('Paraşüt ürün/hizmet kataloğu yerel stok tablosuna işlensin mi?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="sync_parasut_products">
                <button type="submit" class="button primary">Paraşüt’ten tüm ürünleri içeri al</button>
            </form>
        </section>

        <section class="stock-manual-panel" id="stock-manual-entry">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Manuel kayıt</p>
                    <h2>Stok / teklif kalemi ekle</h2>
                    <span>Paraşüt’te olmayan ürün, hizmet, montaj veya lisans kalemlerini buradan yerel kataloğa ekleyin.</span>
                </div>
            </div>
            <form method="post" class="form-grid stock-manual-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_stock_item">
                <label>
                    Ürün / hizmet adı
                    <input name="name" maxlength="190" placeholder="Örn: Kamera montaj hizmeti" required>
                </label>
                <label>
                    Marka / model
                    <input name="brand" maxlength="120" placeholder="Örn: Hikvision, Sophos">
                </label>
                <label>
                    Kod
                    <input name="code" maxlength="120" placeholder="Stok kodu">
                </label>
                <label>
                    Barkod
                    <input name="barcode" maxlength="120" placeholder="Varsa barkod">
                </label>
                <label>
                    Birim
                    <input name="unit" maxlength="40" value="Adet">
                </label>
                <label>
                    Para birimi
                    <select name="currency">
                        <option value="TRY">TRY</option>
                        <option value="USD">USD</option>
                        <option value="EUR">EUR</option>
                    </select>
                </label>
                <label>
                    Satış fiyatı
                    <input type="number" min="0" step="0.01" name="list_price" value="0.00">
                </label>
                <label>
                    Alış fiyatı
                    <input type="number" min="0" step="0.01" name="buying_price" placeholder="Opsiyonel">
                </label>
                <label>
                    KDV %
                    <input type="number" min="0" max="100" step="0.01" name="vat_rate" value="20.00">
                </label>
                <label>
                    Stok adedi
                    <input type="number" min="0" step="0.01" name="stock_count" placeholder="Opsiyonel">
                </label>
                <label class="checkbox-line field-wide">
                    <input type="checkbox" name="inventory_tracking" value="1">
                    <span>Stok takibi yapılsın</span>
                </label>
                <button type="submit" class="button primary field-wide">Kalemi kaydet</button>
            </form>
        </section>

        <section class="stock-summary-grid">
            <article>
                <span>Toplam kalem</span>
                <strong><?= h((string) $stats['total']) ?></strong>
            </article>
            <article>
                <span>Aktif kalem</span>
                <strong><?= h((string) $stats['active']) ?></strong>
            </article>
            <article>
                <span>Paraşüt kaynaklı</span>
                <strong><?= h((string) $stats['parasut_total']) ?></strong>
            </article>
            <article>
                <span>Son senkron</span>
                <strong><?= h($stats['last_synced_at'] !== '' ? date('d.m.Y H:i', strtotime((string) $stats['last_synced_at'])) : '-') ?></strong>
            </article>
        </section>

        <form method="get" class="filter-bar stock-filter">
            <input name="q" value="<?= h($query) ?>" placeholder="Ürün adı, kod, barkod veya marka ara">
            <button class="button secondary" type="submit">Filtrele</button>
        </form>

        <section class="stock-board">
            <?php if ($items === []): ?>
                <div class="empty">Stok kalemi bulunamadı. Paraşüt ürünlerini içeri alabilir veya manuel kalem ekleyebilirsiniz.</div>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <article class="stock-item-card">
                        <div>
                            <span><?= h((string) (($item['code'] ?? '') ?: ($item['barcode'] ?? '') ?: 'Stok')) ?></span>
                            <strong><?= h((string) $item['name']) ?></strong>
                            <em><?= h(trim((string) (($item['brand'] ?? '') . ' ' . ($item['unit'] ?? '')))) ?></em>
                        </div>
                        <div class="stock-price">
                            <span><?= h(money_format_local($item['list_price'] ?? 0, (string) ($item['currency'] ?? 'TRY'))) ?></span>
                            <small>KDV %<?= h(number_format((float) ($item['vat_rate'] ?? 20), 2, ',', '.')) ?></small>
                        </div>
                        <div class="stock-meta">
                            <span><?= h((string) ($item['source'] ?? 'manual') === 'parasut' ? 'Paraşüt' : 'Manuel') ?> · <?= !empty($item['inventory_tracking']) ? 'Stok takipli' : 'Stok takipsiz' ?></span>
                            <strong><?= $item['stock_count'] !== null ? h(number_format((float) $item['stock_count'], 2, ',', '.')) : '-' ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php
    });
}

function handle_offer_templates(RenewalRepository $repo, string $method): void
{
    $errors = [];

    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'create_template');

        try {
            if ($action === 'create_template') {
                $data = $_POST;
                $data['created_by'] = (int) ($_SESSION['user_id'] ?? 0);
                $repo->createOfferTemplate($data);
                flash('success', 'Teklif şablonu oluşturuldu.');
                redirect('/settings/offer-templates');
            }

            if ($action === 'update_template') {
                $repo->updateOfferTemplate((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Teklif şablonu güncellendi.');
                redirect('/settings/offer-templates');
            }

            if ($action === 'delete_template') {
                $repo->deleteOfferTemplate((int) ($_POST['id'] ?? 0));
                flash('success', 'Teklif şablonu silindi.');
                redirect('/settings/offer-templates');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    $templates = $repo->offerTemplates(true);
    $stockItems = $repo->stockItems('', 250);

    render_layout('Teklif Şablonları', static function () use ($templates, $stockItems, $errors): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Ayarlar</p>
                <h1>Teklif şablonları</h1>
            </div>
            <div class="page-actions">
                <button type="button" class="button primary" data-dialog-open="offer-template-create-dialog">Yeni şablon</button>
                <a href="<?= h(url('/settings')) ?>" class="button secondary">Ayarlara dön</a>
            </div>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert error"><?= h($error) ?></div>
        <?php endforeach; ?>

        <?= render_stock_item_datalist($stockItems) ?>

        <section class="offer-template-board">
            <?php if ($templates === []): ?>
                <div class="empty">Henüz teklif şablonu yok. İlk şablonu oluşturup teklif tarafını hızlandırabilirsiniz.</div>
            <?php endif; ?>
            <?php foreach ($templates as $template): ?>
                <article class="offer-template-card <?= empty($template['is_active']) ? 'inactive' : '' ?>">
                    <div class="section-head compact">
                        <div>
                            <h2><?= h((string) $template['name']) ?></h2>
                            <span><?= h((string) $template['currency']) ?> · <?= h((string) count((array) ($template['items'] ?? []))) ?> kalem</span>
                        </div>
                        <span class="badge <?= !empty($template['is_active']) ? 'active' : 'cancelled' ?>"><?= !empty($template['is_active']) ? 'Aktif' : 'Pasif' ?></span>
                    </div>
                    <?php if (!empty($template['description'])): ?>
                        <p class="muted compact"><?= h((string) $template['description']) ?></p>
                    <?php endif; ?>
                    <details class="definition-card-details">
                        <summary>
                            <span>Detay</span>
                            <strong>Düzenle</strong>
                        </summary>
                        <form method="post" class="form-grid offer-template-form" data-offer-builder-form data-stock-search-url="<?= h(url('/api/stock-items')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_template">
                            <input type="hidden" name="id" value="<?= h((string) $template['id']) ?>">
                            <?= render_offer_template_fields($template) ?>
                            <div class="form-actions span-2">
                                <button type="submit" class="button primary">Şablonu kaydet</button>
                            </div>
                        </form>
                        <form method="post" class="definition-delete-form" onsubmit="return confirm('Bu teklif şablonu silinsin mi? Eski teklifler etkilenmez.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_template">
                            <input type="hidden" name="id" value="<?= h((string) $template['id']) ?>">
                            <button type="submit" class="button danger small">Şablonu sil</button>
                        </form>
                    </details>
                </article>
            <?php endforeach; ?>
        </section>

        <dialog class="app-dialog definition-dialog offer-template-dialog" id="offer-template-create-dialog" <?= $errors !== [] ? 'data-auto-open-dialog' : '' ?>>
            <div class="app-dialog-body">
                <div class="section-head dialog-head">
                    <div>
                        <p class="eyebrow">Yeni şablon</p>
                        <h2>Teklif şablonu oluştur</h2>
                        <span>Örneğin “4 kameralı sistem” gibi sık kullanılan teklifleri buradan hazırlayın.</span>
                    </div>
                    <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
                </div>
                <form method="post" class="form-grid offer-template-form" data-offer-builder-form data-stock-search-url="<?= h(url('/api/stock-items')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_template">
                    <?= render_offer_template_fields(['currency' => 'TRY', 'items' => [['title' => '', 'brand' => '', 'description' => '', 'quantity' => '1.00', 'unit_price' => '0.00', 'vat_rate' => '20.00']]]) ?>
                    <div class="form-actions span-2">
                        <button type="button" class="button secondary" data-dialog-close>Vazgeç</button>
                        <button type="submit" class="button primary">Şablonu oluştur</button>
                    </div>
                </form>
            </div>
        </dialog>
        <?php
    });
}

function render_sales_offer_lane(array $offers, bool $canManage, bool $canDelete): string
{
    if ($offers === []) {
        return '<div class="empty lane-empty">Henüz teklif taslağı yok. Sağ tarafı artık teklif modülü için kullanıyoruz.</div>';
    }

    $paymentRepo = new PaymentRequestRepository();
    $paymentRequests = $paymentRepo->all(200);
    ob_start();
    ?>
    <div class="dashboard-card-list">
        <?php foreach ($offers as $offer): ?>
            <?php
            $offerId = (int) ($offer['id'] ?? 0);
            $offerCurrency = normalize_allowed_currency((string) ($offer['currency'] ?? 'TRY'));
            $advancePayment = sales_offer_payment_request($offer, $paymentRepo);
            $balancePayment = sales_offer_balance_payment_request($offer, $paymentRepo);
            $remainingBalance = sales_offer_remaining_balance_amount($offer, $advancePayment, $balancePayment);
            $advancePaid = $advancePayment && (string) ($advancePayment['status'] ?? 'pending') === 'paid';
            $balancePaid = $balancePayment && (string) ($balancePayment['status'] ?? 'pending') === 'paid';
            $parasutInvoiceId = trim((string) ($offer['parasut_invoice_id'] ?? ''));
            $parasutInvoiceNo = trim((string) ($offer['parasut_invoice_no'] ?? ''));
            $parasutInvoiceStatus = trim((string) ($offer['parasut_invoice_status'] ?? ''));
            $canCollectBalance = $canManage
                && (string) ($offer['status'] ?? 'draft') === 'approved'
                && $advancePaid
                && !$balancePaid
                && $remainingBalance > 0;
            $canCreateParasutInvoice = $canManage && sales_offer_can_create_parasut_invoice($offer);
            $statusLabel = sales_offer_dashboard_status_label($offer, $advancePayment);
            $statusBadge = sales_offer_dashboard_status_badge($offer, $advancePayment);
            ?>
            <details class="dashboard-track-card offers sales-offer-card">
                <summary class="track-summary">
                    <span class="track-main">
                        <small>Teklif / Cari</small>
                        <strong><?= h((string) $offer['customer_name']) ?></strong>
                        <em><?= h(trim((string) (($offer['offer_number'] ?? '') !== '' ? ($offer['offer_number'] . ' · ' . $offer['title']) : $offer['title']))) ?></em>
                    </span>
                    <span class="track-days">
                        <small>Tutar</small>
                        <strong><?= h(money_format_local($offer['total'] ?? 0, (string) ($offer['currency'] ?? 'TRY'))) ?></strong>
                    </span>
                    <span class="badge <?= h($statusBadge) ?>">
                        <?= h($statusLabel) ?>
                    </span>
                    <span class="track-toggle">Göster</span>
                </summary>
                <div class="track-body">
                    <div class="track-meta-grid">
                        <div>
                            <span>Teklif no</span>
                            <strong><?= h((string) (($offer['offer_number'] ?? '') ?: ('TK-' . date('Y', strtotime((string) $offer['created_at'])) . '-' . str_pad((string) (int) $offer['id'], 6, '0', STR_PAD_LEFT)))) ?></strong>
                        </div>
                        <div>
                            <span>Şablon</span>
                            <strong><?= h((string) (($offer['template_name'] ?? '') ?: 'Boş teklif')) ?></strong>
                        </div>
                        <div>
                            <span>Oluşturma</span>
                            <strong><?= h(date('d.m.Y H:i', strtotime((string) $offer['created_at']))) ?></strong>
                        </div>
                        <div>
                            <span>Ara toplam</span>
                            <strong><?= h(money_format_local($offer['subtotal'] ?? 0, (string) ($offer['currency'] ?? 'TRY'))) ?></strong>
                        </div>
                        <div>
                            <span>KDV dahil</span>
                            <strong><?= h(money_format_local($offer['total'] ?? 0, $offerCurrency)) ?></strong>
                        </div>
                        <div>
                            <span>Ön ödeme</span>
                            <strong>
                                <?= $advancePayment ? h(money_format_local($advancePayment['amount'] ?? 0, $offerCurrency)) : '-' ?>
                                <?= $advancePayment ? ' · ' . h(payment_request_status_label((string) ($advancePayment['status'] ?? 'pending'))) : '' ?>
                            </strong>
                        </div>
                        <div>
                            <span>Kalan bakiye</span>
                            <strong>
                                <?php if ($balancePayment): ?>
                                    <?= h(money_format_local($balancePayment['amount'] ?? 0, $offerCurrency)) ?> · <?= h(payment_request_status_label((string) ($balancePayment['status'] ?? 'pending'))) ?>
                                <?php else: ?>
                                    <?= h(money_format_local($remainingBalance, $offerCurrency)) ?>
                                <?php endif; ?>
                            </strong>
                        </div>
                        <div>
                            <span>Paraşüt faturası</span>
                            <strong>
                                <?php if ($parasutInvoiceId !== ''): ?>
                                    <?= h($parasutInvoiceNo !== '' ? $parasutInvoiceNo : '#' . $parasutInvoiceId) ?>
                                <?php elseif ($parasutInvoiceStatus === 'failed'): ?>
                                    Oluşturulamadı
                                <?php elseif (sales_offer_can_create_parasut_invoice($offer)): ?>
                                    Fatura kesilebilir
                                <?php else: ?>
                                    Fatura kapalı
                                <?php endif; ?>
                            </strong>
                        </div>
                        <div>
                            <span>Okunma</span>
                            <strong><?= h(sales_offer_read_summary_label($offer)) ?></strong>
                        </div>
                        <div>
                            <span>Onaylayan</span>
                            <strong><?= h(sales_offer_approval_label($offer) ?: 'Henüz yok') ?></strong>
                        </div>
                    </div>
                    <?php if (!empty($offer['notes'])): ?>
                        <div class="settings-note compact"><?= nl2br(h((string) $offer['notes']), false) ?></div>
                    <?php endif; ?>
                    <?php if ($parasutInvoiceStatus === 'failed' && !empty($offer['parasut_invoice_error'])): ?>
                        <div class="settings-note compact">Paraşüt fatura hatası: <?= h((string) $offer['parasut_invoice_error']) ?></div>
                    <?php endif; ?>
                    <?= render_sales_offer_delivery_status($offer) ?>
                    <?= render_sales_offer_operation_panel($offer, $advancePayment, $balancePayment, $remainingBalance, $parasutInvoiceId, $parasutInvoiceNo, $parasutInvoiceStatus, $canManage) ?>
                    <?php if ($offerId > 0 && ($canManage || $canDelete)): ?>
                        <div class="track-actions">
                            <?php if ($canManage): ?>
                                <a class="button small secondary" target="_blank" rel="noopener" href="<?= h(url('/offers/' . $offerId . '/preview')) ?>">Taslak görüntüle</a>
                                <button type="button" class="button small primary" data-dialog-open="sales-offer-send-<?= h($offerId) ?>">Müşteriye gönder</button>
                                <button type="button" class="button small secondary" data-dialog-open="sales-offer-payment-match-<?= h($offerId) ?>">Ödeme eşleştir</button>
	                                    <?php if ($canCollectBalance): ?>
	                                        <form method="post" action="<?= h(url('/offers/' . $offerId . '/collect-balance')) ?>" onsubmit="return confirm('Bu teklif için kalan bakiye ödeme talebi mail olarak gönderilsin mi?')">
	                                            <?= csrf_field() ?>
	                                            <input type="hidden" name="return_to" value="<?= h(route_path()) ?>">
	                                            <button type="submit" class="button small primary"><?= $balancePayment ? 'Kalan bakiye mailini tekrar gönder' : 'Kalan bakiyeyi tahsil et' ?></button>
	                                        </form>
	                                    <?php endif; ?>
                                        <?php if ($canCreateParasutInvoice): ?>
                                            <button type="button" class="button small primary" data-dialog-open="sales-offer-parasut-<?= h($offerId) ?>">Paraşüt’e aktar</button>
	                                        <?php endif; ?>
			                                <a class="button small secondary" target="_blank" rel="noopener" href="<?= h(url('/offers/' . $offerId . '/pdf')) ?>">PDF olarak indir</a>
		                                <a class="button small secondary" href="<?= h(url('/offers/' . $offerId . '/edit')) ?>">Düzenle</a>
	                                    <?= render_sales_offer_send_dialog($offer) ?>
                                        <?= render_sales_offer_payment_match_dialog($offer, $paymentRequests, $advancePayment, $balancePayment) ?>
                                        <?php if ($canCreateParasutInvoice): ?>
                                            <?= render_sales_offer_parasut_transfer_dialog($offer, $advancePayment, $balancePayment) ?>
                                        <?php endif; ?>
		                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" class="inline-delete-form" action="<?= h(url('/offers/' . $offerId . '/delete')) ?>" onsubmit="return confirm('Bu teklif ve kalemleri silinsin mi?')">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="button small danger">Sil</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function render_sales_offer_parasut_transfer_dialog(array $offer, ?array $advancePayment = null, ?array $balancePayment = null): string
{
    $offerId = (int) ($offer['id'] ?? 0);
    if ($offerId < 1) {
        return '';
    }

    $currency = normalize_allowed_currency((string) ($offer['currency'] ?? 'TRY'));
    $total = money_format_local($offer['total'] ?? 0, $currency);
    $offerNumber = sales_offer_number($offer);
    $hasPaidCollection = ($advancePayment && (string) ($advancePayment['status'] ?? 'pending') === 'paid')
        || ($balancePayment && (string) ($balancePayment['status'] ?? 'pending') === 'paid');
    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());

    ob_start();
    ?>
    <dialog class="app-dialog communication-dialog parasut-transfer-dialog" id="sales-offer-parasut-<?= h((string) $offerId) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <p class="eyebrow">Paraşüt aktarımı</p>
                    <h2>Aktarım tipini seçin</h2>
                    <span><?= h($offerNumber) ?> teklifini <?= h($total) ?> tutarıyla Paraşüt’e aktarın.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <div class="settings-note compact">
                <strong>Paraşüt’e aktar</strong> sadece faturayı oluşturur; sistemdeki ödeme/tahsilat kaydı fatura notuna eklenmez.
                <br>
                <strong>Paraşüt’e tahsilatlı aktar</strong> faturayı oluşturur ve varsa ödenmiş ödeme kaydının ID, tarih, tutar bilgisini fatura notuna ekler.
                <br>
                Teklif müşteri tarafından onaylanmamış olsa bile fatura oluşturabilirsiniz; bu işlem teklif durumunu değiştirmez.
            </div>

            <?php if (!$hasPaidCollection): ?>
                <div class="alert warning compact">Bu teklif için sistemde ödenmiş tahsilat görünmüyor. Tahsilatlı aktar seçeneği fatura oluşturur; ödeme ID/tutar detayı yoksa notlara eklenmez.</div>
            <?php endif; ?>

            <div class="form-actions parasut-transfer-actions">
                <form method="post" action="<?= h(url('/offers/' . $offerId . '/parasut-invoice')) ?>" onsubmit="return confirm('Bu teklifi sadece fatura olarak Paraşüt’e aktaralım mı?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                    <input type="hidden" name="parasut_transfer_mode" value="invoice_only">
                    <button type="submit" class="button secondary">Paraşüt’e aktar</button>
                </form>

                <form method="post" action="<?= h(url('/offers/' . $offerId . '/parasut-invoice')) ?>" onsubmit="return confirm('Bu teklifi tahsilat bilgisiyle Paraşüt’e aktaralım mı?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                    <input type="hidden" name="parasut_transfer_mode" value="with_collection">
                    <button type="submit" class="button primary">Paraşüt’e tahsilatlı aktar</button>
                </form>
            </div>
        </div>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function render_sales_offer_payment_match_dialog(array $offer, array $paymentRequests, ?array $advancePayment = null, ?array $balancePayment = null): string
{
    $offerId = (int) ($offer['id'] ?? 0);
    if ($offerId < 1) {
        return '';
    }

    foreach ([$advancePayment, $balancePayment] as $currentRequest) {
        if (!$currentRequest) {
            continue;
        }

        $currentId = (int) ($currentRequest['id'] ?? 0);
        $exists = false;
        foreach ($paymentRequests as $request) {
            if ((int) ($request['id'] ?? 0) === $currentId) {
                $exists = true;
                break;
            }
        }
        if (!$exists && $currentId > 0) {
            $paymentRequests[] = $currentRequest;
        }
    }

    $currency = normalize_allowed_currency((string) ($offer['currency'] ?? 'TRY'));
    $total = money_format_local($offer['total'] ?? 0, $currency);
    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());
    $advanceId = (int) ($offer['payment_request_id'] ?? 0);
    $balanceId = (int) ($offer['balance_payment_request_id'] ?? 0);

    ob_start();
    ?>
    <dialog class="app-dialog communication-dialog payment-match-dialog" id="sales-offer-payment-match-<?= h((string) $offerId) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <p class="eyebrow">Ödeme eşleştirme</p>
                    <h2>Teklif ile ödeme talebini bağla</h2>
                    <span><?= h(sales_offer_number($offer)) ?> · <?= h((string) ($offer['customer_name'] ?? '-')) ?> · <?= h($total) ?></span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <div class="settings-note compact">
                Ödeme talebi silinmez; sadece bu teklif kartındaki ön ödeme ve kalan bakiye alanlarına bağlanır. Başka bir teklife bağlı ödeme talebi tekrar seçilemez.
            </div>

            <form method="post" action="<?= h(url('/offers/' . $offerId . '/match-payment')) ?>" class="form-grid payment-match-form">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                <label>
                    Ön ödeme / ana ödeme
                    <select name="payment_request_id">
                        <option value="0">Eşleşme yok</option>
                        <?php foreach ($paymentRequests as $request): ?>
                            <?= option((string) (int) ($request['id'] ?? 0), sales_offer_payment_request_option_label($request), (string) $advanceId) ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Kalan bakiye ödemesi
                    <select name="balance_payment_request_id">
                        <option value="0">Eşleşme yok</option>
                        <?php foreach ($paymentRequests as $request): ?>
                            <?= option((string) (int) ($request['id'] ?? 0), sales_offer_payment_request_option_label($request), (string) $balanceId) ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="payment-match-current field-wide">
                    <div>
                        <span>Mevcut ön ödeme</span>
                        <strong><?= $advancePayment ? h(sales_offer_payment_request_option_label($advancePayment)) : 'Eşleşme yok' ?></strong>
                    </div>
                    <div>
                        <span>Mevcut kalan bakiye</span>
                        <strong><?= $balancePayment ? h(sales_offer_payment_request_option_label($balancePayment)) : 'Eşleşme yok' ?></strong>
                    </div>
                </div>
                <div class="form-actions field-wide">
                    <button type="button" class="button secondary" data-dialog-close>Vazgeç</button>
                    <button type="submit" class="button primary">Ödeme eşleştir</button>
                </div>
            </form>
        </div>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function sales_offer_payment_request_option_label(array $request): string
{
    $number = manual_payment_request_number($request);
    $customer = trim((string) (($request['customer_name'] ?? '') ?: ($request['customer_email'] ?? '') ?: 'Müşteri yok'));
    $amount = money_format_local($request['amount'] ?? 0, (string) ($request['currency'] ?? 'TRY'));
    $status = payment_request_status_label((string) ($request['status'] ?? 'pending'));

    return $number . ' - ' . $customer . ' - ' . $amount . ' - ' . $status;
}

function render_sales_offer_operation_panel(
    array $offer,
    ?array $advancePayment,
    ?array $balancePayment,
    float $remainingBalance,
    string $parasutInvoiceId,
    string $parasutInvoiceNo,
    string $parasutInvoiceStatus,
    bool $canManage
): string {
    if ((string) ($offer['status'] ?? '') !== 'approved') {
        return '';
    }

    $offerId = (int) ($offer['id'] ?? 0);
    if ($offerId < 1) {
        return '';
    }

    $currency = normalize_allowed_currency((string) ($offer['currency'] ?? 'TRY'));
    $operationStatus = sales_offer_operation_status((string) ($offer['operation_status'] ?? 'approved'));
    $operationLabel = sales_offer_operation_label($operationStatus);
    $operationUpdated = sales_offer_delivery_date((string) ($offer['operation_updated_at'] ?? ''));
    $operationNote = trim((string) ($offer['operation_note'] ?? ''));
    $advancePaid = $advancePayment && (string) ($advancePayment['status'] ?? 'pending') === 'paid';
    $balancePaid = $balancePayment && (string) ($balancePayment['status'] ?? 'pending') === 'paid';
    $invoiceReady = $parasutInvoiceId !== '';
    $invoiceLabel = $invoiceReady
        ? ($parasutInvoiceNo !== '' ? $parasutInvoiceNo : '#' . $parasutInvoiceId)
        : ($parasutInvoiceStatus === 'failed' ? 'Hata aldı' : 'Bekliyor');
    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());

    ob_start();
    ?>
    <div class="sales-offer-operation-panel">
        <div class="sales-offer-operation-head">
            <div>
                <strong>Onay sonrası işlem</strong>
                <span>Fatura, tahsilat ve teslim adımlarını bu teklif kartında takip edin.</span>
            </div>
            <span class="operation-badge <?= h(sales_offer_operation_badge_class($operationStatus)) ?>">
                <?= h($operationLabel) ?>
            </span>
        </div>

        <div class="operation-step-grid">
            <div class="operation-step <?= $invoiceReady ? 'done' : ($parasutInvoiceStatus === 'failed' ? 'danger' : 'waiting') ?>">
                <span>Paraşüt faturası</span>
                <strong><?= h($invoiceLabel) ?></strong>
            </div>
            <div class="operation-step <?= $advancePaid ? 'done' : ($advancePayment ? 'waiting' : 'muted') ?>">
                <span>Ön ödeme</span>
                <strong>
                    <?php if ($advancePayment): ?>
                        <?= h(payment_request_status_label((string) ($advancePayment['status'] ?? 'pending'))) ?> · <?= h(money_format_local($advancePayment['amount'] ?? 0, $currency)) ?>
                    <?php else: ?>
                        Talep yok
                    <?php endif; ?>
                </strong>
            </div>
            <div class="operation-step <?= $balancePaid ? 'done' : ($remainingBalance > 0 ? 'waiting' : 'done') ?>">
                <span>Kalan bakiye</span>
                <strong>
                    <?php if ($balancePayment): ?>
                        <?= h(payment_request_status_label((string) ($balancePayment['status'] ?? 'pending'))) ?> · <?= h(money_format_local($balancePayment['amount'] ?? 0, $currency)) ?>
                    <?php else: ?>
                        <?= h(money_format_local($remainingBalance, $currency)) ?>
                    <?php endif; ?>
                </strong>
            </div>
            <div class="operation-step <?= $operationStatus === 'completed' ? 'done' : 'waiting' ?>">
                <span>Operasyon</span>
                <strong><?= h($operationLabel) ?><?= $operationUpdated !== '' ? ' · ' . h($operationUpdated) : '' ?></strong>
            </div>
        </div>

        <?php if ($operationNote !== ''): ?>
            <div class="operation-note"><?= nl2br(h($operationNote), false) ?></div>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <form method="post" action="<?= h(url('/offers/' . $offerId . '/operation')) ?>" class="sales-offer-operation-form">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                <label>
                    İş akışı durumu
                    <select name="operation_status">
                        <?php foreach (sales_offer_operation_status_options() as $value => $label): ?>
                            <?= option($value, $label, $operationStatus) ?>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Operasyon notu
                    <input name="operation_note" value="<?= h($operationNote) ?>" placeholder="Örn: Tedarikçiye sipariş geçildi, kurulum tarihi bekleniyor">
                </label>
                <button type="submit" class="button small primary">Durumu kaydet</button>
            </form>
        <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function sales_offer_operation_status_options(): array
{
    return RenewalRepository::salesOfferOperationStatuses();
}

function sales_offer_operation_status(string $status): string
{
    return array_key_exists($status, sales_offer_operation_status_options()) ? $status : 'approved';
}

function sales_offer_operation_label(string $status): string
{
    $status = sales_offer_operation_status($status);

    return (string) sales_offer_operation_status_options()[$status];
}

function sales_offer_operation_badge_class(string $status): string
{
    return match (sales_offer_operation_status($status)) {
        'completed' => 'done',
        'delivered' => 'delivered',
        'processing', 'supplier_ordered', 'delivery_waiting' => 'working',
        default => 'approved',
    };
}

function sales_offer_read_summary_label(array $offer): string
{
    $deliveries = array_values((array) ($offer['deliveries'] ?? []));
    if ($deliveries === []) {
        return 'Takipli gönderim yok';
    }

    $opened = 0;
    $views = 0;
    foreach ($deliveries as $delivery) {
        if (!empty($delivery['first_viewed_at'])) {
            $opened++;
        }
        $views += (int) ($delivery['view_count'] ?? 0);
    }

    return $opened . '/' . count($deliveries) . ' alıcı · ' . $views . ' görüntüleme';
}

function render_sales_offer_delivery_status(array $offer): string
{
    $deliveries = array_values((array) ($offer['deliveries'] ?? []));
    if ($deliveries === []) {
        return '<div class="settings-note compact sales-offer-read-empty">Bu teklif henüz takipli link ile gönderilmemiş. Müşteriye gönder butonundan mail veya WhatsApp gönderildiğinde okundu bilgisi burada görünecek.</div>';
    }

    ob_start();
    ?>
    <div class="sales-offer-read-box">
        <div class="sales-offer-read-head">
            <strong>Okunma ve görüntüleme</strong>
            <span><?= h(sales_offer_read_summary_label($offer)) ?></span>
        </div>
        <div class="reader-chip-list sales-offer-reader-list">
            <?php foreach ($deliveries as $delivery): ?>
                <?php
                $opened = !empty($delivery['first_viewed_at']);
                $approved = !empty($delivery['approved_at']);
                $failed = (string) ($delivery['status'] ?? '') === 'failed';
                $class = $approved ? 'approved' : ($opened ? 'opened' : ($failed ? 'failed' : 'pending'));
                $viewCount = (int) ($delivery['view_count'] ?? 0);
                $date = $opened
                    ? sales_offer_delivery_date((string) ($delivery['last_viewed_at'] ?? $delivery['first_viewed_at'] ?? ''))
                    : sales_offer_delivery_date((string) ($delivery['sent_at'] ?? $delivery['created_at'] ?? ''));
                ?>
                <span class="reader-chip sales-offer-reader-chip <?= h($class) ?>">
                    <strong><?= h(sales_offer_delivery_recipient_label($delivery)) ?></strong>
                    <small><?= h(sales_offer_delivery_channel_label((string) ($delivery['channel'] ?? 'mail'))) ?> · <?= h(sales_offer_delivery_status_label($delivery)) ?></small>
                    <small><?= h($opened ? ($viewCount . ' görüntüleme' . ($date !== '' ? ' · ' . $date : '')) : ($date !== '' ? $date : 'Bekliyor')) ?></small>
                    <?php if ($approved): ?>
                        <small>Onaylayan: <?= h(sales_offer_delivery_approval_label($delivery)) ?></small>
                    <?php endif; ?>
                    <?php if ($failed && !empty($delivery['error_message'])): ?>
                        <small><?= h(mb_substr((string) $delivery['error_message'], 0, 90)) ?></small>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function sales_offer_delivery_recipient_label(array $delivery): string
{
    $name = trim((string) ($delivery['recipient_name'] ?? ''));
    $email = trim((string) ($delivery['recipient_email'] ?? ''));
    $phone = trim((string) ($delivery['recipient_phone'] ?? ''));

    if ($name !== '' && $email !== '') {
        return $name . ' · ' . $email;
    }

    return $name ?: ($email ?: ($phone ?: 'Genel bağlantı'));
}

function sales_offer_delivery_approval_label(array $delivery): string
{
    $name = trim((string) (($delivery['approval_name'] ?? '') ?: ($delivery['recipient_name'] ?? '')));
    $email = trim((string) (($delivery['approval_email'] ?? '') ?: ($delivery['recipient_email'] ?? '')));
    $phone = trim((string) (($delivery['approval_phone'] ?? '') ?: ($delivery['recipient_phone'] ?? '')));
    $date = sales_offer_delivery_date((string) ($delivery['approved_at'] ?? ''));
    $parts = array_values(array_filter([$name, $email, $phone], static fn (string $value): bool => trim($value) !== ''));

    return ($parts !== [] ? implode(' · ', $parts) : 'Kişi bilgisi yok') . ($date !== '' ? ' · ' . $date : '');
}

function sales_offer_delivery_channel_label(string $channel): string
{
    return match ($channel) {
        'whatsapp' => 'WhatsApp',
        'public' => 'Genel link',
        default => 'Mail',
    };
}

function sales_offer_delivery_status_label(array $delivery): string
{
    if (!empty($delivery['approved_at'])) {
        return 'Onayladı';
    }
    if (!empty($delivery['first_viewed_at'])) {
        return 'Okundu';
    }

    return match ((string) ($delivery['status'] ?? 'queued')) {
        'sent' => 'Gönderildi, okunmadı',
        'failed' => 'Gönderilemedi',
        'opened' => 'Okundu',
        default => 'Hazırlandı',
    };
}

function sales_offer_delivery_date(string $value): string
{
    $time = strtotime($value);
    if (!$time) {
        return '';
    }

    return date('d.m.Y H:i', $time);
}

function render_sales_offer_send_dialog(array $offer): string
{
    $id = (int) ($offer['id'] ?? 0);
    if ($id <= 0) {
        return '';
    }

    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());
    $contacts = sales_offer_contacts($offer);
    $offerTitle = (string) (($offer['title'] ?? '') ?: 'Teklif');
    $subject = '[' . sales_offer_number($offer) . '] Teklifiniz hazır: ' . $offerTitle;
    $defaultMessage = 'Hazırlanan teklifinizi inceleyebilmeniz için bağlantıyı paylaşıyoruz.';
    $previewUrl = sales_offer_public_url($id, 'view');
    $pdfUrl = sales_offer_public_url($id, 'pdf');

    ob_start();
    ?>
    <dialog class="app-dialog communication-dialog customer-send-dialog" id="sales-offer-send-<?= h($id) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <h2>Müşteriye gönder</h2>
                    <span><?= h((string) ($offer['customer_name'] ?? '-')) ?> için teklif bağlantısını mail veya WhatsApp ile gönderin.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <div class="customer-send-layout">
                <form method="post" action="<?= h(url('/offers/' . $id . '/send-email')) ?>" class="form-grid customer-send-mail-form">
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
                                <input type="checkbox" name="offer_mail_recipients[]" value="<?= h($email) ?>" <?= !empty($contact['notify_enabled']) ? 'checked' : '' ?>>
                                <span>
                                    <b><?= h((string) (($contact['full_name'] ?? '') ?: $email)) ?></b>
                                    <em><?= h($email) ?></em>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$hasMailRecipient): ?>
                            <p class="muted compact">Bu teklif için kayıtlı e-posta yetkilisi yok. Aşağıya manuel alıcı yazabilirsiniz.</p>
                        <?php endif; ?>
                    </div>
                    <label>
                        Gönderim türü
                        <select name="offer_send_mode">
                            <option value="view">Teklif görüntüleme bağlantısı</option>
                            <option value="pdf">PDF / çıktı bağlantısı</option>
                        </select>
                    </label>
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
                    <div class="inline-actions">
                        <a class="button secondary" target="_blank" rel="noopener" href="<?= h($previewUrl) ?>">Teklif sayfasını aç</a>
                        <a class="button secondary" target="_blank" rel="noopener" href="<?= h($pdfUrl) ?>">PDF çıktısını aç</a>
                        <button type="submit" class="button primary">Müşteriye mail gönder</button>
                    </div>
                </form>

                <div class="customer-whatsapp-panel">
                    <div class="section-head compact">
                        <div>
                            <h3>WhatsApp ile gönder</h3>
                            <span class="muted compact">Yetkili seçildiğinde teklif bağlantılı hazır mesaj WhatsApp'ta açılır.</span>
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
                            $baseQuery = [
                                'phone' => $phone,
                                'name' => (string) (($contact['full_name'] ?? '') ?: $phone),
                                'email' => (string) ($contact['email'] ?? ''),
                                'return_to' => $returnTo,
                            ];
                            ?>
                            <div class="whatsapp-contact-card">
                                <div>
                                    <strong><?= h((string) (($contact['full_name'] ?? '') ?: $phone)) ?></strong>
                                    <span><?= h($phone) ?><?= !empty($contact['email']) ? ' - ' . h((string) $contact['email']) : '' ?></span>
                                </div>
                                <div class="inline-actions compact">
                                    <a class="button small whatsapp" target="takip_whatsapp_web" href="<?= h(url('/offers/' . $id . '/whatsapp') . '?' . http_build_query($baseQuery + ['mode' => 'view'])) ?>">Teklif</a>
                                    <a class="button small secondary" target="takip_whatsapp_web" href="<?= h(url('/offers/' . $id . '/whatsapp') . '?' . http_build_query($baseQuery + ['mode' => 'pdf'])) ?>">PDF</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$hasWhatsappRecipient): ?>
                            <div class="empty">Bu cari için WhatsApp'a uygun telefon numarası bulunamadı.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function sales_offer_contacts(array $offer): array
{
    $contacts = [];
    try {
        $customerId = (int) ($offer['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = (new RenewalRepository())->findCustomer($customerId);
            if ($customer && !empty($customer['contacts']) && is_array($customer['contacts'])) {
                $contacts = $customer['contacts'];
            }
        }
    } catch (Throwable) {
        $contacts = [];
    }

    $fallback = [
        'full_name' => (string) (($offer['customer_name'] ?? '') ?: 'Cari yetkilisi'),
        'email' => (string) ($offer['customer_email'] ?? ''),
        'phone' => (string) ($offer['customer_phone'] ?? ''),
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
        $name = trim((string) ($contact['full_name'] ?? $contact['contact_name'] ?? $contact['name'] ?? ''));
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

function sales_offer_status_label(string $status): string
{
    return match ($status) {
        'sent' => 'Gönderildi',
        'approved' => 'Onaylandı',
        'revision_requested' => 'Revize istendi',
        'rejected' => 'Reddedildi',
        'expired' => 'Süresi doldu',
        default => 'Taslak',
    };
}

function sales_offer_status_badge(string $status): string
{
    return match ($status) {
        'approved' => 'active',
        'revision_requested' => 'pending',
        'rejected', 'expired' => 'cancelled',
        'sent' => 'warning',
        default => 'muted',
    };
}

function sales_offer_dashboard_status_label(array $offer, ?array $advancePayment = null): string
{
    if (sales_offer_is_waiting_advance_payment($offer, $advancePayment)) {
        return 'Ön ödeme bekliyor';
    }

    return sales_offer_status_label((string) ($offer['status'] ?? 'draft'));
}

function sales_offer_dashboard_status_badge(array $offer, ?array $advancePayment = null): string
{
    if (sales_offer_is_waiting_advance_payment($offer, $advancePayment)) {
        return 'warning';
    }

    return sales_offer_status_badge((string) ($offer['status'] ?? 'draft'));
}

function sales_offer_is_waiting_advance_payment(array $offer, ?array $advancePayment = null): bool
{
    return !empty($offer['payment_request_enabled'])
        && (int) ($offer['payment_request_id'] ?? 0) > 0
        && (string) ($offer['status'] ?? 'draft') !== 'approved'
        && (string) ($advancePayment['status'] ?? 'pending') !== 'paid';
}

function stock_item_payload(array $item): array
{
    return [
        'id' => (int) ($item['id'] ?? 0),
        'name' => (string) ($item['name'] ?? ''),
        'code' => (string) ($item['code'] ?? ''),
        'barcode' => (string) ($item['barcode'] ?? ''),
        'brand' => (string) ($item['brand'] ?? ''),
        'unit' => (string) ($item['unit'] ?? ''),
        'currency' => (string) ($item['currency'] ?? 'TRY'),
        'list_price' => (float) ($item['list_price'] ?? 0),
        'vat_rate' => (float) ($item['vat_rate'] ?? 20),
        'stock_count' => $item['stock_count'] ?? null,
    ];
}

function render_stock_item_datalist(array $stockItems): string
{
    ob_start();
    ?>
    <datalist id="stock-item-options">
        <?php foreach ($stockItems as $item): ?>
            <?php
            $payload = stock_item_payload($item);
            $parts = array_filter([
                $payload['name'],
                $payload['code'] !== '' ? $payload['code'] : null,
                $payload['brand'] !== '' ? $payload['brand'] : null,
            ]);
            $value = implode(' | ', $parts);
            ?>
            <option
                value="<?= h($value) ?>"
                data-stock-id="<?= h((string) $payload['id']) ?>"
                data-title="<?= h($payload['name']) ?>"
                data-brand="<?= h($payload['brand']) ?>"
                data-description="<?= h($payload['code'] !== '' ? 'Kod: ' . $payload['code'] : '') ?>"
                data-unit-price="<?= h(number_format($payload['list_price'], 2, '.', '')) ?>"
                data-vat-rate="<?= h(number_format($payload['vat_rate'], 2, '.', '')) ?>"
                data-currency="<?= h($payload['currency']) ?>"
            ></option>
        <?php endforeach; ?>
    </datalist>
    <?php

    return (string) ob_get_clean();
}

function render_offer_template_fields(array $template): string
{
    $items = (array) ($template['items'] ?? []);
    if ($items === []) {
        $items = [['title' => '', 'brand' => '', 'description' => '', 'quantity' => '1.00', 'unit_price' => '0.00', 'vat_rate' => '20.00']];
    }

    ob_start();
    ?>
    <div class="form-grid three span-2">
        <label>
            Şablon adı
            <input name="template_name" value="<?= h((string) ($template['name'] ?? '')) ?>" placeholder="Örn: 4 kameralı sistem" required>
        </label>
        <label>
            Para birimi
            <select name="currency" data-offer-builder-currency>
                <?= option('TRY', 'TRY', (string) ($template['currency'] ?? 'TRY')) ?>
                <?= option('USD', 'USD', (string) ($template['currency'] ?? 'TRY')) ?>
                <?= option('EUR', 'EUR', (string) ($template['currency'] ?? 'TRY')) ?>
            </select>
        </label>
        <label>
            Kısa açıklama
            <input name="description" value="<?= h((string) ($template['description'] ?? '')) ?>" placeholder="Kamera paketi, lisans paketi...">
        </label>
    </div>

    <div class="offer-line-editor span-2">
        <div class="section-head compact">
            <div>
                <h3>Şablon kalemleri</h3>
                <span>Yeni teklif açıldığında bu kalemler otomatik gelir.</span>
            </div>
            <button type="button" class="button small secondary" data-offer-add-line>+ Kalem ekle</button>
        </div>
        <div class="offer-line-list" data-offer-line-list>
            <?php foreach (array_values($items) as $index => $item): ?>
                <?= render_offer_builder_item_row((int) $index, (array) $item) ?>
            <?php endforeach; ?>
        </div>
        <template data-offer-line-template>
            <?= render_offer_builder_item_row('__INDEX__', []) ?>
        </template>
    </div>
    <div class="customer-offer-total-preview offer-builder-total span-2">
        <span>Ara toplam: <b data-offer-subtotal>-</b></span>
        <span>KDV: <b data-offer-vat>-</b></span>
        <span>KDV dahil: <b data-offer-total>-</b></span>
    </div>
    <?php

    return (string) ob_get_clean();
}

function render_offer_builder_item_row(int|string $index, array $item): string
{
    $quantity = (float) ($item['quantity'] ?? 1);
    $unitPrice = (float) ($item['unit_price'] ?? 0);
    $vatRate = (float) ($item['vat_rate'] ?? 20);
    $lineSubtotal = $quantity * $unitPrice;
    $lineTotal = $lineSubtotal + ($lineSubtotal * $vatRate / 100);
    $namePrefix = 'items[' . (string) $index . ']';

    ob_start();
    ?>
    <div class="offer-builder-line" data-offer-line>
        <input type="hidden" name="<?= h($namePrefix) ?>[stock_item_id]" value="<?= h((string) ($item['stock_item_id'] ?? '')) ?>" data-stock-item-id>
        <div class="offer-builder-line-head">
            <strong>Kalem</strong>
            <button type="button" class="button small danger" data-offer-remove-line>Kaldır</button>
        </div>
        <div class="offer-line-info">
            <span aria-hidden="true">i</span>
            <p>Stok kataloğundan seçim yaparsanız marka, fiyat, KDV ve para birimi otomatik doldurulur.</p>
        </div>
        <div class="offer-builder-line-row">
            <label>
                Ürün / hizmet
                <input name="<?= h($namePrefix) ?>[title]" value="<?= h((string) ($item['title'] ?? '')) ?>" placeholder="Kamera, NVR, lisans..." list="stock-item-options" data-stock-title autocomplete="off" required>
            </label>
            <label>
                Marka / model
                <input name="<?= h($namePrefix) ?>[brand]" value="<?= h((string) ($item['brand'] ?? '')) ?>" placeholder="Hikvision, Sophos..." data-stock-brand>
            </label>
            <label>
                Adet
                <input type="number" min="0.01" step="0.01" name="<?= h($namePrefix) ?>[quantity]" value="<?= h(number_format($quantity > 0 ? $quantity : 1, 2, '.', '')) ?>" data-offer-qty>
            </label>
        </div>
        <div class="offer-builder-line-row">
            <label>
                Birim fiyat
                <input type="number" min="0" step="0.01" name="<?= h($namePrefix) ?>[unit_price]" value="<?= h(number_format($unitPrice, 2, '.', '')) ?>" data-offer-unit>
            </label>
            <label>
                KDV %
                <input type="number" min="0" max="100" step="0.01" name="<?= h($namePrefix) ?>[vat_rate]" value="<?= h(number_format($vatRate, 2, '.', '')) ?>" data-offer-vat-rate>
            </label>
            <label>
                Açıklama
                <input name="<?= h($namePrefix) ?>[description]" value="<?= h((string) ($item['description'] ?? '')) ?>" placeholder="Montaj, teslim, kapsam..." data-stock-description>
            </label>
        </div>
        <div class="offer-line-preview">
            <span>Toplam: <b data-offer-line-subtotal><?= h(money_format_local($lineSubtotal, 'TRY')) ?></b></span>
            <span>KDV dahil: <b data-offer-line-total><?= h(money_format_local($lineTotal, 'TRY')) ?></b></span>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function render_flows(RenewalRepository $repo): void
{
    $overview = $repo->flowOverview();
    $renewal = $overview['renewal'];
    $offer = $overview['offer'];
    $collection = $overview['collection'];
    $insights = flow_insights($overview);

    $renewalSteps = [
        ['title' => 'Cari ve ürün kaydı', 'count' => $renewal['total'], 'tone' => $renewal['total'] > 0 ? 'done' : 'muted', 'text' => 'Müşterinin altında ürün, hizmet, periyot ve bilgilendirme günleri tanımlanır.'],
        ['title' => 'Takipte aktif', 'count' => $renewal['active'], 'tone' => $renewal['active'] > 0 ? 'active' : 'muted', 'text' => 'Sistem yenileme tarihine göre kalan günü ve aciliyet rengini hesaplar.'],
        ['title' => 'Yaklaşan dönem', 'count' => $renewal['due_soon'], 'tone' => $renewal['due_soon'] > 0 ? 'warning' : 'muted', 'text' => '60 gün ve altındaki kayıtlar dikkat alanına girer.'],
        ['title' => 'Bilgilendirme gönderildi', 'count' => $renewal['notifications_sent'], 'tone' => $renewal['notifications_sent'] > 0 ? 'done' : 'muted', 'text' => 'Mail veya manuel bildirim gönderilen kayıtlar burada görünür.'],
        ['title' => 'Okundu bilgisi', 'count' => $renewal['notifications_read'], 'tone' => $renewal['notifications_read'] > 0 ? 'done' : 'muted', 'text' => 'Müşteri yetkilisinin okuduğu kayıtlar akışta kapanmaya yaklaşır.'],
        ['title' => 'Geciken kontrol', 'count' => $renewal['overdue'], 'tone' => $renewal['overdue'] > 0 ? 'danger' : 'done', 'text' => 'Sıfırın altına düşen kayıt varsa acil işlem gerekir.'],
    ];

    $offerSteps = [
        ['title' => 'Tedarikçiden fiyat talebi', 'count' => $offer['supplier_requests'], 'tone' => $offer['supplier_requests'] > 0 ? 'active' : 'muted', 'text' => 'Mail, WhatsApp veya manuel link ile tedarikçiye form gönderilir.'],
        ['title' => 'Tedarikçi formu açtı', 'count' => $offer['supplier_opened'] + $offer['supplier_submitted'], 'tone' => ($offer['supplier_opened'] + $offer['supplier_submitted']) > 0 ? 'active' : 'muted', 'text' => 'Form açıldıysa talep tedarikçi tarafına ulaşmış demektir.'],
        ['title' => 'Fiyat geldi', 'count' => $offer['supplier_submitted'], 'tone' => $offer['supplier_submitted'] > 0 ? 'done' : 'muted', 'text' => 'Peşin, 30 gün, 60 gün, çek veya özel vade fiyatları girilir.'],
        ['title' => 'Uygun teklif seçildi', 'count' => $offer['supplier_selections'], 'tone' => $offer['renewals_waiting_selection'] > 0 ? 'warning' : ($offer['supplier_selections'] > 0 ? 'done' : 'muted'), 'text' => 'Her kalemde en uygun tedarikçi ve vade seçilir.'],
        ['title' => 'Müşteriye teklif gönderildi', 'count' => $offer['customer_offers'], 'tone' => $offer['renewals_waiting_customer_offer'] > 0 ? 'warning' : ($offer['customer_offers'] > 0 ? 'done' : 'muted'), 'text' => 'Seçili fiyatlardan müşteri teklif linki oluşturulur.'],
        ['title' => 'Müşteri kararı', 'count' => $offer['customer_approved'], 'tone' => $offer['customer_approved'] > 0 ? 'done' : 'muted', 'text' => 'Onay, red veya revize istekleri bu aşamada netleşir.'],
    ];

    $collectionSteps = [
        ['title' => 'Ödeme yöntemi seçimi', 'count' => $collection['choice'], 'tone' => $collection['choice'] > 0 ? 'warning' : 'muted', 'text' => 'Müşteri ödeme tipini seçmediyse tahsilat bu adımda bekler.'],
        ['title' => 'Tahsilat bekleyen', 'count' => $collection['awaiting'], 'tone' => $collection['awaiting'] > 0 ? 'warning' : 'done', 'text' => 'Ödeme bekleyen aktif yenilemeler tahsilat merkezine düşer.'],
        ['title' => 'Havale / EFT', 'count' => $collection['bank'], 'tone' => $collection['bank'] > 0 ? 'active' : 'muted', 'text' => 'Banka havalesi seçenlerde IBAN ve dekont akışı takip edilir.'],
        ['title' => 'Dekont alındı', 'count' => $collection['receipts'], 'tone' => $collection['receipts'] > 0 ? 'done' : 'muted', 'text' => 'Müşteri dekont yüklediyse muhasebe kontrolü yapılır.'],
        ['title' => 'Kart ödemesi tamamlandı', 'count' => $collection['paid_card'], 'tone' => $collection['paid_card'] > 0 ? 'done' : 'muted', 'text' => 'Kredi kartı ödemesi başarılı kayıtlar burada kapanır.'],
    ];

    $backPath = Auth::can('settings.manage') ? '/settings' : (Auth::can('dashboard.view') ? '/' : (first_allowed_path() ?? '/'));
    $backLabel = Auth::can('settings.manage') ? 'Ayarlara dön' : "Dashboard'a dön";

    render_layout('Akış Şemaları', static function () use ($renewalSteps, $offerSteps, $collectionSteps, $insights, $backPath, $backLabel): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Ayarlar</p>
                <h1>Akış Şemaları</h1>
            </div>
            <div class="page-actions">
                <a href="<?= h(url($backPath)) ?>" class="button secondary"><?= h($backLabel) ?></a>
            </div>
        </div>

        <section class="flow-hero">
            <div>
                <h2>İşin hangi adımda olduğunu tek ekrandan görün.</h2>
                <p>Müşteri ürün takibi, tedarikçi teklif süreci ve tahsilat akışı mevcut kayıtlara göre işaretlenir. Sarı alanlar bekleyen işlem, kırmızı alanlar acil müdahale gerektiren noktadır.</p>
            </div>
            <nav class="flow-jump">
                <a href="#musteri-urun-takip">Ürün takip</a>
                <a href="#teklif-akisi">Teklif</a>
                <a href="#tahsilat-akisi">Tahsilat</a>
            </nav>
        </section>

        <?php if ($insights !== []): ?>
            <section class="flow-insights">
                <?php foreach ($insights as $insight): ?>
                    <article class="flow-insight <?= h($insight['tone']) ?>">
                        <span><?= h($insight['label']) ?></span>
                        <strong><?= h($insight['title']) ?></strong>
                        <p><?= h($insight['text']) ?></p>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="flow-board">
            <?= render_flow_card('musteri-urun-takip', 'Müşteri ürün takip şeması', 'Ürün ve hizmet yenileme akışı', 'Kayıt açılır, bilgilendirme gönderilir, okundu bilgisi ve gecikme kontrol edilir.', $renewalSteps, '/renewals', 'Yenilemeleri aç') ?>
            <?= render_flow_card('teklif-akisi', 'Teklif şeması', 'Tedarikçi fiyatı ve müşteri teklifi', 'Tedarikçiden fiyat alınır, uygun satır seçilir ve müşteriye teklif gönderilir.', $offerSteps, '/', 'Paneli aç') ?>
            <?= render_flow_card('tahsilat-akisi', 'Tahsilat şeması', 'Ödeme ve dekont takip akışı', 'Müşteri ödeme yöntemini seçer, tahsilat merkezi bekleyenleri ayırır.', $collectionSteps, '/collections', 'Tahsilatı aç') ?>
        </section>
        <?php
    });
}

function render_flow_card(string $id, string $eyebrow, string $title, string $summary, array $steps, string $href, string $cta): string
{
    ob_start();
    ?>
    <article class="flow-card" id="<?= h($id) ?>">
        <div class="flow-card-head">
            <div>
                <p class="eyebrow"><?= h($eyebrow) ?></p>
                <h2><?= h($title) ?></h2>
                <p><?= h($summary) ?></p>
            </div>
            <a class="button small secondary" href="<?= h(url($href)) ?>"><?= h($cta) ?></a>
        </div>
        <ol class="flow-steps">
            <?php foreach ($steps as $index => $step): ?>
                <li class="flow-step <?= h((string) ($step['tone'] ?? 'muted')) ?>">
                    <span class="flow-step-index"><?= h((string) ($index + 1)) ?></span>
                    <div class="flow-step-copy">
                        <div>
                            <strong><?= h((string) ($step['title'] ?? 'Adım')) ?></strong>
                            <b><?= h((string) ($step['count'] ?? 0)) ?></b>
                        </div>
                        <p><?= h((string) ($step['text'] ?? '')) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </article>
    <?php
    return (string) ob_get_clean();
}

function flow_insights(array $overview): array
{
    $renewal = $overview['renewal'] ?? [];
    $offer = $overview['offer'] ?? [];
    $collection = $overview['collection'] ?? [];
    $insights = [];

    if ((int) ($renewal['overdue'] ?? 0) > 0) {
        $insights[] = [
            'tone' => 'danger',
            'label' => 'Acil',
            'title' => (int) $renewal['overdue'] . ' geciken yenileme var',
            'text' => 'Bu kayıtlar sıfırı geçmiş. Müşteri bilgilendirme, teklif veya tahsilat adımı kontrol edilmeli.',
        ];
    }

    if ((int) ($offer['renewals_waiting_selection'] ?? 0) > 0) {
        $insights[] = [
            'tone' => 'warning',
            'label' => 'Teklif',
            'title' => (int) $offer['renewals_waiting_selection'] . ' kayıt tedarikçi seçimi bekliyor',
            'text' => 'Tedarikçiler fiyat göndermiş; uygun satır seçilip müşteriye teklif hazırlanmalı.',
        ];
    }

    if ((int) ($offer['renewals_waiting_customer_offer'] ?? 0) > 0) {
        $insights[] = [
            'tone' => 'warning',
            'label' => 'Müşteri teklifi',
            'title' => (int) $offer['renewals_waiting_customer_offer'] . ' kayıt müşteriye teklif bekliyor',
            'text' => 'Tedarikçi fiyatı seçilmiş, fakat müşteri teklif linki henüz oluşturulmamış görünüyor.',
        ];
    }

    if ((int) ($collection['awaiting'] ?? 0) > 0) {
        $insights[] = [
            'tone' => 'active',
            'label' => 'Tahsilat',
            'title' => (int) $collection['awaiting'] . ' ödeme takibi bekliyor',
            'text' => 'Tahsilat ekranında ödeme yöntemi, dekont ve cari hesap adımlarını kontrol edebilirsiniz.',
        ];
    }

    if ($insights === []) {
        $insights[] = [
            'tone' => 'done',
            'label' => 'Temiz',
            'title' => 'Kritik bekleyen adım görünmüyor',
            'text' => 'Akışlarda kırmızı veya sarı bekleyen durum oluşmadı. Günlük takip ekranından kontrole devam edebilirsiniz.',
        ];
    }

    return $insights;
}

function render_collections(RenewalRepository $repo): void
{
    $filter = collection_filter_key((string) ($_GET['filter'] ?? 'all'));
    $rows = $filter === 'paid-card' ? [] : $repo->collectionRows($filter);
    $allRows = $repo->collectionRows('all');
    $manualPaymentRepo = new PaymentRequestRepository();
    $paidCardRows = array_merge(
        $repo->paidCardPaymentRows(120),
        $manualPaymentRepo->paidCardPaymentRows(120)
    );
    usort($paidCardRows, static function (array $a, array $b): int {
        $left = strtotime((string) (($a['paid_at'] ?? '') ?: ($a['updated_at'] ?? '') ?: ($a['created_at'] ?? ''))) ?: 0;
        $right = strtotime((string) (($b['paid_at'] ?? '') ?: ($b['updated_at'] ?? '') ?: ($b['created_at'] ?? ''))) ?: 0;

        return $right <=> $left;
    });
    $stats = collection_stats($allRows);
    $stats['paid_card'] = count($paidCardRows);
    $canSendMail = Auth::can('collections.manage');
    $returnTo = safe_return_path($_SERVER['REQUEST_URI'] ?? '/collections');

    render_layout('Tahsilat', static function () use ($rows, $paidCardRows, $filter, $stats, $canSendMail, $returnTo): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Tahsilat</p>
                <h1>Ödeme bekleyenler</h1>
            </div>
        </div>

        <section class="stats collection-stats">
            <?= stat_card('Bekleyen', $stats['total'], 'warning') ?>
            <?= stat_card('Ödenmemiş', $stats['unpaid'], 'danger') ?>
            <?= stat_card('Havale / EFT', $stats['bank'], '') ?>
            <?= stat_card('Kart ödemeleri', $stats['paid_card'], 'done') ?>
        </section>

        <div class="collection-filter-tabs">
            <?php foreach (collection_filter_options() as $key => $label): ?>
                <a class="<?= $filter === $key ? 'active' : '' ?>" href="<?= h(url('/collections' . ($key === 'all' ? '' : '?filter=' . $key))) ?>">
                    <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <section class="collection-list-panel">
            <?php if ($filter === 'paid-card'): ?>
                <?= render_paid_card_collection_list($paidCardRows) ?>
            <?php elseif ($rows === []): ?>
                <div class="empty">Bu filtrede tahsilat bekleyen kayıt bulunmuyor.</div>
            <?php else: ?>
                <div class="collection-card-list">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $state = collection_payment_state($row);
                        $total = money_format_local($row['item_total'] ?? $row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'));
                        $selectedAt = !empty($row['payment_selected_at']) ? date('d.m.Y H:i', strtotime((string) $row['payment_selected_at'])) : '-';
                        $contacts = collection_mail_recipients($row);
                        ?>
                        <article class="collection-card">
                            <div class="collection-card-main">
                                <div>
                                    <small>Müşteri</small>
                                    <strong><?= h((string) ($row['company_name'] ?? '-')) ?></strong>
                                    <span><?= h((string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? '-'))) ?></span>
                                </div>
                                <div class="collection-card-meta">
                                    <span class="badge <?= h((string) $state['tone']) ?>"><?= h((string) $state['label']) ?></span>
                                    <b><?= h($total) ?></b>
                                </div>
                            </div>
                            <div class="collection-card-grid">
                                <div>
                                    <span>Ödeme şartı</span>
                                    <strong><?= h(renewal_payment_label($row)) ?></strong>
                                </div>
                                <div>
                                    <span>Seçim tarihi</span>
                                    <strong><?= h($selectedAt) ?></strong>
                                </div>
                                <div>
                                    <span>Yenileme tarihi</span>
                                    <strong><?= h(!empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-') ?></strong>
                                </div>
                                <div>
                                    <span>Alıcı</span>
                                    <strong><?= h((string) count($contacts)) ?> kişi</strong>
                                </div>
                            </div>
                            <div class="collection-card-actions">
                                <a class="button small secondary" href="<?= h(url('/renewals/' . (int) $row['id'] . '/edit')) ?>">Kaydı aç</a>
                                <a class="button small secondary" href="<?= h(PaymentLink::urlForRenewal((int) $row['id'], 60, (string) ($row['customer_email'] ?? ''))) ?>" target="_blank" rel="noopener">Ödeme linki</a>
                                <?php if ($canSendMail): ?>
                                    <form method="post" action="<?= h(url('/collections/' . (int) $row['id'] . '/mail')) ?>" onsubmit="return confirm('Bu kayıt için tahsilat maili gönderilsin mi?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                                        <button class="button small primary" type="submit" <?= $contacts === [] ? 'disabled' : '' ?>>Tahsilat maili gönder</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    });
}

function render_paid_card_collection_list(array $rows): string
{
    if ($rows === []) {
        return '<div class="empty">Henüz başarılı iyzico kart ödemesi bulunmuyor.</div>';
    }

    ob_start();
    ?>
    <div class="collection-card-list">
        <?php foreach ($rows as $row): ?>
            <?php
            $sourceType = (string) ($row['source_type'] ?? 'renewal');
            $isManual = $sourceType === 'manual';
            $recordId = (int) ($isManual ? ($row['request_id'] ?? 0) : ($row['renewal_id'] ?? 0));
            $recordUrl = $isManual
                ? url('/payment-requests?created=' . $recordId)
                : url('/renewals/' . $recordId . '/edit');
            $recordLabel = $isManual
                ? manual_payment_request_number(['id' => $recordId])
                : 'Yenileme #' . $recordId;
            $paidAtRaw = (string) (($row['paid_at'] ?? '') ?: ($row['updated_at'] ?? '') ?: ($row['created_at'] ?? ''));
            $paidAt = $paidAtRaw !== '' ? date('d.m.Y H:i', strtotime($paidAtRaw)) : '-';
            $company = trim((string) ($row['company_name'] ?? ''));
            if ($company === '') {
                $company = trim((string) (($row['customer_email'] ?? '') ?: ($row['customer_phone'] ?? '')));
            }
            $paymentId = trim((string) ($row['payment_id'] ?? ''));
            $paymentStatus = trim((string) ($row['payment_status'] ?? ''));
            ?>
            <article class="collection-card paid-card">
                <div class="collection-card-main">
                    <div>
                        <small><?= h($isManual ? 'Manuel ödeme' : 'Yenileme ödemesi') ?></small>
                        <strong><?= h($company !== '' ? $company : '-') ?></strong>
                        <span><?= h((string) (($row['title'] ?? '') ?: '-')) ?></span>
                    </div>
                    <div class="collection-card-meta">
                        <span class="badge active">Tahsil edildi</span>
                        <b><?= h(money_format_local($row['amount'] ?? null, (string) ($row['currency'] ?? 'TRY'))) ?></b>
                    </div>
                </div>
                <div class="collection-card-grid">
                    <div>
                        <span>Kayıt</span>
                        <strong><?= h($recordLabel) ?></strong>
                    </div>
                    <div>
                        <span>Ödeme tarihi</span>
                        <strong><?= h($paidAt) ?></strong>
                    </div>
                    <div>
                        <span>iyzico ödeme no</span>
                        <strong><?= h($paymentId !== '' ? $paymentId : '-') ?></strong>
                    </div>
                    <div>
                        <span>Durum</span>
                        <strong><?= h($paymentStatus !== '' ? $paymentStatus : 'SUCCESS') ?></strong>
                    </div>
                </div>
                <div class="collection-card-actions">
                    <a class="button small secondary" href="<?= h($recordUrl) ?>">Kaydı aç</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

function render_notes(): void
{
    $repo = new NotesRepository();
    $filters = [
        'q' => trim((string) ($_GET['q'] ?? '')),
        'status' => trim((string) ($_GET['status'] ?? '')),
        'channel' => trim((string) ($_GET['channel'] ?? '')),
    ];
    $notes = $repo->all($filters, 160);
    $stats = $repo->stats();
    $customers = (new RenewalRepository())->customers();
    $focusId = (int) ($_GET['focus'] ?? 0);
    $canManage = Auth::can('notes.manage');
    $canDelete = Auth::can('notes.delete');

    render_layout('Görüşmeler ve Notlar', static function () use ($notes, $stats, $customers, $filters, $focusId, $canManage, $canDelete): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Müşteri takip</p>
                <h1>Görüşmeler ve Notlar</h1>
            </div>
            <div class="page-actions">
                <a href="<?= h(url('/notes')) ?>" class="button secondary">Listeyi yenile</a>
            </div>
        </div>

        <section class="note-stats">
            <div><span>Takip</span><strong><?= h((string) ($stats['open'] ?? 0)) ?></strong></div>
            <div><span>Cevap bekleyen</span><strong><?= h((string) ($stats['waiting'] ?? 0)) ?></strong></div>
            <div><span>Tamamlanan</span><strong><?= h((string) ($stats['done'] ?? 0)) ?></strong></div>
            <div><span>Vazgeçilen</span><strong><?= h((string) ($stats['cancelled'] ?? 0)) ?></strong></div>
        </section>

        <section class="notes-layout">
            <article class="panel note-create-panel">
                <div class="section-head compact">
                    <div>
                        <p class="eyebrow">Yeni kayıt</p>
                        <h2>Görüşme notu ekle</h2>
                        <span>Konuştuğunuz, fiyat verdiğiniz veya takip edeceğiniz müşteriyi buraya not alın.</span>
                    </div>
                </div>
                <form method="post" class="note-form">
                    <?= csrf_field() ?>
                    <?= render_note_form_fields([], $customers, false) ?>
                    <button class="button primary full" type="submit" <?= $canManage ? '' : 'disabled' ?>>Notu kaydet</button>
                </form>
            </article>

            <section class="panel note-list-panel">
                <div class="section-head compact">
                    <div>
                        <p class="eyebrow">Kontrol listesi</p>
                        <h2>Kayıtlı görüşmeler</h2>
                        <span><?= h((string) count($notes)) ?> not listeleniyor</span>
                    </div>
                </div>

                <form method="get" class="toolbar notes-toolbar">
                    <input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Müşteri, kişi, not veya başlık ara">
                    <select name="status">
                        <?= option('', 'Tüm durumlar', $filters['status']) ?>
                        <?php foreach (NotesRepository::statusOptions() as $statusKey => $statusLabel): ?>
                            <?= option((string) $statusKey, (string) $statusLabel, $filters['status']) ?>
                        <?php endforeach; ?>
                    </select>
                    <select name="channel">
                        <?= option('', 'Tüm görüşme tipleri', $filters['channel']) ?>
                        <?php foreach (NotesRepository::channelOptions() as $channelKey => $channelLabel): ?>
                            <?= option((string) $channelKey, (string) $channelLabel, $filters['channel']) ?>
                        <?php endforeach; ?>
                    </select>
                    <button class="button secondary" type="submit">Filtrele</button>
                </form>

                <?php if ($notes === []): ?>
                    <div class="empty">Bu filtreye uygun görüşme notu bulunamadı.</div>
                <?php else: ?>
                    <div class="notes-list">
                        <?php foreach ($notes as $note): ?>
                            <?= render_note_card($note, $customers, $canManage, $canDelete, $focusId === (int) $note['id']) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </section>
        <?php
    });
}

function render_note_card(array $note, array $customers, bool $canManage, bool $canDelete, bool $open = false): string
{
    $id = (int) ($note['id'] ?? 0);
    $status = NotesRepository::normalizeStatus((string) ($note['status'] ?? 'open'));
    $channel = NotesRepository::normalizeChannel((string) ($note['channel'] ?? 'meeting'));
    $customer = note_customer_label($note);
    $followLabel = note_datetime_label($note['follow_up_at'] ?? null, 'Takip tarihi yok');
    $price = note_price_label($note);

    ob_start();
    ?>
    <details class="note-card <?= h(note_status_class($status)) ?>" id="note-<?= h((string) $id) ?>" <?= $open ? 'open' : '' ?>>
        <summary class="note-summary">
            <span class="note-summary-main">
                <small><?= h($customer) ?></small>
                <strong><?= h((string) ($note['title'] ?? 'Görüşme notu')) ?></strong>
                <em><?= h(note_channel_label($channel)) ?><?= !empty($note['contact_name']) ? ' · ' . h((string) $note['contact_name']) : '' ?></em>
            </span>
            <span class="note-summary-side">
                <span class="badge <?= h(note_status_class($status)) ?>"><?= h(note_status_label($status)) ?></span>
                <b><?= h($followLabel) ?></b>
            </span>
        </summary>
        <div class="note-card-body">
            <div class="note-meta-grid">
                <div><span>Müşteri</span><strong><?= h($customer) ?></strong></div>
                <div><span>Görüşme tipi</span><strong><?= h(note_channel_label($channel)) ?></strong></div>
                <div><span>Sözlü fiyat</span><strong><?= h($price) ?></strong></div>
                <div><span>Kaydı açan</span><strong><?= h((string) (($note['created_by_name'] ?? '') ?: '-')) ?></strong></div>
            </div>
            <div class="note-text">
                <?= nl2br(h((string) ($note['note'] ?? '')), false) ?>
            </div>

            <?php if ($canManage): ?>
                <form method="post" action="<?= h(url('/notes/' . $id . '/update')) ?>" class="note-edit-form">
                    <?= csrf_field() ?>
                    <?= render_note_form_fields($note, $customers, true) ?>
                    <button class="button small primary" type="submit">Güncelle</button>
                </form>
                <form method="post" action="<?= h(url('/notes/' . $id . '/status')) ?>" class="note-status-form">
                    <?= csrf_field() ?>
                    <select name="status">
                        <?php foreach (NotesRepository::statusOptions() as $statusKey => $statusLabel): ?>
                            <?= option((string) $statusKey, (string) $statusLabel, $status) ?>
                        <?php endforeach; ?>
                    </select>
                    <button class="button small secondary" type="submit">Durumu kaydet</button>
                </form>
            <?php endif; ?>

            <?php if ($canDelete): ?>
                <form method="post" action="<?= h(url('/notes/' . $id . '/delete')) ?>" class="note-delete-form" onsubmit="return confirm('Bu görüşme notu silinsin mi?')">
                    <?= csrf_field() ?>
                    <button class="button small danger" type="submit">Sil</button>
                </form>
            <?php endif; ?>
        </div>
    </details>
    <?php
    return (string) ob_get_clean();
}

function render_note_form_fields(array $note, array $customers, bool $compact): string
{
    $selectedCustomerId = (string) ($note['customer_id'] ?? '');
    $channel = NotesRepository::normalizeChannel((string) ($note['channel'] ?? 'meeting'));
    $status = NotesRepository::normalizeStatus((string) ($note['status'] ?? 'open'));
    $currency = NotesRepository::normalizeCurrency((string) ($note['currency'] ?? 'TRY'));

    ob_start();
    ?>
    <label class="<?= $compact ? '' : 'field-wide' ?>">
        Müşteri / cari
        <select name="customer_id">
            <?= option('', 'Müşteri seçmeden not al', $selectedCustomerId) ?>
            <?php foreach ($customers as $customer): ?>
                <?= option((string) $customer['id'], (string) $customer['company_name'], $selectedCustomerId) ?>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        Görüşülen kişi
        <input name="contact_name" maxlength="190" value="<?= h((string) ($note['contact_name'] ?? '')) ?>" placeholder="Ad soyad">
    </label>
    <label>
        Görüşme tipi
        <select name="channel">
            <?php foreach (NotesRepository::channelOptions() as $channelKey => $channelLabel): ?>
                <?= option((string) $channelKey, (string) $channelLabel, $channel) ?>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        Durum
        <select name="status">
            <?php foreach (NotesRepository::statusOptions() as $statusKey => $statusLabel): ?>
                <?= option((string) $statusKey, (string) $statusLabel, $status) ?>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        Takip tarihi
        <input type="date" name="follow_up_date" value="<?= h(note_follow_date_value($note)) ?>">
    </label>
    <label>
        Saat
        <input type="time" name="follow_up_time" value="<?= h(note_follow_time_value($note)) ?>">
    </label>
    <label class="<?= $compact ? '' : 'field-wide' ?>">
        Başlık
        <input name="title" maxlength="190" value="<?= h((string) ($note['title'] ?? '')) ?>" placeholder="Örn: Kamera sistemi için sözlü fiyat verildi" required>
    </label>
    <label>
        Sözlü fiyat / tutar
        <input type="number" min="0" step="0.01" name="quoted_amount" value="<?= h(note_amount_value($note)) ?>" placeholder="Opsiyonel">
    </label>
    <label>
        Para birimi
        <select name="currency">
            <?php foreach (allowed_currency_options() as $currencyOption): ?>
                <?= option($currencyOption, $currencyOption, $currency) ?>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field-wide">
        Not
        <textarea name="note" rows="<?= $compact ? '3' : '5' ?>" placeholder="Konuşulan konu, verilen fiyat, geri dönüş beklentisi ve önemli detaylar" required><?= h((string) ($note['note'] ?? '')) ?></textarea>
    </label>
    <?php
    return (string) ob_get_clean();
}

function note_status_label(string $status): string
{
    return NotesRepository::statusOptions()[NotesRepository::normalizeStatus($status)] ?? 'Takip edilecek';
}

function note_channel_label(string $channel): string
{
    return NotesRepository::channelOptions()[NotesRepository::normalizeChannel($channel)] ?? 'Görüşme';
}

function note_status_class(string $status): string
{
    return match (NotesRepository::normalizeStatus($status)) {
        'done' => 'active',
        'waiting' => 'pending',
        'cancelled' => 'cancelled',
        default => 'renewed',
    };
}

function note_customer_label(array $note): string
{
    return trim((string) ($note['company_name'] ?? '')) ?: 'Müşteri seçilmedi';
}

function note_price_label(array $note): string
{
    if (($note['quoted_amount'] ?? null) === null || (string) ($note['quoted_amount'] ?? '') === '') {
        return '-';
    }

    return money_format_local($note['quoted_amount'], NotesRepository::normalizeCurrency((string) ($note['currency'] ?? 'TRY')));
}

function note_datetime_label(mixed $value, string $empty = '-'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $empty;
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('d.m.Y H:i', $timestamp) : $empty;
}

function note_follow_date_value(array $note): string
{
    $value = trim((string) ($note['follow_up_at'] ?? ''));
    $timestamp = $value !== '' ? strtotime($value) : false;

    return $timestamp ? date('Y-m-d', $timestamp) : '';
}

function note_follow_time_value(array $note): string
{
    $value = trim((string) ($note['follow_up_at'] ?? ''));
    $timestamp = $value !== '' ? strtotime($value) : false;

    return $timestamp ? date('H:i', $timestamp) : '09:00';
}

function note_amount_value(array $note): string
{
    if (($note['quoted_amount'] ?? null) === null || (string) ($note['quoted_amount'] ?? '') === '') {
        return '';
    }

    return number_format((float) $note['quoted_amount'], 2, '.', '');
}

function render_payment_requests(): void
{
    $repo = new PaymentRequestRepository();
    $requests = $repo->all(40);
    $openRequests = array_values(array_filter($requests, static function (array $request): bool {
        return !in_array((string) ($request['status'] ?? 'pending'), ['paid', 'refunded'], true);
    }));
    $closedRequests = array_values(array_filter($requests, static function (array $request): bool {
        return in_array((string) ($request['status'] ?? 'pending'), ['paid', 'refunded'], true);
    }));
    $createdId = (int) ($_GET['created'] ?? 0);
    $created = $createdId > 0 ? $repo->find($createdId) : null;
    $message = $created ? payment_request_default_message($created) : '';
    $paymentUrl = $created ? manual_payment_request_url($created) : '';
    $whatsappNumber = $created ? whatsapp_number_from_phone((string) ($created['customer_phone'] ?? '')) : null;
    $whatsappHref = $created && $whatsappNumber !== null
        ? whatsapp_web_url($whatsappNumber, payment_request_whatsapp_message($created, $message, $paymentUrl))
        : '';
    $customerChoices = manual_payment_customer_choices((new RenewalRepository())->customersWithContacts());
    $createdRecipients = $created ? manual_payment_request_recipients($created) : [];
    $canManage = Auth::can('collections.manage');

    render_layout('Ödeme Talep Et', static function () use ($requests, $openRequests, $closedRequests, $created, $message, $paymentUrl, $whatsappHref, $customerChoices, $createdRecipients, $canManage): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Manuel tahsilat</p>
                <h1>Ödeme Talep Et</h1>
            </div>
            <div class="page-actions">
                <button type="button" class="button primary" data-toggle-details="manual-payment-create">Manuel ödeme talebi oluştur</button>
            </div>
        </div>

        <section class="payment-request-layout manual-payment-request-layout">
            <details class="panel payment-request-panel manual-payment-create-panel" id="manual-payment-create">
                <summary class="manual-payment-create-summary">
                    <div>
                        <p class="eyebrow">Yeni case</p>
                        <h2>Manuel ödeme talebi oluştur</h2>
                        <p>Dükkanda, telefonda veya tek seferlik satışlarda tutarı ve açıklamayı yazıp müşteriye ödeme linki gönderin.</p>
                    </div>
                    <span class="button small primary" data-open-label>Formu aç</span>
                    <span class="button small secondary" data-close-label>Formu kapat</span>
                </summary>

                <form method="post" class="payment-request-form" data-manual-payment-form>
                    <?= csrf_field() ?>
                    <label class="field-wide">
                        Ne için ödeme alınacak?
                        <input name="title" maxlength="190" placeholder="Örn: Teknik servis ücreti, adaptör satışı, yerinde destek" required>
                    </label>
                    <label>
                        Tutar
                        <input type="number" min="0.01" step="0.01" name="amount" placeholder="100.00" required>
                    </label>
                    <label>
                        Para birimi
                        <select name="currency">
                            <?php foreach (allowed_currency_options() as $currency): ?>
                                <?= option($currency, $currency, 'TRY') ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Ödeme günü
                        <input type="date" name="payment_due_date">
                    </label>
                    <div class="payment-request-reminder-card field-wide" data-payment-request-reminder>
                        <div>
                            <strong>Otomatik hatırlatma</strong>
                            <span>Ödeme gününe 3 gün kala başlar; ödeme alınmazsa her gün tekrar eder.</span>
                        </div>
                        <label>
                            Hatırlatma saati
                            <input type="time" name="reminder_time" value="09:00">
                        </label>
                        <label>
                            Kaç gün önce?
                            <input type="number" min="0" max="365" name="reminder_start_days_before" value="3">
                        </label>
                        <label class="payment-request-check">
                            <input type="checkbox" name="reminder_repeat_daily" value="1" data-reminder-repeat-daily>
                            <span>Her gün tekrarla</span>
                        </label>
                        <label class="payment-request-check">
                            <input type="checkbox" name="reminder_until_paid" value="1" data-reminder-until-paid>
                            <span>Ödeyene kadar her gün tekrarla</span>
                        </label>
                    </div>
                    <div class="manual-payment-customer-field">
                        <label>
                            Firma / müşteri
                            <input name="customer_name" maxlength="190" placeholder="Cari unvanı yazın veya manuel girin" autocomplete="off" data-manual-customer-input>
                            <input type="hidden" name="customer_id" value="" data-manual-customer-id>
                        </label>
                        <div class="manual-payment-customer-results" data-manual-customer-results hidden></div>
                    </div>
                    <label>
                        Telefon
                        <input name="customer_phone" placeholder="05xx xxx xx xx" data-manual-customer-phone>
                    </label>
                    <label>
                        E-posta alıcıları
                        <input type="text" name="customer_email" placeholder="musteri@firma.com, muhasebe@firma.com" autocomplete="off" data-manual-customer-email>
                        <span class="field-help">Birden fazla alıcı için virgül, noktalı virgül veya boşluk kullanın.</span>
                    </label>
                    <label>
                        Vergi / TC no
                        <input name="customer_tax_number" maxlength="60" placeholder="Kart ödemesinde gerekebilir" data-manual-customer-tax>
                    </label>
                    <div class="manual-payment-contact-panel field-wide" data-manual-contact-panel hidden>
                        <div class="section-head compact">
                            <div>
                                <strong>Yetkili seç</strong>
                                <span>Ödeme linkini göndermek istediğiniz yetkilileri işaretleyin.</span>
                            </div>
                            <span class="badge active" data-manual-contact-count>0 yetkili</span>
                        </div>
                        <div class="manual-payment-contact-list" data-manual-contact-list></div>
                    </div>
                    <label class="field-wide">
                        Açıklama
                        <textarea name="description" rows="5" placeholder="Ne yapıldı, neden bu tahsilat alınıyor, müşteriye görünecek kısa açıklama"></textarea>
                    </label>
                    <div class="payment-request-submit field-wide">
                        <button class="button primary" type="submit" <?= $canManage ? '' : 'disabled' ?>>Ödeme talebi oluştur</button>
                    </div>
                </form>
            </details>

            <aside class="panel payment-request-preview">
                <?php if ($created): ?>
                    <div class="payment-request-preview-head">
                        <small>Oluşturulan talep</small>
                        <strong><?= h((string) ($created['title'] ?? '-')) ?></strong>
                        <span><?= h(manual_payment_request_number($created)) ?> · <?= h(payment_request_status_label((string) ($created['status'] ?? 'pending'))) ?></span>
                    </div>

                    <div class="payment-request-summary">
                        <div>
                            <span>Tutar</span>
                            <strong><?= h(money_format_local($created['amount'] ?? null, (string) ($created['currency'] ?? 'TRY'))) ?></strong>
                            <small><?= h((string) (($created['customer_name'] ?? '') ?: 'Müşteri adı boş')) ?></small>
                        </div>
                        <div>
                            <span>İletişim</span>
                            <strong><?= h((string) (($created['customer_phone'] ?? '') ?: '-')) ?></strong>
                            <small><?= h(manual_payment_email_input_value($created) ?: 'E-posta yok') ?></small>
                        </div>
                        <div>
                            <span>Ödeme günü</span>
                            <strong><?= h(payment_request_due_date_label($created)) ?></strong>
                            <small>Hatırlatma başlangıcı bu tarihe göre hesaplanır.</small>
                        </div>
                        <div>
                            <span>Hatırlatma</span>
                            <strong><?= h(payment_request_reminder_time_value($created)) ?></strong>
                            <small><?= h(payment_request_reminder_summary($created)) ?></small>
                        </div>
                    </div>

                    <label class="payment-request-link">
                        Ödeme linki
                        <input type="text" value="<?= h($paymentUrl) ?>" readonly>
                    </label>

                    <div class="payment-request-actions">
                        <a class="button whatsapp" href="<?= h($whatsappHref ?: '#') ?>" target="takip_whatsapp_web" <?= $whatsappHref === '' ? 'aria-disabled="true"' : '' ?>>WhatsApp üzerinden yolla</a>
                        <?php if ($canManage): ?>
                            <form method="post" action="<?= h(url('/payment-requests/' . (int) $created['id'] . '/mail')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="recipient_email" value="__all__">
                                <input type="hidden" name="message" value="<?= h($message) ?>">
                                <button class="button secondary" type="submit" <?= manual_payment_request_email_recipients($created) === [] ? 'disabled' : '' ?>>Tüm alıcılara mail at</button>
                            </form>
                            <?php if ((string) ($created['status'] ?? 'pending') === 'paid'): ?>
                                <form method="post" action="<?= h(url('/payment-requests/' . (int) $created['id'] . '/refund')) ?>" onsubmit="return confirm('Bu ödeme iade edildi olarak işaretlensin mi? Bu işlem tahsilat toplamlarından düşer, kayıt geçmişte kalır.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="refund_note" value="Manuel iade kaydı">
                                    <button class="button danger" type="submit">İade edildi</button>
                                </form>
                            <?php elseif ((string) ($created['status'] ?? 'pending') !== 'refunded'): ?>
                                <form method="post" action="<?= h(url('/payment-requests/' . (int) $created['id'] . '/delete')) ?>" onsubmit="return confirm('Bu ödeme talebi silinsin mi? Link iptal edilecek ve hatırlatma gönderilmeyecek.')">
                                    <?= csrf_field() ?>
                                    <button class="button danger" type="submit">Talebi sil</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a class="button primary" href="<?= h($paymentUrl) ?>" target="_blank" rel="noopener">Direkt ödeme linkini aç</a>
                    </div>
                    <?php if ($createdRecipients !== []): ?>
                        <div class="manual-payment-recipient-list">
                            <?php foreach ($createdRecipients as $recipient): ?>
                                <?php
                                $recipientMessage = payment_request_whatsapp_message($created, $message, $paymentUrl);
                                $waNumber = whatsapp_number_from_phone((string) ($recipient['phone'] ?? ''));
                                $waHref = $waNumber !== null ? whatsapp_web_url($waNumber, $recipientMessage) : '';
                                ?>
                                <article class="manual-payment-recipient-card">
                                    <div>
                                        <strong><?= h((string) ($recipient['name'] ?: 'Yetkili')) ?></strong>
                                        <span><?= h((string) (($recipient['email'] ?? '') ?: ($recipient['phone'] ?? '-'))) ?></span>
                                    </div>
                                    <a class="button small whatsapp" href="<?= h($waHref ?: '#') ?>" target="takip_whatsapp_web" <?= $waHref === '' ? 'aria-disabled="true"' : '' ?>>WhatsApp</a>
                                    <?php if ($canManage): ?>
                                        <form method="post" action="<?= h(url('/payment-requests/' . (int) $created['id'] . '/mail')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="recipient_email" value="<?= h((string) ($recipient['email'] ?? '')) ?>">
                                            <input type="hidden" name="message" value="<?= h($message) ?>">
                                            <button class="button small secondary" type="submit" <?= !filter_var((string) ($recipient['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? 'disabled' : '' ?>>Mail</button>
                                        </form>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="payment-request-preview-head">
                        <small>Nasıl çalışır?</small>
                        <strong>Case oluştur, link gönder, ödeme al.</strong>
                        <span>Tutar ve açıklama yenileme kaydından bağımsız tutulur.</span>
                    </div>
                    <div class="settings-note">
                        <strong>Örnek kullanım</strong>
                        <span>“100 TL servis ücreti” yazın; telefon varsa WhatsApp, e-posta alıcıları varsa mail, müşteri yanınızdaysa direkt ödeme linkini açın.</span>
                    </div>
                <?php endif; ?>
            </aside>
        </section>

        <script type="application/json" id="manual-payment-customers-json"><?= json_encode($customerChoices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?></script>

        <section class="panel manual-payment-history">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Geçmiş</p>
                    <h2>Manuel ödeme talepleri</h2>
                    <span>Ödenmemiş talepler üstte, tamamlanan tahsilatlar altta ayrı izlenir.</span>
                </div>
            </div>
            <?php if ($requests === []): ?>
                <div class="empty">Henüz manuel ödeme talebi oluşturulmadı.</div>
            <?php else: ?>
                <div class="manual-payment-board">
                    <section class="manual-payment-group open">
                        <div class="manual-payment-group-head">
                            <div>
                                <small>Üstte takip edilecekler</small>
                                <strong>Ödenmemiş ödeme talepleri</strong>
                            </div>
                            <span class="badge warning"><?= count($openRequests) ?> açık</span>
                        </div>
                        <?= render_manual_payment_request_list($openRequests, $canManage, 'Şu anda bekleyen ödeme talebi yok.') ?>
                    </section>

                    <section class="manual-payment-group closed">
                        <div class="manual-payment-group-head">
                            <div>
                                <small>Geçmiş kayıt</small>
                                <strong>Ödenmiş ve iade edilmiş talepler</strong>
                            </div>
                            <span class="badge active"><?= count($closedRequests) ?> tamamlandı</span>
                        </div>
                        <?= render_manual_payment_request_list($closedRequests, $canManage, 'Henüz tamamlanmış ödeme talebi yok.') ?>
                    </section>
                </div>
            <?php endif; ?>
        </section>
        <?php
    });
}

function render_manual_payment_request_list(array $requests, bool $canManage, string $emptyMessage): string
{
    if ($requests === []) {
        return '<div class="empty compact-empty">' . h($emptyMessage) . '</div>';
    }

    ob_start();
    ?>
    <div class="manual-payment-list">
        <?php foreach ($requests as $request): ?>
            <?php $requestUrl = manual_payment_request_url($request); ?>
            <?php $requestStatus = (string) ($request['status'] ?? 'pending'); ?>
            <?php $isPaidRequest = $requestStatus === 'paid'; ?>
            <?php $isRefundedRequest = $requestStatus === 'refunded'; ?>
            <?php $isFinalizedRequest = $isPaidRequest || $isRefundedRequest; ?>
            <details class="manual-payment-case <?= $isFinalizedRequest ? 'finalized' : 'open' ?>">
                <summary class="manual-payment-row">
                    <div>
                        <small><?= h(manual_payment_request_number($request)) ?></small>
                        <strong><?= h((string) ($request['title'] ?? '-')) ?></strong>
                        <span><?= h((string) (($request['customer_name'] ?? '') ?: ($request['customer_phone'] ?? '') ?: ($request['customer_email'] ?? '-'))) ?></span>
                    </div>
                    <b><?= h(money_format_local($request['amount'] ?? null, (string) ($request['currency'] ?? 'TRY'))) ?></b>
                    <span class="badge <?= h(payment_request_status_class((string) ($request['status'] ?? 'pending'))) ?>"><?= h(payment_request_status_label((string) ($request['status'] ?? 'pending'))) ?></span>
                    <span class="button small secondary">Düzenle</span>
                </summary>
                <div class="manual-payment-case-body">
                    <div class="manual-payment-case-actions">
                        <a class="button small secondary" href="<?= h($requestUrl) ?>" target="_blank" rel="noopener">Linki aç</a>
                        <span class="badge"><?= h(payment_request_reminder_summary($request)) ?></span>
                        <?php if (manual_payment_request_email_recipients($request) === []): ?>
                            <span class="badge warning">E-posta eksik</span>
                        <?php endif; ?>
                        <?php if ($canManage && $isPaidRequest): ?>
                            <form method="post" action="<?= h(url('/payment-requests/' . (int) $request['id'] . '/refund')) ?>" onsubmit="return confirm('Bu ödeme iade edildi olarak işaretlensin mi? Bu işlem tahsilat toplamlarından düşer, kayıt geçmişte kalır.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="refund_note" value="Manuel iade kaydı">
                                <button class="button small danger" type="submit">İade edildi</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canManage && !$isFinalizedRequest): ?>
                            <form method="post" action="<?= h(url('/payment-requests/' . (int) $request['id'] . '/delete')) ?>" onsubmit="return confirm('Bu ödeme talebi silinsin mi? Link iptal edilecek ve hatırlatma gönderilmeyecek.')">
                                <?= csrf_field() ?>
                                <button class="button small danger" type="submit">Sil</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($canManage): ?>
                        <form method="post" action="<?= h(url('/payment-requests/' . (int) $request['id'] . '/update')) ?>" class="manual-payment-edit-form">
                            <?= csrf_field() ?>
                            <label>
                                Başlık
                                <input name="title" value="<?= h((string) ($request['title'] ?? '')) ?>" required>
                            </label>
                            <label>
                                Tutar
                                <input type="number" min="0.01" step="0.01" name="amount" value="<?= h(number_format((float) ($request['amount'] ?? 0), 2, '.', '')) ?>" <?= $isFinalizedRequest ? 'readonly' : '' ?> required>
                            </label>
                            <label>
                                Para birimi
                                <select name="currency" <?= $isFinalizedRequest ? 'disabled' : '' ?>>
                                    <?php foreach (allowed_currency_options() as $currency): ?>
                                        <?= option($currency, $currency, (string) ($request['currency'] ?? 'TRY')) ?>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Ödeme günü
                                <input type="date" name="payment_due_date" value="<?= h(payment_request_due_date_value($request)) ?>">
                            </label>
                            <div class="payment-request-reminder-card field-wide compact" data-payment-request-reminder>
                                <div>
                                    <strong>Otomatik hatırlatma</strong>
                                    <span><?= h(payment_request_reminder_summary($request)) ?></span>
                                </div>
                                <label>
                                    Saat
                                    <input type="time" name="reminder_time" value="<?= h(payment_request_reminder_time_value($request)) ?>">
                                </label>
                                <label>
                                    Gün önce
                                    <input type="number" min="0" max="365" name="reminder_start_days_before" value="<?= h((string) max(0, (int) ($request['reminder_start_days_before'] ?? 3))) ?>">
                                </label>
                                <label class="payment-request-check">
                                    <input type="checkbox" name="reminder_repeat_daily" value="1" data-reminder-repeat-daily <?= !empty($request['reminder_repeat_daily']) ? 'checked' : '' ?>>
                                    <span>Her gün</span>
                                </label>
                                <label class="payment-request-check">
                                    <input type="checkbox" name="reminder_until_paid" value="1" data-reminder-until-paid <?= !empty($request['reminder_until_paid']) ? 'checked' : '' ?>>
                                    <span>Ödeyene kadar</span>
                                </label>
                            </div>
                            <label>
                                Firma / müşteri
                                <input name="customer_name" value="<?= h((string) ($request['customer_name'] ?? '')) ?>" placeholder="Cari unvanı">
                            </label>
                            <label>
                                Telefon
                                <input name="customer_phone" value="<?= h((string) ($request['customer_phone'] ?? '')) ?>" placeholder="05xx xxx xx xx">
                            </label>
                            <label>
                                E-posta alıcıları
                                <input type="text" name="customer_email" value="<?= h(manual_payment_email_input_value($request)) ?>" placeholder="musteri@firma.com, muhasebe@firma.com">
                            </label>
                            <label>
                                Vergi / TC no
                                <input name="customer_tax_number" value="<?= h((string) ($request['customer_tax_number'] ?? '')) ?>" maxlength="60">
                            </label>
                            <label class="field-wide">
                                Açıklama
                                <textarea name="description" rows="3"><?= h((string) ($request['description'] ?? '')) ?></textarea>
                            </label>
                            <div class="field-wide manual-payment-edit-footer">
                                <?php if ($isFinalizedRequest): ?>
                                    <span><?= $isRefundedRequest ? 'İade edilmiş' : 'Ödenmiş' ?> case için tutar ve para birimi korunur; iletişim ve açıklama güncellenir.</span>
                                <?php endif; ?>
                                <button type="submit" class="button small primary">Kaydet</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
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
                    <a href="<?= h(url('/renewals/create')) ?>" class="button primary">Yeni kayıt</a>
                </div>
            <?php endif; ?>
        </div>

        <form method="get" class="toolbar">
            <input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Müşteri, ürün veya hizmet ara">
            <select name="state">
                <?= option('', 'Tüm durumlar', $filters['state']) ?>
                <?= option('due_soon', 'Yaklaşan', $filters['state']) ?>
                <?= option('overdue', 'Geciken', $filters['state']) ?>
                <?= option('active', 'Aktif', $filters['state']) ?>
                <?= option('renewed', 'Yenilendi', $filters['state']) ?>
                <?= option('cancelled', 'İptal', $filters['state']) ?>
            </select>
            <select name="kind">
                <?= option('', 'Tüm tipler', $filters['kind']) ?>
                <?= option('product', 'Ürün', $filters['kind']) ?>
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
                            <button type="button" class="button small primary" data-dialog-open="renewal-customer-send-<?= h($row['id']) ?>">Müşteriye gönder</button>
                            <button type="button" class="button small secondary" data-dialog-open="manual-price-<?= h($row['id']) ?>">Manuel fiyat ver</button>
                            <button type="button" class="button small supplier-price" data-dialog-open="supplier-price-<?= h($row['id']) ?>">Tedarikçiden fiyat al</button>
                            <a href="<?= h(url('/renewals/' . $row['id'] . '/edit')) ?>" class="button small secondary">Düzenle</a>
                            <?= render_renewal_communication_dialogs($row) ?>
                            <?= render_manual_price_dialog($row) ?>
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
        flash('error', 'Kayıt bulunamadı.');
        redirect('/renewals');
    }

    $errors = [];
    if ($method === 'POST') {
        verify_csrf();
        $errors = validate_renewal($_POST);

        if ($errors === []) {
            if ($id) {
                $repo->update($id, $_POST);
                flash('success', 'Yenileme kaydı güncellendi.');
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
                flash('success', $oldEntryMode ? 'Eski tarihli onaylanmış teklif takip listesine alındı.' : 'Yenileme kaydı oluşturuldu.');
            }
            redirect('/');
        }

        $renewal = array_merge($renewal ?? [], $_POST);
        $renewal['payment_customer_choice'] = !empty($_POST['payment_customer_choice']) ? '1' : '0';
        $renewal['supplier_share_customer_info'] = !empty($_POST['supplier_share_customer_info']) ? '1' : '0';
    }

    $customers = $repo->customers();
    $suppliers = $repo->suppliers();
    $supplierGroups = $repo->supplierGroups();
    $definitions = $repo->renewalDefinitions();
    $periods = $repo->renewalPeriods();
    $itemRows = renewal_item_rows($repo, $renewal, $id);
    $reminderRows = renewal_reminder_days($renewal);
    $invoiceRows = $id ? $repo->invoicePeriods($id) : [];
    $paymentRows = $id ? $repo->cardPayments($id) : [];
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

    render_layout($id ? 'Yenileme düzenle' : ($oldEntryMode ? 'Eski tarihli giriş' : 'Yeni yenileme'), static function () use ($id, $renewal, $customers, $suppliers, $supplierGroups, $definitions, $periods, $itemRows, $errors, $reminderRows, $invoiceRows, $paymentRows, $iyzicoReady, $settings, $nextRenewalStartDate, $nextRenewalDate, $oldEntryMode): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow"><?= $id ? 'Kayıt düzenle' : ($oldEntryMode ? 'Onaylı eski teklif' : 'Yeni kayıt') ?></p>
                <h1><?= $id ? 'Yenileme Düzenle' : ($oldEntryMode ? 'Eski Tarihli Giriş' : 'Yeni Yenileme') ?></h1>
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
                        <span>Daha önce yapılmış ve tahsilatı tamamlanmış ürün veya hizmeti eski satın alma/onay tarihiyle girin. Sistem seçtiğiniz periyoda göre yenileme gününü hesaplayıp takip listesine alır; bu kayıt tahsilat merkezine düşmez.</span>
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
                        $removedPaymentMethod = payment_method_is_removed_30_day($paymentMethod);
                        if ($removedPaymentMethod) {
                            $paymentMethod = '';
                        }
                        $paymentOptions = payment_method_options();
                        ?>
                        <div class="field-block payment-choice">
                            <label>
                                Ödeme şekli
                                <select name="payment_method" data-payment-method-select>
                                    <?= option('', 'Ödeme şeklini seçin', $paymentMethod) ?>
                                    <?php if ($paymentMethod !== '' && !payment_method_is_removed_30_day($paymentMethod) && !in_array($paymentMethod, $paymentOptions, true)): ?>
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
                                    <?= !empty($renewal['payment_customer_choice']) || $removedPaymentMethod || (!$id && $paymentMethod === '') ? 'checked' : '' ?>
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
                            <span class="muted compact">30, 20 ve 15 gün kala bilgilendirme; 7 gün ve altında günlük bildirim aktiftir.</span>
                        </div>

                        <div class="contact-editor supplier-price-box">
                            <div class="section-head">
                                <h3>TEDARİKÇİ FİYAT TALEBİ</h3>
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
                                <label class="checkline choice-line">
                                    <input
                                        type="checkbox"
                                        name="supplier_share_customer_info"
                                        value="1"
                                        <?= (($renewal['supplier_share_customer_info'] ?? '1') !== '0') ? 'checked' : '' ?>
                                    >
                                    Tedarikçiye cari bilgisini gönder
                                </label>
                                <div class="settings-note compact span-2">
                                    <strong>Cari gizliliği</strong>
                                    <span>Kutucuğu kapatırsanız fiyat talebi mailinde ve tedarikçi teklif formunda müşteri/cari adı “Cari bilgisi gizli” olarak görünür.</span>
                                </div>
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
            <section class="panel narrow payment-panel" id="card-payment">
                <div class="section-head">
                    <div>
                        <h2>Kredi kartı tahsilatı</h2>
                        <p class="muted compact">Kart bilgisi panelde tutulmaz; müşteri iyzico Checkout Form sayfasına yönlendirilir.</p>
                    </div>
                    <span class="badge <?= $iyzicoReady ? 'active' : 'cancelled' ?>">
                        <?= $iyzicoReady ? 'iyzico hazır' : 'Ayar bekliyor' ?>
                    </span>
                </div>

                <?php if (!$iyzicoReady): ?>
                    <div class="settings-note payment-note">
                        <strong>Kredi kartı ayarları eksik</strong>
                        <span>Ödeme linki oluşturmak için Ayarlar bölümünden iyzico API bilgilerini girip entegrasyonu aktif edin.</span>
                    </div>
                    <a class="button secondary" href="<?= h(url('/settings#iyzico-settings')) ?>">iyzico ayarlarına git</a>
                <?php else: ?>
                    <?= render_card_payment_create_form((int) $id, 'iyzico ödeme linki oluştur', $renewal) ?>
                <?php endif; ?>

                <?php if ($paymentRows): ?>
                    <div class="payment-list">
                        <?php foreach ($paymentRows as $paymentRow): ?>
                            <div class="payment-row">
                                <div>
                                    <span class="badge <?= h(iyzico_status_badge_class((string) $paymentRow['status'])) ?>">
                                        <?= h(strtoupper((string) ($paymentRow['provider'] ?? 'card'))) ?> · <?= h(iyzico_status_label((string) $paymentRow['status'])) ?>
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
                    onsubmit="return confirm('Kayıt mevcut yenileme tarihinden sonraki döneme ilerletilsin mi?')"
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
                $requestResult = send_customer_info_request($requestRepo, $_POST);
                if (($requestResult['channel'] ?? '') === 'whatsapp' && !empty($requestResult['whatsapp_url'])) {
                    if (wants_json_response()) {
                        json_response([
                            'ok' => true,
                            'message' => 'Cari bilgi talep linki hazırlandı.',
                            'whatsapp_url' => (string) $requestResult['whatsapp_url'],
                        ]);
                    }

                    header('Location: ' . (string) $requestResult['whatsapp_url']);
                    return;
                }

                flash('success', 'Cari bilgi talep maili gönderildi. Link 48 saat geçerli olacak.');
                redirect('/customers');
            } catch (Throwable $e) {
                if (wants_json_response()) {
                    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
                }
                $errors[] = $e->getMessage();
            }
        } else {
            $errors = validate_customer_form($_POST);

            if ($errors === []) {
                $repo->createCustomer($_POST);
                flash('success', 'Müşteri eklendi.');
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
    $recentCustomers = $customers;
    usort($recentCustomers, static function (array $a, array $b): int {
        $dateCompare = strtotime((string) ($b['created_at'] ?? '')) <=> strtotime((string) ($a['created_at'] ?? ''));

        return $dateCompare !== 0 ? $dateCompare : ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
    });
    $recentCustomers = array_slice($recentCustomers, 0, 10);

    render_layout('Müşteriler', static function () use ($customers, $recentCustomers, $errors, $parasutStatus, $contactRows, $canManage, $canDelete, $canDetails): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Cari kaynak</p>
                <h1>Müşteriler</h1>
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

        <section class="customer-board" data-customer-board>
            <div class="panel customer-list-panel">
                <div class="section-head compact">
                    <div>
                        <h2>Tüm cariler</h2>
                        <span data-customer-filter-status><?= h((string) count($customers)) ?> cari listeleniyor</span>
                    </div>
                </div>
                <label class="customer-live-filter">
                    <span>Alfabetik filtre</span>
                    <input type="search" placeholder="A, BA, firma adı veya vergi no yazın" autocomplete="off" data-customer-filter-input>
                </label>
                <div class="stack customer-list-stack" data-customer-filter-list>
                    <?php foreach ($customers as $customer): ?>
                        <?= render_customer_list_card($customer, $canManage, $canDelete, $canDetails) ?>
                    <?php endforeach; ?>
                    <div class="customer-empty-state" data-customer-empty hidden>Bu filtreye uyan cari bulunamadı.</div>
                </div>
            </div>
            <aside class="panel recent-customers-panel">
                <div class="section-head compact">
                    <div>
                        <h2>Son eklenen 10 cari</h2>
                        <span>Yeni açılan cari kartları</span>
                    </div>
                </div>
                <div class="recent-customer-list">
                    <?php foreach ($recentCustomers as $customer): ?>
                        <?= render_recent_customer_card($customer, $canManage) ?>
                    <?php endforeach; ?>
                </div>
            </aside>
        </section>
        <?php
    });
}

function handle_customer_parasut_contact_send(RenewalRepository $repo, int $customerId): void
{
    verify_csrf();
    $returnTo = safe_return_path($_POST['return_to'] ?? '/customers');
    $customer = $repo->findCustomer($customerId);
    if (!$customer) {
        flash('error', 'Cari kartı bulunamadı.');
        redirect($returnTo);
    }

    try {
        $result = send_customer_to_parasut($repo, $customer);
        $name = trim((string) ($result['name'] ?? ($customer['company_name'] ?? '')));
        $contactId = trim((string) ($result['id'] ?? ''));
        if (!empty($result['already_linked'])) {
            flash('success', 'Cari zaten Paraşüt ile bağlı: #' . $contactId);
        } elseif (!empty($result['matched'])) {
            flash('success', ($name !== '' ? $name . ' ' : '') . 'Paraşüt’te bulundu ve cari kartına bağlandı: #' . $contactId);
        } else {
            flash('success', ($name !== '' ? $name . ' ' : '') . 'Paraşüt carisi olarak oluşturuldu: #' . $contactId);
        }
    } catch (Throwable $e) {
        error_log('Cari Paraşüt’e gönderilemedi: ' . $e->getMessage());
        flash('error', friendly_error_message($e));
    }

    redirect($returnTo);
}

function send_customer_to_parasut(RenewalRepository $repo, array $customer): array
{
    $customerId = (int) ($customer['id'] ?? 0);
    $existingId = trim((string) ($customer['parasut_contact_id'] ?? ''));
    if ($customerId < 1) {
        throw new RuntimeException('Cari kartı ID bilgisi eksik.');
    }
    if ($existingId !== '') {
        return [
            'already_linked' => true,
            'id' => $existingId,
            'name' => (string) ($customer['company_name'] ?? ''),
        ];
    }

    $companyName = trim((string) ($customer['company_name'] ?? ''));
    if ($companyName === '') {
        throw new RuntimeException('Paraşüt’e göndermek için cari ünvanı zorunlu.');
    }

    $client = new ParasutClient();
    $status = $client->status();
    if (empty($status['connected'])) {
        throw new RuntimeException('Paraşüt bağlantısı aktif değil. Ayarlar > Paraşüt bölümünden bağlantıyı tamamlayın.');
    }

    $taxNumber = preg_replace('/\D+/', '', (string) ($customer['tax_number'] ?? '')) ?? '';
    $searchTerms = array_values(array_unique(array_filter([
        $taxNumber,
        $companyName,
        trim((string) ($customer['email'] ?? '')),
    ], static fn (string $value): bool => mb_strlen($value, 'UTF-8') >= 2)));

    foreach ($searchTerms as $term) {
        $matched = parasut_contact_match_for_customer($client->searchContacts($term, 8, 'customer'), $companyName, $taxNumber);
        $matchedId = trim((string) ($matched['id'] ?? ''));
        if ($matchedId !== '') {
            $repo->setCustomerParasutContactId($customerId, $matchedId);

            return [
                'matched' => true,
                'id' => $matchedId,
                'name' => (string) (($matched['name'] ?? '') ?: $companyName),
            ];
        }
    }

    $created = $client->createCustomerContact($customer);
    $contactId = trim((string) ($created['id'] ?? ''));
    if ($contactId === '') {
        throw new RuntimeException('Paraşüt cari ID dönmedi.');
    }
    $repo->setCustomerParasutContactId($customerId, $contactId);

    return [
        'created' => true,
        'id' => $contactId,
        'name' => (string) (($created['name'] ?? '') ?: $companyName),
    ];
}

function render_customer_parasut_send_control(array $customer, string $returnTo = '/customers'): string
{
    $customerId = (int) ($customer['id'] ?? 0);
    $parasutContactId = trim((string) ($customer['parasut_contact_id'] ?? $customer['submitted_parasut_contact_id'] ?? ''));
    if ($customerId < 1) {
        return '';
    }

    if ($parasutContactId !== '') {
        return '<span class="badge active">Paraşüt #' . h($parasutContactId) . '</span>';
    }

    ob_start();
    ?>
    <form method="post" action="<?= h(url('/customers/' . $customerId . '/parasut-contact')) ?>" class="inline-action-form" onsubmit="return confirm('Bu cariyi Paraşüt’e gönderelim mi?')">
        <?= csrf_field() ?>
        <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
        <button type="submit" class="button small primary">Cariyi Paraşüt’e gönder</button>
    </form>
    <?php

    return (string) ob_get_clean();
}

function render_customer_list_card(array $customer, bool $canManage, bool $canDelete, bool $canDetails): string
{
    $contacts = is_array($customer['contacts'] ?? null) ? $customer['contacts'] : [];
    $filterParts = [
        $customer['company_name'] ?? '',
        $customer['contact_name'] ?? '',
        $customer['email'] ?? '',
        $customer['phone'] ?? '',
        $customer['tax_number'] ?? '',
        $customer['tax_office'] ?? '',
        $customer['city'] ?? '',
        $customer['district'] ?? '',
        $customer['parasut_contact_id'] ?? '',
    ];
    foreach ($contacts as $contact) {
        $filterParts[] = $contact['full_name'] ?? '';
        $filterParts[] = $contact['email'] ?? '';
        $filterParts[] = $contact['phone'] ?? '';
    }
    $filterText = trim(implode(' ', array_filter(array_map(static fn (mixed $value): string => trim((string) $value), $filterParts))));
    $companyName = (string) ($customer['company_name'] ?? '');
    $primaryContact = trim((string) (($customer['contact_name'] ?? '') ?: ''));
    $locationLine = trim((string) (($customer['city'] ?? '') . (!empty($customer['district']) ? ' / ' . $customer['district'] : '')));
    $taxNumber = trim((string) ($customer['tax_number'] ?? ''));

    ob_start();
    ?>
    <?php if ($canDetails): ?>
        <details class="customer-row customer-row-compact" data-customer-card data-customer-name="<?= h($companyName) ?>" data-customer-filter="<?= h($filterText) ?>">
            <summary class="customer-summary">
                <span class="customer-summary-main">
                    <strong><?= h($companyName) ?></strong>
                </span>
                <span class="customer-summary-meta">
                    <span><?= h($primaryContact !== '' ? $primaryContact : 'Yetkili yok') ?></span>
                    <?php if ($locationLine !== ''): ?>
                        <span><?= h($locationLine) ?></span>
                    <?php endif; ?>
                    <span class="badge active"><?= h((string) count($contacts)) ?> yetkili</span>
                    <?php if ($taxNumber !== ''): ?>
                        <span>VKN: <?= h($taxNumber) ?></span>
                    <?php endif; ?>
                </span>
                <span class="customer-detail-toggle">
                    <span class="closed">Göster</span>
                    <span class="open">Gizle</span>
                </span>
            </summary>
            <div class="customer-details">
                <div class="customer-detail-grid">
                    <span><small>Yetkili</small><strong><?= h(($customer['contact_name'] ?? '') ?: '-') ?></strong></span>
                    <span><small>E-posta</small><strong><?= h(($customer['email'] ?? '') ?: '-') ?></strong></span>
                    <span><small>Telefon</small><strong><?= h(($customer['phone'] ?? '') ?: '-') ?></strong></span>
                    <span><small>Konum</small><strong><?= h(trim((string) (($customer['city'] ?? '') . ' / ' . ($customer['district'] ?? '')), ' /') ?: '-') ?></strong></span>
                    <span><small>Vergi no</small><strong><?= h(($customer['tax_number'] ?? '') ?: '-') ?></strong></span>
                    <span><small>Paraşüt</small><strong><?= h(!empty($customer['parasut_contact_id']) ? '#' . $customer['parasut_contact_id'] : '-') ?></strong></span>
                </div>
                <?php if (!empty($customer['address'])): ?>
                    <p class="customer-address"><?= h((string) $customer['address']) ?></p>
                <?php endif; ?>
                <?php if ($contacts !== []): ?>
                    <div class="customer-contacts">
                        <?php foreach ($contacts as $contact): ?>
                            <div class="customer-contact">
                                <span>
                                    <strong><?= h((string) ($contact['full_name'] ?? '')) ?></strong>
                                    <?php if (!empty($contact['role_title'])): ?>
                                        <em><?= h((string) $contact['role_title']) ?></em>
                                    <?php endif; ?>
                                    <?= h(($contact['email'] ?? '') ?: '-') ?>
                                    <?= !empty($contact['phone']) ? ' - ' . h((string) $contact['phone']) : '' ?>
                                </span>
                                <span class="badge <?= ((int) ($contact['notify_enabled'] ?? 0) === 1) ? 'active' : 'cancelled' ?>">
                                    <?= ((int) ($contact['notify_enabled'] ?? 0) === 1) ? 'Bilgilendirme açık' : 'Bilgilendirme kapalı' ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <?= render_customer_info_request_quick_form($customer) ?>
                <?php endif; ?>
                <?php if ($canManage || $canDelete): ?>
                    <div class="customer-actions">
                        <?php if ($canManage): ?>
                            <?= render_customer_parasut_send_control($customer, '/customers') ?>
                            <a href="<?= h(url('/customers/' . $customer['id'] . '/edit')) ?>" class="button small secondary" onclick="return confirm('Bu müşteri kartı düzenlensin mi?')">Düzenle</a>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                            <form method="post" action="<?= h(url('/customers/' . $customer['id'] . '/delete')) ?>" onsubmit="return confirm('Bu müşteri silinenler havuzuna tasinsin mi?')">
                                <?= csrf_field() ?>
                                <button type="submit" class="button small danger">Sil</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </details>
    <?php else: ?>
        <div class="customer-row customer-row-compact" data-customer-card data-customer-name="<?= h($companyName) ?>" data-customer-filter="<?= h($filterText) ?>">
            <div class="customer-row-head">
                <strong><?= h($companyName) ?></strong>
                <span>Detay bilgileri yetkinize kapalı.</span>
            </div>
        </div>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

function render_recent_customer_card(array $customer, bool $canManage): string
{
    $createdAt = !empty($customer['created_at']) ? date('d.m.Y H:i', strtotime((string) $customer['created_at'])) : '-';
    $contactLine = trim((string) (($customer['contact_name'] ?? '') ?: ($customer['email'] ?? '')));
    $location = trim((string) (($customer['city'] ?? '') . (!empty($customer['district']) ? ' / ' . $customer['district'] : '')));

    ob_start();
    ?>
    <article class="recent-customer-card">
        <span><?= h($createdAt) ?></span>
        <strong><?= h((string) ($customer['company_name'] ?? '-')) ?></strong>
        <small><?= h($contactLine !== '' ? $contactLine : 'Yetkili yok') ?><?= $location !== '' ? ' - ' . h($location) : '' ?></small>
        <?php if ($canManage): ?>
            <div class="recent-customer-actions">
                <?= render_customer_parasut_send_control($customer, '/customers') ?>
                <a href="<?= h(url('/customers/' . $customer['id'] . '/edit')) ?>" class="button small secondary">Düzenle</a>
            </div>
        <?php endif; ?>
    </article>
    <?php
    return (string) ob_get_clean();
}

function render_customer_info_request_quick_form(array $customer): string
{
    $contacts = is_array($customer['contacts'] ?? null) ? $customer['contacts'] : [];
    $recipient = [
        'name' => trim((string) ($customer['contact_name'] ?? '')),
        'email' => trim((string) ($customer['email'] ?? '')),
        'phone' => normalize_phone_number($customer['phone'] ?? ''),
    ];

    foreach ($contacts as $contact) {
        if (!is_array($contact)) {
            continue;
        }

        $candidate = [
            'name' => trim((string) ($contact['full_name'] ?? '')),
            'email' => trim((string) ($contact['email'] ?? '')),
            'phone' => normalize_phone_number($contact['phone'] ?? ''),
        ];

        if ($candidate['email'] !== '' || $candidate['phone'] !== '') {
            $recipient = $candidate;
            break;
        }
    }

    ob_start();
    ?>
    <div class="customer-info-request-card">
        <div>
            <strong>Eksik yetkili bilgilerini tamamlat</strong>
            <span>Cari bilgisindeki bilgilendirme yapılacak kişiler eksikse 48 saat geçerli tek kullanımlık link gönderin.</span>
        </div>
        <form method="post" action="<?= h(url('/customers')) ?>" class="customer-info-request-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_customer_info_request">
            <input type="hidden" name="request_customer_id" value="<?= h((string) ($customer['id'] ?? '')) ?>">
            <input type="hidden" name="request_expires_hours" value="48">
            <label>
                Yetkili
                <input name="request_contact_name" value="<?= h($recipient['name']) ?>" placeholder="Ad soyad">
            </label>
            <label>
                E-posta
                <input type="email" name="request_email" value="<?= h($recipient['email']) ?>" placeholder="mail@firma.com">
            </label>
            <label>
                Telefon
                <input name="request_phone" value="<?= h($recipient['phone']) ?>" placeholder="0549 576 05 49">
            </label>
            <div class="customer-info-request-actions">
                <button type="submit" name="request_channel" value="mail" class="button small secondary">Mail gönder</button>
                <button type="submit" name="request_channel" value="whatsapp" class="button small whatsapp" formtarget="takip_whatsapp_web">WhatsApp aç</button>
            </div>
        </form>
    </div>
    <?php

    return (string) ob_get_clean();
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
                <div class="contact-editor span-2" data-contact-editor data-contact-role-options="<?= contact_role_options_json() ?>" data-next-index="<?= h((string) next_contact_index($contactRows)) ?>">
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
    $recentRequests = (new CustomerInfoRequestRepository())->recent(8);

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
                        <input type="email" name="request_email" placeholder="müşteri@firma.com" required>
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

            <?php if ($recentRequests !== []): ?>
                <div class="customer-request-recent">
                    <div class="customer-request-recent-head">
                        <strong>Son cari bilgi talepleri</strong>
                        <span>Form doldurulunca buradan tek tuşla Paraşüt carisi oluşturabilirsiniz.</span>
                    </div>
                    <div class="customer-request-recent-list">
                        <?php foreach ($recentRequests as $request): ?>
                            <?= render_customer_info_request_recent_row($request, $returnTo) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </dialog>
    <?php

    return (string) ob_get_clean();
}

function render_customer_info_request_recent_row(array $request, string $returnTo): string
{
    $status = (string) ($request['status'] ?? '');
    $statusLabel = match ($status) {
        'submitted' => 'Tamamlandı',
        'expired' => 'Süresi doldu',
        default => 'Bekleniyor',
    };
    $badgeClass = match ($status) {
        'submitted' => 'active',
        'expired' => 'cancelled',
        default => 'pending',
    };
    $customerName = trim((string) (($request['submitted_customer_name'] ?? '') ?: ($request['customer_name'] ?? '') ?: ($request['recipient_email'] ?? '-')));
    $submittedCustomerId = (int) ($request['submitted_customer_id'] ?? 0);
    $submittedAt = !empty($request['submitted_at']) ? date('d.m.Y H:i', strtotime((string) $request['submitted_at'])) : '';
    $createdAt = !empty($request['created_at']) ? date('d.m.Y H:i', strtotime((string) $request['created_at'])) : '';
    $customerForAction = [
        'id' => $submittedCustomerId,
        'parasut_contact_id' => (string) ($request['submitted_parasut_contact_id'] ?? ''),
    ];

    ob_start();
    ?>
    <article class="customer-request-recent-row">
        <div>
            <strong><?= h($customerName) ?></strong>
            <span><?= h($submittedAt !== '' ? 'Doldurdu: ' . $submittedAt : 'Talep: ' . $createdAt) ?></span>
        </div>
        <div class="customer-request-recent-actions">
            <span class="badge <?= h($badgeClass) ?>"><?= h($statusLabel) ?></span>
            <?php if ($status === 'submitted' && $submittedCustomerId > 0): ?>
                <?= render_customer_parasut_send_control($customerForAction, $returnTo) ?>
            <?php endif; ?>
        </div>
    </article>
    <?php

    return (string) ob_get_clean();
}

function handle_customer_edit(RenewalRepository $repo, string $method, int $id): void
{
    $customer = $repo->findCustomer($id);
    if (!$customer) {
        flash('error', 'Müşteri bulunamadı.');
        redirect('/customers');
    }

    $errors = [];
    $contactRows = $customer['contacts'] ?: default_contact_rows();

    if ($method === 'POST') {
        verify_csrf();
        $errors = validate_customer_form($_POST);

        if ($errors === []) {
            $repo->updateCustomer($id, $_POST);
            flash('success', 'Müşteri güncellendi.');
            redirect('/customers');
        }

        $customer = array_merge($customer, $_POST);
        $contactRows = submitted_contact_rows();
    }

    $parasutStatus = (new ParasutClient())->status();

    render_layout('Müşteri Düzenle', static function () use ($customer, $contactRows, $parasutStatus, $errors): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Müşteri kartı</p>
                <h1>Müşteri Düzenle</h1>
            </div>
            <div class="page-actions">
                <?= render_customer_parasut_send_control($customer, '/customers/' . (int) $customer['id'] . '/edit') ?>
                <a href="<?= h(url('/customers')) ?>" class="button secondary">Müşterilere dön</a>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert error"><?= h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <section class="panel narrow-form">
            <form method="post" class="form-grid" onsubmit="return confirm('Müşteri bilgileri güncellensin mi?')">
                <?= csrf_field() ?>
                <?= render_customer_form_fields($customer, $contactRows, $parasutStatus, false) ?>
                <button type="submit" class="button primary">Güncelle</button>
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
            <a href="<?= h(url('/customers')) ?>" class="button secondary">Müşterilere dön</a>
        </div>

        <section class="panel">
            <?php if ($customers === []): ?>
                <div class="empty">Silinen müşteri bulunmuyor.</div>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($customers as $customer): ?>
                        <div class="customer-row">
                            <div class="customer-row-head">
                                <strong><?= h($customer['company_name']) ?></strong>
                                <form method="post" action="<?= h(url('/customers/' . $customer['id'] . '/restore')) ?>" onsubmit="return confirm('Bu müşteri havuzdan geri alınsın mi?')">
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
                flash('success', 'Tedarikçi eklendi.');
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

    render_layout('Tedarikçiler', static function () use ($suppliers, $supplierGroups, $supplierFormData, $errors, $parasutStatus, $contactRows, $canManage, $canDelete, $canDetails, $canDefinitions): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Tedarik kaynagi</p>
                <h1>Tedarikçiler</h1>
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
                <h2>Kayıtlı tedarikçiler</h2>
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
                                                    <a href="<?= h(url('/suppliers/' . $supplier['id'] . '/edit')) ?>" class="button small secondary" onclick="return confirm('Bu tedarikçi kartı düzenlensin mi?')">Düzenle</a>
                                                <?php endif; ?>
                                                <?php if ($canDelete): ?>
                                                    <form method="post" action="<?= h(url('/suppliers/' . $supplier['id'] . '/delete')) ?>" onsubmit="return confirm('Bu tedarikçi silinenler havuzuna tasinsin mi?')">
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
                                        <span>Detay bilgileri yetkinize kapalı.</span>
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
        flash('error', 'Tedarikçi bulunamadı.');
        redirect('/suppliers');
    }

    $errors = [];
    $contactRows = $supplier['contacts'] ?: default_contact_rows();

    if ($method === 'POST') {
        verify_csrf();
        $errors = validate_supplier_form($_POST);

        if ($errors === []) {
            $repo->updateSupplier($id, $_POST);
            flash('success', 'Tedarikçi güncellendi.');
            redirect('/suppliers');
        }

        $supplier = array_merge($supplier, $_POST);
        $contactRows = submitted_contact_rows();
    }

    $parasutStatus = (new ParasutClient())->status();
    $supplierGroups = $repo->supplierGroups();

    render_layout('Tedarikçi Düzenle', static function () use ($supplier, $contactRows, $parasutStatus, $supplierGroups, $errors): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Tedarikçi kartı</p>
                <h1>Tedarikçi Düzenle</h1>
            </div>
            <a href="<?= h(url('/suppliers')) ?>" class="button secondary">Tedarikçilere dön</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert error"><?= h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <section class="panel narrow-form">
            <form method="post" class="form-grid" onsubmit="return confirm('Tedarikçi bilgileri güncellensin mi?')">
                <?= csrf_field() ?>
                <?= render_customer_form_fields($supplier, $contactRows, $parasutStatus, false, 'supplier', $supplierGroups) ?>
                <button type="submit" class="button primary">Güncelle</button>
            </form>
        </section>
        <?php
    });
}

function render_deleted_suppliers(RenewalRepository $repo): void
{
    $suppliers = $repo->suppliersWithContacts(true);

    render_layout('Silinen Tedarikçiler', static function () use ($suppliers): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Havuz</p>
                <h1>Silinen Tedarikçiler</h1>
            </div>
            <a href="<?= h(url('/suppliers')) ?>" class="button secondary">Tedarikçilere dön</a>
        </div>

        <section class="panel">
            <?php if ($suppliers === []): ?>
                <div class="empty">Silinen tedarikçi bulunmuyor.</div>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($suppliers as $supplier): ?>
                        <div class="customer-row">
                            <div class="customer-row-head">
                                <strong><?= h($supplier['company_name']) ?></strong>
                                <form method="post" action="<?= h(url('/suppliers/' . $supplier['id'] . '/restore')) ?>" onsubmit="return confirm('Bu tedarikçi havuzdan geri alınsın mi?')">
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
        $errors[] = 'Tedarikçi adı zorunlu.';
    }
    foreach (submitted_contact_rows() as $contact) {
        if (!empty($contact['notify_enabled']) && trim((string) ($contact['email'] ?? '')) === '') {
            $errors[] = 'Bilgilendirme gönderilecek yetkililer için e-posta zorunlu.';
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
        $requestResult = send_customer_info_request(new CustomerInfoRequestRepository(), $_POST);
        if (($requestResult['channel'] ?? '') === 'whatsapp' && !empty($requestResult['whatsapp_url'])) {
            if (wants_json_response()) {
                json_response([
                    'ok' => true,
                    'message' => 'Cari bilgi talep linki hazırlandı.',
                    'whatsapp_url' => (string) $requestResult['whatsapp_url'],
                ]);
            }

            header('Location: ' . (string) $requestResult['whatsapp_url']);
            return;
        }

        flash('success', 'Cari bilgi talep maili gönderildi. Link 48 saat geçerli olacak.');
    } catch (Throwable $e) {
        if (wants_json_response()) {
            json_response(['ok' => false, 'message' => $e->getMessage()], 422);
        }
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
                    <?php if (!empty($contact['role_title'])): ?>
                        <em><?= h((string) $contact['role_title']) ?></em>
                    <?php endif; ?>
                    <?= h($contact['email'] ?: '-') ?>
                </span>
                <span class="badge <?= ((int) $contact['notify_enabled'] === 1) ? 'active' : 'cancelled' ?>">
                    <?= ((int) $contact['notify_enabled'] === 1) ? 'Bilgilendirme açık' : 'Bilgilendirme kapalı' ?>
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
        $errors[] = 'Firma adı zorunlu.';
    }
    foreach (submitted_contact_rows() as $contact) {
        if (!empty($contact['notify_enabled']) && trim((string) ($contact['email'] ?? '')) === '') {
            $errors[] = 'Bilgilendirme gönderilecek yetkililer için e-posta zorunlu.';
            break;
        }
    }

    return $errors;
}

function send_customer_info_request(CustomerInfoRequestRepository $repo, array $data): array
{
    $channel = (string) ($data['request_channel'] ?? 'mail');
    $channel = in_array($channel, ['mail', 'whatsapp'], true) ? $channel : 'mail';
    $email = trim((string) ($data['request_email'] ?? ''));
    if ($channel === 'mail' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Cari bilgi talebi için geçerli bir e-posta adresi girin.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Cari bilgi talebi için geçerli bir e-posta adresi girin.');
    }

    $phone = normalize_phone_number($data['request_phone'] ?? '');
    $whatsappNumber = $channel === 'whatsapp' ? whatsapp_number_from_phone($phone) : null;
    if ($channel === 'whatsapp' && $whatsappNumber === null) {
        throw new RuntimeException('WhatsApp göndermek için geçerli bir telefon numarası girin.');
    }

    $recipientName = trim((string) ($data['request_contact_name'] ?? ''));
    $customerId = empty($data['request_customer_id']) ? null : (int) $data['request_customer_id'];
    $expiresHours = max(1, min(168, (int) ($data['request_expires_hours'] ?? 48)));
    $request = $repo->create($email, $customerId, (int) ($_SESSION['user_id'] ?? 0), $recipientName, $expiresHours);
    $link = absolute_app_url('/cari-bilgi/' . $request['token']);
    $stopReminderLink = absolute_app_url('/cari-bilgi/' . $request['token'] . '/hatirlatma-kapat');

    if ($channel === 'whatsapp') {
        return [
            'channel' => 'whatsapp',
            'request' => $request,
            'link' => $link,
            'whatsapp_url' => whatsapp_web_url((string) $whatsappNumber, customer_info_whatsapp_message($request, $link, $stopReminderLink)),
        ];
    }

    $settings = (new SettingsRepository())->all();
    $mail = MailTemplate::renderCustomerInfoRequest($settings, $request, $link, $stopReminderLink);
    $result = Mailer::sendWithResult(
        $email,
        'Cari bilgi formu',
        (string) $mail['body'],
        (bool) $mail['is_html'],
        $mail['inline_attachments'] ?? []
    );
    log_customer_info_request_mail(
        $email,
        'Cari bilgi formu',
        (string) ($result['body'] ?? $mail['body']),
        !empty($result['ok']) ? 'sent' : 'failed',
        !empty($result['ok']) ? null : (string) ($result['error'] ?? 'Mail gönderimi başarısız.')
    );

    if (!$result['ok']) {
        throw new RuntimeException((string) $result['error']);
    }

    return [
        'channel' => 'mail',
        'request' => $request,
        'link' => $link,
    ];
}

function log_customer_info_request_mail(string $email, string $subject, string $body, string $status, ?string $error = null): void
{
    try {
        $stmt = Database::connection()->prepare(
            'INSERT INTO mail_logs (renewal_id, recipient_email, subject, body, status, error_message, sent_at)
             VALUES (NULL, :recipient_email, :subject, :body, :status, :error_message, NOW())'
        );
        $stmt->execute([
            'recipient_email' => $email,
            'subject' => $subject,
            'body' => $body,
            'status' => $status === 'sent' ? 'sent' : 'failed',
            'error_message' => $error,
        ]);
    } catch (Throwable $e) {
        error_log('Cari bilgi talebi mail logu yazılamadı: ' . $e->getMessage());
    }
}

function customer_info_whatsapp_message(array $request, string $link, string $stopReminderLink = ''): string
{
    $name = trim((string) ($request['recipient_name'] ?? ''));
    $greeting = $name !== '' ? 'Merhaba ' . $name . ',' : 'Merhaba,';
    $expiresAt = !empty($request['expires_at']) ? date('d.m.Y H:i', strtotime((string) $request['expires_at'])) : '48 saat';

    return $greeting
        . "\n\nCari kartınızdaki bilgilendirme yapılacak yetkili kişi bilgileri eksik görünüyor. Ürün yenileme bildirimi ve teklif süreçlerini doğru kişilere ulaştırabilmemiz için aşağıdaki güvenli linkten firma ve yetkili bilgilerinizi tamamlamanızı rica ederiz."
        . "\n\nLink: " . $link
        . "\n\nBağlantı geçerlilik süresi: " . $expiresAt
        . ($stopReminderLink !== '' ? "\nTekrar hatırlatma istemiyorsanız: " . $stopReminderLink : '')
        . "\nBilgiler gönderildikten sonra link otomatik kapanır.";
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
        return ['ok' => false, 'error' => 'Bildirim için geçerli bir alıcı e-posta adresi bulunamadı.'];
    }

    $settings = (new SettingsRepository())->all();
    $mail = MailTemplate::renderCustomerInfoSubmitted($settings, $request, $values, $customerId);

    return Mailer::sendWithResult(
        $to,
        'Cari bilgi formu tamamlandı',
        (string) $mail['body'],
        (bool) $mail['is_html'],
        $mail['inline_attachments'] ?? []
    );
}

function customer_info_status_label(string $status): string
{
    return match ($status) {
        'submitted' => 'Tamamlandı',
        'expired' => 'Süresi doldu',
        default => 'Bekliyor',
    };
}

function render_customer_form_fields(array $customer, array $contactRows, array $parasutStatus, bool $showParasutSearch = true, string $parasutType = 'customer', array $supplierGroups = []): string
{
    ob_start();
    if ($showParasutSearch): ?>
        <div class="parasut-search" data-parasut-search data-parasut-type="all">
            <label>
                Paraşüt cari ara
                <input type="search" data-parasut-query placeholder="Cari adı yazın" autocomplete="off">
            </label>
            <div class="suggestions" data-parasut-results>
                <?php if (!$parasutStatus['connected']): ?>
                    <a href="<?= h(url('/settings#parasut-settings')) ?>">Paraşüt bağlantısını yap</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <input type="hidden" name="parasut_contact_id" value="<?= h($customer['parasut_contact_id'] ?? '') ?>">
    <?php if ($parasutType !== 'customer'): ?>
        <label>
            Tedarikçi grubu
            <select name="supplier_group_id">
                <option value="">Grup seçilmedi</option>
                <?php foreach ($supplierGroups as $group): ?>
                    <?= option((string) $group['id'], $group['name'], (string) ($customer['supplier_group_id'] ?? '')) ?>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <label>Firma adı <input name="company_name" value="<?= h($customer['company_name'] ?? '') ?>" required></label>
    <label>Yetkili <input name="contact_name" value="<?= h($customer['contact_name'] ?? '') ?>"></label>
    <label>E-posta <input type="email" name="email" value="<?= h($customer['email'] ?? '') ?>"></label>
    <label>Telefon <input name="phone" value="<?= h($customer['phone'] ?? '') ?>"></label>
    <label>Vergi dairesi <input name="tax_office" value="<?= h($customer['tax_office'] ?? '') ?>"></label>
    <label>Vergi no <input name="tax_number" value="<?= h($customer['tax_number'] ?? '') ?>"></label>
    <label>İl <input name="city" value="<?= h($customer['city'] ?? '') ?>"></label>
    <label>İlçe <input name="district" value="<?= h($customer['district'] ?? '') ?>"></label>
    <label>Adres <textarea name="address" rows="3"><?= h($customer['address'] ?? '') ?></textarea></label>
    <div class="contact-editor" data-contact-editor data-contact-role-options="<?= contact_role_options_json() ?>" data-next-index="<?= h((string) next_contact_index($contactRows)) ?>">
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

function default_contact_rows(array $primaryContact = []): array
{
    $rows = [];
    foreach (default_contact_role_titles() as $index => $roleTitle) {
        $rows[] = [
            'full_name' => $index === 0 ? trim((string) ($primaryContact['full_name'] ?? '')) : '',
            'role_title' => $roleTitle,
            'email' => $index === 0 ? trim((string) ($primaryContact['email'] ?? '')) : '',
            'phone' => $index === 0 ? normalize_phone_number($primaryContact['phone'] ?? '') : '',
            'notify_enabled' => 1,
        ];
    }

    return $rows;
}

function default_contact_role_titles(): array
{
    static $titles = null;
    if ($titles !== null) {
        return $titles;
    }

    $available = [];
    foreach (contact_role_definitions() as $role) {
        $name = trim((string) ($role['name'] ?? ''));
        if ($name !== '') {
            $available[contact_role_key($name)] = $name;
        }
    }

    $titles = array_map(
        static fn (string $preferred): string => $available[contact_role_key($preferred)] ?? $preferred,
        ['Satın alma', 'Muhasebe', 'Yönetici']
    );

    return $titles;
}

function contact_role_key(string $value): string
{
    $value = strtr($value, [
        'Ç' => 'c',
        'Ğ' => 'g',
        'İ' => 'i',
        'I' => 'i',
        'Ö' => 'o',
        'Ş' => 's',
        'Ü' => 'u',
        'ç' => 'c',
        'ğ' => 'g',
        'ı' => 'i',
        'i' => 'i',
        'ö' => 'o',
        'ş' => 's',
        'ü' => 'u',
    ]);

    return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($value));
}

function contact_role_definitions(): array
{
    static $roles = null;
    if ($roles !== null) {
        return $roles;
    }

    try {
        $roles = (new RenewalRepository())->contactRoles();
    } catch (Throwable) {
        $roles = array_map(
            static fn (string $name): array => ['name' => $name],
            RenewalRepository::defaultContactRoles()
        );
    }

    return $roles;
}

function contact_role_options_json(): string
{
    $names = array_values(array_filter(array_map(
        static fn (array $role): string => trim((string) ($role['name'] ?? '')),
        contact_role_definitions()
    )));

    return h(json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
}

function contact_role_select_options(string $selected): string
{
    $html = '<option value="">Görev seçin</option>';
    foreach (contact_role_definitions() as $role) {
        $name = trim((string) ($role['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $html .= option($name, $name, $selected);
    }

    return $html;
}

function next_contact_index(array $rows): int
{
    $numericKeys = array_filter(array_keys($rows), static fn (mixed $key): bool => is_int($key) || ctype_digit((string) $key));

    return $numericKeys === [] ? 0 : max(array_map('intval', $numericKeys)) + 1;
}

function render_contact_input_row(int $index, array $contact = []): string
{
    $checked = !empty($contact['notify_enabled']) ? 'checked' : '';
    $roleTitle = trim((string) ($contact['role_title'] ?? ''));
    $cardTitle = $roleTitle !== '' ? $roleTitle : 'Yeni görev kartı';

    return sprintf(
        '<div class="contact-entry contact-card" data-contact-row>
            <div class="contact-card-head">
                <div>
                    <span class="contact-card-eyebrow">Görev kartı</span>
                    <strong class="contact-card-title" data-contact-card-title>%7$s</strong>
                </div>
                <button type="button" class="button small danger ghost-danger" data-remove-contact>Sil</button>
            </div>
            <div class="contact-card-grid">
                <label>Yetkili adı <input name="contacts[%1$d][full_name]" value="%2$s" data-contact-name></label>
                <label>Görev tanımı <select name="contacts[%1$d][role_title]" data-contact-role>%6$s</select></label>
                <label>E-posta <input type="email" name="contacts[%1$d][email]" value="%3$s" data-contact-email></label>
                <label>Telefon <input name="contacts[%1$d][phone]" value="%4$s" data-contact-phone></label>
                <label class="checkline contact-card-check"><input type="checkbox" name="contacts[%1$d][notify_enabled]" value="1" %5$s> Bilgilendirme gönder</label>
            </div>
        </div>',
        $index,
        h($contact['full_name'] ?? ''),
        h($contact['email'] ?? ''),
        h($contact['phone'] ?? ''),
        $checked,
        contact_role_select_options($roleTitle),
        h($cardTitle)
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
        $rows = [30, 20, 15, 7];
    }

    $days = [];
    foreach ($rows as $row) {
        $day = max(0, (int) $row);
        if ($day > 0) {
            $days[$day] = $day;
        }
    }

    foreach ([30, 20, 15, 7] as $standardDay) {
        $days[$standardDay] = $standardDay;
    }
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
                flash('success', 'Paraşüt bağlantısı tamamlandı.');
                redirect('/settings#parasut-settings');
            }

            if ($action === 'disconnect') {
                $client->disconnect();
                flash('success', 'Paraşüt bağlantısı kaldırıldı.');
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
                    throw new RuntimeException('Ürün/hizmet adı zorunlu.');
                }

                $repo->createRenewalDefinition($_POST);
                flash('success', 'Tanım kaydedildi.');
                redirect('/settings/definitions');
            }

            if ($action === 'delete') {
                $repo->deleteRenewalDefinition((int) ($_POST['id'] ?? 0));
                flash('success', 'Tanım silindi.');
                redirect('/settings/definitions');
            }

            if ($action === 'update') {
                $repo->updateRenewalDefinition((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Tanım güncellendi.');
                redirect('/settings/definitions');
            }

            if ($action === 'create_period') {
                if ((int) ($_POST['interval_count'] ?? 0) < 1) {
                    throw new RuntimeException('Periyot sayısı en az 1 olmalı.');
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
                    throw new RuntimeException('Periyot sayısı en az 1 olmalı.');
                }

                $repo->updateRenewalPeriod((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Yenileme periyodu güncellendi.');
                redirect('/settings/definitions');
            }

            if ($action === 'create_supplier_group') {
                if (trim((string) ($_POST['group_name'] ?? '')) === '') {
                    throw new RuntimeException('Tedarikçi grup adı zorunlu.');
                }

                $repo->createSupplierGroup($_POST);
                flash('success', 'Tedarikçi grubu kaydedildi.');
                redirect('/settings/definitions#supplier-groups');
            }

            if ($action === 'delete_supplier_group') {
                $repo->deleteSupplierGroup((int) ($_POST['id'] ?? 0));
                flash('success', 'Tedarikçi grubu silindi.');
                redirect('/settings/definitions#supplier-groups');
            }

            if ($action === 'update_supplier_group') {
                $repo->updateSupplierGroup((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Tedarikçi grubu güncellendi.');
                redirect('/settings/definitions#supplier-groups');
            }

            if ($action === 'create_payment_method') {
                $repo->createPaymentMethod($_POST);
                flash('success', 'Ödeme yöntemi kaydedildi.');
                redirect('/settings/definitions#payment-methods');
            }

            if ($action === 'delete_payment_method') {
                $repo->deletePaymentMethod((int) ($_POST['id'] ?? 0));
                flash('success', 'Ödeme yöntemi silindi.');
                redirect('/settings/definitions#payment-methods');
            }

            if ($action === 'update_payment_method') {
                $repo->updatePaymentMethod((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Ödeme yöntemi güncellendi.');
                redirect('/settings/definitions#payment-methods');
            }

            if ($action === 'create_contact_role') {
                $repo->createContactRole($_POST);
                flash('success', 'Görev tanımı kaydedildi.');
                redirect('/settings/definitions#contact-roles');
            }

            if ($action === 'delete_contact_role') {
                $repo->deleteContactRole((int) ($_POST['id'] ?? 0));
                flash('success', 'Görev tanımı silindi.');
                redirect('/settings/definitions#contact-roles');
            }

            if ($action === 'update_contact_role') {
                $repo->updateContactRole((int) ($_POST['id'] ?? 0), $_POST);
                flash('success', 'Görev tanımı güncellendi.');
                redirect('/settings/definitions#contact-roles');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $definitions = $repo->renewalDefinitions();
    $periods = $repo->renewalPeriods();
    $supplierGroups = $repo->supplierGroups();
    $paymentMethods = $repo->paymentMethods();
    $contactRoles = $repo->contactRoles();
    $backPath = Auth::can('settings.manage') ? '/settings' : (Auth::can('dashboard.view') ? '/' : '/settings/definitions');

    render_layout('Tanımlamalar', static function () use ($definitions, $periods, $supplierGroups, $paymentMethods, $contactRoles, $error, $backPath): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Ayarlar</p>
                <h1>Tanımlamalar</h1>
            </div>
            <a href="<?= h(url($backPath)) ?>" class="button secondary">Geri dön</a>
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

            <article class="definition-card" id="contact-roles">
                <div class="definition-card-head">
                    <div>
                        <p class="eyebrow">Yetkili</p>
                        <h2>Görev tanımları</h2>
                    </div>
                    <span class="definition-count"><?= h((string) count($contactRoles)) ?></span>
                </div>
                <div class="definition-card-actions">
                    <button type="button" class="button small primary" data-dialog-open="contact-role-create-dialog">+ Yeni</button>
                </div>
                <details class="definition-card-details">
                    <summary>
                        <span>Detay</span>
                        <strong>Kayıtları göster</strong>
                    </summary>
                    <div class="definition-card-body">
                        <?php if ($contactRoles === []): ?>
                            <div class="empty">Görev tanımı bulunmuyor.</div>
                        <?php else: ?>
                            <div class="definition-list">
                                <?php foreach ($contactRoles as $role): ?>
                                    <div class="customer-row definition-row">
                                        <form method="post" class="definition-edit-form period-edit-form" onsubmit="return confirm('Bu görev tanımı güncellensin mi?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_contact_role">
                                            <input type="hidden" name="id" value="<?= h($role['id']) ?>">
                                            <label>
                                                Görev tanımı
                                                <input name="role_name" value="<?= h($role['name']) ?>" required>
                                            </label>
                                            <label>
                                                Sıra
                                                <input type="number" min="0" name="sort_order" value="<?= h($role['sort_order']) ?>">
                                            </label>
                                            <button type="submit" class="button small primary">Kaydet</button>
                                        </form>
                                        <form method="post" class="definition-delete-form" onsubmit="return confirm('Bu görev tanımı silinsin mi? Eski yetkili kayıtlarında yazı olarak kalır.')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_contact_role">
                                            <input type="hidden" name="id" value="<?= h($role['id']) ?>">
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

        <dialog class="app-dialog definition-dialog" id="contact-role-create-dialog">
            <div class="app-dialog-body">
                <div class="section-head dialog-head">
                    <div>
                        <p class="eyebrow">Yeni görev</p>
                        <h2>Yetkili görev tanımı ekle</h2>
                        <span>Müşteri yetkililerine görev seçerken bu liste kullanılır.</span>
                    </div>
                    <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
                </div>
                <form method="post" class="form-grid definition-dialog-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_contact_role">
                    <label>
                        Görev tanımı
                        <input name="role_name" placeholder="Örn: Satın alma" required>
                    </label>
                    <label>
                        Sıra
                        <input type="number" min="0" name="sort_order" value="10">
                    </label>
                    <div class="form-actions">
                        <button type="button" class="button secondary" data-dialog-close>Vazgeç</button>
                        <button type="submit" class="button primary">Görev tanımı ekle</button>
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
        flash('error', 'Kullanıcı bulunamadı.');
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
            $errors[] = 'Kendi kullanıcınizi pasife alamazsiniz.';
        }

        if ($isLastActiveAdmin && (!$willBeAdmin || !$willBeActive)) {
            $errors[] = 'Sistemde en az bir aktif admin kullanıcı kalmali.';
        }

        if ($errors === []) {
            try {
                if ($editing) {
                    $repo->update((int) $id, $_POST, $selectedPermissions);
                    flash('success', 'Kullanıcı ve yetkileri güncellendi.');
                } else {
                    $repo->create($_POST, $selectedPermissions);
                    $mailResult = send_user_account_mail([
                        'name' => trim((string) ($_POST['name'] ?? '')),
                        'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
                        'role' => (string) ($_POST['role'] ?? 'staff'),
                        'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                    ], (string) ($_POST['password'] ?? ''), 'created');

                    flash('success', 'Kullanıcı oluşturuldu.');
                    if ($mailResult['ok']) {
                        flash('success', 'Kullanıcı bilgileri mail olarak gönderildi.');
                    } else {
                        flash('error', 'Kullanıcı oluşturuldu ancak mail gönderilemedi: ' . (string) $mailResult['error']);
                    }
                }

                redirect('/settings/users');
            } catch (Throwable $e) {
                $errors[] = str_contains($e->getMessage(), 'Duplicate')
                    ? 'Bu e-posta adresiyle kayıtlı kullanıcı var.'
                    : $e->getMessage();
            }
        }
    }

    $users = $repo->all();
    $backPath = Auth::can('settings.manage') ? '/settings' : (Auth::can('dashboard.view') ? '/' : '/settings/users');
    $currentUserId = (int) ($currentUser['id'] ?? 0);

    render_layout('Kullanıcılar', static function () use ($users, $formData, $editing, $id, $errors, $backPath, $currentUserId): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Yetkilendirme</p>
                <h1>Kullanıcılar</h1>
            </div>
            <div class="customer-actions">
                <?php if ($editing): ?>
                    <a href="<?= h(url('/settings/users')) ?>" class="button primary">Yeni kullanıcı</a>
                <?php endif; ?>
                <a href="<?= h(url('/settings/users/deleted')) ?>" class="button secondary">Silinen kullanıcılar</a>
                <a href="<?= h(url($backPath)) ?>" class="button secondary">Geri dön</a>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert error"><?= h(implode(' ', $errors)) ?></div>
        <?php endif; ?>

        <section class="grid-2 user-board">
            <div class="panel">
                <h2><?= $editing ? 'Kullanıcı düzenle' : 'Yeni kullanıcı' ?></h2>
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
                        Şifre
                        <input type="password" name="password" value="" autocomplete="new-password" <?= $editing ? '' : 'required' ?> placeholder="<?= $editing ? 'Değiştirmek istemiyorsanız boş bırakın' : 'En az 6 karakter' ?>">
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
                            Kullanıcı aktif
                        </label>
                    </div>

                    <div class="permission-box">
                        <div class="section-head">
                            <h3>Yetkiler</h3>
                            <span class="badge active">Panel</span>
                        </div>
                        <p class="muted compact">Admin rolünde tüm yetkiler otomatik açıktır. Personel için görüntüleme, detay ve düzenleme izinlerini buradan seçin.</p>
                        <?= render_permission_checkboxes((array) ($formData['permissions'] ?? []), (string) ($formData['role'] ?? 'staff')) ?>
                    </div>

                    <button type="submit" class="button primary"><?= $editing ? 'Kullanıcıyı güncelle' : 'Kullanıcı oluştur' ?></button>
                </form>
            </div>

            <div class="panel">
                <h2>Kayıtlı kullanıcılar</h2>
                <div class="stack settings-form">
                    <?php foreach ($users as $row): ?>
                        <div class="customer-row">
                            <div class="customer-row-head">
                                <strong><?= h($row['name']) ?></strong>
                                <div class="customer-actions">
                                    <a href="<?= h(url('/settings/users/' . $row['id'] . '/edit')) ?>" class="button small secondary">Düzenle</a>
                                    <?php if ((int) $row['id'] !== $currentUserId): ?>
                                        <form method="post" action="<?= h(url('/settings/users/' . $row['id'] . '/delete')) ?>" onsubmit="return confirm('Bu kullanıcı silinenler havuzuna tasinsin mi?')">
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
                                    <?= $row['role'] === 'admin' ? 'Tüm yetkiler' : count((array) $row['permissions']) . ' yetki' ?>
                                </span>
                            </div>
                            <?php if (!empty($row['last_login_at'])): ?>
                                <span>Son giriş: <?= h(date('d.m.Y H:i', strtotime($row['last_login_at']))) ?></span>
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
        flash('error', 'Kullanıcı bulunamadı.');
        redirect('/settings/users');
    }

    if ((int) ($currentUser['id'] ?? 0) === $id) {
        flash('error', 'Kendi kullanıcınizi silemezsiniz.');
        redirect('/settings/users');
    }

    if (($user['role'] ?? '') === 'admin' && (int) ($user['is_active'] ?? 0) === 1 && $repo->activeAdminCount() <= 1) {
        flash('error', 'Sistemde en az bir aktif admin kullanıcı kalmali.');
        redirect('/settings/users');
    }

    $repo->delete($id);
    flash('success', 'Kullanıcı silinenler havuzuna taşındı.');
    redirect('/settings/users');
}

function handle_user_restore(int $id): void
{
    verify_csrf();

    $repo = new UserRepository();
    $user = $repo->find($id, true);

    if (!$user || empty($user['deleted_at'])) {
        flash('error', 'Silinen kullanıcı bulunamadı.');
        redirect('/settings/users/deleted');
    }

    $repo->restore($id);
    flash('success', 'Kullanıcı havuzdan geri alındı.');
    redirect('/settings/users/deleted');
}

function render_deleted_users(): void
{
    $repo = new UserRepository();
    $users = $repo->deleted();

    render_layout('Silinen Kullanıcılar', static function () use ($users): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Havuz</p>
                <h1>Silinen Kullanıcılar</h1>
            </div>
            <a href="<?= h(url('/settings/users')) ?>" class="button secondary">Kullanıcılara dön</a>
        </div>

        <section class="panel">
            <?php if ($users === []): ?>
                <div class="empty">Silinen kullanıcı bulunmuyor.</div>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($users as $user): ?>
                        <div class="customer-row">
                            <div class="customer-row-head">
                                <strong><?= h($user['name']) ?></strong>
                                <form method="post" action="<?= h(url('/settings/users/' . $user['id'] . '/restore')) ?>" onsubmit="return confirm('Bu kullanıcı havuzdan geri alınsın mi?')">
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
                                    <?= $user['role'] === 'admin' ? 'Tüm yetkiler' : count((array) $user['permissions']) . ' yetki' ?>
                                </span>
                            </div>
                            <span>Silinme tarihi: <?= h($user['deleted_at'] ? date('d.m.Y H:i', strtotime($user['deleted_at'])) : '-') ?></span>
                            <?php if (!empty($user['last_login_at'])): ?>
                                <span>Son giriş: <?= h(date('d.m.Y H:i', strtotime($user['last_login_at']))) ?></span>
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

    render_layout('Mail logları', static function () use ($files, $selectedFile, $selectedLines, $mailLogs): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Ayarlar</p>
                <h1>Mail logları</h1>
            </div>
            <div class="customer-actions">
                <a href="<?= h(url('/settings/mail-logs')) ?>" class="button secondary">Yenile</a>
                <a href="<?= h(url('/settings')) ?>" class="button secondary">Ayarlara dön</a>
            </div>
        </div>

        <section class="grid-2 log-board">
            <div class="panel">
                <h2>Log dosyaları</h2>
                <?php if ($files === []): ?>
                    <div class="empty">Log dosyası bulunmuyor.</div>
                <?php else: ?>
                    <div class="stack settings-form">
                        <?php foreach ($files as $file): ?>
                            <a class="log-file-row <?= $selectedFile && $selectedFile['name'] === $file['name'] ? 'active' : '' ?>" href="<?= h(url('/settings/mail-logs') . '?file=' . rawurlencode($file['name'])) ?>">
                                <strong><?= h($file['label']) ?></strong>
                                <span><?= h($file['size_label']) ?> - <?= h($file['modified_label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel log-detail-panel">
                <div class="section-head">
                    <h2><?= h($selectedFile['label'] ?? 'Log detayı') ?></h2>
                    <span class="badge active">Son 350 satır</span>
                </div>
                <?php if ($selectedFile === null): ?>
                    <div class="empty">İncelemek için soldan bir log dosyası seçin.</div>
                <?php elseif ($selectedLines === []): ?>
                    <div class="empty">Bu log dosyası boş.</div>
                <?php else: ?>
                    <pre class="log-lines"><?= h(implode("\n", $selectedLines)) ?></pre>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="section-head">
                <h2>Mail gönderim kayıtları</h2>
                <span class="badge <?= $mailLogs === [] ? 'cancelled' : 'active' ?>"><?= h((string) count($mailLogs)) ?> kayıt</span>
            </div>
            <?php if ($mailLogs === []): ?>
                <div class="empty">Mail log kaydı bulunmuyor.</div>
            <?php else: ?>
                <div class="table-wrap settings-form">
                    <table>
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Alıcı</th>
                                <th>Konu</th>
                                <th>Durum</th>
                                <th>Okunma</th>
                                <th>Hata</th>
                                <th>Detay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mailLogs as $row): ?>
                                <?php $body = trim((string) ($row['body'] ?? '')); ?>
                                <?php $previewHtml = mail_log_preview_html($body); ?>
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
                                            <?= h($row['status'] === 'sent' ? 'Gönderildi' : 'Hatalı') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['read_at'])): ?>
                                            <span class="badge active">Okundu</span>
                                            <br><span class="muted compact"><?= h(max(1, (int) ($row['read_count'] ?? 1)) . ' kez · ' . date('d.m.Y H:i', strtotime((string) $row['read_at']))) ?></span>
                                        <?php elseif (!empty($row['tracking_status'])): ?>
                                            <span class="badge cancelled">Bekliyor</span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h((string) ($row['error_message'] ?: '-')) ?></td>
                                    <td>
                                        <details class="mail-log-detail">
                                            <summary>Detay</summary>
                                            <div class="mail-log-detail-card">
                                                <div class="mail-log-detail-grid">
                                                    <span><small>Alıcı</small><strong><?= h((string) $row['recipient_email']) ?></strong></span>
                                                    <span><small>Konu</small><strong><?= h((string) $row['subject']) ?></strong></span>
                                                    <span><small>Durum</small><strong><?= h($row['status'] === 'sent' ? 'Gönderildi' : 'Hatalı') ?></strong></span>
                                                    <span><small>Tarih</small><strong><?= h(date('d.m.Y H:i', strtotime((string) $row['sent_at']))) ?></strong></span>
                                                </div>
                                                <strong>Mail önizlemesi</strong>
                                                <?php if ($body !== ''): ?>
                                                    <iframe
                                                        class="mail-log-preview"
                                                        sandbox=""
                                                        referrerpolicy="no-referrer"
                                                        title="Mail önizlemesi"
                                                        srcdoc="<?= h($previewHtml) ?>"
                                                    ></iframe>
                                                <?php else: ?>
                                                    <div class="empty compact">Bu kayıtta içerik bulunmuyor.</div>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    </td>
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

function render_security_logs(): void
{
    $events = recent_security_events();
    $stats = security_log_stats($events);

    render_layout('Güvenlik logları', static function () use ($events, $stats): void {
        ?>
        <div class="page-title">
            <div>
                <p class="eyebrow">Ayarlar</p>
                <h1>Güvenlik logları</h1>
            </div>
            <div class="customer-actions">
                <a href="<?= h(url('/settings/security-logs')) ?>" class="button secondary">Yenile</a>
                <a href="<?= h(url('/settings')) ?>" class="button secondary">Ayarlara dön</a>
            </div>
        </div>

        <section class="grid-3 compact-stats">
            <div class="stat"><span>Toplam olay</span><strong><?= h((string) $stats['total']) ?></strong></div>
            <div class="stat warning"><span>Uyarı</span><strong><?= h((string) $stats['warning']) ?></strong></div>
            <div class="stat danger"><span>Kritik</span><strong><?= h((string) $stats['critical']) ?></strong></div>
        </section>

        <section class="panel">
            <div class="section-head">
                <h2>Giriş denemeleri ve güvenlik olayları</h2>
                <span class="badge <?= $events === [] ? 'cancelled' : 'active' ?>"><?= h((string) count($events)) ?> kayıt</span>
            </div>

            <?php if ($events === []): ?>
                <div class="empty">Güvenlik log kaydı bulunmuyor.</div>
            <?php else: ?>
                <div class="table-wrap settings-form">
                    <table>
                        <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Seviye</th>
                                <th>Olay</th>
                                <th>E-posta</th>
                                <th>IP</th>
                                <th>Mesaj</th>
                                <th>Detay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <?php $context = security_context_pretty((string) ($event['context_json'] ?? '')); ?>
                                <tr>
                                    <td><?= h(date('d.m.Y H:i', strtotime((string) $event['created_at']))) ?></td>
                                    <td>
                                        <span class="badge <?= h(security_severity_badge((string) $event['severity'])) ?>">
                                            <?= h(security_severity_label((string) $event['severity'])) ?>
                                        </span>
                                    </td>
                                    <td><strong><?= h(security_event_label((string) $event['event_type'])) ?></strong></td>
                                    <td><?= h((string) ($event['email'] ?: '-')) ?></td>
                                    <td><?= h((string) ($event['ip_address'] ?: '-')) ?></td>
                                    <td><?= h((string) $event['message']) ?></td>
                                    <td>
                                        <details class="mail-log-detail">
                                            <summary>Detay</summary>
                                            <div class="mail-log-detail-card security-log-detail-card">
                                                <div class="mail-log-detail-grid">
                                                    <span><small>Olay tipi</small><strong><?= h((string) $event['event_type']) ?></strong></span>
                                                    <span><small>Kullanıcı</small><strong><?= h((string) ($event['user_name'] ?: '-')) ?></strong></span>
                                                    <span><small>Tarayıcı</small><strong><?= h((string) ($event['user_agent'] ?: '-')) ?></strong></span>
                                                    <span><small>Kayıt no</small><strong>#<?= h((string) $event['id']) ?></strong></span>
                                                </div>
                                                <?php if ($context !== ''): ?>
                                                    <pre class="log-lines security-context"><?= h($context) ?></pre>
                                                <?php else: ?>
                                                    <div class="empty compact">Bu kayıtta ek detay yok.</div>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    </td>
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

function recent_security_events(int $limit = 200): array
{
    try {
        ensure_login_ip_attempts_schema();
        $stmt = Database::connection()->prepare(
            'SELECT se.*, u.name AS user_name
             FROM security_events se
             LEFT JOIN users u ON u.id = se.user_id
             ORDER BY se.created_at DESC, se.id DESC
             LIMIT :limit_count'
        );
        $stmt->bindValue('limit_count', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

function security_log_stats(array $events): array
{
    $stats = ['total' => count($events), 'warning' => 0, 'critical' => 0];
    foreach ($events as $event) {
        $severity = (string) ($event['severity'] ?? '');
        if ($severity === 'warning') {
            $stats['warning']++;
        } elseif ($severity === 'critical') {
            $stats['critical']++;
        }
    }

    return $stats;
}

function security_context_pretty(string $json): string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return trim($json);
    }

    return (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function security_event_label(string $eventType): string
{
    return match ($eventType) {
        'login_success' => 'Başarılı giriş',
        'login_failed' => 'Hatalı giriş',
        'login_challenge_failed' => 'Doğrulama başarısız',
        'login_ip_locked' => 'IP kilitlendi',
        'login_email_locked' => 'E-posta kilitlendi',
        'login_blocked_ip' => 'Kilitli IP engellendi',
        'login_blocked_email' => 'Kilitli e-posta engellendi',
        default => $eventType,
    };
}

function security_severity_label(string $severity): string
{
    return match ($severity) {
        'critical' => 'Kritik',
        'warning' => 'Uyarı',
        default => 'Bilgi',
    };
}

function security_severity_badge(string $severity): string
{
    return match ($severity) {
        'critical' => 'overdue',
        'warning' => 'due_soon',
        default => 'active',
    };
}

function mail_log_preview_html(string $body): string
{
    $body = trim($body);
    if ($body === '') {
        return '';
    }

    $logoUrl = branding_logo_url();
    if ($logoUrl !== null) {
        $body = str_replace(['cid:app_logo', '{{logo_url}}'], $logoUrl, $body);
    }

    $body = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $body) ?? $body;
    $body = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $body) ?? $body;
    $body = preg_replace('#\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)#is', '', $body) ?? $body;

    if (preg_match('#<(?:!doctype|html|body|table|div|p|br|img|h[1-6]|span|a)\b#i', $body) === 1) {
        if (preg_match('#<html\b#i', $body) === 1) {
            return $body;
        }

        return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="color-scheme" content="light">'
            . '<style>body{margin:0;background:#f4f6f5;color:#17201c;font-family:Arial,sans-serif;}img{max-width:100%;height:auto;}</style>'
            . '</head><body>' . $body . '</body></html>';
    }

    return '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="color-scheme" content="light">'
        . '<style>body{margin:0;background:#fff;color:#17201c;font:16px/1.55 Arial,sans-serif;padding:24px;white-space:pre-wrap;}</style>'
        . '</head><body>' . h($body) . '</body></html>';
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
                    ml.body,
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
                        SELECT COALESCE(rnd.last_read_at, rnd.read_at)
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
                    ) AS read_at,
                    (
                        SELECT GREATEST(rnd.read_count, CASE WHEN rnd.read_at IS NOT NULL THEN 1 ELSE 0 END)
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
                    ) AS read_count
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
        $errors[] = 'Geçerli bir e-posta adresi girin.';
    }

    $password = (string) ($data['password'] ?? '');
    if (!$editing && $password === '') {
        $errors[] = 'Yeni kullanıcı için şifre zorunlu.';
    }

    if ($password !== '' && strlen($password) < 6) {
        $errors[] = 'Şifre en az 6 karakter olmalı.';
    }

    if (!in_array((string) ($data['role'] ?? 'staff'), ['admin', 'staff'], true)) {
        $errors[] = 'Geçersiz rol seçimi.';
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
        'reports.view',
        'flows.view',
        'renewals.view',
        'renewals.details',
        'collections.view',
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
            'label' => 'Yetkili adı',
            'token' => '{{contact_name}}',
            'description' => 'Bilgilendirme gönderilen yetkilinin adını yazar.',
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
            'label' => 'Fiyat gizli bilgisi',
            'token' => '{{total_amount}}',
            'description' => 'Mailde fiyat göstermez; detayların güvenli bağlantıda olduğunu yazar.',
        ],
        [
            'key' => 'items_table',
            'label' => 'Ürün satırları',
            'token' => '{{items_table}}',
            'description' => 'Birden fazla ürün varsa fiyat içermeyen ürün ve adet tablosunu ekler.',
        ],
        [
            'key' => 'summary_action',
            'label' => 'Teklif şablonu butonu',
            'token' => '{{summary_action}}',
            'description' => 'Alıcıya özel takipli link ile detaylı teklif/yenileme sayfasını açan butonu ekler.',
        ],
        [
            'key' => 'summary_url',
            'label' => 'Teklif şablonu linki',
            'token' => '{{summary_url}}',
            'description' => 'Alıcıya özel takipli detay bağlantısını yazar.',
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
            'label' => 'Kayıt tipi',
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
            'label' => 'Sistem adı',
            'token' => '{{app_name}}',
            'description' => 'Uygulamanın sistem adını yazar.',
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
        'definition_notification_info' => 'Alan adı ve hosting yenilemeleri genellikle sessiz ilerleyen, ancak süresi kaçırıldığında etkisi hızlı hissedilen süreçlerdir. Süre dolduğunda web sitesi, e-posta hesapları, DNS yönlendirmeleri ve bağlı servislerde erişim kesintileri yaşanabilir. Alan adı tarafında ilk günlerde yenileme çoğu zaman yapılabilse de, bekleme veya kurtarma dönemine girildiğinde ek ücret, kesinti süresi ve alan adının kaybedilmesi riski oluşabilir.',
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
                    throw new RuntimeException('Test maili için geçerli bir e-posta adresi girin.');
                }
                $settingsRepo->setMany(['template.renewal.test_email' => $testEmail]);

                $settings = array_merge($settingsRepo->all(), $templateValues, ['template.renewal.enabled' => '1']);
                $sampleRow = template_test_row();
                $recipient = [
                    'name' => 'Test Alıcı',
                    'email' => $testEmail,
                    'read_ack_url' => url('/renewals/1/read?token=' . str_repeat('0', 64)),
                    'summary_url' => renewal_summary_url(1, 60, $testEmail),
                ];
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

function handle_settings(RenewalRepository $repo, string $method): void
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

                flash('success', 'Mail ayarları kaydedildi.');
                redirect('/settings');
            }

            if ($action === 'test') {
                $testEmail = trim((string) ($_POST['test_email'] ?? ''));
                if ($testEmail === '') {
                    throw new RuntimeException('Test e-postası için alıcı adresi gerekli.');
                }

                $result = Mailer::sendWithResult(
                    $testEmail,
                    'Yenileme Takip Sistemi test e-postası',
                    "Bu e-posta mail ayarlarının test edilmesi için gönderildi.\n\nTarih: " . date('d.m.Y H:i')
                );

                if (!$result['ok']) {
                    throw new RuntimeException((string) $result['error']);
                }

                flash('success', 'Test e-postası gönderildi.');
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
                flash('success', 'Logo kaldırıldı.');
                redirect('/settings');
            }

            if ($action === 'clear_m365') {
                $settingsRepo->clearMicrosoftToken();
                flash('success', 'Microsoft 365 bağlantısı kaldırıldı.');
                redirect('/settings');
            }

            if ($action === 'save_notifications') {
                $settingsRepo->setMany([
                    'notifications.send_time' => normalize_notification_time((string) ($_POST['notification_time'] ?? '09:00')),
                ]);
                flash('success', 'Bildirim saati kaydedildi.');
                redirect('/settings#notification-settings');
            }

            if ($action === 'save_security') {
                $current = $settingsRepo->all();
                $settingsRepo->setMany([
                    'security.challenge.enabled' => !empty($_POST['security_challenge_enabled']) ? '1' : '0',
                    'security.notify.enabled' => !empty($_POST['security_notify_enabled']) ? '1' : '0',
                    'security.turnstile.site_key' => trim((string) ($_POST['security_turnstile_site_key'] ?? '')),
                    'security.turnstile.secret_key' => (string) ((string) ($_POST['security_turnstile_secret_key'] ?? '') !== '' ? $_POST['security_turnstile_secret_key'] : ($current['security.turnstile.secret_key'] ?? '')),
                ]);
                flash('success', 'Güvenlik ayarları kaydedildi.');
                redirect('/settings#security-settings');
            }

            if ($action === 'save_backup') {
                $settingsRepo->setMany([
                    'backup.enabled' => !empty($_POST['backup_enabled']) ? '1' : '0',
                    'backup.email' => trim((string) ($_POST['backup_email'] ?? '')),
                    'backup.time' => normalize_notification_time((string) ($_POST['backup_time'] ?? '02:00')),
                    'backup.keep_days' => (string) max(1, (int) ($_POST['backup_keep_days'] ?? 14)),
                ]);
                flash('success', 'Yedekleme ayarları kaydedildi.');
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
                flash('success', 'iyzico ayarları kaydedildi.');
                redirect('/settings#iyzico-settings');
            }

            if ($action === 'save_bank_transfer') {
                $settingsRepo->setMany([
                    'bank_transfer.iban_info' => trim((string) ($_POST['bank_transfer_iban_info'] ?? '')),
                    'bank_transfer.notify_emails' => normalize_email_list((string) ($_POST['bank_transfer_notify_emails'] ?? '')),
                ]);
                flash('success', 'Havale/EFT ayarları kaydedildi.');
                redirect('/settings#bank-transfer-settings');
            }

            if ($action === 'run_backup') {
                $result = DatabaseBackup::runDaily($settingsRepo, true);
                $archive = $result['archive'] ?? [];
                $mail = $result['mail'] ?? [];
                if (!empty($mail['ok'])) {
                    flash('success', 'Yedek alındı ve mail olarak gönderildi: ' . basename((string) ($archive['zip_path'] ?? '')));
                } else {
                    flash('error', 'Yedek alındı fakat mail gönderilemedi: ' . (string) ($mail['error'] ?? 'Bilinmeyen hata'));
                }
                redirect('/settings#backup-settings');
            }

            if ($action === 'parasut_exchange') {
                $parasutClient->exchangeCode((string) ($_POST['code'] ?? ''), (string) ($_POST['company_id'] ?? ''));
                flash('success', 'Paraşüt bağlantısı tamamlandı.');
                redirect('/settings#parasut-settings');
            }

            if ($action === 'parasut_disconnect') {
                $parasutClient->disconnect();
                flash('success', 'Paraşüt bağlantısı kaldırıldı.');
                redirect('/settings#parasut-settings');
            }

            if ($action === 'save_database') {
                $databaseConfig = submitted_database_config(app_config('database', []));
                test_database_config($databaseConfig, (int) ($_SESSION['user_id'] ?? 0));
                save_database_config($databaseConfig);
                flash('success', 'Veritabanı ayarları test edildi ve kaydedildi.');
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
    $canFlows = Auth::can('flows.view');
    $canLogs = Auth::can('logs.view');
    $databaseConfig = app_config('database', []);
    $stockStats = $repo->stockItemStats();

    render_layout('Ayarlar', static function () use ($settings, $token, $error, $parasutClient, $parasutStatus, $stockStats, $canDefinitions, $canUsers, $canFlows, $canLogs, $databaseConfig): void {
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
                    <a href="<?= h(url('/settings/users')) ?>" class="button primary">Kullanıcılar</a>
                <?php endif; ?>
                <a href="<?= h(url('/settings/grapesjs')) ?>" class="button primary">Mail şablon tasarımı</a>
                <a href="<?= h(url('/settings/offer-templates')) ?>" class="button secondary">Teklif şablonları</a>
                <a href="<?= h(url('/settings/stock-items')) ?>" class="button secondary">Stok kalemleri</a>
                <?php if ($canDefinitions): ?>
                    <a href="<?= h(url('/settings/definitions')) ?>" class="button secondary">Tanımlamalar</a>
                <?php endif; ?>
                <?php if ($canFlows): ?>
                    <a href="<?= h(url('/settings/flows')) ?>" class="button secondary">Akış Şemaları</a>
                <?php endif; ?>
                <?php if ($canLogs): ?>
                    <a href="<?= h(url('/settings/mail-logs')) ?>" class="button secondary">Mail logları</a>
                    <a href="<?= h(url('/settings/security-logs')) ?>" class="button secondary">Güvenlik logları</a>
                <?php endif; ?>
                <a href="<?= h(url('/')) ?>" class="button secondary">Dashboard'a dön</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><?= h($error) ?></div>
        <?php endif; ?>

        <section class="settings-board settings-board-categorized" data-settings-board>
            <div class="settings-category-heading" data-settings-group="communication">
                <span>01</span>
                <strong>İletişim ve marka</strong>
                <em>Mail, logo ve testler</em>
            </div>
            <div class="settings-category-heading" data-settings-group="integration">
                <span>02</span>
                <strong>Entegrasyon ve ödeme</strong>
                <em>Paraşüt, tahsilat ve bildirimler</em>
            </div>
            <div class="settings-category-heading" data-settings-group="system">
                <span>03</span>
                <strong>Sistem</strong>
                <em>Veritabanı ve yedekleme</em>
            </div>

            <div class="settings-card settings-card-brand" data-settings-card data-settings-key="logo" data-settings-group="communication">
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
                            <span class="muted compact">Logo yüklenmedi</span>
                        <?php endif; ?>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="logo-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_logo">
                        <label>
                            Logo dosyası
                            <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.ico,image/png,image/jpeg,image/webp,image/x-icon,image/vnd.microsoft.icon" required>
                        </label>
                        <button type="submit" class="button primary">Logo yükle</button>
                    </form>

                    <?php if ($logoUrl !== null): ?>
                        <form method="post" class="logo-remove" onsubmit="return confirm('Logo kaldırılsın mi?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="remove_logo">
                            <button type="submit" class="button danger full">Logoyu kaldır</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" class="settings-card settings-card-mail" data-settings-card data-settings-key="mail" data-settings-group="communication">
                <div class="section-head">
                    <h2>Mail Ayarları</h2>
                    <span class="badge active" data-mail-driver-badge><?= h((string) ($settings['mail.driver'] ?? 'log')) ?></span>
                </div>
                <div class="form-grid settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">

                    <div class="form-grid three">
                        <label>
                            Aktif gönderim tipi
                            <select name="mail_driver" data-mail-driver-select>
                                <?= option('log', 'Log / test modu', (string) ($settings['mail.driver'] ?? 'log')) ?>
                                <?= option('smtp', 'Normal SMTP', (string) ($settings['mail.driver'] ?? 'log')) ?>
                                <?= option('microsoft365', 'Microsoft 365 Exchange', (string) ($settings['mail.driver'] ?? 'log')) ?>
                                <?= option('mail', 'PHP mail()', (string) ($settings['mail.driver'] ?? 'log')) ?>
                            </select>
                        </label>
                        <label>
                            Gönderen adı
                            <input name="from_name" value="<?= h($settings['mail.from_name'] ?? '') ?>" required>
                        </label>
                        <label>
                            Gönderen e-posta
                            <input type="email" name="from_email" value="<?= h($settings['mail.from_email'] ?? '') ?>" required>
                        </label>
                    </div>

                    <div class="settings-section" data-mail-settings="log" <?= ($settings['mail.driver'] ?? 'log') === 'log' ? '' : 'hidden' ?>>
                        <div class="section-head">
                            <h3>Log / test modu</h3>
                            <span class="badge active">Log</span>
                        </div>
                        <div class="settings-note">
                            <strong>Mail gönderimi kapalı</strong>
                            <span>E-postalar sunucuda log kaydına yazilir.</span>
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
                                Kullanıcı adı
                                <input name="smtp_username" value="<?= h($settings['smtp.username'] ?? '') ?>">
                            </label>
                            <label>
                                Şifre
                                <input type="password" name="smtp_password" value="" placeholder="<?= ($settings['smtp.password'] ?? '') !== '' ? '********' : 'SMTP şifresi' ?>" autocomplete="new-password">
                            </label>
                        </div>
                    </div>

                    <div class="settings-section" data-mail-settings="microsoft365" <?= ($settings['mail.driver'] ?? 'log') === 'microsoft365' ? '' : 'hidden' ?>>
                        <div class="section-head">
                            <h3>Microsoft 365 Exchange</h3>
                            <span class="badge <?= $hasMicrosoftToken ? 'active' : 'cancelled' ?>"><?= $hasMicrosoftToken ? 'Bağlı' : 'Bağlı değil' ?></span>
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
                                Gönderen kullanıcı
                            <input name="m365_from_user" value="<?= h($settings['m365.from_user'] ?? '') ?>" placeholder="Boş kalırsa oturum açan hesap">
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
                            <span>Mail gönderimi hosting uzerindeki PHP mail() fonksiyonu ile yapilir.</span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="button primary">Ayarları kaydet</button>
                    </div>
                </div>
            </form>

            <div class="settings-side">
                <div class="settings-card" id="database-settings" data-settings-card data-settings-key="database" data-settings-group="system">
                    <div class="section-head">
                        <h2>Veritabanı</h2>
                        <span class="badge active">MariaDB</span>
                    </div>
                    <div class="settings-meta">
                        <span>Aktif veritabanı</span>
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
                                Veritabanı adı
                                <input name="db_database" value="<?= h($databaseConfig['database'] ?? '') ?>" required>
                            </label>
                            <label>
                                Kullanıcı adı
                                <input name="db_username" value="<?= h($databaseConfig['username'] ?? '') ?>" required>
                            </label>
                            <label>
                                Şifre
                                <input type="password" name="db_password" value="" placeholder="<?= $hasDatabasePassword ? '********' : 'Veritabanı şifresi' ?>" autocomplete="new-password">
                                <span class="field-help">Bos birakirsaniz mevcut şifre korunur.</span>
                            </label>
                            <label>
                                Karakter seti
                                <input name="db_charset" value="<?= h($databaseConfig['charset'] ?? 'utf8mb4') ?>" required>
                            </label>
                        </div>
                        <div class="settings-note">
                            <strong>Kaydetmeden önce test edilir</strong>
                            <span>Bağlantı kurulamayan veya gerekli tablolar bulunmayan veritabanı kaydedilmez.</span>
                        </div>
                        <button type="submit" class="button primary full">Bağlantıyı test et ve kaydet</button>
                    </form>
                </div>

                <div class="settings-card" id="notification-settings" data-settings-card data-settings-key="notifications" data-settings-group="integration">
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
                        <strong>Tarayıcı bildirimi</strong>
                        <span>Bildirimleri açarak yenileme hatırlatmalarını bilgisayarınızda alabilirsiniz.</span>
                    </div>
                    <div class="settings-actions settings-form">
                        <button type="button" class="button primary" data-push-subscribe>Bildirimleri ac</button>
                        <button type="button" class="button secondary" data-push-test>Test bildirimi</button>
                    </div>
                </div>

                <div class="settings-card" id="security-settings" data-settings-card data-settings-key="security" data-settings-group="system">
                    <div class="section-head">
                        <h2>Güvenlik</h2>
                        <span class="badge <?= ($settings['security.challenge.enabled'] ?? '1') === '1' ? 'active' : 'cancelled' ?>">
                            <?= ($settings['security.challenge.enabled'] ?? '1') === '1' ? 'Aktif' : 'Kapalı' ?>
                        </span>
                    </div>
                    <form method="post" class="form-grid settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_security">
                        <label class="checkline">
                            <input type="checkbox" name="security_challenge_enabled" value="1" <?= ($settings['security.challenge.enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                            Hatalı girişten sonra güvenlik doğrulaması iste
                        </label>
                        <label class="checkline">
                            <input type="checkbox" name="security_notify_enabled" value="1" <?= ($settings['security.notify.enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                            Kilit ve şüpheli girişlerde yöneticiye mail / push bildirimi gönder
                        </label>
                        <div class="settings-note">
                            <strong>Mevcut kilit kuralları</strong>
                            <span>IP: 2 hatada 10 dakika, 10 hatada 1 gün. E-posta: 5 hatada 30 dakika, 10 hatada 1 gün.</span>
                        </div>
                        <div class="form-grid two">
                            <label>
                                Cloudflare Turnstile Site Key
                                <input name="security_turnstile_site_key" value="<?= h($settings['security.turnstile.site_key'] ?? '') ?>" placeholder="Boşsa matematik doğrulaması kullanılır">
                            </label>
                            <label>
                                Cloudflare Turnstile Secret Key
                                <input type="password" name="security_turnstile_secret_key" value="" placeholder="<?= ($settings['security.turnstile.secret_key'] ?? '') !== '' ? '********' : 'Opsiyonel secret key' ?>" autocomplete="new-password">
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="button primary">Güvenlik ayarlarını kaydet</button>
                            <?php if ($canLogs): ?>
                                <a href="<?= h(url('/settings/security-logs')) ?>" class="button secondary">Güvenlik loglarını aç</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="settings-card" id="backup-settings" data-settings-card data-settings-key="backup" data-settings-group="system">
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

                <?php if ($canLogs): ?>
                    <div class="settings-card" data-settings-card data-settings-key="mail-logs" data-settings-group="system">
                        <div class="section-head">
                            <h2>Mail logları</h2>
                            <span class="badge active">Kayıtlar</span>
                        </div>
                        <div class="settings-note">
                            <strong>Gönderim ve okuma geçmişi</strong>
                            <span>Mail gönderimlerini, hata mesajlarını ve gönderilen içerik detaylarını buradan inceleyebilirsiniz.</span>
                        </div>
                        <a href="<?= h(url('/settings/mail-logs')) ?>" class="button secondary full settings-form">Mail loglarını aç</a>
                    </div>

                    <div class="settings-card" data-settings-card data-settings-key="security-logs" data-settings-group="system">
                        <div class="section-head">
                            <h2>Güvenlik logları</h2>
                            <span class="badge active">Girişler</span>
                        </div>
                        <div class="settings-note">
                            <strong>Bot ve şifre denemeleri</strong>
                            <span>Hatalı girişleri, IP/e-posta kilitlerini ve güvenlik doğrulama olaylarını buradan izleyebilirsiniz.</span>
                        </div>
                        <a href="<?= h(url('/settings/security-logs')) ?>" class="button secondary full settings-form">Güvenlik loglarını aç</a>
                    </div>
                <?php endif; ?>

                <div class="settings-card" id="iyzico-settings" data-settings-card data-settings-key="iyzico" data-settings-group="integration">
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

                <div class="settings-card" data-settings-card data-settings-key="offer-templates" data-settings-group="integration">
                    <div class="section-head">
                        <h2>Teklif şablonları</h2>
                        <span class="badge active">Şablon</span>
                    </div>
                    <div class="settings-note">
                        <strong>Tekrarlı teklifleri hızlandırın</strong>
                        <span>Kamera sistemi, lisans paketi veya bakım hizmeti gibi hazır kalemli teklifleri buradan yönetin.</span>
                    </div>
                    <a href="<?= h(url('/settings/offer-templates')) ?>" class="button secondary full settings-form">Teklif şablonlarını aç</a>
                </div>

                <div class="settings-card" data-settings-card data-settings-key="stock-items" data-settings-group="integration">
                    <div class="section-head">
                        <h2>Stok / teklif kalemleri</h2>
                        <span class="badge active"><?= h((string) $stockStats['active']) ?> aktif</span>
                    </div>
                    <div class="settings-note">
                        <strong>Paraşüt ürün kataloğu</strong>
                        <span>Ürün ve hizmetleri yerel stok tablosuna alıp tekliflerde hızlı seçebilirsiniz.</span>
                    </div>
                    <div class="settings-meta">
                        <span>Son senkron</span>
                        <strong><?= h($stockStats['last_synced_at'] !== '' ? date('d.m.Y H:i', strtotime((string) $stockStats['last_synced_at'])) : '-') ?></strong>
                    </div>
                    <a href="<?= h(url('/settings/stock-items')) ?>" class="button secondary full settings-form">Stok kalemlerini yönet</a>
                </div>

                <div class="settings-card" id="bank-transfer-settings" data-settings-card data-settings-key="bank-transfer" data-settings-group="integration">
                    <div class="section-head">
                        <h2>Havale / EFT</h2>
                        <span class="badge active">Makbuz</span>
                    </div>
                    <form method="post" class="form-grid settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_bank_transfer">
                        <label>
                            IBAN bilgileri
                            <textarea name="bank_transfer_iban_info" rows="6" placeholder="Banka adı&#10;Hesap sahibi&#10;IBAN: TR00 0000 0000 0000 0000 0000 00"><?= h($settings['bank_transfer.iban_info'] ?? '') ?></textarea>
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

                <div class="settings-card" data-settings-card data-settings-key="mail-test" data-settings-group="communication">
                    <div class="section-head">
                        <h2>Test E-postası</h2>
                        <span class="badge active">Test</span>
                    </div>
                    <form method="post" class="form-grid settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="test">
                    <label>
                        Test e-posta adresi
                        <input type="email" name="test_email" placeholder="ornek@firma.com" required>
                    </label>
                    <button type="submit" class="button primary">Test e-postası gönder</button>
                    </form>
                </div>

                <div class="settings-card" id="parasut-settings" data-settings-card data-settings-key="parasut" data-settings-group="integration">
                    <div class="section-head">
                        <h2>Paraşüt</h2>
                        <span class="badge <?= $parasutStatus['connected'] ? 'active' : 'cancelled' ?>"><?= $parasutStatus['connected'] ? 'Bağlı' : 'Bekliyor' ?></span>
                    </div>
                    <div class="settings-meta">
                        <span>Firma ID</span>
                        <strong><?= h($parasutStatus['company_id'] ?: '-') ?></strong>
                    </div>
                    <a class="button secondary full settings-form" href="<?= h($parasutClient->authorizationUrl()) ?>" target="_blank" rel="noopener">Paraşüt izin ekranını aç</a>
                    <form method="post" class="form-grid settings-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="parasut_exchange">
                        <label>Firma ID <input name="company_id" value="<?= h($parasutStatus['company_id'] ?? '') ?>" required></label>
                        <label>
                            Onay kodu
                            <input type="password" name="code" placeholder="********" autocomplete="one-time-code" required>
                            <span class="field-help">Önce izin ekranını açın, gelen kodu bu alana yazın.</span>
                        </label>
                        <button type="submit" class="button primary">Bağlantıyı kaydet</button>
                    </form>
                    <?php if ($parasutStatus['connected']): ?>
                        <form method="post" class="disconnect-form" onsubmit="return confirm('Paraşüt bağlantısı kaldırılsın mi?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="parasut_disconnect">
                            <button type="submit" class="button danger full">Paraşüt bağlantısını kaldır</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="settings-card" data-settings-card data-settings-key="microsoft-status" data-settings-group="communication">
                    <div class="section-head">
                        <h2>Microsoft Durumu</h2>
                        <span class="badge <?= $hasMicrosoftToken ? 'active' : 'cancelled' ?>"><?= $hasMicrosoftToken ? 'Bağlı' : 'Bağlı değil' ?></span>
                    </div>
                    <div class="settings-meta">
                        <span>Token bitis</span>
                        <strong><?= h($expiresAt) ?></strong>
                    </div>
                    <form method="post" class="disconnect-form" onsubmit="return confirm('Microsoft 365 bağlantısı kaldırılsın mi?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_m365">
                        <button type="submit" class="button danger full">Microsoft 365 bağlantısını kaldır</button>
                    </form>
                    <div class="settings-note">
                        <strong>Mail.Send</strong>
                        <span>Azure/Entra callback URL tanımlı olmalı.</span>
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
        error_log('Otomatik yedekleme çalışamadı: ' . $e->getMessage());
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
        throw new RuntimeException('Veritabanı host bilgisi gerekli.');
    }

    if ($config['port'] < 1 || $config['port'] > 65535) {
        throw new RuntimeException('Veritabanı portu 1 ile 65535 arasinda olmalı.');
    }

    if ($config['database'] === '') {
        throw new RuntimeException('Veritabanı adı gerekli.');
    }

    if ($config['username'] === '') {
        throw new RuntimeException('Veritabanı kullanıcı adı gerekli.');
    }

    if ($config['charset'] === '' || !preg_match('/^[A-Za-z0-9_]+$/', $config['charset'])) {
        throw new RuntimeException('Veritabanı karakter seti geçersiz.');
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
        throw new RuntimeException('Veritabanı bağlantısı kuruldu fakat gerekli sistem tabloları bulunamadı.');
    }

    if ($currentUserId > 0) {
        $userStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = :id AND is_active = 1');
        $userStatement->execute(['id' => $currentUserId]);
        if ((int) $userStatement->fetchColumn() < 1) {
            throw new RuntimeException('Veritabanı bağlantısı kuruldu fakat mevcut kullanıcı bu veritabanında bulunamadı.');
        }
    }
}

function save_database_config(array $config): void
{
    $path = database_settings_path();
    $dir = dirname($path);

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Veritabanı ayar klasörü oluşturulamadı.');
    }

    $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Veritabanı ayarları kaydedilemedi.');
    }

    @chmod($path, 0640);
}

function save_branding_logo(string $currentPath): string
{
    $file = $_FILES['logo'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Logo dosyası seçin.');
    }

    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo yüklenemedi.');
    }

    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Logo dosyası en fazla 2 MB olmalı.');
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
        throw new RuntimeException('Logo PNG, JPG, WEBP veya ICO formatinda olmalı.');
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
        throw new RuntimeException('Logo dosya tipi geçersiz.');
    }

    $extension = $extension === 'jpeg' ? 'jpg' : $extension;
    $uploadDir = ROOT_PATH . '/public/uploads/branding';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Logo klasörü oluşturulamadı.');
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

function company_letterhead_data(?array $settings = null): array
{
    $settings ??= branding_settings();
    $email = trim((string) (($settings['company.email'] ?? '') ?: ($settings['mail.from_email'] ?? '')));
    if ($email === '' || mb_strtolower($email, 'UTF-8') === 'yenileme@example.com') {
        $email = 'satis@bigabilisim.com';
    }

    $website = trim((string) ($settings['company.website'] ?? ''));
    if ($website === '' || str_contains(mb_strtolower($website, 'UTF-8'), 'takip.bigabilisim.com')) {
        $website = 'https://www.antalyabigabilisim.com';
    }

    return [
        'name' => trim((string) (($settings['company.name'] ?? '') ?: ($settings['mail.from_name'] ?? '') ?: app_config('app.name', 'Yenileme Takibi'))),
        'email' => $email,
        'phone' => trim((string) ($settings['company.phone'] ?? '')),
        'address' => trim((string) ($settings['company.address'] ?? '')),
        'website' => $website,
        'website_label' => website_display_label($website),
        'logo_url' => branding_logo_url($settings),
    ];
}

function website_display_label(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return preg_replace('/^www\./i', '', $host) ?? $host;
    }

    return preg_replace('#^https?://#i', '', rtrim($url, '/')) ?? $url;
}

function render_company_letterhead(string $contextLabel = 'Teklifi hazırlayan firma'): string
{
    $company = company_letterhead_data();
    $details = [];
    if ($company['email'] !== '') {
        $details[] = '<a href="mailto:' . h((string) $company['email']) . '">' . h((string) $company['email']) . '</a>';
    }
    if ($company['phone'] !== '') {
        $details[] = '<span>' . h((string) $company['phone']) . '</span>';
    }
    if ($company['website_label'] !== '') {
        $details[] = '<a href="' . h((string) $company['website']) . '" target="_blank" rel="noopener">' . h((string) $company['website_label']) . '</a>';
    }

    ob_start();
    ?>
    <div class="company-letterhead">
        <div class="company-letterhead-copy">
            <span><?= h($contextLabel) ?></span>
            <strong><?= h((string) $company['name']) ?></strong>
            <?php if ($details !== []): ?>
                <p class="company-letterhead-links"><?= implode(' - ', $details) ?></p>
            <?php endif; ?>
            <?php if ($company['address'] !== ''): ?>
                <em><?= h((string) $company['address']) ?></em>
            <?php endif; ?>
        </div>
        <?php if ($company['logo_url'] !== null): ?>
            <img src="<?= h((string) $company['logo_url']) ?>" alt="<?= h((string) $company['name']) ?> logosu">
        <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
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
        flash('error', 'Önce Microsoft 365 Client ID ve Client Secret bilgilerini kaydedin.');
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
        flash('error', 'Microsoft 365 oturum doğrulaması geçersiz.');
        redirect('/settings');
    }

    $code = trim((string) ($_GET['code'] ?? ''));
    if ($code === '') {
        flash('error', 'Microsoft 365 onay kodu alınamadı.');
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
        flash('success', 'Microsoft 365 mail bağlantısı tamamlandı.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('/settings');
}

function validate_renewal(array $data): array
{
    $errors = [];
    if (empty($data['customer_id'])) {
        $errors[] = 'Müşteri seçimi zorunlu.';
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
            $errors[] = 'Ürün satırlarinda adet 0’dan büyük olmalı.';
            break;
        }
        if ($hasTitle && $unitPriceRaw !== '' && !is_numeric(str_replace(',', '.', $unitPriceRaw))) {
            $errors[] = 'Birim fiyat sayısal olmalı.';
            break;
        }
        if ($hasTitle && $vatRateRaw !== '' && !is_numeric(str_replace(',', '.', $vatRateRaw))) {
            $errors[] = 'KDV oranı sayısal olmalı.';
            break;
        }
    }
    if ($validItemCount < 1 && empty($data['definition_id']) && trim((string) ($data['title'] ?? '')) === '') {
        $errors[] = 'En az bir ürün veya hizmet satıri ekleyin.';
    }
    if (empty($data['renewal_period_id'])) {
        $errors[] = 'Yenileme periyodu zorunlu.';
    }
    if (!empty($data['old_entry_mode'])) {
        $startDate = trim((string) ($data['start_date'] ?? ''));
        if ($startDate === '') {
            $errors[] = 'Eski tarihli girişte satın alma / onay tarihi zorunlu.';
        } elseif (strtotime($startDate) === false) {
            $errors[] = 'Eski tarihli girişte tarih geçersiz.';
        } elseif (strtotime($startDate) > strtotime(date('Y-m-d'))) {
            $errors[] = 'Eski tarihli girişte tarih bugünden ileri olamaz.';
        }
    }
    if (!in_array(strtoupper((string) ($data['currency'] ?? 'TRY')), allowed_currency_options(), true)) {
        $errors[] = 'Para birimi geçersiz.';
    }
    if (!empty($data['supplier_price_request_enabled'])) {
        if ((int) ($data['supplier_price_request_days'] ?? 0) < 1) {
            $errors[] = 'Tedarikçi fiyat talebi için gün sayısı en az 1 olmalı.';
        }
        if (empty($data['supplier_id']) && empty($data['supplier_group_id'])) {
            $errors[] = 'Tedarikçi fiyat talebi için tedarikçi veya tedarikçi grubu seçin.';
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
                    <?= Auth::can('reports.view') ? nav_link('/reports/sales', 'Raporlar') : '' ?>
                    <?= Auth::can('notes.view') ? nav_link('/notes', 'Görüşmeler ve Notlar') : '' ?>
                    <?= Auth::can('collections.view') ? nav_link('/collections', 'Tahsilat') : '' ?>
                    <?= Auth::can('collections.view') ? nav_link('/payment-requests', 'Ödeme Talep Et') : '' ?>
                    <?= Auth::can('customers.view') ? nav_link('/customers', 'Müşteriler') : '' ?>
                    <?= Auth::can('suppliers.view') ? nav_link('/suppliers', 'Tedarikçiler') : '' ?>
                    <?= $settingsHref !== null ? nav_link($settingsHref, 'Ayarlar') : '' ?>
                </nav>
                <form method="post" action="<?= h(url('/logout')) ?>" class="logout">
                    <?= csrf_field() ?>
                    <small class="app-version">Sürüm <?= h(app_version_label()) ?></small>
                    <span><?= h($user['name'] ?? '') ?></span>
                    <button type="submit">Çıkış</button>
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
    $reference = report_application_error($e, 'render_error');
    $message = friendly_error_message($e);
    $debug = (bool) app_config('app.debug', false);

    $content = static function () use ($message, $reference, $debug, $e): void {
        ?>
        <section class="public-card payment-result-card error">
            <p class="eyebrow">İşlem tamamlanamadı</p>
            <h1>Bir sorun oluştu.</h1>
            <p><?= h($message) ?></p>
            <div class="payment-result-summary">
                <span>Hata referansı</span>
                <strong><?= h($reference) ?></strong>
                <span>Ne yapabilirsiniz?</span>
                <strong>Sayfayı yenileyip tekrar deneyin. Sorun devam ederse bu referans kodunu bize iletin.</strong>
            </div>
            <?php if ($debug): ?>
                <details class="settings-note">
                    <summary>Teknik detay</summary>
                    <pre><?= h($e->getMessage()) ?></pre>
                </details>
            <?php endif; ?>
        </section>
        <?php
    };

    try {
        if (Auth::check()) {
            render_layout('Hata', $content);
            return;
        }
    } catch (Throwable) {
        // DB kaynaklı hatalarda panel layout'u da çalışmayabilir; public layout ile devam et.
    }

    try {
        render_public_layout('Hata', $content);
    } catch (Throwable) {
        echo '<!doctype html><meta charset="utf-8"><title>Hata</title><div style="font-family:Arial,sans-serif;padding:24px">'
            . '<h1>Bir sorun oluştu.</h1><p>' . h($message) . '</p><p>Hata referansı: ' . h($reference) . '</p></div>';
    }
}

function handle_caught_error(Throwable $e, string $context = ''): string
{
    report_application_error($e, $context !== '' ? $context : 'caught');

    return friendly_error_message($e);
}

function report_application_error(Throwable $e, string $context = ''): string
{
    $reference = application_error_reference($e);
    error_log('[' . $reference . '] ' . (string) $e);

    try {
        $path = route_path();
        $message = friendly_error_message($e);
        $technical = mb_substr($e->getMessage(), 0, 160);
        send_internal_push_notification(
            'Sistem hatası',
            $reference . ' · ' . $message,
            $path !== '' ? $path : '/',
            'app-error-' . $reference
        );
        send_internal_mail_notification(
            'Sistem hatası: ' . $reference,
            application_error_mail_body($e, $reference, $message, $technical, $context)
        );
    } catch (Throwable $notifyError) {
        error_log('[' . $reference . '] Hata bildirimi gönderilemedi: ' . $notifyError->getMessage());
    }

    return $reference;
}

function application_error_reference(Throwable $e): string
{
    return 'ERR-' . strtoupper(substr(hash('sha1', $e::class . '|' . $e->getFile() . '|' . $e->getLine() . '|' . $e->getMessage()), 0, 10));
}

function friendly_error_message(Throwable|string $error): string
{
    $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
    $normalized = mb_strtolower($message, 'UTF-8');

    if (str_contains($normalized, 'sqlstate') || str_contains($normalized, 'pdoexception') || str_contains($normalized, 'database')) {
        if (str_contains($normalized, 'access denied')) {
            return 'Veritabanı kullanıcı adı veya şifresi hatalı görünüyor. Ayarlar > Veritabanı bölümünden bağlantı bilgilerini kontrol edin.';
        }
        if (str_contains($normalized, 'unknown column') || str_contains($normalized, 'base table') || str_contains($normalized, 'table') && str_contains($normalized, 'exist')) {
            return 'Veritabanı yapısı uygulama sürümüyle uyumlu değil. Migration çalıştırılıp tekrar denenmeli.';
        }
        if (str_contains($normalized, 'duplicate')) {
            return 'Aynı kayıt daha önce oluşturulmuş görünüyor. Mevcut kaydı kontrol edip tekrar deneyin.';
        }
        if (str_contains($normalized, 'foreign key')) {
            return 'Bu kayıt başka işlemlerle bağlantılı olduğu için işlem tamamlanamadı. Önce bağlı kayıtları kontrol edin.';
        }

        return 'Veritabanı işlemi tamamlanamadı. Bağlantı ve tablo yapısı kontrol edilmeli.';
    }

    if (str_contains($normalized, 'iyzico')) {
        return payment_failure_public_reason($message, ['errorMessage' => $message]);
    }

    if (str_contains($normalized, 'parasut') || str_contains($normalized, 'paraşüt')) {
        if (str_contains($normalized, 'try again') || str_contains($normalized, 'rate')) {
            return 'Paraşüt geçici yoğunluk nedeniyle isteği bekletiyor. Birkaç saniye sonra tekrar deneyin.';
        }

        return 'Paraşüt entegrasyon işlemi tamamlanamadı. Yetki, firma ID ve bağlantı ayarlarını kontrol edin.';
    }

    if (str_contains($normalized, 'smtp') || str_contains($normalized, 'mail') || str_contains($normalized, 'e-posta')) {
        return 'E-posta gönderimi tamamlanamadı. Mail ayarlarını, kullanıcı şifresini ve alıcı adresini kontrol edin.';
    }

    if (str_contains($normalized, 'curl') || str_contains($normalized, 'timeout') || str_contains($normalized, 'connection') || str_contains($normalized, 'baglant') || str_contains($normalized, 'bağlant')) {
        return 'Dış servis bağlantısı zamanında cevap vermedi. İnternet bağlantısı veya servis tarafı geçici olarak kontrol edilmeli.';
    }

    if (str_contains($normalized, 'csrf') || str_contains($normalized, 'oturum dogrulamasi') || str_contains($normalized, 'oturum doğrulaması')) {
        return 'Oturum doğrulaması süresi doldu. Sayfayı yenileyip işlemi tekrar gönderin.';
    }

    if (str_contains($normalized, 'permission') || str_contains($normalized, 'yetki')) {
        return 'Bu işlem için yetkiniz bulunmuyor. Kullanıcı yetkilerini kontrol edin.';
    }

    if (str_contains($normalized, 'upload') || str_contains($normalized, 'dosya')) {
        return 'Dosya işlemi tamamlanamadı. Dosya türünü, boyutunu ve yükleme izinlerini kontrol edin.';
    }

    $clean = trim(preg_replace('/\s+/', ' ', $message) ?? '');
    if ($clean !== '' && !str_contains($clean, '/') && !str_contains($clean, '\\') && !str_contains(mb_strtolower($clean), 'stack trace')) {
        return mb_substr($clean, 0, 220);
    }

    return 'İşlem tamamlanamadı. Sistem yöneticisine hata referans koduyla birlikte bilgi verildi.';
}

function application_error_mail_body(Throwable $e, string $reference, string $message, string $technical, string $context = ''): string
{
    $rows = [
        'Referans' => $reference,
        'Kullanıcı mesajı' => $message,
        'Teknik mesaj' => $technical,
        'Konum' => $e->getFile() . ':' . $e->getLine(),
        'Sayfa' => (string) ($_SERVER['REQUEST_URI'] ?? route_path()),
        'Bağlam' => $context !== '' ? $context : '-',
        'Tarih' => date('d.m.Y H:i:s'),
    ];

    $htmlRows = '';
    foreach ($rows as $key => $value) {
        $htmlRows .= '<tr><td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-weight:700;">' . h($key) . '</td>'
            . '<td style="padding:10px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-weight:800;">' . h((string) $value) . '</td></tr>';
    }

    return '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6f5;color:#17201c;font-family:Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
        . '<table role="presentation" width="680" cellpadding="0" cellspacing="0" style="max-width:680px;width:100%;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="height:6px;background:#b42318;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:28px;">'
        . '<p style="margin:0 0 8px;color:#b42318;font-size:13px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;">Sistem hatası</p>'
        . '<h1 style="margin:0 0 12px;font-size:30px;line-height:1.1;color:#17201c;">Uygulamada hata yakalandı.</h1>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 22px;">' . $htmlRows . '</table>'
        . '<a href="' . h(url('/settings/security-logs')) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;font-weight:800;padding:13px 18px;border-radius:8px;">Paneli aç</a>'
        . '</td></tr></table></td></tr></table></body></html>';
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
        echo '<div class="empty">Kayıt bulunamadı.</div>';
        return (string) ob_get_clean();
    }
    ?>
    <div class="renewal-list">
        <?php foreach ($rows as $row): ?>
            <?php
            $days = days_until($row['renewal_date']);
            $daysLabel = $days === null ? '-' : ($days < 0 ? abs($days) . ' gün gecikti' : $days . ' gün');
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
                        <small>Müşteri</small>
                        <strong><?= h($row['company_name']) ?></strong>
                    </span>
                    <span class="renewal-title">
                        <small>Ürün / Hizmet</small>
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
                                <span>Müşteri yetkilisi</span>
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
                                <span>Tedarikçi</span>
                                <strong><?= h($row['supplier_display'] ?: '-') ?></strong>
                            </div>
                            <div>
                                <span>Tedarikçi grubu</span>
                                <strong><?= h($row['supplier_group_name'] ?: '-') ?></strong>
                            </div>
                            <?php if (!empty($row['supplier_price_request_enabled'])): ?>
                                <div>
                                    <span>Fiyat talebi</span>
                                    <strong><?= h((string) $row['supplier_price_request_days']) ?> gün kala</strong>
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
    $manualParasutOffer = null;
    if ($canManage) {
        try {
            $manualParasutOffer = (new RenewalRepository())->latestApprovedCustomerOfferWaitingParasut((int) $row['id']);
        } catch (Throwable) {
            $manualParasutOffer = null;
        }
    }

    ob_start();
    ?>
    <div class="renewal-actions">
        <?php if ($canNotify): ?>
            <form method="post" action="<?= h(url('/renewals/' . $row['id'] . '/notify')) ?>" onsubmit="return confirm('Bu yenileme için bilgilendirme maili hemen gönderilsin mi?')">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? route_path()) ?>">
                <button type="submit" class="button small primary">Bildirim gönder</button>
            </form>
        <?php endif; ?>
        <?php if ($canAcknowledge): ?>
            <?php if (!empty($row['reminder_acknowledged_today'])): ?>
                <span class="badge active">Okundu</span>
            <?php else: ?>
                <form method="post" action="<?= h(url('/renewals/' . $row['id'] . '/ack')) ?>" onsubmit="return confirm('Bugünkü bildirim durdurulsun mu?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? route_path()) ?>">
                    <button type="submit" class="button small secondary">Okudum</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <button type="button" class="button small primary" data-dialog-open="renewal-customer-send-<?= h($row['id']) ?>">Müşteriye gönder</button>
            <?php if ($manualParasutOffer): ?>
                <form method="post" action="<?= h(url('/customer-offers/' . (int) $manualParasutOffer['id'] . '/parasut-invoice')) ?>" onsubmit="return confirm('Onaylı müşteri teklifini manuel olarak Paraşüt faturası şeklinde oluşturalım mı?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_to" value="<?= h($_SERVER['REQUEST_URI'] ?? route_path()) ?>">
                    <button type="submit" class="button small primary">Manuel Paraşüt'e gönder</button>
                </form>
            <?php endif; ?>
            <button type="button" class="button small secondary" data-dialog-open="manual-price-<?= h($row['id']) ?>">Manuel fiyat ver</button>
            <button type="button" class="button small supplier-price" data-dialog-open="supplier-price-<?= h($row['id']) ?>">Tedarikçiden fiyat al</button>
            <a href="<?= h(url('/renewals/' . $row['id'] . '/edit')) ?>" class="button small">Düzenle</a>
            <button type="button" class="button small primary" data-dialog-open="renewal-decision-approved-<?= h($row['id']) ?>">Onaylandı</button>
            <button type="button" class="button small danger" data-dialog-open="renewal-decision-rejected-<?= h($row['id']) ?>">Reddedildi</button>
            <button type="button" class="button small secondary" data-dialog-open="renewal-decision-postponed-<?= h($row['id']) ?>">Ertelendi</button>
            <button type="button" class="button small secondary" data-dialog-open="renewal-decision-revision-<?= h($row['id']) ?>">Revize istendi</button>
            <?= render_renewal_communication_dialogs($row) ?>
            <?= render_manual_price_dialog($row) ?>
            <?= render_supplier_price_request_dialog($row) ?>
            <?= render_renewal_decision_dialogs($row) ?>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <form method="post" action="<?= h(url('/renewals/' . $row['id'] . '/delete')) ?>" onsubmit="return confirm('Bu kayıt silinsin mi?')">
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
    <dialog class="app-dialog communication-dialog customer-send-dialog" id="renewal-customer-send-<?= h($id) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <h2>Müşteriye gönder</h2>
                    <span><?= h($row['company_name'] ?? '-') ?> için mail veya WhatsApp ile kişi bazlı takip edilen PDF/özet bağlantısı gönderin.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <div class="customer-send-layout">
                <form method="post" action="<?= h(url('/renewals/' . $id . '/mail')) ?>" class="form-grid customer-send-mail-form">
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
                    <div class="inline-actions">
                        <a class="button secondary" target="_blank" rel="noopener" href="<?= h($summaryUrl) ?>">PDF / özet sayfasını aç</a>
                        <button type="submit" class="button primary">Müşteriye mail gönder</button>
                    </div>
                </form>

                <div class="customer-whatsapp-panel">
                    <div class="section-head compact">
                        <div>
                            <h3>WhatsApp ile gönder</h3>
                            <span class="muted compact">Her yetkili için ayrı takip linki üretilir; kaç kez ve ne zaman açıldığı kartta görünür.</span>
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
                        $contactKey = renewal_contact_tracking_key($contact);
                        $whatsappLink = url('/renewals/' . $id . '/whatsapp') . '?' . http_build_query([
                            'contact' => $contactKey,
                            'return_to' => $returnTo,
                        ]);
                        ?>
                        <div class="whatsapp-contact-card">
                            <div>
                                <strong><?= h((string) (($contact['full_name'] ?? '') ?: $phone)) ?></strong>
                                <span><?= h($phone) ?><?= !empty($contact['email']) ? ' - ' . h((string) $contact['email']) : '' ?></span>
                            </div>
                            <a class="button small whatsapp" target="takip_whatsapp_web" href="<?= h($whatsappLink) ?>">WhatsApp aç</a>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$hasWhatsappRecipient): ?>
                        <div class="empty">Bu cari için WhatsApp'a uygun telefon numarası bulunamadı.</div>
                    <?php endif; ?>
                    </div>
                </div>
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
                        <input type="email" name="custom_supplier_email" placeholder="tedarikçi@firma.com">
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
                            <input type="email" name="custom_supplier_email" placeholder="tedarikçi@firma.com">
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
                                <?php $linkMeta = array_values(array_filter([(string) ($link['number'] ?? ''), (string) ($link['created_at'] ?? '')], static fn (string $value): bool => trim($value) !== '')); ?>
                                <div class="supplier-link-card">
                                    <div>
                                        <strong><?= h((string) ($link['label'] ?? 'Tedarikçi')) ?></strong>
                                        <span><?= h(implode(' · ', $linkMeta)) ?></span>
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
                                    <a class="button small whatsapp" target="takip_whatsapp_web" href="<?= h($whatsappLink) ?>">WhatsApp aç</a>
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

function render_manual_price_dialog(array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    if ($id < 1) {
        return '';
    }

    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());
    $currency = normalize_allowed_currency($row['currency'] ?? 'TRY');
    $items = [];
    try {
        $items = (new RenewalRepository())->renewalItems($id);
    } catch (Throwable) {
        $items = [];
    }

    if ($items === []) {
        $items = [[
            'id' => 0,
            'title' => (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Ürün / hizmet')),
            'brand' => (string) ($row['brand'] ?? ''),
            'quantity' => 1,
            'unit_price' => $row['amount'] ?? '',
        ]];
    }

    ob_start();
    ?>
    <dialog class="app-dialog communication-dialog manual-price-dialog" id="manual-price-<?= h((string) $id) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <h2>Manuel fiyat ver</h2>
                    <span>Tedarikçi teklifini beklemeden kalemlere satış fiyatı girip müşteri teklif ekranını hazırlayın.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <form method="post" action="<?= h(url('/renewals/' . $id . '/manual-price')) ?>" class="form-grid manual-price-form">
                <?= csrf_field() ?>
                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                <label class="span-2">
                    Para birimi
                    <select name="manual_price_currency">
                        <?php foreach (allowed_currency_options() as $currencyOption): ?>
                            <?= option($currencyOption, $currencyOption, $currency) ?>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="manual-price-lines span-2">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemId = (int) ($item['id'] ?? 0);
                        $quantity = max(1.0, (float) ($item['quantity'] ?? 1));
                        $unitPrice = ($item['unit_price'] ?? null) === null ? '' : (string) $item['unit_price'];
                        ?>
                        <div class="manual-price-line">
                            <div>
                                <strong><?= h((string) (($item['title'] ?? '') ?: 'Ürün / hizmet')) ?></strong>
                                <span>
                                    <?= h(number_format($quantity, 2, ',', '.')) ?> adet
                                    <?php if (!empty($item['brand'])): ?>
                                        · <?= h((string) $item['brand']) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <label>
                                Birim satış fiyatı
                                <input type="number" min="0" step="0.01" name="manual_prices[<?= h((string) $itemId) ?>][unit_price]" value="<?= h($unitPrice) ?>" placeholder="Örn: 1200.00">
                            </label>
                            <input type="hidden" name="manual_prices[<?= h((string) $itemId) ?>][renewal_item_id]" value="<?= h((string) $itemId) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <p class="muted compact span-2">Boş bıraktığınız kalemler değişmez. Kaydettikten sonra kart içindeki “Müşteriye teklif gönder” butonu aktif olur; teklif ekranında fiyatı, KDV oranını ve mesajı tekrar düzenleyebilirsiniz.</p>
                <button type="submit" class="button primary span-2">Manuel fiyatı kaydet</button>
            </form>
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

function supplier_quote_lines_have_prices(array $lines): bool
{
    foreach ($lines as $line) {
        foreach (['price_cash', 'price_30', 'price_60', 'price_check', 'price_custom'] as $field) {
            if (($line[$field] ?? null) !== null && (string) $line[$field] !== '') {
                return true;
            }
        }
    }

    return false;
}

function render_customer_offer_dialog(array $row, array $selectedQuotes, bool $hasSelectablePrices = false): string
{
    $id = (int) ($row['id'] ?? 0);
    if ($id < 1 || ($selectedQuotes === [] && !$hasSelectablePrices)) {
        return '';
    }

    $returnTo = (string) ($_SERVER['REQUEST_URI'] ?? route_path());
    $contacts = renewal_customer_contacts($row);
    $currency = normalize_allowed_currency($row['currency'] ?? 'TRY');
    $subject = 'Yenileme teklifiniz: ' . (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'Ürün / hizmet'));
    $message = 'Seçilen fiyatlar üzerinden yenileme teklifinizi hazırladık. Lütfen fiyatları inceleyip onay, revize veya red tercihinizi iletin.';

    ob_start();
    ?>
    <dialog class="app-dialog communication-dialog customer-offer-dialog" id="customer-offer-<?= h((string) $id) ?>">
        <div class="app-dialog-body">
            <div class="section-head dialog-head">
                <div>
                    <h2>Müşteriye teklif gönder</h2>
                    <span>Seçili fiyatlardan müşteriye onay/revize/red bağlantılı teklif hazırlayın.</span>
                </div>
                <button type="button" class="button small secondary" data-dialog-close>Kapat</button>
            </div>

            <?php if ($selectedQuotes === []): ?>
                <div class="settings-note">
                    <strong>Önce tedarikçi fiyatı seçin.</strong>
                    <p>Tedarikçiden fiyat gelmiş görünüyor; müşteriye teklif gönderebilmek için aşağıdaki fiyat kartlarından uygun vade/fiyat satırındaki <b>Onayla</b> butonuna basın. Seçim yapıldıktan sonra bu pencere alıcı ve teklif satırlarıyla açılır.</p>
                </div>
            <?php else: ?>
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
                    <input type="email" name="custom_customer_offer_email" placeholder="müşteri@firma.com">
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
            <?php endif; ?>
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

    $returnTo = safe_return_path($_SERVER['REQUEST_URI'] ?? route_path());
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
                $offerMeta = array_values(array_filter([(string) ($offer['offer_number'] ?? ''), (string) ($offer['recipient_email'] ?? '-'), $createdAt], static fn (string $value): bool => trim($value) !== ''));
                ?>
                <details class="customer-offer-history-card">
                    <summary>
                        <span>
                            <strong><?= h((string) (($offer['recipient_name'] ?? '') ?: ($offer['recipient_email'] ?? 'Müşteri'))) ?></strong>
                            <em><?= h(implode(' · ', $offerMeta)) ?></em>
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
                    <?php
                    $parasutInvoiceId = trim((string) ($offer['parasut_invoice_id'] ?? ''));
                    $parasutInvoiceNo = trim((string) ($offer['parasut_invoice_no'] ?? ''));
                    $parasutStatus = trim((string) ($offer['parasut_invoice_status'] ?? ''));
                    ?>
                    <?php if ($status === 'approved' || $parasutStatus !== ''): ?>
                        <div class="settings-note compact">
                            <strong>Paraşüt faturası</strong>
                            <?php if ($parasutInvoiceId !== ''): ?>
                                <span><?= h($parasutInvoiceNo !== '' ? $parasutInvoiceNo : '#' . $parasutInvoiceId) ?> oluşturuldu<?= !empty($offer['parasut_invoice_created_at']) ? ' · ' . h(date('d.m.Y H:i', strtotime((string) $offer['parasut_invoice_created_at']))) : '' ?></span>
                            <?php elseif ($parasutStatus === 'failed'): ?>
                                <span>Oluşturulamadı: <?= h((string) ($offer['parasut_invoice_error'] ?? 'Bilinmeyen hata')) ?></span>
                            <?php else: ?>
                                <span>Henüz oluşturulmadı. Manuel Paraşüt'e gönder ile oluşturabilirsiniz.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="customer-offer-history-actions">
                        <?php if ($status === 'approved' && $parasutInvoiceId === ''): ?>
                            <form method="post" action="<?= h(url('/customer-offers/' . (int) $offer['id'] . '/parasut-invoice')) ?>" onsubmit="return confirm('Bu onaylı teklifi manuel olarak Paraşüt faturası şeklinde oluşturalım mı?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                                <button type="submit" class="button primary small">Manuel Paraşüt'e gönder</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= h(url('/customer-offers/' . (int) $offer['id'] . '/delete')) ?>" onsubmit="return confirm('Bu müşteri teklif geçmişi silinsin mi? Teklif linki geçersiz olur, müşteriye bilgi maili gönderilmez.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                            <button type="submit" class="button danger small">Geçmişi sil</button>
                        </form>
                    </div>
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

function render_supplier_quote_request_manager(array $requests, string $returnTo): string
{
    if ($requests === []) {
        return '';
    }

    ob_start();
    ?>
    <div class="supplier-quote-request-manager">
        <div class="section-head compact">
            <div>
                <h3>Tedarikçi talep listesi</h3>
                <span>İstediğiniz tedarikçi talebini sessizce silebilirsiniz; tedarikçiye bilgi gitmez.</span>
            </div>
        </div>
        <div class="supplier-quote-request-list">
            <?php foreach ($requests as $request): ?>
                <?php
                $requestId = (int) ($request['id'] ?? 0);
                $status = (string) ($request['status'] ?? 'pending');
                $createdAt = !empty($request['created_at']) ? date('d.m.Y H:i', strtotime((string) $request['created_at'])) : '-';
                $requestMeta = array_values(array_filter([(string) ($request['quote_number'] ?? ''), (string) (($request['recipient_email'] ?? '') ?: ($request['recipient_phone'] ?? '-')), $createdAt], static fn (string $value): bool => trim($value) !== ''));
                ?>
                <div class="supplier-quote-request-row">
                    <div>
                        <strong><?= h((string) (($request['supplier_display'] ?? '') ?: ($request['recipient_email'] ?? 'Tedarikçi'))) ?></strong>
                        <span><?= h(implode(' · ', $requestMeta)) ?></span>
                    </div>
                    <span class="badge <?= h(supplier_quote_status_badge($status)) ?>"><?= h(supplier_quote_status_label($status)) ?></span>
                    <?php if ($requestId > 0): ?>
                        <form method="post" action="<?= h(url('/supplier-quotes/' . $requestId . '/delete')) ?>" onsubmit="return confirm('Bu tedarikçinin teklif talebi ve fiyatları sessizce silinsin mi? Tedarikçiye bilgi gönderilmeyecek.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
                            <button type="submit" class="button small danger">Sil</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

function supplier_quote_status_label(string $status): string
{
    return match ($status) {
        'opened' => 'Açıldı',
        'submitted' => 'Teklif verdi',
        'expired' => 'Kapatıldı',
        default => 'Bekliyor',
    };
}

function supplier_quote_status_badge(string $status): string
{
    return match ($status) {
        'submitted' => 'active',
        'opened' => 'urgency-warning',
        'expired' => 'cancelled',
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
    $hasSelectablePrices = supplier_quote_lines_have_prices($lines);

    if ($requests === [] && $lines === [] && $selectedQuotes === [] && $customerOffers === []) {
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
                <h3>Fiyat teklifleri</h3>
                <span>
                    <?php if ($requests !== []): ?>
                        <?= h((string) $submitted) ?> / <?= h((string) count($requests)) ?> tedarikçi teklif verdi. Her kalemde ayrı tedarikçi seçebilirsiniz.
                    <?php else: ?>
                        Manuel fiyatla müşteri teklif ekranı hazırlanabilir.
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($selectedQuotes !== [] || $hasSelectablePrices): ?>
                <button type="button" class="button small <?= $selectedQuotes !== [] ? 'primary' : 'secondary' ?>" data-dialog-open="customer-offer-<?= h((string) $renewalId) ?>">Müşteriye teklif gönder</button>
            <?php endif; ?>
        </div>
        <?php if ($selectedQuotes !== [] || $hasSelectablePrices): ?>
            <?php if ($selectedQuotes === []): ?>
                <div class="supplier-selected-manual-list">
                    <span><b>Tedarikçi fiyatı geldi.</b> Müşteriye teklif göndermek için aşağıdaki uygun fiyat/vade satırında <b>Onayla</b> seçin.</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($selectedQuotes !== []): ?>
            <div class="supplier-selected-manual-list">
                <?php foreach ($selectedQuotes as $selection): ?>
                    <span>
                        <b><?= h((string) (($selection['item_title'] ?? '') ?: 'Ürün / hizmet')) ?></b>
                        <?= h(supplier_quote_term_label((string) ($selection['selected_term'] ?? ''), (string) ($selection['custom_term'] ?? ''))) ?>
                        · <?= h(money_format_local($selection['selected_price'] ?? null, (string) ($selection['selected_currency'] ?? 'TRY'))) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?= render_customer_offer_dialog($row, $selectedQuotes, $hasSelectablePrices) ?>
        <?= render_supplier_quote_request_manager($requests, $returnTo) ?>
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
                            <div class="supplier-selected-summary">
                                <em>
                                    Onaylanan: <?= h((string) ($selected['supplier_display'] ?? '-')) ?>
                                    / <?= h(supplier_quote_term_label((string) $selected['selected_term'])) ?>
                                    / <?= h(money_format_local($selected['selected_price'], (string) $selected['currency'])) ?>
                                </em>
                                <?= render_supplier_selection_read_badge($selected) ?>
                            </div>
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

function render_supplier_selection_read_badge(array $selection): string
{
    $sent = (int) ($selection['selection_delivery_count'] ?? 0);
    $read = (int) ($selection['selection_read_count'] ?? 0);
    $reader = trim((string) ($selection['selection_reader'] ?? ''));
    $readAt = trim((string) ($selection['selection_read_at'] ?? ''));

    if ($sent < 1) {
        return '<span class="badge cancelled">Mail bekliyor</span>';
    }

    if ($read > 0) {
        $label = 'Okundu';
        if ($reader !== '') {
            $label .= ': ' . $reader;
        }
        if ($readAt !== '') {
            $label .= ' - ' . date('d.m.Y H:i', strtotime($readAt));
        }

        return '<span class="badge active">' . h($label) . '</span>';
    }

    return '<span class="badge pending">Okunmadı</span>';
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
                    <button type="submit" class="button small <?= $isSelected ? 'primary' : 'secondary' ?>" <?= $isSelected ? 'disabled' : '' ?>><?= $isSelected ? 'Onaylandı' : 'Onayla' ?></button>
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

function renewal_contact_tracking_key(array $contact): string
{
    $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
    $phone = preg_replace('/\D+/', '', (string) ($contact['phone'] ?? '')) ?: '';
    $name = trim(mb_strtolower((string) ($contact['full_name'] ?? $contact['contact_name'] ?? '')));

    return hash('sha256', $email . '|' . $phone . '|' . $name);
}

function renewal_contact_by_tracking_key(array $row, string $key): ?array
{
    if ($key === '') {
        return null;
    }

    foreach (renewal_customer_contacts($row) as $contact) {
        if (hash_equals(renewal_contact_tracking_key($contact), $key)) {
            return $contact;
        }
    }

    return null;
}

function renewal_tracking_recipient_from_contact(array $contact, string $fallbackName = 'Cari yetkilisi'): array
{
    $name = trim((string) ($contact['full_name'] ?? $contact['contact_name'] ?? ''));
    $email = trim(mb_strtolower((string) ($contact['email'] ?? '')));
    $phone = trim((string) ($contact['phone'] ?? ''));

    return [
        'name' => $name !== '' ? $name : $fallbackName,
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        'phone' => $phone,
    ];
}

function renewal_default_direct_message(array $row, string $summaryEmail = '', string $summaryUrl = ''): string
{
    $days = days_until($row['renewal_date'] ?? null);
    $daysLabel = $days === null ? '-' : ($days < 0 ? abs($days) . ' gün geçti' : $days . ' gün kaldı');
    $title = (string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? 'yenileme kaydı'));
    $date = !empty($row['renewal_date']) ? date('d.m.Y', strtotime((string) $row['renewal_date'])) : '-';
    if ($summaryUrl === '' && !empty($row['id'])) {
        $summaryUrl = renewal_summary_url((int) $row['id'], 60, $summaryEmail);
    }
    $summaryLink = $summaryUrl !== '' ? "\nPDF / özet bağlantısı: " . $summaryUrl : '';

    return "Merhaba,\n\n{$title} için yenileme süreci yaklaşmaktadır.\nYenileme tarihi: {$date}\nKalan süre: {$daysLabel}\nDetaylı teklif ve fiyat bilgilerini güvenli bağlantıdan inceleyebilirsiniz.{$summaryLink}\n\nBilginize sunarız.";
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

function whatsapp_web_url(string $number, string $message): string
{
    return 'https://web.whatsapp.com/send?phone=' . rawurlencode($number) . '&text=' . rawurlencode($message);
}

function renewal_whatsapp_message(array $row, array $contact, string $summaryUrl = ''): string
{
    $email = (string) ($contact['email'] ?? '');
    $message = renewal_default_direct_message($row, $email, $summaryUrl);
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
                <textarea name="payment_terms" rows="3" required placeholder="Örn: Peşin / Havale EFT / Özel vade"><?= h($paymentDefault) ?></textarea>
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
        'manual' => 'Manuel fiyat',
        'custom' => $customTerm !== '' ? $customTerm : 'Özel vade',
        default => $term,
    };
}

function state_label(string $state): string
{
    return match ($state) {
        'overdue' => 'Geciken',
        'due_soon' => 'Yaklaşan',
        'active' => 'Aktif',
        'renewed' => 'Yenilendi',
        'cancelled' => 'İptal',
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
        return 'Bugün';
    }

    if ($days <= 7) {
        return 'Acil';
    }

    if ($days <= 30) {
        return 'Yaklasiyor';
    }

    if ($days <= 60) {
        return '60 gün içinde';
    }

    return 'Aktif';
}

function renewal_notification_read_summary(array $row): array
{
    $sent = max(0, (int) ($row['notification_sent_count'] ?? 0));
    $recipients = max(0, (int) ($row['notification_recipient_count'] ?? 0));
    $total = max($sent, $recipients);
    $read = min(max(0, (int) ($row['notification_read_count'] ?? 0)), $total > 0 ? $total : PHP_INT_MAX);
    $views = max(0, (int) ($row['notification_view_count'] ?? 0));

    if ($total < 1 || $sent < 1) {
        return [
            'label' => '-',
            'readers' => '',
            'complete' => false,
        ];
    }

    return [
        'label' => $views > 0
            ? sprintf('%d/%d yetkili okudu · %d görüntüleme', $read, $total, $views)
            : sprintf('%d/%d yetkili okudu', $read, $total),
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
        [$name, $count, $date] = array_pad(explode(' - ', $item, 3), 3, '');
        $html .= '<div class="reader-chip">'
            . '<strong>' . h(trim($name) ?: $item) . '</strong>';
        $meta = trim(implode(' · ', array_filter([trim($count), trim($date)])));
        if ($meta !== '') {
            $html .= '<small>' . h($meta) . '</small>';
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
        if ($ruleDay > 7 && $days === $ruleDay) {
            return true;
        }
    }

    return $days === (int) ($row['reminder_days'] ?? 0);
}

function collection_filter_options(): array
{
    return [
        'all' => 'Tümü',
        'unpaid' => 'Ödenmemiş',
        'bank' => 'Havale / EFT',
        'choice' => 'Seçim bekleyen',
        'paid-card' => 'Kart ödemeleri',
    ];
}

function collection_filter_key(string $filter): string
{
    return array_key_exists($filter, collection_filter_options()) ? $filter : 'all';
}

function collection_stats(array $rows): array
{
    $stats = [
        'total' => count($rows),
        'unpaid' => 0,
        'bank' => 0,
    ];

    foreach ($rows as $row) {
        $method = mb_strtolower(trim((string) ($row['payment_method'] ?? '')));
        if (empty($row['has_paid_card_payment'])) {
            $stats['unpaid']++;
        }
        if (str_contains($method, 'havale') || str_contains($method, 'eft')) {
            $stats['bank']++;
        }
    }

    return $stats;
}

function collection_payment_state(array $row): array
{
    $paymentMethod = trim((string) ($row['payment_method'] ?? ''));
    $method = mb_strtolower($paymentMethod);

    if (!empty($row['has_paid_card_payment'])) {
        return ['label' => 'Tahsil edildi', 'tone' => 'active'];
    }

    if (!empty($row['payment_customer_choice']) || $paymentMethod === '') {
        return ['label' => 'Ödeme seçimi bekleniyor', 'tone' => 'warning'];
    }

    if (payment_method_is_credit_card($paymentMethod)) {
        return ['label' => 'Kart ödemesi bekleniyor', 'tone' => 'warning'];
    }

    if (str_contains($method, 'havale') || str_contains($method, 'eft')) {
        return ['label' => 'Havale / dekont bekleniyor', 'tone' => 'warning'];
    }

    return ['label' => 'Tahsilat bekliyor', 'tone' => 'warning'];
}

function renewal_payment_label(array $row): string
{
    if (!empty($row['payment_customer_choice'])) {
        return 'Müşteri seçimine bırakıldı';
    }

    $paymentMethod = trim((string) ($row['payment_method'] ?? ''));
    if (payment_method_is_removed_30_day($paymentMethod)) {
        return '-';
    }

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

    if (Auth::can('flows.view')) {
        return '/settings/flows';
    }

    if (Auth::can('logs.view')) {
        return '/settings/mail-logs';
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
        'reports.view' => '/reports/sales',
        'notes.view' => '/notes',
        'flows.view' => '/settings/flows',
        'collections.view' => '/collections',
        'renewals.view' => '/renewals',
        'customers.view' => '/customers',
        'suppliers.view' => '/suppliers',
        'logs.view' => '/settings/mail-logs',
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
        'day' => 'Gün',
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
