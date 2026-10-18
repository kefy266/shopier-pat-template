<?php
/**
 * Bağımlılıksız SMTP gönderici (STARTTLS + AUTH LOGIN) ve HTML bildirim şablonu.
 *
 * Ortam değişkenleri: SMTP_HOST, SMTP_PORT (587), SMTP_USER, SMTP_PASS,
 * MAIL_FROM_NAME, MAIL_REPLY_TO, APP_URL (e-postadaki bağlantılar için).
 * Teslim edilebilirlik için gönderen alan adında SPF, DKIM ve DMARC kayıtları olmalıdır.
 */

declare(strict_types=1);

function smtp_send(string $to, string $subject, string $text, ?string $html = null, string $toName = '', array $attachments = []): bool
{
    $host = getenv('SMTP_HOST') ?: '';
    $user = getenv('SMTP_USER') ?: '';
    $pass = getenv('SMTP_PASS') ?: '';
    if ($host === '' || $user === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('mailer: SMTP ayarı eksik veya alıcı geçersiz');
        return false;
    }
    $port = (int) (getenv('SMTP_PORT') ?: 587);
    $s = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 15);
    if (!$s) { error_log("mailer: bağlantı hatası $errstr"); return false; }
    stream_set_timeout($s, 15);
    $read = function () use ($s): string {
        $out = '';
        while (($line = fgets($s, 515)) !== false) { $out .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
        return $out;
    };
    $cmd = function (string $c, array $ok) use ($s, $read): void {
        if ($c !== '') fwrite($s, $c . "\r\n");
        $r = $read();
        if (!in_array((int) substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($r));
    };
    try {
        $cmd('', [220]);
        $cmd('EHLO ' . gethostname(), [250]);
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS başlatılamadı');
        $cmd('EHLO ' . gethostname(), [250]);
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode($pass), [235]);
        $cmd("MAIL FROM:<$user>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        // Yalnızca ASCII dışı karakter içeren başlıklar kodlanır (gereksiz base64 spam puanını artırır)
        $enc = fn(string $v) => preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
        // Metin gövdesi quoted-printable; satır başındaki nokta kodlanır (SMTP DATA güvenliği)
        $qp = fn(string $s) => preg_replace('/^\./m', '=2E', quoted_printable_encode(preg_replace("/\r\n|\r|\n/", "\r\n", $s))) . "\r\n";
        $domain = substr(strrchr($user, '@'), 1);
        $h = [
            'From: ' . $enc(getenv('MAIL_FROM_NAME') ?: 'Bildirim') . " <$user>",
            'To: ' . ($toName !== '' ? $enc($toName) . ' ' : '') . "<$to>",
            'Reply-To: <' . (getenv('MAIL_REPLY_TO') ?: $user) . '>',
            'Subject: ' . $enc($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
            'MIME-Version: 1.0',
        ];
        // Gövde: düz metin (+ HTML alternatifi); ek varsa multipart/mixed içine alınır.
        if ($html === null) {
            $type = 'text/plain; charset=UTF-8';
            $cte = 'Content-Transfer-Encoding: quoted-printable';
            $content = $qp($text);
        } else {
            $b = 'b-' . bin2hex(random_bytes(8));
            $type = "multipart/alternative; boundary=\"$b\"";
            $cte = '';
            $content = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp($text)
                     . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $qp($html)
                     . "--$b--\r\n";
        }
        if (!$attachments) {
            $h[] = 'Content-Type: ' . $type;
            if ($cte !== '') $h[] = $cte;
            $body = $content;
        } else {
            $m = 'm-' . bin2hex(random_bytes(8));
            $h[] = "Content-Type: multipart/mixed; boundary=\"$m\"";
            $body = "--$m\r\nContent-Type: $type\r\n" . ($cte !== '' ? "$cte\r\n" : '') . "\r\n" . $content;
            foreach ($attachments as $a) {
                $n = '=?UTF-8?B?' . base64_encode($a['name']) . '?=';
                $body .= "--$m\r\nContent-Type: " . ($a['type'] ?? 'application/octet-stream') . "; name=\"$n\"\r\n"
                       . "Content-Disposition: attachment; filename=\"$n\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($a['content']));
            }
            $body .= "--$m--\r\n";
        }
        $cmd(implode("\r\n", $h) . "\r\n\r\n" . $body . "\r\n.", [250]);
        $cmd('QUIT', [221]);
        fclose($s);
        return true;
    } catch (Throwable $e) {
        error_log('mailer: ' . $e->getMessage());
        @fclose($s);
        return false;
    }
}

/** Sade HTML bildirim e-postası (başlık, metin, özet tablo, isteğe bağlı düğme). */
function send_notice(string $to, string $name, string $subject, string $title, string $message, array $rows = [], ?array $button = null, array $attachments = []): bool
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $text = "Merhaba $name,\n\n$message\n\n";
    $table = '';
    foreach ($rows as $k => $v) {
        $text .= "$k: $v\n";
        $table .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee;color:#737373">' . $e($k) . '</td>'
                . '<td align="right" style="padding:8px 0;border-bottom:1px solid #eee;font-weight:600">' . $e($v) . '</td></tr>';
    }
    if ($button) $text .= "\n{$button[0]}: {$button[1]}\n";
    $btn = $button ? '<p style="margin:24px 0 0"><a href="' . $e($button[1]) . '" style="background:#0a0a0a;color:#fff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600">' . $e($button[0]) . '</a></p>' : '';
    $html = '<div style="background:#fafafa;padding:32px 12px;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif">'
          . '<div style="max-width:540px;margin:0 auto;background:#fff;border:1px solid #e8e8e8;border-radius:10px;padding:28px;color:#0a0a0a">'
          . '<h1 style="margin:0 0 12px;font-size:21px">' . $e($title) . '</h1>'
          . '<p style="margin:0 0 6px;color:#3f3f3f">Merhaba ' . $e($name) . ',</p><p style="margin:0 0 16px;color:#3f3f3f;line-height:1.6">' . $e($message) . '</p>'
          . ($table ? '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:14px">' . $table . '</table>' : '') . $btn
          . '</div></div>';
    return smtp_send($to, $subject, $text, $html, $name, $attachments);
}
