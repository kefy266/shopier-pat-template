<?php
/**
 * Ödeme sayfası. Shopier ödeme sayfası yeni sekmede (Shopier'in kendi sayfasında) açılır;
 * kart bilgisi sunucunuza gelmez. Bu sekme açık kalır ve ödeme doğrulanınca güncellenir.
 *
 *   /pay.php?order=ORD-...   yeni sipariş (yalnızca siparişi oluşturan oturum)
 *   /pay.php?invoice=ID&t=…  aylık fatura (e-postadaki imzalı bağlantı)
 *
 * Neden iframe değil: Shopier'i gömülü açınca bazı tarayıcılar (Safari, Brave, mobil) üçüncü taraf
 * çerezlerini engelliyor ve Shopier "Hata kodu 1074 — sayfa görüntülenemiyor" gösteriyor.
 * Güvenlik farkı yok: ödeme her durumda sunucuda Shopier API'sinden doğrulanır (status.php / webhook).
 */

declare(strict_types=1);
require __DIR__ . '/../lib/shopier.php';
require __DIR__ . '/../lib/store.php';
require __DIR__ . '/../lib/links.php';
session_start();

$pdo = db();
if (isset($_GET['order'])) {
    $id = (string) $_GET['order'];
    if (!hash_equals((string) ($_SESSION['pay_order'] ?? ''), $id)) { http_response_code(403); exit('Yetkisiz'); }
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: exit('Sipariş bulunamadı');
    $kind = 'order'; $ref = $row['id']; $title = $row['title'] . ' (' . $row['id'] . ')'; $paid = $row['status'] === 'paid';
    $statusUrl = '/status.php?order=' . urlencode($id);
} else {
    $id = (int) ($_GET['invoice'] ?? 0);
    if (!invoice_link_valid($id, (string) ($_GET['t'] ?? ''))) { http_response_code(403); exit('Geçersiz bağlantı'); }
    $st = $pdo->prepare('SELECT i.*, s.title AS stitle FROM invoices i JOIN subscriptions s ON s.id = i.subscription_id WHERE i.id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: exit('Fatura bulunamadı');
    $kind = 'invoice'; $ref = $row['number']; $title = 'Fatura ' . $row['number'] . ' — ' . $row['stitle']; $paid = $row['status'] === 'paid';
    $statusUrl = '/status.php?invoice=' . $id . '&t=' . urlencode((string) $_GET['t']);
}
if ($paid) exit('Bu ödeme zaten alınmış. Teşekkürler.');

// Ödemeye özel tek kullanımlık Shopier ürünü (bir kez oluşturulur)
if (empty($row['shopier_product_id'])) {
    $sp = ShopierClient::fromEnv()->createPaymentProduct($title, (float) $row['amount']);
    $row['shopier_product_id'] = (string) $sp['id'];
    $table = $kind === 'order' ? 'orders' : 'invoices';
    $extra = $kind === 'order' ? ', status = "awaiting_payment"' : '';
    $pdo->prepare("UPDATE $table SET shopier_product_id = ?$extra WHERE id = ?")->execute([$row['shopier_product_id'], $row['id']]);
}
$payUrl = 'https://www.shopier.com/' . rawurlencode($row['shopier_product_id']);
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Ödeme</title>
<style>
  body { margin: 0; font: 15px/1.5 system-ui, sans-serif; background: #fafafa; color: #0a0a0a; }
  .wrap { max-width: 960px; margin: 0 auto; padding: 32px 16px; display: grid; grid-template-columns: 280px 1fr; gap: 20px; }
  .box { background: #fff; border: 1px solid #e8e8e8; border-radius: 10px; }
  .sum { padding: 20px; align-self: start; }
  .sum div { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; font-size: 14px; }
  .status { padding: 12px 16px; border-bottom: 1px solid #eee; font-size: 14px; background: #fafafa; }
  .pay { padding: 28px 20px; display: grid; gap: 14px; justify-items: start; }
  .btn { display: inline-block; background: #0a0a0a; color: #fff; text-decoration: none; padding: 13px 20px; border-radius: 8px; font-weight: 600; }
  .alt { font-size: 13px; color: #737373; }
  @media (max-width: 800px) { .wrap { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <div class="box sum">
    <strong>Özet</strong>
    <div><span>Ödeme</span><span><?= $e($ref) ?></span></div>
    <div><span>Tutar</span><span><?= money((float) $row['amount']) ?></span></div>
  </div>
  <div class="box">
    <div class="status" id="status">Ödemeniz bekleniyor.</div>
    <div class="pay" id="pay">
      <span>Ödeme Shopier'in güvenli sayfasında yeni sekmede açılır.</span>
      <a class="btn" href="<?= $e($payUrl) ?>" target="_blank" rel="noopener">Shopier'de öde · <?= money((float) $row['amount']) ?> ↗</a>
      <span class="alt">Ödemeyi yaptıktan sonra bu sekmeye dönün; ödeme doğrulanınca bu sayfa güncellenir.</span>
    </div>
  </div>
</div>
<script>
  setInterval(function () {
    fetch(<?= json_encode($statusUrl) ?>, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.paid) { document.getElementById('status').textContent = 'Ödemeniz alındı. Teşekkürler!'; document.getElementById('pay').remove(); } })
      .catch(function () {});
  }, 5000);
</script>
</body>
</html>
