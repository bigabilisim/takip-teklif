<?php

declare(strict_types=1);

namespace App\Core;

final class TaxCertificateAnalyzer
{
    public static function analyze(string $path): array
    {
        $items = self::pdfTextItems($path);
        $text = self::itemsToText($items);
        $address = self::boxText($items, 215, 490, 370, 405);
        [$district, $city] = self::cityDistrictFromAddress($address);

        $result = [
            'contact_name' => self::boxText($items, 215, 490, 455, 480),
            'company_name' => self::boxText($items, 215, 490, 415, 440),
            'address' => $address,
            'tax_office' => self::boxText($items, 565, 735, 455, 480),
            'tax_number' => self::digitsOnly(self::boxText($items, 565, 735, 380, 400)),
            'city' => $city,
            'district' => $district,
            'activity' => self::boxText($items, 215, 490, 300, 322),
            'start_date' => self::boxText($items, 565, 735, 340, 362),
            'raw_text' => $text,
        ];

        if ($result['company_name'] === '') {
            $result['company_name'] = $result['contact_name'];
        }

        if ($result['tax_number'] === '' && preg_match('/\b\d{10,11}\b/u', $text, $matches) === 1) {
            $result['tax_number'] = $matches[0];
        }

        return array_map(static fn (string $value): string => trim($value), $result);
    }

    public static function saveUploaded(array $file, string $prefix): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        self::assertValidUpload($file);
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'pdf';
        }

        $dir = ROOT_PATH . '/storage/customer-info-requests';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $filename = preg_replace('/[^a-z0-9_-]+/i', '-', $prefix) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $target = $dir . '/' . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new \RuntimeException('Vergi levhasi dosyasi kaydedilemedi.');
        }

        return 'storage/customer-info-requests/' . $filename;
    }

    public static function assertValidUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Vergi levhasi yuklenemedi.');
        }

        if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new \RuntimeException('Vergi levhasi en fazla 8 MB olabilir.');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'png', 'jpg', 'jpeg'], true)) {
            throw new \RuntimeException('Vergi levhasi PDF, PNG veya JPG olmalidir.');
        }
    }

    private static function pdfTextItems(string $path): array
    {
        if (!self::isPdfFile($path)) {
            return [];
        }

        $pdf = (string) file_get_contents($path);
        preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams, PREG_SET_ORDER);
        $items = [];

        foreach ($streams as $stream) {
            $dictionary = $stream[1];
            $content = $stream[2];
            if (str_contains($dictionary, '/FlateDecode')) {
                $decoded = @gzuncompress($content);
                if ($decoded === false) {
                    $decoded = @gzinflate(substr($content, 2));
                }
                if ($decoded === false) {
                    continue;
                }
                $content = $decoded;
            }

            $items = array_merge($items, self::textItemsFromContent($content));
        }

        usort($items, static fn (array $left, array $right): int => [$right['y'], $left['x']] <=> [$left['y'], $right['x']]);

        return $items;
    }

    private static function isPdfFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $header = (string) file_get_contents($path, false, null, 0, 1024);
        if (str_contains($header, '%PDF')) {
            return true;
        }

        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }

    private static function textItemsFromContent(string $content): array
    {
        preg_match_all('/1\s+0\s+0\s+1\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+Tm|\(((?:\\\\.|[^\\\\()])*)\)\s*Tj/s', $content, $matches, PREG_SET_ORDER);
        $items = [];
        $x = 0.0;
        $y = 0.0;

        foreach ($matches as $match) {
            if (($match[1] ?? '') !== '') {
                $x = (float) $match[1];
                $y = (float) $match[2];
                continue;
            }

            $text = self::decodePdfLiteral((string) ($match[3] ?? ''));
            if (trim($text) === '') {
                continue;
            }

            $items[] = ['x' => $x, 'y' => $y, 'text' => trim($text)];
        }

        return $items;
    }

    private static function decodePdfLiteral(string $value): string
    {
        $output = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char !== '\\') {
                $output .= $char;
                continue;
            }

            $i++;
            if ($i >= $length) {
                break;
            }

            $next = $value[$i];
            $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\b", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
            if (isset($map[$next])) {
                $output .= $map[$next];
                continue;
            }

            if ($next >= '0' && $next <= '7') {
                $octal = $next;
                for ($j = 0; $j < 2 && $i + 1 < $length && $value[$i + 1] >= '0' && $value[$i + 1] <= '7'; $j++) {
                    $octal .= $value[++$i];
                }
                $output .= chr(octdec($octal));
            }
        }

        if (str_starts_with($output, "\xfe\xff")) {
            return mb_convert_encoding(substr($output, 2), 'UTF-8', 'UTF-16BE');
        }

        return mb_convert_encoding($output, 'UTF-8', 'Windows-1254');
    }

    private static function boxText(array $items, float $minX, float $maxX, float $minY, float $maxY): string
    {
        $parts = [];
        foreach ($items as $item) {
            if ($item['x'] >= $minX && $item['x'] <= $maxX && $item['y'] >= $minY && $item['y'] <= $maxY) {
                $parts[] = $item['text'];
            }
        }

        return self::cleanText(implode(' ', $parts));
    }

    private static function itemsToText(array $items): string
    {
        return self::cleanText(implode("\n", array_map(static fn (array $item): string => (string) $item['text'], $items)));
    }

    private static function cleanText(string $value): string
    {
        return trim((string) preg_replace('/[ \t]+/u', ' ', $value));
    }

    private static function digitsOnly(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    private static function cityDistrictFromAddress(string $address): array
    {
        $address = trim($address);
        if (preg_match('/([A-ZÇĞİÖŞÜa-zçğıöşü\s]+)\s*\/\s*([A-ZÇĞİÖŞÜa-zçğıöşü\s]+)\s*$/u', $address, $matches) !== 1) {
            return ['', ''];
        }

        return [self::titleCase($matches[1]), self::titleCase($matches[2])];
    }

    private static function titleCase(string $value): string
    {
        return mb_convert_case(trim($value), MB_CASE_TITLE, 'UTF-8');
    }
}
