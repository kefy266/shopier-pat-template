<?php
/**
 * Shopier REST API v1 istemcisi (Personal Access Token ile).
 *
 * Belgeler: https://developer.shopier.com/reference
 * PAT, Shopier panelinden oluşturulur ve SHOPIER_PAT ortam değişkeninde tutulur.
 * Token'ı asla koda veya depoya yazmayın.
 */

declare(strict_types=1);

/**
 * Shopier siparişinin bu ürün için gerçekten, eksiksiz ödendiğini doğrular. Ödenen tutarı döndürür; değilse null.
 *
 * Kurallar: ödeme durumu tam olarak "paid"; iptal ya da iade yok; siparişte yalnızca bu ürün (1 adet) var;
 * sipariş toplamı ve ürün satırı beklenen tutarı karşılıyor. Böylece %100 indirim kuponu, 0 TL sipariş,
 * eksik alanlı bildirim ya da tek ödemeyle birden çok hizmet alma gibi atlatmalar reddedilir.
 */
function shopier_verify_order(array $so, string $productId, float $expected, ?string &$reason = null): ?float
{
    $reason = null;
    if (($so['paymentStatus'] ?? '') !== 'paid') { $reason = 'ödenmemiş (' . ($so['paymentStatus'] ?? 'yok') . ')'; return null; }
    if (in_array(strtolower((string) ($so['status'] ?? '')), ['cancelled', 'canceled', 'refunded'], true)) { $reason = 'iptal edilmiş'; return null; }
    if (!empty($so['refunds'])) { $reason = 'iade var'; return null; }
    $lines = array_values($so['lineItems'] ?? []);
    if (count($lines) !== 1 || (string) ($lines[0]['productId'] ?? '') !== $productId) { $reason = 'siparişte başka ürünler var'; return null; }
    if ((int) ($lines[0]['quantity'] ?? 1) !== 1) { $reason = 'adet 1 değil'; return null; }
    if (!isset($so['totals']['total']) || !is_numeric($so['totals']['total'])) { $reason = 'tutar bilgisi yok'; return null; }
    $paid = (float) $so['totals']['total'];
    if (isset($lines[0]['total']) && is_numeric($lines[0]['total'])) $paid = min($paid, (float) $lines[0]['total']);
    if ($paid <= 0 || $paid + 0.01 < $expected) { $reason = "eksik tutar ($paid < $expected)"; return null; }
    return $paid;
}

/** Shopier siparişi ödendikten sonra iade/iptal edildiyse true. */
function shopier_order_revoked(array $so): bool
{
    return ($so['paymentStatus'] ?? '') !== 'paid' || !empty($so['refunds'])
        || in_array(strtolower((string) ($so['status'] ?? '')), ['cancelled', 'canceled', 'refunded'], true);
}

final class ShopierClient
{
    private const BASE = 'https://api.shopier.com/v1';

    public function __construct(private string $pat)
    {
        if ($pat === '') {
            throw new InvalidArgumentException('SHOPIER_PAT tanımlı değil');
        }
    }

    public static function fromEnv(): self
    {
        return new self((string) getenv('SHOPIER_PAT'));
    }

    /** @return array{status:int, data:mixed} */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::BASE . $path);
        $headers = ['Authorization: Bearer ' . $this->pat, 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Shopier isteği başarısız: ' . $error);
        }
        return ['status' => $status, 'data' => json_decode((string) $raw, true)];
    }

    /**
     * Tek kullanımlık dijital ürün = ödeme bağlantısı.
     * Dönen dizide 'id' (ürün kimliği) ve 'url' (ödeme sayfası) bulunur.
     */
    public function createPaymentProduct(string $title, float $price, string $currency = 'TRY', ?string $imageUrl = null): array
    {
        $body = [
            'title'         => $title,
            'description'   => $title,
            'type'          => 'digital',
            'priceData'     => ['currency' => $currency, 'price' => round($price, 2)],
            'stockStatus'   => 'inStock',
            'stockQuantity' => 1,
        ];
        if ($imageUrl) {
            $body['media'] = [['url' => $imageUrl, 'type' => 'image', 'placement' => 1]];
        }
        $r = $this->request('POST', '/products', $body);
        if ($r['status'] < 200 || $r['status'] >= 300 || empty($r['data']['id'])) {
            throw new RuntimeException('Ürün oluşturulamadı: HTTP ' . $r['status'] . ' ' . json_encode($r['data']));
        }
        return $r['data'];
    }

    public function getOrder(string $orderId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderId)) return null;
        $r = $this->request('GET', '/orders/' . rawurlencode($orderId));
        if ($r['status'] !== 200 || !is_array($r['data'])) return null;
        $order = $r['data']['data'] ?? $r['data'];
        return is_array($order) && (string) ($order['id'] ?? '') === $orderId ? $order : null;
    }

    /** Son siparişler (yeniden eskiye). */
    public function listOrders(int $limit = 50): array
    {
        $r = $this->request('GET', '/orders?limit=' . $limit);
        return $r['status'] === 200 && is_array($r['data']) ? $r['data'] : [];
    }

    public function createWebhook(string $event, string $url): array
    {
        $r = $this->request('POST', '/webhooks', ['event' => $event, 'url' => $url]);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new RuntimeException('Webhook oluşturulamadı: HTTP ' . $r['status'] . ' ' . json_encode($r['data']));
        }
        return $r['data'];   // 'token' yalnızca bu yanıtta bir kez gelir
    }

    public function listWebhooks(): array
    {
        $r = $this->request('GET', '/webhooks');
        return $r['status'] === 200 && is_array($r['data']) ? $r['data'] : [];
    }

    public function deleteWebhook(string $id): bool
    {
        return $this->request('DELETE', '/webhooks/' . rawurlencode($id))['status'] < 300;
    }
}
