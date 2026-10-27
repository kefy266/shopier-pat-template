<?php
/**
 * Yedek eşitleme: Webhook kaçarsa (sunucu kapalıydı vb.) Shopier'deki son ödenmiş
 * siparişleri çekip bekleyen siparişlerle eşleştirir. Cron ile 10 dakikada bir çalıştırın
 * (örnek satır README'de).
 */

declare(strict_types=1);
require __DIR__ . '/../lib/shopier.php';
require __DIR__ . '/../lib/store.php';

$count = 0;
foreach (ShopierClient::fromEnv()->allOrders() as $order) {
    $count += count(process_shopier_order($order));
}
echo "Eşitlendi: $count sipariş\n";
