# Shopier PAT Template

Shopier REST API v1 ile **Personal Access Token (PAT)** kullanarak ödeme almak için sade, bağımlılıksız bir PHP şablonu.

- Siparişe özel tek kullanımlık **ödeme bağlantısı** (dijital ürün) oluşturur.
- Ödemeyi **imzalı webhook** (`Shopier-Signature`, HMAC-SHA256) ile doğrular.
- Webhook kaçarsa **yedek eşitleme** betiği ödenmiş siparişleri sonradan yakalar.
- Aynı bildirim iki kez gelirse sipariş ikinci kez işlenmez (idempotent).

> PHP 8.1+, `curl` ve `pdo_sqlite` eklentileri. Framework veya Composer gerekmez.

## Akış

```
Müşteri ──► checkout.php ──► Shopier: POST /products (tek kullanımlık dijital ürün)
                │                         │
                │◄──────── ödeme URL'si ───┘
                ▼
        Shopier ödeme sayfası (kart bilgisi burada girilir, 3D Secure)
                │
                ▼
Shopier ──► webhook.php  (order.created, Shopier-Signature ile imzalı)
                │  lineItems[].productId ↔ orders.shopier_product_id
                ▼
          sipariş "paid" ──► on_order_paid()  (hesap, e-posta, teslimat…)
```

## Kurulum

1. **PAT oluşturun:** Shopier paneli → geliştirici / API bölümü. Gerekli yetkiler:
   `products:write`, `orders:read`. İsteğe bağlı: `refunds:write`, `discounts:write`.
2. **Ortam değişkenleri:** `.env.example` dosyasını referans alın.
   ```bash
   export SHOPIER_PAT="..."          # asla depoya eklemeyin
   ```
3. **Web kökü** olarak yalnızca `public/` klasörünü yayınlayın. `storage/` ve `lib/` web'den erişilememelidir.
4. **Webhook kaydı** (imza anahtarı `storage/webhook.token` dosyasına yazılır):
   ```bash
   php scripts/register_webhook.php https://siteniz.com/webhook.php
   ```
5. **Yedek eşitleme** (cron, 10 dakikada bir):
   ```cron
   */10 * * * * SHOPIER_PAT=... php /yol/scripts/sync_orders.php >> /var/log/shopier-sync.log 2>&1
   ```
6. `lib/store.php` içindeki `on_order_paid()` fonksiyonuna kendi iş mantığınızı yazın.

## Dosyalar

| Dosya | Görev |
|---|---|
| `lib/shopier.php` | API istemcisi: ürün (ödeme bağlantısı), sipariş, webhook işlemleri |
| `lib/webhook.php` | `Shopier-Signature` doğrulama |
| `lib/store.php` | Örnek SQLite sipariş deposu ve `mark_order_paid()` |
| `public/checkout.php` | Ödeme başlatma örneği |
| `public/webhook.php` | Webhook alıcısı |
| `scripts/register_webhook.php` | `order.created` aboneliği oluşturur |
| `scripts/sync_orders.php` | Webhook kaçarsa ödenmiş siparişleri eşitler |

## PAT ile neler yapılabilir, neler yapılamaz?

| Yapılabilir | Yapılamaz |
|---|---|
| Ödeme bağlantısı (dijital ürün) oluşturma | Kart bilgisini kendi sitenizde alıp çekim yapma |
| Siparişleri listeleme / ödeme durumunu sorgulama | Kart saklama (tokenization) |
| Webhook ile anlık ödeme bildirimi | Otomatik tekrarlayan (abonelik) çekim |
| İade başlatma (`refunds:write`) | |
| İndirim kodu oluşturma (`discounts:write`) | |
| Bakiye ve ödeme aktarımlarını görüntüleme | |

Kart bilgisi her zaman Shopier'in güvenli ödeme sayfasında girilir. Bu, sizi kart verisi işleme (PCI DSS) yükümlülüğünden korur.

## Güvenlik notları

- Tutarı **asla istemciden almayın**; sunucudaki fiyat listenizden belirleyin.
- Webhook imzası tutmayan istekleri işlemeyin (şablon 401 döndürür).
- Ödenen tutar sipariş tutarından düşükse sipariş işlenmez.
- `storage/` klasörünü (veritabanı, imza anahtarı) web'e kapatın. Nginx örneği:
  ```nginx
  location ~* \.(sqlite|db|token|log)$ { return 404; }
  ```

## Lisans

MIT
