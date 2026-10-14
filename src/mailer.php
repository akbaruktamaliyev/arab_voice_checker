<?php
/**
 * QRaft — minimal SMTP mailer (no dependencies).
 *
 * Uses the SMTP settings configured in the admin panel. Returns true on send,
 * false when email is not configured or the send failed (callers degrade
 * gracefully — a reset still works if the user can reach the link another way,
 * but normally email is configured in production).
 */

declare(strict_types=1);

/** Is outgoing email configured? */
function mail_enabled(): bool
{
    return trim((string) setting('smtp_host', '')) !== ''
        && trim((string) setting('smtp_from', '')) !== '';
}

/**
 * Send an HTML email via the configured SMTP server.
 * Supports 'ssl' (implicit TLS), 'tls' (STARTTLS) and 'none'.
 */
function send_mail(string $to, string $subject, string $html): bool
{
    if (!mail_enabled()) {
        return false;
    }
    $host   = trim((string) setting('smtp_host'));
    $port   = (int) (setting('smtp_port', '587') ?: 587);
    $user   = (string) setting('smtp_user', '');
    $pass   = (string) setting('smtp_pass', '');
    $from   = trim((string) setting('smtp_from'));
    $secure = (string) setting('smtp_secure', 'tls');

    $transport = $secure === 'ssl' ? "ssl://$host" : $host;
    $fp = @stream_socket_client("$transport:$port", $errno, $errstr, 15);
    if (!$fp) {
        return false;
    }

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') { break; }
        }
        return $data;
    };
    $cmd = function (string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return $read();
    };

    $ok = static fn(string $r, string $code): bool => str_starts_with($r, $code);

    try {
        if (!$ok($read(), '220')) { fclose($fp); return false; }
        $ehlo = "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $cmd($ehlo);

        if ($secure === 'tls') {
            if (!$ok($cmd('STARTTLS'), '220')) { fclose($fp); return false; }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp); return false;
            }
            $cmd($ehlo);
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode($user));
            if (!$ok($cmd(base64_encode($pass)), '235')) { fclose($fp); return false; }
        }
        if (!$ok($cmd("MAIL FROM:<$from>"), '250')) { fclose($fp); return false; }
        if (!$ok($cmd("RCPT TO:<$to>"), '25')) { fclose($fp); return false; }
        if (!$ok($cmd('DATA'), '354')) { fclose($fp); return false; }

        $headers = "From: " . app_name() . " <$from>\r\n"
            . "To: <$to>\r\n"
            . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        // Dot-stuff lines beginning with a period, per RFC 5321.
        $body = preg_replace('/^\./m', '..', $html);
        $sent = $ok($cmd($headers . $body . "\r\n."), '250');
        $cmd('QUIT');
        fclose($fp);
        return $sent;
    } catch (Throwable $e) {
        @fclose($fp);
        return false;
    }
}
