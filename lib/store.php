<?php
/**
 * Örnek sipariş deposu (SQLite). Kendi veritabanınızla değiştirin.
 * Not: storage/ klasörünü web sunucusunun dışında tutun ya da erişime kapatın.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = getenv('APP_DB') ?: __DIR__ . '/../storage/app.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
        id                 TEXT PRIMARY KEY,
        email              TEXT NOT NULL,
        title              TEXT NOT NULL,
        amount             REAL NOT NULL,
        status             TEXT NOT NULL DEFAULT "pending",   -- pending | awaiting_payment | paid
        shopier_product_id TEXT,
        shopier_order_id   TEXT,
        created_at         TEXT DEFAULT CURRENT_TIMESTAMP,
        paid_at            TEXT
    )');
    return $pdo;
}

/**
 * Ödemesi doğrulanan siparişi işaretler. Aynı sipariş tekrar gelirse false döner.
 * Kendi iş mantığınızı (hesap açma, e-posta, teslimat) on_order_paid() içine yazın.
 */
function mark_order_paid(string $productId, string $shopierOrderId, ?float $paidTotal): ?string
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM orders WHERE shopier_product_id = ?');
    $st->execute([$productId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order || $order['status'] === 'paid') return null;
    if ($paidTotal !== null && $paidTotal + 0.01 < (float) $order['amount']) {
        error_log("Tutar uyuşmuyor: {$order['id']} ($paidTotal < {$order['amount']})");
        return null;
    }
    $up = $pdo->prepare('UPDATE orders SET status = "paid", shopier_order_id = ?, paid_at = CURRENT_TIMESTAMP WHERE id = ? AND status != "paid"');
    $up->execute([$shopierOrderId, $order['id']]);
    if ($up->rowCount() === 0) return null;
    on_order_paid($order);
    return $order['id'];
}

function on_order_paid(array $order): void
{
    // Buraya kendi işlemlerinizi yazın: müşteri hesabı, e-posta, lisans, teslimat...
    error_log("Sipariş ödendi: {$order['id']} ({$order['amount']} TL)");
}
