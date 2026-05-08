<?php

declare(strict_types=1);

namespace App\Core;

final class MailTemplate
{
    private const LAYOUT_GUARD_START = '/* renewal-template-layout-guard:start */';
    private const LAYOUT_GUARD_END = '/* renewal-template-layout-guard:end */';
    private const TEMPLATE_TEXT_REPLACEMENTS = [
        'kaydı için yenileme süreci yaklasiyor.' => 'kaydı için yenileme süreci yaklaşıyor.',
        'KALAN SURE' => 'KALAN SÜRE',
        'Kalan süre' => 'Kalan süre',
        'kalan süre' => 'kalan süre',
        'YENILEME TARIHI' => 'YENİLEME TARİHİ',
        'Urun / hizmet' => 'Ürün / hizmet',
        'URUN / HIZMET' => 'ÜRÜN / HİZMET',
        'Lisans / referans' => 'Bir Önceki Fatura Numarası',
        'Bir önceki fatura no' => 'Bir Önceki Fatura Numarası',
        'Bir onceki fatura no' => 'Bir Önceki Fatura Numarası',
        'Ödeme sekli' => 'Ödeme şekli',
        'ODEME SEKLI' => 'ÖDEME ŞEKLİ',
        'Urun ve Hizmet' => 'Ürün ve Hizmet',
        'TEDARIKÇI FIYAT TALEBI' => 'TEDARİKÇİ FİYAT TALEBİ',
        'TEDARIKCI FIYAT TALEBI' => 'TEDARİKÇİ FİYAT TALEBİ',
        'FIYAT TALEBI' => 'FİYAT TALEBİ',
        '{{license_key}}' => '{{previous_invoice_number}}',
        'Tedarikçi' => 'Tedarikçi',
        'TEDARIKCI' => 'TEDARİKÇİ',
        'otomatik oluşturuldu' => 'otomatik oluşturuldu',
    ];

    public static function defaultHtml(): string
    {
        return <<<'HTML'
<div class="mail-document">
  <table class="mail-shell" role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center">
        <table class="mail-card" role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td class="accent-pink" width="33%" height="6" style="height:6px;font-size:0;line-height:0;background:#ed1678;">&nbsp;</td>
            <td class="accent-blue" width="34%" height="6" style="height:6px;font-size:0;line-height:0;background:#0068ad;">&nbsp;</td>
            <td class="accent-green" width="33%" height="6" style="height:6px;font-size:0;line-height:0;background:#35aa47;">&nbsp;</td>
          </tr>
          <tr>
            <td colspan="3" class="card-inner">
              <table class="brand-row" role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td class="brand-copy" valign="middle">
                    <span class="eyebrow">Yenileme bildirimi</span>
                    <strong>{{app_name}}</strong>
                  </td>
                  <td class="brand-logo-cell" valign="middle" align="right">
                    <img class="brand-logo" data-template-logo="1" src="{{logo_url}}" alt="{{app_name}}">
                  </td>
                </tr>
              </table>

              <table class="hero-card" role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <h1>{{customer_name}}</h1>
                    <p class="lead"><strong>{{title}}</strong> kaydı için yenileme süreci yaklaşıyor.</p>
                  </td>
                </tr>
              </table>

              <table class="metric-row" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;margin:18px 0;border-collapse:separate;border-spacing:0;table-layout:fixed;">
                <tr>
                  <td class="metric metric-remaining {{urgency_class}}" width="49%" bgcolor="{{urgency_bg}}" style="width:49% !important;padding:16px;background:{{urgency_bg}};border:1px solid {{urgency_border}};border-radius:8px;vertical-align:top;">
                    <span style="display:block;color:{{urgency_text}};font-size:11px;font-weight:700;text-transform:uppercase;line-height:1.2;">Kalan süre</span>
                    <strong style="display:block;margin-top:7px;color:{{urgency_text}};font-size:26px;line-height:1.08;word-break:break-word;">{{remaining_days}}</strong>
                  </td>
                  <td class="metric-gap" width="2%" style="width:2%;font-size:0;line-height:0;">&nbsp;</td>
                  <td class="metric metric-date" width="49%" bgcolor="#eef6fb" style="width:49% !important;padding:16px;background:#eef6fb;border:1px solid #d5e7ef;border-radius:8px;vertical-align:top;">
                    <span style="display:block;color:#66756f;font-size:11px;font-weight:700;text-transform:uppercase;line-height:1.2;">Yenileme tarihi</span>
                    <strong style="display:block;margin-top:7px;color:#0f625b;font-size:26px;line-height:1.08;word-break:break-word;">{{renewal_date}}</strong>
                  </td>
                </tr>
              </table>

              <table class="message-box" role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <p>Merhaba {{contact_name}},</p>
                  </td>
                </tr>
              </table>

              <table class="info-table" role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr><td>Ürün / hizmet</td><td>{{title}}</td></tr>
                <tr><td>Marka</td><td>{{brand}}</td></tr>
                <tr><td>Bir Önceki Fatura Numarası</td><td>{{previous_invoice_number}}</td></tr>
                <tr><td>Ödeme şekli</td><td>{{payment_method}}</td></tr>
                <tr><td>KDV dahil toplam fiyat</td><td>{{total_amount}}</td></tr>
              </table>

              {{items_table}}

              {{read_ack_action}}

              <table class="definition-info" role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <strong>Bilgilendirme</strong>
                    <p>{{definition_notification_info}}</p>
                  </td>
                </tr>
              </table>

              <table class="notes" role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <strong>Notlar</strong>
                    <p>{{notes}}</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td class="footer" align="center">
        <strong>{{app_name}}</strong>
        <span>Bu bildirim {{today}} tarihinde otomatik oluşturuldu.</span>
      </td>
    </tr>
    </table>
</div>
HTML;
    }

    public static function defaultCss(): string
    {
        return <<<'CSS'
.mail-document {
  margin: 0 auto;
  padding: 24px;
  width: 100%;
  max-width: 740px;
  background: #f2f6f4;
  font-family: Arial, sans-serif;
  color: #17201c;
  color-scheme: light only;
  box-sizing: border-box;
}
.mail-document * {
  box-sizing: border-box;
}
.mail-shell {
  width: 100%;
  border-collapse: collapse;
}
.mail-card {
  width: 100%;
  max-width: 680px;
  margin: 0 auto;
  background: #ffffff;
  border: 1px solid #d9e3df;
  border-radius: 8px;
  overflow: hidden;
  border-collapse: separate;
  border-spacing: 0;
  box-shadow: 0 12px 28px rgba(18, 37, 34, 0.08);
}
.card-inner {
  padding: 28px;
}
.brand-row {
  width: 100%;
  margin: 0 0 20px;
  border-collapse: collapse;
}
.brand-copy {
  width: 58%;
}
.brand-copy strong {
  display: block;
  margin-top: 4px;
  color: #17201c;
  font-size: 13px;
  line-height: 1.3;
}
.brand-logo-cell {
  width: 42%;
  text-align: right;
}
.brand-logo {
  display: inline-block;
  width: auto;
  max-width: 170px;
  max-height: 62px;
  height: auto;
  object-fit: contain;
}
.eyebrow {
  display: inline-block;
  margin: 0;
  color: #147c72;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0;
}
.hero-card {
  width: 100%;
  margin: 0 0 18px;
  background: #f7faf8;
  border: 1px solid #dfe8e4;
  border-radius: 8px;
  border-collapse: separate;
  border-spacing: 0;
}
.hero-card td {
  padding: 22px;
}
h1 {
  margin: 0 0 12px;
  color: #17201c;
  font-size: 28px;
  line-height: 1.16;
  overflow-wrap: anywhere;
  word-break: break-word;
}
p {
  margin: 0 0 12px;
  line-height: 1.55;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.lead {
  margin: 0;
  color: #31413c;
  font-size: 16px;
}
.metric-row {
  width: 100%;
  margin: 18px 0;
  border-collapse: separate;
  border-spacing: 0;
  table-layout: fixed;
}
.metric {
  width: 49%;
  padding: 16px;
  border-radius: 8px;
  background: #e8f7f4;
  border: 1px solid #c8ece5;
  vertical-align: top;
}
.metric-gap {
  width: 2%;
  font-size: 0;
  line-height: 0;
}
.metric-date {
  background: #eef6fb;
  border-color: #d5e7ef;
}
.metric span {
  display: block;
  color: #66756f;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  line-height: 1.2;
}
.metric strong {
  display: block;
  margin-top: 7px;
  color: #0f625b;
  font-size: 26px;
  line-height: 1.08;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.metric-remaining {
  background: {{urgency_bg}};
  border: 1px solid {{urgency_border}};
}
.metric-remaining span,
.metric-remaining strong {
  color: {{urgency_text}};
}
.urgency-safe {
  background: #e8f7f4;
  border-color: #c8ece5;
}
.urgency-watch {
  background: #fff7db;
  border-color: #f5d36b;
}
.urgency-warning {
  background: #ffe8cc;
  border-color: #f59e0b;
}
.urgency-critical {
  background: #ffd7d7;
  border-color: #ef4444;
}
.urgency-overdue {
  background: #7f0000;
  border-color: #4c0000;
}
.urgency-overdue span,
.urgency-overdue strong {
  color: #ffffff;
}
.message-box {
  width: 100%;
  margin: 8px 0 16px;
  background: #ffffff;
  border-collapse: collapse;
}
.message-box td {
  padding: 0;
}
.info-table {
  width: 100%;
  border-collapse: collapse;
  margin: 14px 0 18px;
  table-layout: fixed;
}
.info-table td {
  padding: 12px 0;
  border-bottom: 1px solid #d8e0dd;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.info-table td:first-child {
  width: 44%;
  color: #607069;
  font-weight: 700;
}
.items-table {
  width: 100%;
  margin: 0 0 18px;
  border: 1px solid #d8e0dd;
  border-collapse: collapse;
  table-layout: fixed;
}
.items-table th,
.items-table td {
  padding: 10px;
  border-bottom: 1px solid #d8e0dd;
  text-align: left;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.items-table th {
  color: #607069;
  font-size: 11px;
  text-transform: uppercase;
}
.payment-action {
  width: 100%;
  margin: 0 0 18px;
  background: #101b18;
  border: 1px solid #1ec6aa;
  border-radius: 8px;
  border-collapse: separate;
  border-spacing: 0;
}
.payment-action td {
  padding: 20px;
}
.payment-action strong {
  display: block;
  color: #ffffff;
  margin-bottom: 8px;
  font-size: 18px;
}
.payment-action p {
  margin: 0;
  color: #d9f5ef;
}
.payment-button {
  display: block;
  margin-top: 16px;
  padding: 18px 22px;
  border-radius: 10px;
  background: #1ec6aa;
  color: #ffffff !important;
  text-decoration: none;
  text-align: center;
  font-size: 18px;
  font-weight: 800;
}
.read-ack-action {
  width: 100%;
  margin: -4px 0 18px;
  border-collapse: separate;
  border-spacing: 0;
}
.read-ack-action td {
  padding: 12px 14px;
  background: #f7fbfa;
  border: 1px solid #d8e0dd;
  border-radius: 8px;
  color: #607069;
  font-size: 13px;
  text-align: center;
}
.read-ack-button {
  display: inline-block;
  padding: 8px 13px;
  border: 1px solid #147c72;
  border-radius: 8px;
  color: #147c72 !important;
  font-size: 13px;
  font-weight: 800;
  text-decoration: none;
}
.read-ack-info {
  display: inline-block;
  width: 24px;
  height: 24px;
  margin-left: 8px;
  border-radius: 999px;
  background: #e8f7f4;
  color: #147c72;
  font-size: 13px;
  font-weight: 900;
  line-height: 24px;
  text-align: center;
  vertical-align: middle;
}
.read-ack-help {
  display: block;
  margin-top: 8px;
  color: #607069;
  font-size: 12px;
  line-height: 1.35;
}
.notes {
  width: 100%;
  background: #f4f7f5;
  border: 1px solid #e0e8e4;
  border-radius: 8px;
  border-collapse: separate;
  border-spacing: 0;
}
.notes td {
  padding: 16px;
}
.notes p {
  margin-top: 8px;
  margin-bottom: 0;
}
.definition-info {
  width: 100%;
  margin: 0 0 18px;
  background: #eef7f5;
  border: 1px solid #cae5df;
  border-radius: 8px;
  border-collapse: separate;
  border-spacing: 0;
}
.definition-info td {
  padding: 16px;
}
.definition-info strong {
  display: block;
  color: #0f625b;
  margin-bottom: 8px;
}
.definition-info p {
  margin: 0;
  color: #31413c;
}
.footer {
  width: 100%;
  max-width: 680px;
  padding: 14px 0 0;
  color: #607069;
  font-size: 12px;
  text-align: center;
}
.footer strong,
.footer span {
  display: block;
}
@media screen and (max-width: 620px) {
  .mail-document {
    padding: 14px !important;
  }
  .brand-logo {
    max-width: 130px !important;
    max-height: 52px !important;
  }
  .card-inner {
    padding: 18px !important;
  }
  .hero-card td {
    padding: 16px !important;
  }
  h1 {
    font-size: 21px !important;
  }
  p,
  .lead {
    font-size: 14px !important;
  }
  .metric-row {
    width: 100% !important;
    border-spacing: 0 !important;
    table-layout: fixed !important;
  }
  td.metric,
  .metric-date,
  .metric-remaining {
    width: 49% !important;
    padding: 10px !important;
  }
  .metric-gap {
    width: 2% !important;
    font-size: 0 !important;
    line-height: 0 !important;
  }
  .metric span {
    font-size: 10px !important;
    line-height: 1.2 !important;
  }
  .metric strong {
    font-size: 18px !important;
    line-height: 1.1 !important;
  }
  .info-table td {
    font-size: 13px !important;
  }
}
CSS;
    }

    public static function normalizeTemplateHtml(string $html): string
    {
        $html = str_replace(array_keys(self::TEMPLATE_TEXT_REPLACEMENTS), array_values(self::TEMPLATE_TEXT_REPLACEMENTS), $html);
        $html = preg_replace(
            '#\s*<tr>\s*<td>\s*Geçen yıl fatura no\s*</td>\s*<td>\s*\{\{previous_invoice_number\}\}\s*</td>\s*</tr>#iu',
            '',
            $html
        ) ?? $html;

        if (self::isLegacyDefaultTemplate($html)) {
            return self::defaultHtml();
        }

        $html = self::normalizeRemainingMetric($html);
        $html = self::normalizeMetricRow($html);
        $html = self::removeDisabledTemplateFields($html);
        $html = self::removePaymentTemplateFields($html);
        $html = self::ensurePaymentMethodRow($html);
        $html = self::ensureTotalAmountRow($html);
        $html = self::ensureReadAckActionBlock($html);
        $html = self::ensureDefinitionInfoBlock($html);

        return preg_replace_callback(
            '#<img\b(?=[^>]*\{\{logo_url\}\})[^>]*>#i',
            static fn (array $matches): string => self::normalizeLogoTag($matches[0]),
            $html
        ) ?? $html;
    }

    public static function normalizeTemplateProjectJson(string $json): string
    {
        return str_replace(array_keys(self::TEMPLATE_TEXT_REPLACEMENTS), array_values(self::TEMPLATE_TEXT_REPLACEMENTS), $json);
    }

    public static function enforceLayoutCss(string $css): string
    {
        $css = str_replace(array_keys(self::TEMPLATE_TEXT_REPLACEMENTS), array_values(self::TEMPLATE_TEXT_REPLACEMENTS), $css);
        if (self::isLegacyDefaultCss($css)) {
            $css = self::defaultCss();
        }

        $css = preg_replace(
            '#\s*/\* renewal-template-layout-guard:start \*/.*?/\* renewal-template-layout-guard:end \*/\s*#s',
            "\n",
            $css
        ) ?? $css;

        return trim($css) . "\n\n" . self::layoutGuardCss();
    }

    private static function isLegacyDefaultTemplate(string $html): bool
    {
        return str_contains($html, 'class="mail-card"')
            && str_contains($html, 'class="circle circle-soft"')
            && str_contains($html, '{{remaining_days}}')
            && str_contains($html, '{{renewal_date}}');
    }

    private static function isLegacyDefaultCss(string $css): bool
    {
        return str_contains($css, '.circle-soft')
            && str_contains($css, '.stripe-top')
            && str_contains($css, '.mail-card');
    }

    public static function renderRenewal(array $settings, array $row, array $recipient, string $statusLine, ?int $days): array
    {
        $templateEnabled = ($settings['template.renewal.enabled'] ?? '0') === '1';
        $html = $templateEnabled ? trim((string) ($settings['template.renewal.html'] ?? '')) : '';
        $css = $templateEnabled ? trim((string) ($settings['template.renewal.css'] ?? '')) : '';

        if ($html === '') {
            $html = self::defaultHtml();
        }

        if ($css === '') {
            $css = self::defaultCss();
        }

        $html = self::normalizeTemplateHtml($html);
        $css = self::enforceLayoutCss($css);
        $inlineAttachments = self::logoInlineAttachments($settings);
        $logoSrc = $inlineAttachments !== []
            ? 'cid:' . $inlineAttachments[0]['cid']
            : self::logoUrl($settings);

        return [
            'body' => self::replacePlaceholders(self::wrapHtml($html, $css), self::renewalPlaceholders($settings, $row, $recipient, $statusLine, $days, $logoSrc)),
            'is_html' => true,
            'inline_attachments' => $inlineAttachments,
        ];
    }

    public static function renderCustomerInfoRequest(array $settings, array $request, string $link): array
    {
        $inlineAttachments = self::logoInlineAttachments($settings);
        $logoSrc = $inlineAttachments !== []
            ? 'cid:' . $inlineAttachments[0]['cid']
            : self::logoUrl($settings);
        $appName = self::escape((string) \app_config('app.name', 'Yenileme Takip Sistemi'));
        $expiresAt = !empty($request['expires_at']) ? date('d.m.Y H:i', strtotime((string) $request['expires_at'])) : '-';
        $recipientName = trim((string) ($request['recipient_name'] ?? ''));
        $greeting = $recipientName !== '' ? 'Merhaba ' . self::escape($recipientName) . ',' : 'Merhaba,';

        $body = '<!doctype html><html><head><meta charset="UTF-8"><meta name="color-scheme" content="light"></head>'
            . '<body style="margin:0;background:#f4f6f5;color:#17201c;font-family:Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:28px;">'
            . '<img src="' . self::escape($logoSrc) . '" alt="' . $appName . '" style="display:block;width:auto;max-width:150px;max-height:58px;margin:0 0 22px;">'
            . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Cari bilgi talebi</p>'
            . '<h1 style="margin:0 0 14px;color:#17201c;font-size:26px;line-height:1.2;">Cari bilgilerinizi tamamlayın</h1>'
            . '<p style="margin:0 0 18px;color:#607069;font-size:15px;line-height:1.55;">' . $greeting . ' cari kartınızdaki bilgilendirme yapılacak yetkili kişi bilgileri eksik görünüyor. Firma, vergi, adres ve yetkili bilgilerinizi güvenli form üzerinden tamamlamanızı rica ederiz. İsterseniz vergi levhanızı yükleyerek alanların otomatik dolmasını sağlayabilirsiniz.</p>'
            . '<div style="margin:0 0 22px;padding:14px 16px;background:#eef6f4;border:1px solid #cae5df;border-radius:8px;color:#31413c;font-size:14px;line-height:1.55;"><strong style="display:block;margin:0 0 5px;color:#147c72;">Neden gerekli?</strong>Ürün yenileme bildirimi ve teklif süreçlerinin doğru kişilere ulaşabilmesi için bilgilendirme yapılacak yetkililerin eksiksiz ve güncel olması gerekir.</div>'
            . '<p style="margin:0 0 22px;"><a href="' . self::escape($link) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:13px 18px;font-weight:700;">Cari bilgilerini doldur</a></p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#eef3f1;border-radius:8px;">'
            . ($recipientName !== '' ? '<tr><td style="padding:12px 14px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:13px;">Yetkili</td><td style="padding:12px 14px;border-bottom:1px solid #d8e0dd;color:#17201c;font-size:13px;font-weight:700;text-align:right;">' . self::escape($recipientName) . '</td></tr>' : '')
            . '<tr><td style="padding:12px 14px;color:#607069;font-size:13px;">Bağlantı geçerlilik süresi</td><td style="padding:12px 14px;color:#17201c;font-size:13px;font-weight:700;text-align:right;">' . self::escape($expiresAt) . '</td></tr>'
            . '</table>'
            . '<p style="margin:18px 0 0;color:#607069;font-size:12px;line-height:1.5;">Bu bağlantı size özel oluşturuldu. Bilgileri gönderdikten sonra bağlantı kapanır.</p>'
            . '</td></tr></table>'
            . '<p style="margin:14px 0 0;color:#607069;font-size:12px;">' . $appName . '</p>'
            . '</td></tr></table></body></html>';

        return [
            'body' => $body,
            'is_html' => true,
            'inline_attachments' => $inlineAttachments,
        ];
    }

    public static function renderCustomerInfoSubmitted(array $settings, array $request, array $values, int $customerId): array
    {
        $inlineAttachments = self::logoInlineAttachments($settings);
        $logoSrc = $inlineAttachments !== []
            ? 'cid:' . $inlineAttachments[0]['cid']
            : self::logoUrl($settings);
        $appName = self::escape((string) \app_config('app.name', 'Yenileme Takip Sistemi'));
        $customerUrl = \url('/customers/' . $customerId . '/edit');
        $rows = [
            'Firma adı' => $values['company_name'] ?? '',
            'Yetkililer' => self::customerInfoContactSummary($values),
            'Vergi dairesi' => $values['tax_office'] ?? '',
            'Vergi no / TC kimlik no' => $values['tax_number'] ?? '',
            'İl / İlçe' => trim((string) (($values['city'] ?? '') . ' / ' . ($values['district'] ?? '')), ' /'),
            'Adres' => $values['address'] ?? '',
            'Not' => $values['notes'] ?? '',
        ];

        $tableRows = '';
        foreach ($rows as $label => $value) {
            $tableRows .= '<tr>'
                . '<td style="padding:11px 12px;border-bottom:1px solid #d8e0dd;color:#607069;font-size:13px;font-weight:700;width:34%;">' . self::escape($label) . '</td>'
                . '<td style="padding:11px 12px;border-bottom:1px solid #d8e0dd;color:#17201c;font-size:13px;font-weight:700;">' . nl2br(self::escape(trim((string) $value) !== '' ? (string) $value : '-'), false) . '</td>'
                . '</tr>';
        }

        $body = '<!doctype html><html><head><meta charset="UTF-8"><meta name="color-scheme" content="light"></head>'
            . '<body style="margin:0;background:#f4f6f5;color:#17201c;font-family:Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:28px;">'
            . '<img src="' . self::escape($logoSrc) . '" alt="' . $appName . '" style="display:block;width:auto;max-width:150px;max-height:58px;margin:0 0 22px;">'
            . '<p style="margin:0 0 8px;color:#147c72;font-size:12px;font-weight:700;text-transform:uppercase;">Cari bilgi formu tamamlandı</p>'
            . '<h1 style="margin:0 0 14px;color:#17201c;font-size:24px;line-height:1.25;">' . self::escape((string) (($values['company_name'] ?? '') ?: 'Yeni cari bilgisi')) . '</h1>'
            . '<p style="margin:0 0 18px;color:#607069;font-size:15px;line-height:1.55;">Gönderdiğiniz cari bilgi talebi müşteri tarafından dolduruldu. Alınan bilgiler aşağıdadır.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#fbfcfb;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">' . $tableRows . '</table>'
            . '<p style="margin:22px 0 0;"><a href="' . self::escape($customerUrl) . '" style="display:inline-block;background:#147c72;color:#ffffff;text-decoration:none;border-radius:8px;padding:12px 16px;font-weight:700;">Cari kartını aç</a></p>'
            . '<p style="margin:18px 0 0;color:#607069;font-size:12px;line-height:1.5;">Talep maili: ' . self::escape((string) ($request['recipient_email'] ?? '-')) . '</p>'
            . '</td></tr></table>'
            . '<p style="margin:14px 0 0;color:#607069;font-size:12px;">' . $appName . '</p>'
            . '</td></tr></table></body></html>';

        return [
            'body' => $body,
            'is_html' => true,
            'inline_attachments' => $inlineAttachments,
        ];
    }

    public static function prepareBrandedMail(array $settings, string $body, bool $isHtml, array $inlineAttachments = []): array
    {
        $logoAttachments = self::logoInlineAttachments($settings);
        if ($logoAttachments === []) {
            return [
                'body' => $body,
                'is_html' => $isHtml,
                'inline_attachments' => $inlineAttachments,
            ];
        }

        if (!$isHtml) {
            $body = self::plainTextBrandedHtml($settings, $body);
            $isHtml = true;
        }

        $logoCid = (string) $logoAttachments[0]['cid'];
        $logoSrc = 'cid:' . $logoCid;
        $inlineAttachments = self::mergeInlineAttachments($inlineAttachments, $logoAttachments);
        $body = str_replace('{{logo_url}}', $logoSrc, $body);
        $body = self::rewriteTemplateLogoSource($body, $logoSrc);

        if (!self::bodyContainsLogo($body, $logoCid)) {
            $body = self::injectStandardLogo($settings, $body, $logoSrc);
        }

        return [
            'body' => $body,
            'is_html' => true,
            'inline_attachments' => $inlineAttachments,
        ];
    }

    private static function customerInfoContactSummary(array $values): string
    {
        $contacts = $values['contacts'] ?? [];
        $lines = [];
        if (is_array($contacts)) {
            foreach ($contacts as $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                $parts = array_values(array_filter([
                    trim((string) ($contact['full_name'] ?? '')),
                    trim((string) ($contact['email'] ?? '')),
                    trim((string) ($contact['phone'] ?? '')),
                ], static fn (string $value): bool => $value !== ''));

                if ($parts !== []) {
                    $lines[] = implode(' - ', $parts);
                }
            }
        }

        if ($lines !== []) {
            return implode("\n", $lines);
        }

        return trim(implode(' - ', array_values(array_filter([
            trim((string) ($values['contact_name'] ?? '')),
            trim((string) ($values['email'] ?? '')),
            trim((string) ($values['phone'] ?? '')),
        ], static fn (string $value): bool => $value !== ''))));
    }

    public static function plainRenewal(array $row, array $recipient, string $statusLine): string
    {
        $recordType = $row['kind'] === 'product' ? 'lisans' : 'uyelik / hizmet';

        return implode("\n", [
            'Merhaba ' . ($recipient['name'] ?: ''),
            '',
            sprintf('%s için %s kaydının yenileme tarihi: %s', $row['company_name'], $row['title'], date('d.m.Y', strtotime($row['renewal_date']))),
            '',
            'Kayıt tipi: ' . $recordType,
            'Bir Önceki Fatura Numarası: ' . self::invoiceNumberLabel($row),
            'Ödeme şekli: ' . self::paymentMethodLabel($row),
            'Toplam: ' . self::totalAmountLabel($row) . ' KDV dahil',
            self::readAckActionText($recipient),
            'Bilgilendirme:',
            self::definitionInfoLabel($row),
            'Notlar:',
            (string) ($row['notes'] ?: '-'),
            '',
            'Yenileme Takip Sistemi',
        ]);
    }

    private static function renewalPlaceholders(array $settings, array $row, array $recipient, string $statusLine, ?int $days, ?string $logoSrc = null): array
    {
        return [
            'app_name' => self::escape((string) \app_config('app.name', 'Yenileme Takip Sistemi')),
            'logo_url' => self::escape($logoSrc ?? self::logoUrl($settings)),
            'contact_name' => self::escape((string) ($recipient['name'] ?: '')),
            'customer_name' => self::escape((string) ($row['company_name'] ?? '')),
            'title' => self::escape((string) (($row['item_summary'] ?? '') ?: ($row['title'] ?? ''))),
            'brand' => self::escape((string) (((int) ($row['item_count'] ?? 1) > 1 ? 'Çoklu ürün' : ($row['brand'] ?? '')) ?: '-')),
            'license_key' => self::escape((string) (($row['license_key'] ?? '') ?: '-')),
            'invoice_number' => self::escape(self::invoiceNumberLabel($row)),
            'previous_invoice_number' => self::escape(self::invoiceNumberLabel($row)),
            'payment_method' => self::escape(self::paymentMethodLabel($row)),
            'total_amount' => self::escape(self::totalAmountLabel($row)),
            'items_table' => self::itemsTableHtml($row),
            'payment_choice_url' => '',
            'payment_action' => '',
            'read_ack_url' => self::escape((string) ($recipient['read_ack_url'] ?? '')),
            'read_ack_action' => self::readAckActionHtml($recipient),
            'definition_notification_info' => nl2br(self::escape(self::definitionInfoLabel($row)), false),
            'definition_info' => nl2br(self::escape(self::definitionInfoLabel($row)), false),
            'notification_info' => nl2br(self::escape(self::definitionInfoLabel($row)), false),
            'renewal_date' => self::escape(date('d.m.Y', strtotime((string) $row['renewal_date']))),
            'remaining_days' => self::escape(self::remainingDaysLabel($days)),
            'urgency_class' => self::escape(self::urgencyClass($days)),
            'urgency_bg' => self::escape(self::urgencyColors($days)['bg']),
            'urgency_border' => self::escape(self::urgencyColors($days)['border']),
            'urgency_text' => self::escape(self::urgencyColors($days)['text']),
            'status_line' => '',
            'notes' => nl2br(self::escape((string) (($row['notes'] ?? '') ?: '-')), false),
            'record_type' => self::escape($row['kind'] === 'product' ? 'Lisans' : 'Üyelik / hizmet'),
            'supplier' => '',
            'today' => self::escape(date('d.m.Y')),
        ];
    }

    private static function logoUrl(array $settings): string
    {
        $path = trim((string) ($settings['branding.logo_path'] ?? ''));
        if ($path === '') {
            return \url('/assets/pwa-icon.svg');
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return \url($path);
        }

        return \url('/' . $path);
    }

    private static function invoiceNumberLabel(array $row): string
    {
        $invoiceNumber = trim((string) (
            ($row['current_invoice_number'] ?? '')
            ?: ($row['previous_invoice_number'] ?? '')
            ?: ($row['invoice_number'] ?? '')
        ));

        return $invoiceNumber !== '' ? $invoiceNumber : '-';
    }

    private static function paymentMethodLabel(array $row): string
    {
        if (!empty($row['payment_customer_choice'])) {
            return 'Müşteri seçimine bırakıldı';
        }

        $paymentMethod = trim((string) ($row['payment_method'] ?? ''));

        return $paymentMethod !== '' ? $paymentMethod : '-';
    }

    private static function totalAmountLabel(array $row): string
    {
        $amount = ($row['item_total'] ?? null) ?: ($row['amount'] ?? null);
        if ($amount === null || $amount === '' || (float) $amount <= 0) {
            return '-';
        }

        return \money_format_local((float) $amount, (string) ($row['currency'] ?? 'TRY'));
    }

    private static function itemsTableHtml(array $row): string
    {
        $renewalId = (int) ($row['id'] ?? 0);
        if ($renewalId < 1) {
            return '';
        }

        try {
            $items = (new \App\Models\RenewalRepository())->renewalItems($renewalId);
        } catch (\Throwable) {
            $items = [];
        }

        if ($items === [] || count($items) < 2) {
            return '';
        }

        $currency = (string) ($row['currency'] ?? 'TRY');
        $html = '<table class="items-table" role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><th>Ürün</th><th>Adet</th><th>Tutar</th></tr>';

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = ($item['unit_price'] ?? null) === null ? null : (float) $item['unit_price'];
            $vatRate = (float) ($item['vat_rate'] ?? 0);
            $net = $unitPrice === null ? null : $quantity * $unitPrice;
            $lineTotal = $net === null ? null : $net + ($net * $vatRate / 100);
            $html .= '<tr>'
                . '<td>' . self::escape((string) ($item['title'] ?? '-')) . '<br><span>' . self::escape((string) (($item['brand'] ?? '') ?: '-')) . '</span></td>'
                . '<td>' . self::escape(number_format($quantity, 2, ',', '.')) . '</td>'
                . '<td>' . self::escape(\money_format_local($lineTotal, $currency)) . '<br><span>KDV %' . self::escape(number_format($vatRate, 2, ',', '.')) . ' dahil</span></td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    private static function paymentActionHtml(array $row, array $recipient): string
    {
        return '';
    }

    private static function paymentActionText(array $row, array $recipient): string
    {
        return '';
    }

    private static function readAckActionHtml(array $recipient): string
    {
        $url = trim((string) ($recipient['read_ack_url'] ?? ''));
        if ($url === '') {
            return '';
        }

        return '<table class="read-ack-action" role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td>'
            . '<a class="read-ack-button" href="' . self::escape($url) . '">Okudum</a>'
            . '<span class="read-ack-info" title="Bu bildirimi aldığınızı kaydetmek için">i</span>'
            . '<span class="read-ack-help">Bu bildirimi aldığınızı kaydetmek için</span>'
            . '</td></tr></table>';
    }

    private static function readAckActionText(array $recipient): string
    {
        $url = trim((string) ($recipient['read_ack_url'] ?? ''));
        if ($url === '') {
            return '';
        }

        return 'Okudum olarak işaretle: ' . $url;
    }

    private static function paymentChoiceUrl(array $row, array $recipient): string
    {
        return PaymentLink::urlForRenewal(
            (int) ($row['id'] ?? 0),
            60,
            (string) ($recipient['email'] ?? '')
        );
    }

    private static function creditCardPaymentUrl(array $row, array $recipient): string
    {
        return self::paymentChoiceUrl($row, $recipient) . '&method=credit_card';
    }

    private static function shouldShowPaymentAction(array $row): bool
    {
        return false;
    }

    private static function definitionInfoLabel(array $row): string
    {
        $definitionInfo = trim((string) (
            ($row['definition_notification_info'] ?? '')
            ?: ($row['notification_info'] ?? '')
            ?: ($row['definition_info'] ?? '')
        ));

        return $definitionInfo !== '' ? $definitionInfo : '-';
    }

    private static function logoInlineAttachments(array $settings): array
    {
        $path = self::localLogoPath($settings);
        if ($path === null) {
            return [];
        }

        $mimeType = self::mimeType($path);
        if (!in_array($mimeType, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return [];
        }

        return [[
            'cid' => 'app_logo',
            'path' => $path,
            'name' => basename($path),
            'content_type' => $mimeType,
        ]];
    }

    private static function plainTextBrandedHtml(array $settings, string $body): string
    {
        $appName = self::escape((string) \app_config('app.name', 'Yenileme Takip Sistemi'));
        $content = nl2br(self::escape($body), false);

        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="color-scheme" content="light"></head>'
            . '<body style="margin:0;background:#f4f6f5;color:#17201c;font-family:Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:24px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0dd;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="height:6px;background:#147c72;font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:26px;">'
            . '<div style="margin:0 0 18px;text-align:center;"><img data-template-logo="1" src="{{logo_url}}" alt="' . $appName . '" style="display:inline-block;width:auto;max-width:150px;max-height:58px;height:auto;object-fit:contain;"></div>'
            . '<div style="color:#26322e;font-size:15px;line-height:1.6;white-space:normal;">' . $content . '</div>'
            . '</td></tr></table>'
            . '<p style="margin:14px 0 0;color:#607069;font-size:12px;">' . $appName . '</p>'
            . '</td></tr></table></body></html>';
    }

    private static function mergeInlineAttachments(array $inlineAttachments, array $logoAttachments): array
    {
        $merged = [];
        foreach (array_merge($inlineAttachments, $logoAttachments) as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $cid = (string) ($attachment['cid'] ?? '');
            if ($cid === '') {
                $cid = 'inline_' . substr(hash('sha256', (string) ($attachment['path'] ?? json_encode($attachment))), 0, 16);
            }

            $merged[$cid] = array_merge($attachment, ['cid' => $cid]);
        }

        return array_values($merged);
    }

    private static function rewriteTemplateLogoSource(string $body, string $logoSrc): string
    {
        return (string) preg_replace_callback(
            '#<img\b(?=[^>]*(?:data-template-logo|brand-logo))[^>]*>#i',
            static function (array $matches) use ($logoSrc): string {
                $tag = (string) $matches[0];
                if (preg_match('#\bsrc=(["\'])(.*?)\1#i', $tag) === 1) {
                    return (string) preg_replace('#\bsrc=(["\'])(.*?)\1#i', 'src="${1}' . $logoSrc . '${1}', $tag, 1);
                }

                return preg_replace('#<img\b#i', '<img src="' . $logoSrc . '"', $tag, 1) ?? $tag;
            },
            $body
        );
    }

    private static function bodyContainsLogo(string $body, string $logoCid): bool
    {
        return stripos($body, 'cid:' . $logoCid) !== false
            || stripos($body, 'data-template-logo') !== false;
    }

    private static function injectStandardLogo(array $settings, string $body, string $logoSrc): string
    {
        $appName = self::escape((string) \app_config('app.name', 'Yenileme Takip Sistemi'));
        $logoBlock = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f5;padding:18px 24px 0;"><tr><td align="center">'
            . '<img data-template-logo="1" src="' . self::escape($logoSrc) . '" alt="' . $appName . '" style="display:inline-block;width:auto;max-width:150px;max-height:58px;height:auto;object-fit:contain;">'
            . '</td></tr></table>';

        if (preg_match('#<body\b[^>]*>#i', $body) === 1) {
            return (string) preg_replace('#(<body\b[^>]*>)#i', '$1' . $logoBlock, $body, 1);
        }

        return $logoBlock . $body;
    }

    private static function localLogoPath(array $settings): ?string
    {
        $path = trim((string) ($settings['branding.logo_path'] ?? ''));
        if ($path === '' || preg_match('#^https?://#i', $path) === 1) {
            return null;
        }

        $relative = ltrim($path, '/');
        $candidates = [
            $path,
            ROOT_PATH . '/public/' . $relative,
            ROOT_PATH . '/' . $relative,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function mimeType(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($path);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'image/png',
        };
    }

    private static function remainingDaysLabel(?int $days): string
    {
        if ($days === null) {
            return '-';
        }

        if ($days < 0) {
            return abs($days) . ' gün geçti';
        }

        if ($days === 0) {
            return 'Bugün';
        }

        return $days . ' gün';
    }

    private static function urgencyClass(?int $days): string
    {
        if ($days === null || $days > 60) {
            return 'urgency-safe';
        }

        if ($days < 0) {
            return 'urgency-overdue';
        }

        if ($days <= 7) {
            return 'urgency-critical';
        }

        if ($days <= 30) {
            return 'urgency-warning';
        }

        return 'urgency-watch';
    }

    private static function urgencyColors(?int $days): array
    {
        return match (self::urgencyClass($days)) {
            'urgency-overdue' => ['bg' => '#7f0000', 'border' => '#4c0000', 'text' => '#ffffff'],
            'urgency-critical' => ['bg' => '#ffd7d7', 'border' => '#ef4444', 'text' => '#991b1b'],
            'urgency-warning' => ['bg' => '#ffe8cc', 'border' => '#f59e0b', 'text' => '#9a3412'],
            'urgency-watch' => ['bg' => '#fff7db', 'border' => '#f5d36b', 'text' => '#92400e'],
            default => ['bg' => '#e8f7f4', 'border' => '#c8ece5', 'text' => '#0f625b'],
        };
    }

    private static function replacePlaceholders(string $html, array $values): string
    {
        foreach ($values as $key => $value) {
            $html = str_replace('{{' . $key . '}}', (string) $value, $html);
        }

        return $html;
    }

    private static function wrapHtml(string $html, string $css): string
    {
        $style = '<style>' . $css . '</style>';

        if (stripos($html, '<html') !== false) {
            $meta = '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">';
            return str_ireplace('</head>', $meta . $style . '</head>', $html);
        }

        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">' . $style . '</head><body>' . $html . '</body></html>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function normalizeLogoTag(string $tag): string
    {
        $tag = self::ensureTagClass($tag, 'brand-logo');

        if (stripos($tag, 'data-template-logo') === false) {
            $tag = self::insertTagAttribute($tag, 'data-template-logo="1"');
        }

        return $tag;
    }

    private static function normalizeRemainingMetric(string $html): string
    {
        return preg_replace_callback(
            '#<div\b(?=[^>]*class=(?:"(?:metric(?:\s|")|[^"]*\smetric(?:\s|"))|\'(?:metric(?:\s|\')|[^\']*\smetric(?:\s|\'))))[^>]*>(?:(?!</div>).)*\{\{remaining_days\}\}(?:(?!</div>).)*</div>#is',
            static function (array $matches): string {
                $block = $matches[0];
                $block = self::ensureTagClass($block, 'metric-remaining');
                $block = self::ensureTagClass($block, '{{urgency_class}}');

                return $block;
            },
            $html
        ) ?? $html;
    }

    private static function normalizeMetricRow(string $html): string
    {
        $html = preg_replace_callback(
            '#<table\b[^>]*>.*?</table>#is',
            static function (array $matches): string {
                if (!self::tagHasClass($matches[0], 'metric-row')) {
                    return $matches[0];
                }

                if (!str_contains($matches[0], '{{remaining_days}}') || !str_contains($matches[0], '{{renewal_date}}')) {
                    return $matches[0];
                }

                return self::metricRowTableHtml();
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '#<div\b[^>]*>\s*((?:<div\b[^>]*>.*?</div>\s*){2})</div>#is',
            static function (array $matches): string {
                if (!self::tagHasClass($matches[0], 'metric-row')) {
                    return $matches[0];
                }

                preg_match_all('#<div\b[^>]*>.*?</div>#is', $matches[1], $children);
                $blocks = $children[0] ?? [];
                if (count($blocks) !== 2) {
                    return $matches[0];
                }

                if (!self::tagHasClass($blocks[0], 'metric') || !self::tagHasClass($blocks[1], 'metric')) {
                    return $matches[0];
                }

                $firstHasRemaining = str_contains($blocks[0], '{{remaining_days}}');
                $secondHasRemaining = str_contains($blocks[1], '{{remaining_days}}');
                $firstHasDate = str_contains($blocks[0], '{{renewal_date}}');
                $secondHasDate = str_contains($blocks[1], '{{renewal_date}}');

                if ((!$firstHasRemaining && !$secondHasRemaining) || (!$firstHasDate && !$secondHasDate)) {
                    return $matches[0];
                }

                $remainingBlock = $firstHasRemaining ? $blocks[0] : $blocks[1];
                $dateBlock = $firstHasDate ? $blocks[0] : $blocks[1];

                return self::metricRowTableHtml(
                    self::innerDivHtml($remainingBlock),
                    self::innerDivHtml($dateBlock)
                );
            },
            $html
        ) ?? $html;

        return self::normalizeStandaloneMetricPair($html);
    }

    private static function removeDisabledTemplateFields(string $html): string
    {
        $tokens = '(?:\{\{status_line\}\}|\{\{supplier\}\})';
        $html = preg_replace('#\s*<tr\b[^>]*>(?:(?!</tr>).)*' . $tokens . '(?:(?!</tr>).)*</tr>#isu', '', $html) ?? $html;
        $html = preg_replace('#\s*<tr\b[^>]*>(?:(?!</tr>).)*(?:Tedarik(?:ci|çi)|TEDAR(?:IKCI|İKÇİ)|Durum)(?:(?!</tr>).)*</tr>#isu', '', $html) ?? $html;
        $html = preg_replace('#\s*<p\b[^>]*>(?:(?!</p>).)*' . $tokens . '(?:(?!</p>).)*</p>#isu', '', $html) ?? $html;
        $html = preg_replace('#\s*<span\b[^>]*>(?:(?!</span>).)*' . $tokens . '(?:(?!</span>).)*</span>#isu', '', $html) ?? $html;
        $html = preg_replace('#\s*<div\b[^>]*>(?:(?!</div>).)*' . $tokens . '(?:(?!</div>).)*</div>#isu', '', $html) ?? $html;

        return $html;
    }

    private static function removePaymentTemplateFields(string $html): string
    {
        $tokens = '\{\{payment_action\}\}';
        foreach (['table', 'div', 'p', 'span', 'a'] as $tag) {
            $html = preg_replace('#\s*<' . $tag . '\b[^>]*>(?:(?!</' . $tag . '>).)*' . $tokens . '(?:(?!</' . $tag . '>).)*</' . $tag . '>#isu', '', $html) ?? $html;
        }

        $html = preg_replace('#\s*<a\b[^>]*\{\{payment_choice_url\}\}[^>]*>.*?</a>#isu', '', $html) ?? $html;

        return str_replace(['{{payment_action}}', '{{payment_choice_url}}'], '', $html);
    }

    private static function ensurePaymentMethodRow(string $html): string
    {
        if (str_contains($html, '{{payment_method}}')) {
            return $html;
        }

        $row = "\n                <tr><td>Ödeme şekli</td><td>{{payment_method}}</td></tr>";
        $htmlWithInvoiceRow = preg_replace(
            '#(<tr\b[^>]*>\s*<td\b[^>]*>\s*Bir Önceki Fatura Numarası\s*</td>\s*<td\b[^>]*>\s*\{\{previous_invoice_number\}\}\s*</td>\s*</tr>)#iu',
            '$1' . $row,
            $html,
            1,
            $count
        );

        if ($htmlWithInvoiceRow !== null && $count > 0) {
            return $htmlWithInvoiceRow;
        }

        return preg_replace_callback(
            '#<table\b[^>]*>.*?</table>#is',
            static function (array $matches) use ($row): string {
                if (!self::tagHasClass($matches[0], 'info-table')) {
                    return $matches[0];
                }

                return preg_replace('#</table>\s*$#i', $row . "\n              </table>", $matches[0], 1) ?? $matches[0];
            },
            $html
        ) ?? $html;
    }

    private static function ensureTotalAmountRow(string $html): string
    {
        if (str_contains($html, '{{total_amount}}')) {
            return $html;
        }

        $row = "\n                <tr><td>KDV dahil toplam fiyat</td><td>{{total_amount}}</td></tr>";
        $htmlWithPaymentRow = preg_replace(
            '#(<tr\b[^>]*>(?:(?!</tr>).)*\{\{payment_method\}\}(?:(?!</tr>).)*</tr>)#isu',
            '$1' . $row,
            $html,
            1,
            $count
        );

        if ($htmlWithPaymentRow !== null && $count > 0) {
            return $htmlWithPaymentRow;
        }

        $htmlWithInvoiceRow = preg_replace(
            '#(<tr\b[^>]*>\s*<td\b[^>]*>\s*Bir Önceki Fatura Numarası\s*</td>\s*<td\b[^>]*>\s*\{\{previous_invoice_number\}\}\s*</td>\s*</tr>)#iu',
            '$1' . $row,
            $html,
            1,
            $count
        );

        if ($htmlWithInvoiceRow !== null && $count > 0) {
            return $htmlWithInvoiceRow;
        }

        return preg_replace_callback(
            '#<table\b[^>]*>.*?</table>#is',
            static function (array $matches) use ($row): string {
                if (!self::tagHasClass($matches[0], 'info-table')) {
                    return $matches[0];
                }

                return preg_replace('#</table>\s*$#i', $row . "\n              </table>", $matches[0], 1) ?? $matches[0];
            },
            $html
        ) ?? $html;
    }

    private static function ensurePaymentActionBlock(string $html): string
    {
        return $html;
    }

    private static function ensureReadAckActionBlock(string $html): string
    {
        if (str_contains($html, '{{read_ack_action}}')) {
            return $html;
        }

        $block = "\n\n              {{read_ack_action}}";
        $inserted = false;

        $afterInfoTable = preg_replace_callback(
            '#<table\b[^>]*>.*?</table>#is',
            static function (array $matches) use ($block, &$inserted): string {
                if ($inserted || !self::tagHasClass($matches[0], 'info-table')) {
                    return $matches[0];
                }

                $inserted = true;
                return $matches[0] . $block;
            },
            $html
        );

        return $afterInfoTable ?? $html;
    }

    private static function ensureDefinitionInfoBlock(string $html): string
    {
        if (str_contains($html, '{{definition_notification_info}}')
            || str_contains($html, '{{definition_info}}')
            || str_contains($html, '{{notification_info}}')) {
            return $html;
        }

        $block = <<<'HTML'

              <table class="definition-info" role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <strong>Bilgilendirme</strong>
                    <p>{{definition_notification_info}}</p>
                  </td>
                </tr>
              </table>
HTML;

        $beforeNotes = preg_replace(
            '#(\s*<table\b[^>]*class=(["\'])(?:(?!\2).)*\bnotes\b(?:(?!\2).)*\2[^>]*>)#is',
            $block . '$1',
            $html,
            1,
            $count
        );

        if ($beforeNotes !== null && $count > 0) {
            return $beforeNotes;
        }

        return preg_replace_callback(
            '#<table\b[^>]*>.*?</table>#is',
            static function (array $matches) use ($block): string {
                if (!self::tagHasClass($matches[0], 'info-table')) {
                    return $matches[0];
                }

                return $matches[0] . $block;
            },
            $html
        ) ?? $html;
    }

    private static function normalizeStandaloneMetricPair(string $html): string
    {
        $metricDivPattern = '<div\b(?=[^>]*(?:class="(?:[^"]*\s)?metric(?:\s|(?="))[^"]*"|class=\'(?:[^\']*\s)?metric(?:\s|(?=\'))[^\']*\'))[^>]*>.*?</div>';

        return preg_replace_callback(
            '#(' . $metricDivPattern . ')((?:\s|<br\s*/?>|&nbsp;)*)(' . $metricDivPattern . ')#is',
            static function (array $matches): string {
                $first = $matches[1];
                $second = $matches[3];

                $firstHasRemaining = str_contains($first, '{{remaining_days}}');
                $secondHasRemaining = str_contains($second, '{{remaining_days}}');
                $firstHasDate = str_contains($first, '{{renewal_date}}');
                $secondHasDate = str_contains($second, '{{renewal_date}}');

                if ((!$firstHasRemaining && !$secondHasRemaining) || (!$firstHasDate && !$secondHasDate)) {
                    return $matches[0];
                }

                if (!self::tagHasClass($first, 'metric') || !self::tagHasClass($second, 'metric')) {
                    return $matches[0];
                }

                return self::metricRowTableHtml();
            },
            $html
        ) ?? $html;
    }

    private static function metricRowTableHtml(string $remainingHtml = '', string $dateHtml = ''): string
    {
        $remainingHtml = '<span style="display:block;color:{{urgency_text}};font-size:11px;font-weight:700;text-transform:uppercase;line-height:1.2;">Kalan süre</span><strong style="display:block;margin-top:7px;color:{{urgency_text}};font-size:26px;line-height:1.08;word-break:break-word;">{{remaining_days}}</strong>';
        $dateHtml = '<span style="display:block;color:#66756f;font-size:11px;font-weight:700;text-transform:uppercase;line-height:1.2;">Yenileme tarihi</span><strong style="display:block;margin-top:7px;color:#0f625b;font-size:26px;line-height:1.08;word-break:break-word;">{{renewal_date}}</strong>';

        return <<<HTML
<table class="metric-row" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;margin:18px 0;border-collapse:separate;border-spacing:0;table-layout:fixed;">
  <tr>
    <td class="metric metric-remaining {{urgency_class}}" width="49%" bgcolor="{{urgency_bg}}" style="width:49% !important;padding:16px;background:{{urgency_bg}};border:1px solid {{urgency_border}};border-radius:8px;vertical-align:top;">{$remainingHtml}</td>
    <td class="metric-gap" width="2%" style="width:2%;font-size:0;line-height:0;">&nbsp;</td>
    <td class="metric metric-date" width="49%" bgcolor="#eef6fb" style="width:49% !important;padding:16px;background:#eef6fb;border:1px solid #d5e7ef;border-radius:8px;vertical-align:top;">{$dateHtml}</td>
  </tr>
</table>
HTML;
    }

    private static function innerDivHtml(string $html): string
    {
        if (preg_match('#^<div\b[^>]*>(.*)</div>$#is', trim($html), $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($html);
    }

    private static function tagHasClass(string $tag, string $class): bool
    {
        if (preg_match('#^<\w+\b[^>]*>#is', trim($tag), $openingTag) !== 1) {
            return false;
        }

        if (preg_match('#\sclass=(["\'])(.*?)\1#is', $openingTag[0], $matches) !== 1) {
            return false;
        }

        $classes = preg_split('/\s+/', trim(html_entity_decode((string) $matches[2], ENT_QUOTES, 'UTF-8'))) ?: [];

        return in_array($class, $classes, true);
    }

    private static function ensureTagClass(string $tag, string $class): string
    {
        $updated = preg_replace_callback(
            '#\sclass=(["\'])(.*?)\1#i',
            static function (array $matches) use ($class): string {
                $classes = preg_split('/\s+/', trim((string) $matches[2])) ?: [];
                if (!in_array($class, $classes, true)) {
                    $classes[] = $class;
                }

                return ' class=' . $matches[1] . trim(implode(' ', $classes)) . $matches[1];
            },
            $tag,
            1,
            $count
        );

        if ($updated !== null && $count > 0) {
            return $updated;
        }

        return self::insertTagAttribute($tag, 'class="' . $class . '"');
    }

    private static function insertTagAttribute(string $tag, string $attribute): string
    {
        return preg_replace('#\s*/?>$#', ' ' . $attribute . '$0', $tag, 1) ?? $tag;
    }

    private static function layoutGuardCss(): string
    {
        return <<<'CSS'
/* renewal-template-layout-guard:start */
.mail-document,
.mail-document * {
  box-sizing: border-box;
}
.mail-document {
  width: 100% !important;
  max-width: 740px !important;
  margin-left: auto !important;
  margin-right: auto !important;
  overflow: hidden !important;
  color-scheme: light only;
}
.mail-document .mail-card,
.mail-document .brand-row,
.mail-document .footer {
  width: 100% !important;
  max-width: 680px !important;
}
.mail-document .brand-row {
  margin-left: auto !important;
  margin-right: auto !important;
  text-align: center !important;
}
.mail-document img {
  max-width: 100%;
  height: auto;
}
.mail-document .brand-logo,
.mail-document [data-template-logo] {
  display: inline-block !important;
  width: auto !important;
  max-width: 170px !important;
  max-height: 62px !important;
  height: auto !important;
  object-fit: contain !important;
}
.mail-document h1,
.mail-document h2,
.mail-document h3,
.mail-document p,
.mail-document a,
.mail-document span,
.mail-document strong,
.mail-document td {
  max-width: 100%;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.mail-document .metric-row {
  width: 100% !important;
  border-collapse: separate !important;
  border-spacing: 0 !important;
  table-layout: fixed !important;
}
.mail-document .metric {
  width: 49% !important;
  min-width: 0 !important;
  vertical-align: top !important;
}
.mail-document .metric-gap {
  width: 2% !important;
  font-size: 0 !important;
  line-height: 0 !important;
}
.mail-document .metric + .metric {
  margin-top: 0 !important;
}
.mail-document .metric span {
  line-height: 1.2;
}
.mail-document .metric strong {
  line-height: 1.12;
}
.mail-document .metric-remaining {
  background: {{urgency_bg}} !important;
  border: 1px solid {{urgency_border}} !important;
}
.mail-document .metric-remaining span,
.mail-document .metric-remaining strong {
  color: {{urgency_text}} !important;
}
.mail-document .urgency-overdue,
.mail-document .metric-remaining.urgency-overdue {
  background: #7f0000 !important;
  border-color: #4c0000 !important;
}
.mail-document .urgency-overdue span,
.mail-document .urgency-overdue strong {
  color: #ffffff !important;
}
.mail-document table {
  max-width: 100%;
  table-layout: fixed;
}
.mail-document .definition-info {
  width: 100% !important;
  margin: 0 0 18px !important;
  background: #eef7f5 !important;
  border: 1px solid #cae5df !important;
  border-radius: 8px !important;
  border-collapse: separate !important;
  border-spacing: 0 !important;
}
.mail-document .definition-info td {
  padding: 16px !important;
}
.mail-document .definition-info strong {
  display: block !important;
  color: #0f625b !important;
  margin-bottom: 8px !important;
}
.mail-document .definition-info p {
  margin: 0 !important;
  color: #31413c !important;
}
.mail-document .payment-action {
  width: 100% !important;
  margin: 0 0 18px !important;
  background: #101b18 !important;
  border: 1px solid #1ec6aa !important;
  border-radius: 8px !important;
  border-collapse: separate !important;
  border-spacing: 0 !important;
}
.mail-document .payment-action td {
  padding: 20px !important;
}
.mail-document .payment-action strong {
  color: #ffffff !important;
  font-size: 18px !important;
}
.mail-document .payment-action p {
  color: #d9f5ef !important;
}
.mail-document .payment-button {
  display: block !important;
  width: 100% !important;
  box-sizing: border-box !important;
  margin-top: 16px !important;
  padding: 18px 22px !important;
  border-radius: 10px !important;
  background: #1ec6aa !important;
  color: #ffffff !important;
  text-decoration: none !important;
  text-align: center !important;
  font-size: 18px !important;
  font-weight: 800 !important;
}
.mail-document .read-ack-action {
  width: 100% !important;
  margin: -4px 0 18px !important;
  border-collapse: separate !important;
  border-spacing: 0 !important;
}
.mail-document .read-ack-action td {
  padding: 12px 14px !important;
  background: #f7fbfa !important;
  border: 1px solid #d8e0dd !important;
  border-radius: 8px !important;
  color: #607069 !important;
  text-align: center !important;
}
.mail-document .read-ack-button {
  display: inline-block !important;
  padding: 8px 13px !important;
  border: 1px solid #147c72 !important;
  border-radius: 8px !important;
  color: #147c72 !important;
  text-decoration: none !important;
  font-weight: 800 !important;
}
.mail-document .read-ack-info {
  display: inline-block !important;
  width: 24px !important;
  height: 24px !important;
  margin-left: 8px !important;
  border-radius: 999px !important;
  background: #e8f7f4 !important;
  color: #147c72 !important;
  font-size: 13px !important;
  font-weight: 900 !important;
  line-height: 24px !important;
  text-align: center !important;
  vertical-align: middle !important;
}
.mail-document .read-ack-help {
  display: block !important;
  margin-top: 8px !important;
  color: #607069 !important;
  font-size: 12px !important;
  line-height: 1.35 !important;
}
@media screen and (max-width: 620px) {
  .mail-document {
    padding: 18px !important;
  }
  .mail-document .brand-logo,
  .mail-document [data-template-logo] {
    max-width: 130px !important;
    max-height: 52px !important;
  }
  .mail-document .card-inner {
    padding: 18px !important;
  }
  .mail-document .hero-card td {
    padding: 16px !important;
  }
  .mail-document h1 {
    font-size: 21px !important;
  }
  .mail-document p,
  .mail-document .lead {
    font-size: 14px !important;
  }
  .mail-document .metric-row {
    width: 100% !important;
    border-spacing: 0 !important;
    table-layout: fixed !important;
  }
  .mail-document .metric {
    width: 49% !important;
    padding: 10px !important;
  }
  .mail-document .metric-gap {
    width: 2% !important;
    font-size: 0 !important;
    line-height: 0 !important;
  }
  .mail-document .metric span {
    font-size: 10px !important;
    line-height: 1.2 !important;
  }
  .mail-document .metric strong {
    font-size: 18px !important;
    line-height: 1.1 !important;
  }
  .mail-document .metric + .metric {
    margin-top: 0 !important;
  }
}
/* renewal-template-layout-guard:end */
CSS;
    }
}
