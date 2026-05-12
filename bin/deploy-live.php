<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$ftpHost = env_value('DEPLOY_FTP_HOST', 'takip.bigabilisim.com');
$ftpUser = env_value('DEPLOY_FTP_USER');
$ftpPass = env_value('DEPLOY_FTP_PASS');
$remoteRoot = env_value('DEPLOY_REMOTE_ROOT', '/public_html/takip');
$liveVersionUrl = env_value('DEPLOY_LIVE_VERSION_URL', 'https://takip.bigabilisim.com/VERSION');
$githubVersionUrl = env_value('DEPLOY_GITHUB_VERSION_URL', 'https://raw.githubusercontent.com/bigabilisim/takip-teklif/main/VERSION');
$githubRawBaseUrl = env_value('DEPLOY_GITHUB_RAW_BASE_URL', 'https://raw.githubusercontent.com/bigabilisim/takip-teklif/main');
$requireGithubMatch = env_bool('DEPLOY_REQUIRE_GITHUB_MATCH', true);

if ($ftpUser === '' || $ftpPass === '') {
    fail('DEPLOY_FTP_USER ve DEPLOY_FTP_PASS ortam degiskenleri zorunlu.');
}

if (!extension_loaded('ftp')) {
    fail('PHP ftp eklentisi aktif degil.');
}

$localVersion = read_version($root . '/VERSION');
$liveVersion = fetch_version($liveVersionUrl);
$githubVersion = fetch_version($githubVersionUrl);

if ($liveVersion !== '' && version_compare($localVersion, $liveVersion, '<')) {
    fail(sprintf(
        'Canli surum (%s), lokal surumden (%s) yeni. Eski kodun canliya basmasi engellendi.',
        $liveVersion,
        $localVersion
    ));
}

if ($requireGithubMatch && $githubVersion !== '' && $localVersion !== $githubVersion) {
    fail(sprintf(
        'Lokal surum (%s) GitHub main surumuyle (%s) ayni degil. Once GitHub ve lokal esitleyin.',
        $localVersion,
        $githubVersion
    ));
}

if ($requireGithubMatch) {
    assert_github_file_match($root, $githubRawBaseUrl, [
        'VERSION',
        '.htaccess',
        'app/Models/RenewalRepository.php',
        'app/Models/PaymentRequestRepository.php',
        'public/index.php',
        'public/assets/app.css',
        'public/assets/app.js',
    ]);
}

echo sprintf(
    "Deploy kontrolu tamam. Lokal: %s | Canli: %s | GitHub: %s\n",
    $localVersion,
    $liveVersion !== '' ? $liveVersion : 'okunamadi',
    $githubVersion !== '' ? $githubVersion : 'okunamadi'
);

$conn = ftp_connect($ftpHost, 21, 30);
if (!$conn) {
    fail('FTP baglantisi kurulamadi.');
}

if (!ftp_login($conn, $ftpUser, $ftpPass)) {
    ftp_close($conn);
    fail('FTP girisi basarisiz.');
}

ftp_pasv($conn, true);

try {
    foreach (['app', 'bin', 'database', 'public'] as $dir) {
        sync_directory($conn, $root, $dir, $remoteRoot);
    }

    foreach (['.htaccess', 'CHANGELOG.md', 'README.md', 'VERSION'] as $file) {
        upload_file($conn, $root . '/' . $file, $remoteRoot . '/' . $file);
    }

    upload_file($conn, $root . '/config/config.example.php', $remoteRoot . '/config/config.example.php');
} finally {
    ftp_close($conn);
}

echo "Deploy tamamlandi.\n";

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);

    return $value === false ? $default : trim((string) $value);
}

function env_bool(string $key, bool $default): bool
{
    $value = getenv($key);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function read_version(string $path): string
{
    if (!is_file($path)) {
        fail('VERSION dosyasi bulunamadi.');
    }

    $version = trim((string) file_get_contents($path));
    if ($version === '') {
        fail('VERSION dosyasi bos.');
    }

    return $version;
}

function fetch_version(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => "User-Agent: takip-teklif-deploy\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return '';
    }

    return trim($body);
}

function assert_github_file_match(string $root, string $githubRawBaseUrl, array $paths): void
{
    foreach ($paths as $path) {
        $localPath = $root . '/' . $path;
        if (!is_file($localPath)) {
            fail('GitHub esitleme kontrolu icin lokal dosya bulunamadi: ' . $path);
        }

        $remoteContent = fetch_text(rtrim($githubRawBaseUrl, '/') . '/' . str_replace('%2F', '/', rawurlencode($path)));
        if ($remoteContent === null) {
            fail('GitHub dosyasi okunamadi: ' . $path);
        }

        if (sha1_file($localPath) !== sha1($remoteContent)) {
            fail('Lokal dosya GitHub main ile ayni degil. Once GitHub/lokal esitleyin: ' . $path);
        }
    }
}

function fetch_text(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "User-Agent: takip-teklif-deploy\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    return $body;
}

function sync_directory($conn, string $root, string $relativeDir, string $remoteRoot): void
{
    $localDir = $root . '/' . $relativeDir;
    if (!is_dir($localDir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($localDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $path => $item) {
        if (!$item->isFile()) {
            continue;
        }

        $relativePath = trim(str_replace($root . '/', '', (string) $path), '/');
        if (should_skip($relativePath)) {
            continue;
        }

        upload_file($conn, (string) $path, $remoteRoot . '/' . $relativePath);
    }
}

function should_skip(string $relativePath): bool
{
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

    return in_array($relativePath, [
        'config/config.php',
        'config/config.test.php',
    ], true);
}

function upload_file($conn, string $localFile, string $remoteFile): void
{
    if (!is_file($localFile)) {
        return;
    }

    ftp_mkdir_recursive($conn, dirname($remoteFile));

    if (!ftp_put($conn, normalize_remote_path($remoteFile), $localFile, FTP_BINARY)) {
        fail('FTP yukleme basarisiz: ' . $localFile);
    }

    echo 'Yuklendi: ' . normalize_remote_path($remoteFile) . "\n";
}

function ftp_mkdir_recursive($conn, string $remotePath): void
{
    $remotePath = normalize_remote_path($remotePath);
    if ($remotePath === '/') {
        return;
    }

    if (@ftp_chdir($conn, $remotePath)) {
        ftp_chdir($conn, '/');
        return;
    }

    $current = '';
    foreach (array_values(array_filter(explode('/', $remotePath), 'strlen')) as $part) {
        $current .= '/' . $part;
        if (@ftp_chdir($conn, $current)) {
            continue;
        }

        if (!ftp_mkdir($conn, $current) && !@ftp_chdir($conn, $current)) {
            fail('Uzak klasor olusturulamadi: ' . $current);
        }
    }

    ftp_chdir($conn, '/');
}

function normalize_remote_path(string $path): string
{
    return '/' . trim(str_replace('\\', '/', $path), '/');
}

function fail(string $message): never
{
    fwrite(STDERR, "Hata: {$message}\n");
    exit(1);
}
