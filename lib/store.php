<?php
/**
 * Örnek veri deposu (SQLite): siparişler, abonelikler ve aylık faturalar.
 * Kendi veritabanınızla değiştirin. storage/ klasörünü web'e kapatın.
 */

declare(strict_types=1);
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/receipt.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = getenv('APP_DB') ?: __DIR__ . '/../storage/app.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
        id                 TEXT PRIMARY KEY,
        email              TEXT NOT NULL,
        name               TEXT NOT NULL DEFAULT "",
        title              TEXT NOT NULL,
        amount             REAL NOT NULL,
        status             TEXT NOT NULL DEFAULT "pending",   -- pending | awaiting_payment | paid
        shopier_product_id TEXT,
        shopier_order_id   TEXT,
        created_at         TEXT DEFAULT CURRENT_TIMESTAMP,
        paid_at            TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS subscriptions (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id   TEXT,
        email      TEXT NOT NULL,
        name       TEXT NOT NULL DEFAULT "",
        title      TEXT NOT NULL,
        price      REAL NOT NULL,                  -- aylık ücret
        status     TEXT NOT NULL DEFAULT "active", -- active | suspended | cancelled
        start_date TEXT NOT NULL                   -- faturalandırma günü bu tarihe sabitlenir
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS invoices (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        subscription_id    INTEGER NOT NULL,
        number             TEXT NOT NULL UNIQUE,
        amount             REAL NOT NULL,
        due_date           TEXT NOT NULL,
        period             TEXT NOT NULL,
        status             TEXT NOT NULL DEFAULT "unpaid",  -- unpaid | paid | cancelled
        shopier_product_id TEXT,
        reminded_at        TEXT,
        paid_at            TEXT
    )');
    return $pdo;
}

/**
 * Shopier'de ödendiği bildirilen ürünü (sipariş ya da fatura) işler.
 * Aynı ödeme tekrar gelirse null döner (idempotent).
 */
function mark_product_paid(string $productId, string $shopierOrderId, ?float $paidTotal): ?string
{
    if ($productId === '') return null;
    $pdo = db();

    $st = $pdo->prepare('SELECT * FROM orders WHERE shopier_product_id = ?');
    $st->execute([$productId]);
    if ($order = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($order['status'] === 'paid' || !amount_ok($paidTotal, (float) $order['amount'], $order['id'])) return null;
        $up = $pdo->prepare('UPDATE orders SET status = "paid", shopier_order_id = ?, paid_at = CURRENT_TIMESTAMP WHERE id = ? AND status != "paid"');
        $up->execute([$shopierOrderId, $order['id']]);
        if ($up->rowCount() === 0) return null;
        on_order_paid(['shopier_order_id' => $shopierOrderId, 'paid_at' => gmdate('Y-m-d H:i:s')] + $order);
        return $order['id'];
    }

    $st = $pdo->prepare('SELECT i.*, s.email, s.name, s.title FROM invoices i JOIN subscriptions s ON s.id = i.subscription_id WHERE i.shopier_product_id = ?');
    $st->execute([$productId]);
    if ($inv = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($inv['status'] === 'paid' || !amount_ok($paidTotal, (float) $inv['amount'], $inv['number'])) return null;
        $up = $pdo->prepare('UPDATE invoices SET status = "paid", paid_at = CURRENT_TIMESTAMP WHERE id = ? AND status != "paid"');
        $up->execute([$inv['id']]);
        if ($up->rowCount() === 0) return null;
        $pdo->prepare('UPDATE subscriptions SET status = "active" WHERE id = ? AND status = "suspended"')->execute([$inv['subscription_id']]);
        send_notice($inv['email'], $inv['name'], 'Ödemeniz alındı — ' . $inv['number'], 'Ödemeniz alındı',
            $inv['number'] . ' numaralı faturanızın ödemesi alındı. Teşekkür ederiz.',
            ['Fatura' => $inv['number'], 'Dönem' => $inv['period'], 'Tutar' => money((float) $inv['amount'])], null,
            pdf_attachment(fn() => invoice_receipt_pdf((int) $inv['id'])));   // PDF fatura ekte
        return $inv['number'];
    }
    return null;
}

/** PDF üretimi başarısız olsa bile e-posta gider; hata log'a yazılır. */
function pdf_attachment(callable $make): array
{
    try {
        $a = $make();
        return $a ? [$a] : [];
    } catch (Throwable $e) {
        error_log('PDF: ' . $e->getMessage());
        return [];
    }
}

function amount_ok(?float $paid, float $expected, string $ref): bool
{
    if ($paid !== null && $paid > 0 && $paid + 0.01 < $expected) {
        error_log("Tutar uyuşmuyor: $ref ($paid < $expected)");
        return false;
    }
    return true;
}

function money(float $v): string
{
    return number_format($v, 2, ',', '.') . ' TL';
}

/**
 * Sipariş ödendiğinde çalışır. Örnek: aylık abonelik başlatır ve onay e-postası atar.
 * Kendi iş mantığınızı (hesap açma, lisans, teslimat) buraya yazın.
 */
function on_order_paid(array $order): void
{
    db()->prepare('INSERT INTO subscriptions (order_id, email, name, title, price, start_date) VALUES (?, ?, ?, ?, ?, date("now"))')
        ->execute([$order['id'], $order['email'], $order['name'], $order['title'], $order['amount']]);
    send_notice($order['email'], $order['name'] ?: 'Merhaba', 'Siparişiniz onaylandı — ' . $order['id'], 'Ödemeniz alındı',
        'Siparişiniz onaylandı. Aylık faturalarınız her ay aynı gün oluşturulur ve e-postayla bildirilir.',
        ['Sipariş' => $order['id'], 'Ürün' => $order['title'], 'Tutar' => money((float) $order['amount'])], null,
        pdf_attachment(fn() => order_receipt_pdf($order)));   // PDF fatura ekte
}
