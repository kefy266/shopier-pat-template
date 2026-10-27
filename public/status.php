<?php
/**
 * Ödeme sayfasının canlı durum sorgusu. Ödeme hâlâ bekleniyorsa Shopier'e doğrudan da sorar
 * (webhook gecikse bile ödeme işlenir). Oturum başına en fazla 8 saniyede bir Shopier'e gidilir.
 */

declare(strict_types=1);
require __DIR__ . '/../lib/shopier.php';
require __DIR__ . '/../lib/store.php';
require __DIR__ . '/../lib/links.php';
session_start();
header('Content-Type: application/json');

$pdo = db();
if (isset($_GET['order'])) {
    if (!hash_equals((string) ($_SESSION['pay_order'] ?? ''), (string) $_GET['order'])) { http_response_code(403); exit('{}'); }
    $st = $pdo->prepare('SELECT status, shopier_product_id FROM orders WHERE id = ?');
    $st->execute([(string) $_GET['order']]);
} else {
    $id = (int) ($_GET['invoice'] ?? 0);
    if (!invoice_link_valid($id, (string) ($_GET['t'] ?? ''))) { http_response_code(403); exit('{}'); }
    $st = $pdo->prepare('SELECT status, shopier_product_id FROM invoices WHERE id = ?');
    $st->execute([$id]);
}
$row = $st->fetch(PDO::FETCH_ASSOC) ?: ['status' => '', 'shopier_product_id' => ''];
$paid = $row['status'] === 'paid';

if (!$paid && $row['shopier_product_id'] && time() - (int) ($_SESSION['last_check'] ?? 0) >= 8) {
    $_SESSION['last_check'] = time();
    session_write_close();
    try { $orders = ShopierClient::fromEnv()->allOrders(); } catch (Throwable $e) { $orders = []; error_log($e->getMessage()); }
    foreach ($orders as $o) {
        foreach ($o['lineItems'] ?? [] as $li) {
            if ((string) ($li['productId'] ?? '') === $row['shopier_product_id'] && process_shopier_order($o)) $paid = true;
        }
    }
}
echo json_encode(['paid' => $paid]);
