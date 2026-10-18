<?php
/**
 * Shopier REST API v1 istemcisi (Personal Access Token ile).
 *
 * Belgeler: https://developer.shopier.com/reference
 * PAT, Shopier panelinden oluşturulur ve SHOPIER_PAT ortam değişkeninde tutulur.
 * Token'ı asla koda veya depoya yazmayın.
 */

declare(strict_types=1);

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
        $r = $this->request('GET', '/orders/' . rawurlencode($orderId));
        return $r['status'] === 200 ? $r['data'] : null;
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
