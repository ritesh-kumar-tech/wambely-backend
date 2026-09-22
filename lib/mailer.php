<?php
// Minimal SMTP client (STARTTLS + AUTH LOGIN) for sending real OTP/notification
// emails via Brevo — no Composer/PHPMailer dependency, matching this
// project's plain-PHP style. Credentials come from .env; see .env.example.

class MailerException extends \RuntimeException {}

/**
 * Sends a plain-text email. Throws MailerException on any failure (bad
 * credentials, network issue, rejected recipient) — callers decide whether
 * a failed send should block the request or just be logged.
 */
function send_email(string $to, string $subject, string $textBody): void {
    $host = (string) getenv('BREVO_SMTP_HOST') ?: 'smtp-relay.brevo.com';
    $port = (int) (getenv('BREVO_SMTP_PORT') ?: 587);
    $login = (string) getenv('BREVO_SMTP_LOGIN');
    $password = (string) getenv('BREVO_SMTP_PASSWORD');
    $fromEmail = (string) (getenv('BREVO_FROM_EMAIL') ?: $login);
    $fromName = (string) (getenv('BREVO_FROM_NAME') ?: 'Wambely');

    if ($login === '' || $password === '') {
        throw new MailerException('Email is not configured (BREVO_SMTP_LOGIN/BREVO_SMTP_PASSWORD missing).');
    }

    $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 15);
    if (!$socket) {
        throw new MailerException("Could not connect to mail server: $errstr");
    }

    try {
        smtp_expect($socket, '220');
        smtp_command($socket, "EHLO wambely.com", '250');
        smtp_command($socket, "STARTTLS", '220');

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new MailerException('TLS negotiation with mail server failed.');
        }

        smtp_command($socket, "EHLO wambely.com", '250');
        smtp_command($socket, "AUTH LOGIN", '334');
        smtp_command($socket, base64_encode($login), '334');
        smtp_command($socket, base64_encode($password), '235');

        smtp_command($socket, "MAIL FROM:<$fromEmail>", '250');
        smtp_command($socket, "RCPT TO:<$to>", '250');
        smtp_command($socket, "DATA", '354');

        $headers = [
            "Subject: $subject",
            "From: $fromName <$fromEmail>",
            "To: <$to>",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=utf-8",
        ];
        // Any line starting with a lone "." must be escaped per the SMTP
        // DATA-termination rule, or it (and everything after) gets truncated.
        $escapedBody = preg_replace('/^\./m', '..', $textBody);
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody . "\r\n.\r\n";
        fwrite($socket, $message);
        smtp_read($socket); // final "250 OK: queued" — not required to match a fixed code

        fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }
}

/** @return resource */
function smtp_read($socket): string {
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') break; // last line of a multi-line reply
    }
    return $response;
}

function smtp_expect($socket, string $expectedCode): void {
    $response = smtp_read($socket);
    if (!str_starts_with($response, $expectedCode)) {
        throw new MailerException("Unexpected SMTP response (wanted $expectedCode): $response");
    }
}

function smtp_command($socket, string $command, string $expectedCode): void {
    fwrite($socket, "$command\r\n");
    smtp_expect($socket, $expectedCode);
}
