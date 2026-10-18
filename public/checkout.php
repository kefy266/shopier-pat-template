<?php
/**
 * Örnek ödeme başlatma: siparişi kaydeder ve site içi ödeme sayfasına (pay.php) yönlendirir.
 * Kullanım: POST /checkout.php  product=basic&email=...&name=...
 * Tutar ve ürün bilgisi istemciden değil, sunucudaki fiyat listesinden gelir.
 */

declare(strict_types=1);
require __DIR__ . '/../lib/store.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST bekleniyor');
}

// ÖRNEK: sabit ürün listesi (aylık ücret) — tutarı asla istemciden almayın.
$PRODUCTS = [
    'basic' => ['title' => 'Temel Paket', 'amount' => 100.0],
    'pro'   => ['title' => 'Pro Paket',   'amount' => 250.0],
];
$key = (string) ($_POST['product'] ?? '');
$email = filter_var((string) ($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$name = trim((string) ($_POST['name'] ?? ''));
if (!isset($PRODUCTS[$key]) || !$email) {
    http_response_code(422);
    exit('Geçersiz ürün veya e-posta');
}

$orderId = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
db()->prepare('INSERT INTO orders (id, email, name, title, amount) VALUES (?, ?, ?, ?, ?)')
    ->execute([$orderId, $email, $name, $PRODUCTS[$key]['title'], $PRODUCTS[$key]['amount']]);

$_SESSION['pay_order'] = $orderId;   // ödeme sayfasını yalnızca bu oturum açabilir
header('Location: /pay.php?order=' . urlencode($orderId));
