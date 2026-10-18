<?php
/**
 * Shopier webhook alıcısı (order.created).
 * 1) Shopier-Signature imzasını doğrular, 2) siparişin lineItems[].productId değerini
 * bizim siparişle eşleştirir, 3) siparişi "paid" yapar. Tekrar gelen bildirim yok sayılır.
 */

declare(strict_types=1);
require __DIR__ . '/../lib/webhook.php';
require __DIR__ . '/../lib/store.php';

header('Content-Type: application/json');
$raw = (string) file_get_contents('php://input');

if (!shopier_signature_valid($raw, $_SERVER['HTTP_SHOPIER_SIGNATURE'] ?? '', shopier_webhook_token())) {
    http_response_code(401);
    exit(json_encode(['error' => 'invalid signature']));
}

$event = json_decode($raw, true);
$order = is_array($event) ? ($event['data'] ?? $event) : null;
if (!is_array($order)) {
    http_response_code(400);
    exit(json_encode(['error' => 'invalid json']));
}
if (($order['paymentStatus'] ?? 'paid') !== 'paid') {
    exit(json_encode(['ok' => true, 'ignored' => 'not paid']));
}

$paid = isset($order['totals']['total']) ? (float) $order['totals']['total'] : null;
$processed = [];
foreach ($order['lineItems'] ?? [] as $item) {
    $id = mark_order_paid((string) ($item['productId'] ?? ''), (string) ($order['id'] ?? ''), $paid);
    if ($id) $processed[] = $id;
}
echo json_encode(['ok' => true, 'processed' => $processed]);
