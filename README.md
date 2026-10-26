# Shopier PAT Template

Shopier REST API v1 ile **Personal Access Token (PAT)** kullanarak ödeme ve **aylık abonelik** tahsilatı için sade, bağımlılıksız bir PHP şablonu.

- **Site içi ödeme:** Shopier ödeme sayfası sizin sayfanızın içinde açılır, müşteri siteden ayrılmaz. Kart bilgisi sunucunuza gelmez.
- **İmzalı webhook:** Ödeme `Shopier-Signature` (HMAC-SHA256) ile doğrulanır.
- **Üç katmanlı doğrulama:** Webhook + ödeme sayfasının canlı sorgusu + cron yedek eşitleme. Bildirim kaçsa bile ödeme işlenir.
- **Aylık faturalandırma:** Fatura oluşturma, hatırlatma, gecikmede askıya alma, ödemede otomatik açma.
- **HTML e-posta bildirimleri:** "Faturanız oluştu", "Son ödeme yarın", "Ödemeniz alındı", "Askıya alındı".
- **PDF fatura:** Ödeme alınınca fatura PDF'i otomatik üretilir ve e-postaya eklenir; imzalı bağlantıyla indirilebilir. Kütüphane gerekmez (TrueType alt küme gömme, Türkçe karakter desteği).
- Aynı ödeme iki kez gelirse ikinci kez işlenmez (idempotent).

> PHP 8.2+ (önerilen 8.3 veya üstü; canlıda 8.3 ile çalışıyor, 8.5 ile sözdizimi denetlendi), `curl`, `openssl`, `pdo_sqlite`. Framework veya Composer gerekmez.
> PHP 8.1 artık güvenlik yaması almıyor; 8.2 desteği 2026 sonunda bitiyor.

## Akış

```
checkout.php ──► pay.php ──► (ilk açılışta) Shopier: POST /products → tek kullanımlık ödeme ürünü
                   │
                   ├─ yeni sekme: Shopier ödeme sayfası (kart + 3D Secure)
                   └─ status.php her 5 sn: ödendi mi? (gerekirse Shopier'e doğrudan sorar)

Shopier ──► webhook.php (order.created, imzalı) ──► mark_product_paid()
                                                      ├─ sipariş → on_order_paid() → abonelik başlar
                                                      └─ fatura  → ödendi, askıdaki abonelik açılır

cron: scripts/billing.php      saatlik — yeni dönem faturası, hatırlatma, askıya alma
cron: scripts/sync_orders.php  10 dk   — webhook kaçarsa ödenmiş siparişleri eşitler
cron: scripts/refund_check.php 30 dk   — Shopier'de iade/iptal edilen ödemeleri geri alır
```

## Kurulum

1. **PAT:** Shopier paneli → geliştirici / API. Gerekli yetkiler: `products:write`, `orders:read`.
2. **Ortam değişkenleri:** `.env.example` dosyasını referans alın (`SHOPIER_PAT`, `APP_URL`, `APP_SECRET`, `SMTP_*`).
3. **Web kökü:** Yalnızca `public/` yayınlanmalı. `lib/`, `scripts/`, `storage/` web'den erişilemez olmalı.
4. **Webhook kaydı** (imza anahtarı `storage/webhook.token` dosyasına yazılır):
   ```bash
   php scripts/register_webhook.php https://siteniz.com/webhook.php
   ```
   > Shopier her olay türü için **tek** webhook adresine izin verir (ikinci kayıt `409 conflict` döner).
   > Aynı Shopier hesabını birden çok uygulama kullanıyorsa, webhook'u bir uygulamaya kaydedin; o uygulama
   > eşleştiremediği ödemeleri **aynı gövde ve `Shopier-Signature` başlığıyla** diğer uygulamaya iletsin.
   > Diğer uygulama aynı imza anahtarıyla doğrular. `sync_orders.php` yedeği yine de çalışır.
5. **Cron:**
   ```cron
   7 * * * *     cd /yol && php scripts/billing.php     >> /var/log/billing.log 2>&1
   */10 * * * *  cd /yol && php scripts/sync_orders.php >> /var/log/shopier-sync.log 2>&1
   */30 * * * *  cd /yol && php scripts/refund_check.php >> /var/log/shopier-iade.log 2>&1
   ```
6. İş mantığınızı `lib/store.php` içindeki `on_order_paid()` fonksiyonuna yazın.
7. **PDF fatura (isteğe bağlı ayarlar):** `SELLER_INFO` (satırlar `|` ile ayrılır), `PDF_FONT_REGULAR` / `PDF_FONT_BOLD`
   (varsayılan: sistemdeki Noto Sans / DejaVu Sans / Liberation Sans), `PDF_LOGO_SVG` (tek yollu SVG).

## Dosyalar

| Dosya | Görev |
|---|---|
| `lib/shopier.php` | API istemcisi: ödeme ürünü, sipariş, webhook |
| `lib/webhook.php` | `Shopier-Signature` doğrulama |
| `lib/store.php` | Örnek SQLite: siparişler, abonelikler, faturalar; `mark_product_paid()` |
| `lib/mailer.php` | SMTP gönderici + HTML bildirim şablonu |
| `lib/links.php` | E-postadaki fatura ödeme bağlantılarının imzası |
| `lib/pdf.php` | Bağımlılıksız PDF üretici (TrueType alt küme, vektör logo) ve fatura şablonu |
| `lib/receipt.php` | Sipariş / aylık fatura için PDF belge |
| `public/checkout.php` | Siparişi kaydeder, ödeme sayfasına yönlendirir |
| `public/pay.php` | Site içi ödeme sayfası (sipariş / fatura) |
| `public/status.php` | Canlı ödeme durumu |
| `public/webhook.php` | Webhook alıcısı |
| `public/invoice_pdf.php` | Fatura PDF'i (imzalı bağlantı) |
| `scripts/billing.php` | Aylık faturalandırma (`--dry-run` destekli) |
| `scripts/register_webhook.php` | `order.created` aboneliği |
| `scripts/sync_orders.php` | Yedek eşitleme |
| `scripts/refund_check.php` | İade / iptal takibi (ödemesi geri alınan hizmeti durdurur) |

## PAT ile neler yapılabilir, neler yapılamaz?

| Yapılabilir | Yapılamaz |
|---|---|
| Ödeme bağlantısı (dijital ürün) oluşturma | Kart bilgisini kendi formunuzla alıp çekim yapma |
| Ödeme sayfasını yeni sekmede açıp durumu kendi sitenizde izleme | Kart saklama (tokenization) |
| Siparişleri ve ödeme durumunu sorgulama | Otomatik tekrarlayan kart çekimi |
| Webhook ile anlık ödeme bildirimi | |
| İade, indirim kodu, bakiye, ödeme aktarımları (yetkiye göre) | |

Aylık abonelik bu yüzden "her ay fatura + ödeme bağlantısı" modeliyle çalışır: müşteri e-postadaki bağlantıdan birkaç saniyede öder.

## Ödeme doğrulama kuralları

Ödeme Shopier'in kendi sayfasında yapılır ve müşteri sitenize geri döner; bu yüzden **tarayıcıdan gelen hiçbir şeye güvenilmez.** Bir ödeme ancak şu kurallar sağlanırsa işlenir (`shopier_verify_order`):

- Webhook gövdesi yalnızca sipariş numarasını verir; sipariş **Shopier API'sinden yeniden okunur**, karar o kayda göre verilir.
- `paymentStatus` tam olarak `paid` olmalı (alan yoksa ödenmemiş sayılır); sipariş iptal edilmemiş, iade edilmemiş olmalı.
- Siparişte **yalnızca bu ödeme ürünü, 1 adet** bulunmalı.
- Ödenen tutar bilinmeli ve beklenen tutarı karşılamalı: **0 TL, %100 kupon, eksik tutar ya da tutar bilgisi olmayan bildirim reddedilir.**
- Ödeme sonrası dönüş/başarı sayfası ödemeyi asla onaylamaz; yalnızca veritabanındaki durumu gösterir.
- `refund_check.php`, ödendikten sonra Shopier'de iade/iptal edilen ödemeleri bulur ve hizmeti durdurur ("öde → al → iade et" istismarı).
- Shopier Modül API (api_pay4) dönüşü kullanılmaz: dönüşteki `status` alanı imzanın dışındadır ve müşterinin tarayıcısından gelir; başarısız bir ödemenin dönüşü `success` yapılarak tekrar gönderilebilir.

## Güvenlik ve teslim edilebilirlik

- Tutarı asla istemciden almayın; sunucudaki fiyat listesinden belirleyin.
- İmzası tutmayan webhook işlenmez (401). Ödenen tutar beklenenden düşükse işlenmez.
- Ödeme sayfası yalnızca siparişi oluşturan oturumda veya imzalı fatura bağlantısıyla açılır.
- `storage/` (veritabanı, imza anahtarı) web'e kapatılmalı:
  ```nginx
  location ~* \.(sqlite|db|token|log|env)$ { return 404; }
  ```
- E-postaların spam'e düşmemesi için gönderen alan adında **SPF**, **DKIM** ve **DMARC** kayıtları tanımlı olmalı. Gönderen sunucunun ters DNS (PTR) kaydı da ayarlanmalı.
- PDF belge, ödeme kaydı niteliğindedir; Türkiye'de resmi fatura için e-Arşiv / e-Fatura entegrasyonu (GİB veya özel entegratör) ayrıca gerekir.
- Shopier'i iframe içinde açmayın: Safari, Brave ve mobil tarayıcılar üçüncü taraf çerezlerini engellediği için Shopier "Hata kodu 1074" gösterebilir. `pay.php` ödemeyi yeni sekmede açar; durum takibi (status.php) bu sekmede çalışmaya devam eder.

## Lisans

MIT
