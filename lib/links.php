<?php
/**
 * E-postadaki fatura ödeme bağlantıları için imza (giriş gerektirmeden güvenli bağlantı).
 * APP_SECRET ortam değişkeni uzun ve rastgele olmalıdır.
 */

declare(strict_types=1);

function invoice_link_token(int $invoiceId): string
{
    $secret = getenv('APP_SECRET') ?: '';
    if (strlen($secret) < 16) throw new RuntimeException('APP_SECRET tanımlı değil (en az 16 karakter)');
    return substr(hash_hmac('sha256', 'invoice:' . $invoiceId, $secret), 0, 32);
}

function invoice_link(int $invoiceId): string
{
    return rtrim((string) getenv('APP_URL'), '/') . '/pay.php?invoice=' . $invoiceId . '&t=' . invoice_link_token($invoiceId);
}

function invoice_link_valid(int $invoiceId, string $token): bool
{
    return $invoiceId > 0 && $token !== '' && hash_equals(invoice_link_token($invoiceId), $token);
}
