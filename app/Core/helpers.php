<?php

declare(strict_types=1);

function app_config(?string $key = null, mixed $default = null): mixed
{
    global $appConfig;

    if ($key === null) {
        return $appConfig;
    }

    $value = $appConfig;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function app_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    $configured = trim((string) app_config('app.version', ''));
    if ($configured !== '') {
        $version = ltrim($configured, 'vV');

        return $version;
    }

    $path = ROOT_PATH . '/VERSION';
    if (is_file($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $version = ltrim($line, 'vV');

                return $version;
            }
        }
    }

    $version = '0.0.0';

    return $version;
}

function app_version_label(): string
{
    return 'v' . app_version();
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function route_path(): string
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    if (!is_string($requestUri) || $requestUri === '') {
        $requestUri = '/';
    }

    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    return '/' . trim($path, '/');
}

function url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    $base = current_request_origin() ?: rtrim((string) app_config('app.url', ''), '/');
    $path = '/' . ltrim($path, '/');

    return $base === '' ? $path : $base . $path;
}

function current_request_origin(): ?string
{
    $host = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host) !== 1) {
        return null;
    }

    $httpsSignals = [
        (string) ($_SERVER['HTTPS'] ?? ''),
        (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''),
        (string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''),
        (string) ($_SERVER['HTTP_FRONT_END_HTTPS'] ?? ''),
        (string) ($_SERVER['REQUEST_SCHEME'] ?? ''),
    ];

    $https = in_array('on', array_map('strtolower', $httpsSignals), true)
        || in_array('https', array_map('strtolower', $httpsSignals), true)
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';

    return ($https ? 'https' : 'http') . '://' . $host;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $posted = $_POST['_token'] ?? '';
    $session = $_SESSION['_csrf_token'] ?? '';

    if (!is_string($posted) || !is_string($session) || !hash_equals($session, $posted)) {
        http_response_code(419);
        echo 'Oturum dogrulamasi basarisiz. Sayfayi yenileyip tekrar deneyin.';
        exit;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return $messages;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function money_format_local(mixed $amount, string $currency = 'TRY'): string
{
    if ($amount === null || $amount === '') {
        return '-';
    }

    return number_format((float) $amount, 2, ',', '.') . ' ' . $currency;
}

function normalize_phone_number(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }

    if (str_starts_with($digits, '0090') && strlen($digits) >= 14) {
        $digits = substr($digits, 4);
    } elseif (str_starts_with($digits, '90') && strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) === 10) {
        $digits = '0' . $digits;
    }

    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        return substr($digits, 0, 4)
            . ' ' . substr($digits, 4, 3)
            . ' ' . substr($digits, 7, 2)
            . ' ' . substr($digits, 9, 2);
    }

    return $raw;
}

function days_until(?string $date): ?int
{
    if (!$date) {
        return null;
    }

    $today = new DateTimeImmutable('today');
    $target = new DateTimeImmutable($date);

    return (int) $today->diff($target)->format('%r%a');
}

function permission_catalog(): array
{
    return [
        'Panel' => [
            'dashboard.view' => 'Dashboard goruntule',
            'dashboard.details' => 'Dashboard detaylarini ac/kapat',
            'reports.view' => 'Satış raporlarını görüntüle',
            'flows.view' => 'Akış şemalarını görüntüle',
        ],
        'Yenilemeler' => [
            'renewals.view' => 'Yenileme listesini goruntule',
            'renewals.details' => 'Yenileme detaylarini ac/kapat',
            'renewals.manage' => 'Yenileme ekle ve duzenle',
            'renewals.delete' => 'Yenileme sil',
        ],
        'Tahsilat' => [
            'collections.view' => 'Tahsilat listesini goruntule',
            'collections.manage' => 'Tahsilat maili gonder',
        ],
        'Görüşmeler ve Notlar' => [
            'notes.view' => 'Görüşme ve notları görüntüle',
            'notes.manage' => 'Görüşme notu ekle ve düzenle',
            'notes.delete' => 'Görüşme notu sil',
        ],
        'Musteriler' => [
            'customers.view' => 'Musterileri goruntule',
            'customers.details' => 'Musteri cari detaylarini goruntule',
            'customers.manage' => 'Musteri ekle ve duzenle',
            'customers.delete' => 'Musteri sil ve geri al',
        ],
        'Tedarikciler' => [
            'suppliers.view' => 'Tedarikcileri goruntule',
            'suppliers.details' => 'Tedarikci cari detaylarini goruntule',
            'suppliers.manage' => 'Tedarikci ekle ve duzenle',
            'suppliers.delete' => 'Tedarikci sil ve geri al',
        ],
        'Ayarlar' => [
            'settings.manage' => 'Sistem, veritabani, mail, logo ve Parasut ayarlari',
            'definitions.manage' => 'Tanimlamalari yonet',
            'users.manage' => 'Kullanici ve yetki yonetimi',
            'logs.view' => 'Mail ve güvenlik loglarını görüntüle',
        ],
    ];
}

function permission_keys(): array
{
    $keys = [];
    foreach (permission_catalog() as $permissions) {
        $keys = array_merge($keys, array_keys($permissions));
    }

    return $keys;
}
