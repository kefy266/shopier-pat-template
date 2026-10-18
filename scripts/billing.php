<?php
/**
 * Aylık faturalandırma (cron ile saatte bir; tekrar çalıştırmak güvenlidir).
 *  1) Aktif/askıdaki aboneliklerin bir sonraki dönem faturasını vadeden DAYS_BEFORE gün önce oluşturur
 *     ve müşteriye ödeme bağlantılı e-posta gönderir. Vade, abonelik başlangıç gününe sabitlenir.
 *  2) Vadesine 1 gün kalan ödenmemiş faturalar için bir kez hatırlatma gönderir.
 *  3) Vadesinden SUSPEND_AFTER gün geçen faturanın aboneliğini askıya alır. Ödeme gelince otomatik açılır.
 * Kullanım: php scripts/billing.php [--dry-run]
 */

declare(strict_types=1);
require __DIR__ . '/../lib/store.php';
require __DIR__ . '/../lib/links.php';

const DAYS_BEFORE = 7;
const SUSPEND_AFTER = 3;

$dry = in_array('--dry-run', $argv, true);
$pdo = db();
$today = new DateTimeImmutable('today');
$log = ['created' => [], 'reminded' => [], 'suspended' => []];

// 1) Yeni dönem faturaları
foreach ($pdo->query("SELECT * FROM subscriptions WHERE status IN ('active','suspended')") as $s) {
    $start = new DateTimeImmutable($s['start_date']);
    $st = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE subscription_id = ?');
    $st->execute([$s['id']]);
    $n = (int) $st->fetchColumn() + 1;                       // ilk dönem siparişte ödendi
    $first = $start->modify('first day of +' . $n . ' month');
    $due = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $start->format('j'), (int) $first->format('t')));
    if ($due->modify('-' . DAYS_BEFORE . ' days') > $today) continue;
    if ($due < $today->modify('+3 days')) $due = $today->modify('+3 days');   // kaçırılmış dönem: süre tanı

    $number = 'INV-' . $due->format('Ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $log['created'][] = $number;
    if ($dry) continue;
    $pdo->prepare('INSERT INTO invoices (subscription_id, number, amount, due_date, period) VALUES (?, ?, ?, ?, ?)')
        ->execute([$s['id'], $number, $s['price'], $due->format('Y-m-d'), $due->format('Y-m')]);
    $id = (int) $pdo->lastInsertId();
    send_notice($s['email'], $s['name'] ?: 'Merhaba', 'Faturanız oluştu — ' . $number, 'Yeni faturanız oluştu',
        $s['title'] . ' aboneliğinizin ' . $due->format('Y-m') . ' dönemi faturası oluşturuldu.',
        ['Fatura' => $number, 'Tutar' => money((float) $s['price']), 'Son ödeme' => $due->format('d.m.Y')],
        ['Faturayı öde', invoice_link($id)]);
}

// 2) Hatırlatma ve 3) askıya alma
foreach ($pdo->query("SELECT i.*, s.email, s.name, s.status AS sstatus FROM invoices i JOIN subscriptions s ON s.id = i.subscription_id WHERE i.status = 'unpaid'") as $i) {
    $days = (int) $today->diff(new DateTimeImmutable($i['due_date']))->format('%r%a');
    if ($days >= 0 && $days <= 1 && empty($i['reminded_at'])) {
        $log['reminded'][] = $i['number'];
        if (!$dry) {
            $pdo->prepare('UPDATE invoices SET reminded_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$i['id']]);
            send_notice($i['email'], $i['name'] ?: 'Merhaba', 'Hatırlatma: son ödeme ' . date('d.m.Y', strtotime($i['due_date'])), 'Son ödeme tarihi yaklaşıyor',
                $i['number'] . ' numaralı faturanızın son ödeme tarihi ' . ($days === 0 ? 'bugün' : 'yarın') . '.',
                ['Fatura' => $i['number'], 'Tutar' => money((float) $i['amount'])], ['Faturayı öde', invoice_link((int) $i['id'])]);
        }
    }
    if ($days < -SUSPEND_AFTER && $i['sstatus'] === 'active') {
        $log['suspended'][] = $i['number'];
        if (!$dry) {
            $pdo->prepare("UPDATE subscriptions SET status = 'suspended' WHERE id = ?")->execute([$i['subscription_id']]);
            send_notice($i['email'], $i['name'] ?: 'Merhaba', 'Aboneliğiniz askıya alındı — ' . $i['number'], 'Aboneliğiniz askıya alındı',
                $i['number'] . ' numaralı fatura ödenmediği için aboneliğiniz askıya alındı. Ödeme yaptığınızda otomatik açılır.',
                ['Fatura' => $i['number'], 'Tutar' => money((float) $i['amount'])], ['Şimdi öde', invoice_link((int) $i['id'])]);
        }
    }
}
echo date('c') . ' ' . json_encode($log) . PHP_EOL;
