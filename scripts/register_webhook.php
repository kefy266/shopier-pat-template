<?php
/**
 * Shopier'de order.created webhook aboneliği oluşturur ve imza anahtarını
 * storage/webhook.token dosyasına yazar (anahtar yalnızca bu yanıtta bir kez döner).
 *
 * Kullanım: SHOPIER_PAT=... php scripts/register_webhook.php https://siteniz.com/webhook.php
 */

declare(strict_types=1);
require __DIR__ . '/../lib/shopier.php';

$url = $argv[1] ?? '';
if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
    fwrite(STDERR, "Kullanım: php scripts/register_webhook.php https://siteniz.com/webhook.php\n");
    exit(1);
}
$shopier = ShopierClient::fromEnv();
foreach ($shopier->listWebhooks() as $w) {
    if (($w['url'] ?? '') === $url && ($w['event'] ?? '') === 'order.created') {
        echo "Zaten kayıtlı (id: {$w['id']}). Yeni imza anahtarı gerekiyorsa bu aboneliği silip betiği tekrar çalıştırın.\n";
        exit(0);
    }
}
$w = $shopier->createWebhook('order.created', $url);
$file = getenv('SHOPIER_WEBHOOK_TOKEN_FILE') ?: __DIR__ . '/../storage/webhook.token';
umask(077);
file_put_contents($file, (string) ($w['token'] ?? ''));
echo "Webhook oluşturuldu (id: {$w['id']}). İmza anahtarı kaydedildi: $file\n";
