<?php
/**
 * Shopier webhook alıcısı (order.created).
 * 1) Shopier-Signature imzasını doğrular, 2) siparişin lineItems[].productId değerini
 * bizim sipariş ya da faturayla eşleştirir, 3) "paid" yapar. Tekrar gelen bildirim yok sayılır.
 */

declare(strict_types=1);
require __DIR__ . '/../lib/webhook.php';
require __DIR__ . '/../lib/shopier.php';
require __DIR__ . '/../lib/store.php';

header('Content-Type: application/json');
$raw = (string) file_get_contents('php://input');

if (!shopier_signature_valid($raw, $_SERVER['HTTP_SHOPIER_SIGNATURE'] ?? '', shopier_webhook_token())) {
    http_response_code(401);
    exit(json_encode(['error' => 'invalid signature']));
}

$event = json_decode($raw, true);
$body = is_array($event) ? ($event['data'] ?? $event) : null;
if (!is_array($body) || empty($body['id'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'invalid json']));
}

// Bildirim yalnızca hangi siparişin geldiğini söyler; karar Shopier API'sinden okunan kayda göre verilir.
$order = ShopierClient::fromEnv()->getOrder((string) $body['id']);
if (!$order) {
    http_response_code(503);   // Shopier yeniden dener
    exit(json_encode(['error' => 'order lookup failed']));
}
echo json_encode(['ok' => true, 'processed' => process_shopier_order($order)]);
