<?php
/**
 * Ödenen sipariş / fatura için PDF fatura belgesi.
 * Ortam değişkenleri:
 *   SELLER_INFO       Satıcı bilgileri, satırlar "|" ile ayrılır: "Firma Ltd.|Adres|Vergi Dairesi · No|destek@ornek.com"
 *   PDF_FONT_REGULAR  TrueType yazı tipi (varsayılan: Noto Sans / DejaVu Sans sistem yolları)
 *   PDF_FONT_BOLD     Kalın TrueType yazı tipi
 *   PDF_LOGO_SVG      İsteğe bağlı, tek yollu SVG logo
 */

declare(strict_types=1);
require_once __DIR__ . '/pdf.php';

function pdf_fonts(): array
{
    $pairs = [
        [getenv('PDF_FONT_REGULAR') ?: '', getenv('PDF_FONT_BOLD') ?: ''],
        ['/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf', '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf'],
        ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'],
        ['/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'],
    ];
    foreach ($pairs as [$r, $b]) if ($r && is_file($r) && is_file($b)) return [$r, $b];
    throw new RuntimeException('PDF için TrueType yazı tipi bulunamadı (PDF_FONT_REGULAR / PDF_FONT_BOLD tanımlayın).');
}

function tr_date(?string $t): string
{
    if (!$t) return '—';
    $months = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $z = strtotime($t . (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d/', $t) ? ' UTC' : ''));   // SQLite CURRENT_TIMESTAMP = UTC
    return date('j', $z) . ' ' . $months[(int) date('n', $z)] . ' ' . date('Y', $z);
}

/**
 * @param array $doc number, date, status ('paid'|'unpaid'), customer [satırlar], items [[ad, detay, adet, birim]],
 *                   paid_at, shopier_order_id, ref [etiket, değer] (isteğe bağlı)
 */
function receipt_pdf(array $doc): string
{
    $total = 0.0;
    $items = [];
    foreach ($doc['items'] as [$name, $detail, $qty, $unit]) {
        $total += $qty * $unit;
        $items[] = [$name, $detail, $qty, money((float) $unit), money($qty * (float) $unit)];
    }
    $paid = ($doc['status'] ?? 'paid') === 'paid';
    $seller = array_values(array_filter(array_map('trim', explode('|', (string) (getenv('SELLER_INFO') ?: 'Satıcı adı|Adres|E-posta')))));
    return invoice_pdf([
        'no' => $doc['number'], 'tarih' => tr_date($doc['date'] ?? date('Y-m-d')), 'durum' => $paid ? 'ÖDENDİ' : 'ÖDEME BEKLİYOR',
        'alt_baslik' => (string) parse_url((string) getenv('APP_URL'), PHP_URL_HOST),
        'ek_alan' => $doc['ref'] ?? null,
        'satici' => $seller, 'alici' => array_values(array_filter($doc['customer'])),
        'kalemler' => $items,
        'toplamlar' => [['Ara toplam', money($total)], ['Genel toplam', money($total)]],
        'odeme' => $paid ? array_values(array_filter([
            ['Ödeme yöntemi', 'Kredi / banka kartı (Shopier)'],
            ['Ödeme tarihi', tr_date($doc['paid_at'] ?? null)],
            !empty($doc['shopier_order_id']) ? ['Shopier işlem no', (string) $doc['shopier_order_id']] : null,
        ])) : [],
        'not' => 'Bu belge ödeme kaydı olarak otomatik oluşturulmuştur.',
        'logo' => getenv('PDF_LOGO_SVG') ?: '',
        'fontlar' => pdf_fonts(),
    ]);
}

/** Aylık fatura kaydından PDF. */
function invoice_receipt_pdf(int $invoiceId): ?array
{
    $st = db()->prepare('SELECT i.*, s.email, s.name, s.title FROM invoices i JOIN subscriptions s ON s.id = i.subscription_id WHERE i.id = ?');
    $st->execute([$invoiceId]);
    $i = $st->fetch(PDO::FETCH_ASSOC);
    if (!$i) return null;
    $pdf = receipt_pdf([
        'number' => $i['number'], 'date' => $i['paid_at'] ?: $i['due_date'], 'status' => $i['status'] === 'paid' ? 'paid' : 'unpaid',
        'customer' => [$i['name'], $i['email']], 'items' => [[$i['title'], $i['period'] . ' dönemi', 1, (float) $i['amount']]],
        'paid_at' => $i['paid_at'], 'ref' => ['Son ödeme', tr_date($i['due_date'])],
    ]);
    return ['name' => 'Fatura-' . $i['number'] . '.pdf', 'type' => 'application/pdf', 'content' => $pdf];
}

/** Sipariş kaydından PDF (ilk ödeme). */
function order_receipt_pdf(array $order): array
{
    $pdf = receipt_pdf([
        'number' => $order['id'], 'date' => $order['paid_at'] ?? null, 'status' => 'paid',
        'customer' => [$order['name'], $order['email']], 'items' => [[$order['title'], '', 1, (float) $order['amount']]],
        'paid_at' => $order['paid_at'] ?? gmdate('Y-m-d H:i:s'), 'shopier_order_id' => $order['shopier_order_id'] ?? '',
    ]);
    return ['name' => 'Fatura-' . $order['id'] . '.pdf', 'type' => 'application/pdf', 'content' => $pdf];
}
