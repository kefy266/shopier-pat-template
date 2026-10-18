<?php
/**
 * Örnek ödeme başlatma: siparişi kaydeder, Shopier'de tek kullanımlık dijital ürün
 * (ödeme bağlantısı) açar ve müşteriyi Shopier ödeme sayfasına yönlendirir.
 *
 * Kullanım (örnek): POST /checkout.php  email=...&title=...&amount=...
 * Gerçek projede tutar ve ürün bilgisi istemciden değil, sunucudaki fiyat listenizden gelmelidir.
 */

declare(strict_types=1);
require __DIR__ . '/../lib/shopier.php';
require __DIR__ . '/../lib/store.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST bekleniyor');
}

// ÖRNEK: sabit ürün listesi — tutarı asla istemciden almayın.
$PRODUCTS = [
    'basic' => ['title' => 'Temel Paket', 'amount' => 100.0],
    'pro'   => ['title' => 'Pro Paket',   'amount' => 250.0],
];
$key = (string) ($_POST['product'] ?? '');
$email = filter_var((string) ($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
if (!isset($PRODUCTS[$key]) || !$email) {
    http_response_code(422);
    exit('Geçersiz ürün veya e-posta');
}

$orderId = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
$product = $PRODUCTS[$key];
db()->prepare('INSERT INTO orders (id, email, title, amount) VALUES (?, ?, ?, ?)')
    ->execute([$orderId, $email, $product['title'], $product['amount']]);

try {
    $shopier = ShopierClient::fromEnv();
    $sp = $shopier->createPaymentProduct($product['title'] . ' (' . $orderId . ')', $product['amount']);
    db()->prepare('UPDATE orders SET shopier_product_id = ?, status = "awaiting_payment" WHERE id = ?')
        ->execute([(string) $sp['id'], $orderId]);
    header('Location: ' . $sp['url']);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(502);
    echo 'Ödeme başlatılamadı, lütfen tekrar deneyin.';
}
