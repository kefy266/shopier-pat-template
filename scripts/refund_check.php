<?php
/**
 * İade / iptal takibi: son 45 günde ödenmiş sipariş ve faturaları Shopier'e yeniden sorar.
 * Ödeme Shopier'de iade ya da iptal edildiyse kayıt "refunded" olur, abonelik iptal/askıya alınır ve
 * ADMIN_EMAIL'e bildirim gider. "Öde → hizmeti al → iade iste" istismarını engeller.
 * Cron ile 30 dakikada bir çalıştırın (örnek satır README'de).
 */

declare(strict_types=1);
require __DIR__ . '/../lib/shopier.php';
require __DIR__ . '/../lib/store.php';

$client = ShopierClient::fromEnv();
$pdo = db();
$admin = (string) getenv('ADMIN_EMAIL');
$revoked = [];

$orders = $pdo->query("SELECT * FROM orders WHERE status = 'paid' AND shopier_order_id IS NOT NULL AND shopier_order_id != ''
                       AND paid_at >= datetime('now', '-45 days')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($orders as $o) {
    $so = $client->getOrder((string) $o['shopier_order_id']);
    if (!$so || !shopier_order_revoked($so)) continue;   // okunamadıysa bir sonraki çalışmada tekrar denenir
    $pdo->prepare('UPDATE orders SET status = "refunded" WHERE id = ? AND status = "paid"')->execute([$o['id']]);
    $pdo->prepare('UPDATE subscriptions SET status = "cancelled" WHERE order_id = ?')->execute([$o['id']]);
    $revoked[] = $o['id'];
}

$invoices = $pdo->query("SELECT * FROM invoices WHERE status = 'paid' AND shopier_order_id IS NOT NULL AND shopier_order_id != ''
                         AND paid_at >= datetime('now', '-45 days')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($invoices as $i) {
    $so = $client->getOrder((string) $i['shopier_order_id']);
    if (!$so || !shopier_order_revoked($so)) continue;
    $pdo->prepare('UPDATE invoices SET status = "refunded" WHERE id = ? AND status = "paid"')->execute([$i['id']]);
    $pdo->prepare('UPDATE subscriptions SET status = "suspended" WHERE id = ? AND status = "active"')->execute([$i['subscription_id']]);
    $revoked[] = $i['number'];
}

if ($revoked) {
    error_log('İade/iptal edilen ödemeler: ' . implode(', ', $revoked));
    if ($admin !== '') send_notice($admin, 'Yönetici', 'İade/iptal edilen ödemeler', 'İade/iptal edilen ödemeler',
        "Shopier'de iade ya da iptal edilmiş ödemeler bulundu; kayıtlar \"refunded\" yapıldı ve abonelikler durduruldu.",
        ['Kayıtlar' => implode(', ', $revoked)], null);
}
echo 'İade kontrolü: ' . count($revoked) . " kayıt\n";
