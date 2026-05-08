<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\SettingsRepository;
use RuntimeException;

final class Mailer
{
    public static function send(string $to, string $subject, string $body, bool $isHtml = false, array $inlineAttachments = [], array $attachments = []): bool
    {
        return self::sendWithResult($to, $subject, $body, $isHtml, $inlineAttachments, $attachments)['ok'];
    }

    public static function sendWithResult(string $to, string $subject, string $body, bool $isHtml = false, array $inlineAttachments = [], array $attachments = []): array
    {
        $preparedBody = $body;
        $preparedIsHtml = $isHtml;

        try {
            $settings = self::settings();
            $driver = $settings['mail.driver'] ?? 'log';
            $branded = MailTemplate::prepareBrandedMail($settings, $body, $isHtml, $inlineAttachments);
            $body = (string) $branded['body'];
            $isHtml = (bool) $branded['is_html'];
            $preparedBody = $body;
            $preparedIsHtml = $isHtml;
            $inlineAttachments = is_array($branded['inline_attachments'] ?? null) ? $branded['inline_attachments'] : [];
            $inlineAttachments = $isHtml ? self::prepareInlineAttachments($inlineAttachments) : [];
            $attachments = self::prepareAttachments($attachments);

            if ($driver === 'smtp') {
                self::sendSmtp($settings, $to, $subject, $body, $isHtml, $inlineAttachments, $attachments);
            } elseif ($driver === 'microsoft365') {
                self::sendMicrosoft365($settings, $to, $subject, $body, $isHtml, $inlineAttachments, $attachments);
            } elseif ($driver === 'mail') {
                self::sendPhpMail($settings, $to, $subject, $body, $isHtml, $inlineAttachments, $attachments);
            } else {
                self::sendLog($settings, $to, $subject, $body, $isHtml, $inlineAttachments, $attachments);
            }

            return ['ok' => true, 'error' => null, 'body' => $preparedBody, 'is_html' => $preparedIsHtml];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'body' => $preparedBody, 'is_html' => $preparedIsHtml];
        }
    }

    public static function sendWithAttachments(string $to, string $subject, string $body, array $attachments, bool $isHtml = false): array
    {
        return self::sendWithResult($to, $subject, $body, $isHtml, [], $attachments);
    }

    private static function sendPhpMail(array $settings, string $to, string $subject, string $body, bool $isHtml, array $inlineAttachments, array $attachments): void
    {
        [$headers, $message] = self::mailHeadersAndBody($settings, $body, $isHtml, $inlineAttachments, $attachments);

        if (!mail($to, $subject, $message, implode("\r\n", $headers))) {
            throw new RuntimeException('PHP mail() gönderimi başarısız.');
        }
    }

    private static function sendLog(array $settings, string $to, string $subject, string $body, bool $isHtml, array $inlineAttachments, array $attachments): void
    {
        $config = \app_config('mail');
        $path = $config['log_path'] ?? ROOT_PATH . '/storage/logs/mail.log';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $entry = sprintf(
            "[%s]\nTO: %s\nSUBJECT: %s\nTYPE: %s\nINLINE: %d\nATTACHMENTS: %d\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $isHtml ? 'HTML' : 'TEXT',
            count($inlineAttachments),
            count($attachments),
            $body
        );

        if (file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Mail log dosyasına yazılamadı.');
        }
    }

    private static function sendSmtp(array $settings, string $to, string $subject, string $body, bool $isHtml, array $inlineAttachments, array $attachments): void
    {
        $host = trim((string) ($settings['smtp.host'] ?? ''));
        $port = (int) ($settings['smtp.port'] ?? 587);
        $encryption = (string) ($settings['smtp.encryption'] ?? 'tls');
        $timeout = max(5, (int) ($settings['smtp.timeout'] ?? 20));

        if ($host === '') {
            throw new RuntimeException('SMTP host boş olamaz.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP bağlantısı kurulamadı: ' . $errstr);
        }

        stream_set_timeout($socket, $timeout);

        try {
            self::smtpExpect($socket, [220]);
            self::smtpCommand($socket, 'EHLO ' . self::hostname(), [250]);

            if ($encryption === 'tls') {
                self::smtpCommand($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP TLS başlatılamadı.');
                }
                self::smtpCommand($socket, 'EHLO ' . self::hostname(), [250]);
            }

            $username = (string) ($settings['smtp.username'] ?? '');
            $password = (string) ($settings['smtp.password'] ?? '');
            if ($username !== '') {
                self::smtpCommand($socket, 'AUTH LOGIN', [334]);
                self::smtpCommand($socket, base64_encode($username), [334]);
                self::smtpCommand($socket, base64_encode($password), [235]);
            }

            $from = self::fromEmail($settings);
            self::smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::smtpCommand($socket, 'DATA', [354]);
            fwrite($socket, self::mimeMessage($settings, $to, $subject, $body, $isHtml, $inlineAttachments, $attachments) . "\r\n.\r\n");
            self::smtpExpect($socket, [250]);
            self::smtpCommand($socket, 'QUIT', [221, 250]);
        } finally {
            fclose($socket);
        }
    }

    private static function sendMicrosoft365(array $settings, string $to, string $subject, string $body, bool $isHtml, array $inlineAttachments, array $attachments): void
    {
        $accessToken = self::microsoftAccessToken($settings);
        $fromUser = trim((string) ($settings['m365.from_user'] ?? ''));
        $endpoint = $fromUser !== ''
            ? 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($fromUser) . '/sendMail'
            : 'https://graph.microsoft.com/v1.0/me/sendMail';

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => $isHtml ? 'HTML' : 'Text',
                    'content' => $body,
                ],
                'toRecipients' => [[
                    'emailAddress' => ['address' => $to],
                ]],
            ],
            'saveToSentItems' => true,
        ];

        foreach ($inlineAttachments as $attachment) {
            $payload['message']['attachments'][] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment['name'],
                'contentType' => $attachment['content_type'],
                'contentBytes' => base64_encode((string) file_get_contents($attachment['path'])),
                'isInline' => true,
                'contentId' => $attachment['cid'],
            ];
        }

        foreach ($attachments as $attachment) {
            $payload['message']['attachments'][] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment['name'],
                'contentType' => $attachment['content_type'],
                'contentBytes' => base64_encode((string) file_get_contents($attachment['path'])),
                'isInline' => false,
            ];
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new RuntimeException('Microsoft Graph bağlantısı başarısız: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode((string) $response, true);
            $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException('Microsoft 365 mail gönderimi başarısız: ' . $message);
        }
    }

    private static function microsoftAccessToken(array $settings): string
    {
        $repo = new SettingsRepository();
        $token = $repo->microsoftToken();

        if (!empty($token['access_token']) && (int) ($token['expires_at'] ?? 0) > time() + 90) {
            return (string) $token['access_token'];
        }

        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Microsoft 365 bağlantısı yapılmamış.');
        }

        $fresh = self::microsoftTokenRequest($settings, [
            'client_id' => $settings['m365.client_id'] ?? '',
            'client_secret' => $settings['m365.client_secret'] ?? '',
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'scope' => self::microsoftScope(),
        ]);
        $repo->setMicrosoftToken($fresh);

        return (string) ($fresh['access_token'] ?? '');
    }

    public static function microsoftTokenRequest(array $settings, array $fields): array
    {
        $tenant = trim((string) ($settings['m365.tenant_id'] ?? 'common')) ?: 'common';
        $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new RuntimeException('Microsoft token bağlantısı başarısız: ' . $error);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Microsoft token yanıtı okunamadı.');
        }

        if ($status >= 400) {
            $message = $decoded['error_description'] ?? $decoded['error'] ?? ('HTTP ' . $status);
            throw new RuntimeException('Microsoft token hatasi: ' . $message);
        }

        return $decoded;
    }

    public static function microsoftScope(): string
    {
        return 'offline_access User.Read Mail.Send';
    }

    private static function smtpCommand($socket, string $command, array $expected): string
    {
        fwrite($socket, $command . "\r\n");

        return self::smtpExpect($socket, $expected);
    }

    private static function smtpExpect($socket, array $expected): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP hata yanıtı: ' . trim($response));
        }

        return $response;
    }

    private static function mimeMessage(array $settings, string $to, string $subject, string $body, bool $isHtml, array $inlineAttachments = [], array $attachments = []): string
    {
        [$contentHeaders, $messageBody] = self::mailHeadersAndBody($settings, $body, $isHtml, $inlineAttachments, $attachments);
        $contentHeaders = array_values(array_filter(
            $contentHeaders,
            static fn (string $header): bool => !str_starts_with($header, 'MIME-Version:') && !str_starts_with($header, 'From:')
        ));
        $headers = array_merge([
            'MIME-Version: 1.0',
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::fromHeader($settings),
            'To: <' . $to . '>',
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
        ], $contentHeaders);

        return implode("\r\n", $headers) . "\r\n\r\n" . self::dotStuff($messageBody);
    }

    private static function mailHeadersAndBody(array $settings, string $body, bool $isHtml, array $inlineAttachments, array $attachments = []): array
    {
        if ($attachments !== []) {
            $boundary = 'mix_' . bin2hex(random_bytes(12));
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
                'From: ' . self::fromHeader($settings),
            ];

            $parts = ['--' . $boundary];
            if ($isHtml && $inlineAttachments !== []) {
                $relatedBoundary = 'rel_' . bin2hex(random_bytes(12));
                $parts[] = 'Content-Type: multipart/related; type="text/html"; boundary="' . $relatedBoundary . '"';
                $parts[] = '';
                $parts = array_merge($parts, self::relatedParts($body, $inlineAttachments, $relatedBoundary));
            } else {
                $parts[] = 'Content-Type: ' . ($isHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8';
                $parts[] = 'Content-Transfer-Encoding: base64';
                $parts[] = '';
                $parts[] = chunk_split(base64_encode($body), 76, "\r\n");
            }

            foreach ($attachments as $attachment) {
                $content = file_get_contents($attachment['path']);
                if ($content === false) {
                    throw new RuntimeException('Mail eki okunamadı: ' . $attachment['name']);
                }

                $parts[] = '--' . $boundary;
                $parts[] = 'Content-Type: ' . $attachment['content_type'] . '; name="' . self::headerSafe($attachment['name']) . '"';
                $parts[] = 'Content-Transfer-Encoding: base64';
                $parts[] = 'Content-Disposition: attachment; filename="' . self::headerSafe($attachment['name']) . '"';
                $parts[] = '';
                $parts[] = chunk_split(base64_encode($content), 76, "\r\n");
            }

            $parts[] = '--' . $boundary . '--';

            return [$headers, implode("\r\n", $parts)];
        }

        if ($isHtml && $inlineAttachments !== []) {
            $boundary = 'rel_' . bin2hex(random_bytes(12));
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: multipart/related; type="text/html"; boundary="' . $boundary . '"',
                'From: ' . self::fromHeader($settings),
            ];

            return [$headers, implode("\r\n", self::relatedParts($body, $inlineAttachments, $boundary))];
        }

        return [[
            'MIME-Version: 1.0',
            'Content-Type: ' . ($isHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . self::fromHeader($settings),
        ], $body];
    }

    private static function relatedParts(string $body, array $inlineAttachments, string $boundary): array
    {
        $parts = [
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($body), 76, "\r\n"),
        ];

        foreach ($inlineAttachments as $attachment) {
            $content = file_get_contents($attachment['path']);
            if ($content === false) {
                throw new RuntimeException('Gömülü mail görseli okunamadı: ' . $attachment['name']);
            }

            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . $attachment['content_type'] . '; name="' . self::headerSafe($attachment['name']) . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-ID: <' . $attachment['cid'] . '>';
            $parts[] = 'Content-Disposition: inline; filename="' . self::headerSafe($attachment['name']) . '"';
            $parts[] = '';
            $parts[] = chunk_split(base64_encode($content), 76, "\r\n");
        }

        $parts[] = '--' . $boundary . '--';

        return $parts;
    }

    private static function prepareInlineAttachments(array $inlineAttachments): array
    {
        $prepared = [];
        foreach ($inlineAttachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $path = (string) ($attachment['path'] ?? '');
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $cid = preg_replace('/[^A-Za-z0-9_.@-]/', '', (string) ($attachment['cid'] ?? ''));
            if ($cid === '') {
                $cid = 'inline_' . substr(hash('sha256', $path), 0, 16);
            }

            $prepared[] = [
                'cid' => $cid,
                'path' => $path,
                'name' => self::headerSafe((string) ($attachment['name'] ?? basename($path))),
                'content_type' => self::headerSafe((string) ($attachment['content_type'] ?? 'application/octet-stream')),
            ];
        }

        return $prepared;
    }

    private static function prepareAttachments(array $attachments): array
    {
        $prepared = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $path = (string) ($attachment['path'] ?? '');
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $prepared[] = [
                'path' => $path,
                'name' => self::headerSafe((string) ($attachment['name'] ?? basename($path))),
                'content_type' => self::headerSafe((string) ($attachment['content_type'] ?? self::guessContentType($path))),
            ];
        }

        return $prepared;
    }

    private static function guessContentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'zip' => 'application/zip',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'sql' => 'application/sql',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private static function headerSafe(string $value): string
    {
        $value = str_replace(["\r", "\n", '"'], ['', '', ''], $value);

        return $value !== '' ? $value : 'attachment';
    }

    private static function dotStuff(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
        }
        unset($line);

        return implode("\r\n", $lines);
    }

    private static function fromHeader(array $settings): string
    {
        $name = trim((string) ($settings['mail.from_name'] ?? 'Yenileme Takip'));
        $email = self::fromEmail($settings);

        return mb_encode_mimeheader($name, 'UTF-8') . ' <' . $email . '>';
    }

    private static function fromEmail(array $settings): string
    {
        $email = trim((string) ($settings['mail.from_email'] ?? ''));
        if ($email === '') {
            throw new RuntimeException('Gönderen e-posta adresi boş olamaz.');
        }

        return $email;
    }

    private static function hostname(): string
    {
        return gethostname() ?: 'localhost';
    }

    private static function settings(): array
    {
        try {
            return (new SettingsRepository())->all();
        } catch (\Throwable) {
            $config = \app_config('mail');

            return [
                'mail.driver' => $config['transport'] ?? 'log',
                'mail.from_email' => $config['from_email'] ?? 'noreply@example.com',
                'mail.from_name' => $config['from_name'] ?? 'Yenileme Takip',
            ];
        }
    }
}
