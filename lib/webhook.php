<?php
/**
 * Shopier webhook imza doğrulama.
 *
 * Shopier her bildirimi "Shopier-Signature" başlığıyla HS256 (HMAC-SHA256) imzalar.
 * İmza anahtarı, webhook aboneliği oluşturulurken yanıtta bir kez dönen "token"dır
 * (scripts/register_webhook.php bunu storage/webhook.token dosyasına yazar).
 *
 * Belgeler imzanın hex mi base64 mü olduğunu belirtmediği için üç biçim de kabul edilir.
 */

declare(strict_types=1);

function shopier_webhook_token(): string
{
    $file = getenv('SHOPIER_WEBHOOK_TOKEN_FILE') ?: __DIR__ . '/../storage/webhook.token';
    return is_readable($file) ? trim((string) file_get_contents($file)) : (string) getenv('SHOPIER_WEBHOOK_TOKEN');
}

function shopier_signature_valid(string $rawBody, string $signatureHeader, string $token): bool
{
    if ($token === '' || $signatureHeader === '') {
        return false;
    }
    $sig = preg_replace('/^(sha256=|hmac-sha256=)/i', '', trim($signatureHeader));
    $mac = hash_hmac('sha256', $rawBody, $token, true);
    return hash_equals(bin2hex($mac), strtolower($sig))
        || hash_equals(base64_encode($mac), $sig)
        || hash_equals(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), rtrim($sig, '='));
}
