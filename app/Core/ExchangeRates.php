<?php

declare(strict_types=1);

namespace App\Core;

final class ExchangeRates
{
    private const SOURCE_URL = 'https://www.tcmb.gov.tr/kurlar/today.xml';
    private const CACHE_TTL = 3600;

    public static function latest(): array
    {
        $cachePath = self::cachePath();
        $cached = self::readCache($cachePath);

        if ($cached !== null && (int) ($cached['fetched_at'] ?? 0) > time() - self::CACHE_TTL) {
            return $cached + ['ok' => true, 'stale' => false];
        }

        try {
            $fresh = self::fetch();
            self::writeCache($cachePath, $fresh);

            return $fresh + ['ok' => true, 'stale' => false];
        } catch (\Throwable) {
            if ($cached !== null) {
                return $cached + ['ok' => true, 'stale' => true];
            }
        }

        return [
            'ok' => false,
            'stale' => false,
            'source' => 'TCMB',
            'date' => '',
            'fetched_at' => time(),
            'rates' => [],
        ];
    }

    private static function fetch(): array
    {
        $xml = self::downloadXml();
        $document = @simplexml_load_string($xml);
        if (!$document instanceof \SimpleXMLElement) {
            throw new \RuntimeException('TCMB kur verisi okunamadi.');
        }

        $rates = [];
        foreach ($document->Currency as $currency) {
            $code = (string) ($currency['CurrencyCode'] ?? '');
            if (!in_array($code, ['USD', 'EUR'], true)) {
                continue;
            }

            $rates[$code] = [
                'code' => $code,
                'name' => $code === 'USD' ? 'Dolar' : 'Euro',
                'buying' => self::decimal((string) $currency->ForexBuying),
                'selling' => self::decimal((string) $currency->ForexSelling),
            ];
        }

        if (!isset($rates['USD'], $rates['EUR'])) {
            throw new \RuntimeException('TCMB dolar/euro verisi bulunamadi.');
        }

        return [
            'ok' => true,
            'stale' => false,
            'source' => 'TCMB',
            'date' => (string) ($document['Tarih'] ?? $document['Date'] ?? ''),
            'fetched_at' => time(),
            'rates' => $rates,
        ];
    }

    private static function downloadXml(): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init(self::SOURCE_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'YenilemeTakibi/1.0',
            ]);

            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if (is_string($body) && $body !== '' && $status < 400 && $error === '') {
                return $body;
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'header' => "User-Agent: YenilemeTakibi/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents(self::SOURCE_URL, false, $context);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('TCMB kur servisine baglanilamadi.');
        }

        return $body;
    }

    private static function decimal(string $value): ?float
    {
        $value = trim(str_replace(',', '.', $value));

        return $value === '' ? null : (float) $value;
    }

    private static function cachePath(): string
    {
        return ROOT_PATH . '/storage/exchange_rates.json';
    }

    private static function readCache(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private static function writeCache(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
