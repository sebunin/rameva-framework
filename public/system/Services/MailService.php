<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Services
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Services;

use System\Core\Events;
use System\Services\MailRateLimitedException;
use System\Services\MailRateState;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс MailService обеспечивает отправку почты без внешних зависимостей
 * с контролем лимитов и защитой от спама.
 */
class MailService
{
    /** @var array Конфигурация */
    private array $config;

    /**
     * Конструктор почтового сервиса.
     *
     * @param array|null $config Параметры конфигурации
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $configFile = APPPATH . '/Config/Mail.php';
            $config = file_exists($configFile) ? (require $configFile) : [];
            $localFile = APPPATH . '/Config/Local.php';
            if (is_file($localFile)) {
                $local = require $localFile;
                if (is_array($local)) $config = array_replace_recursive($config, $local);
            }
        }
        $this->config = $config;
    }

    /**
     * Отправляет электронное письмо.
     *
     * @param string $to Адрес получателя
     * @param string $subject Тема письма
     * @param string $body Содержимое письма (HTML или текст)
     * @param bool $isHtml Отправлять ли как HTML
     * @param array $headers Дополнительные заголовки
     * @return bool
     */
    public function send(string $to, string $subject, string $body, bool $isHtml = true, array $headers = [], ?string $textBody = null): bool
    {
        [$allowed, $retryAfter] = $this->checkRateLimits();
        if (!$allowed) {
            $this->recordFailure($to, $subject, 'rate_limited', 'Mail rate limit exceeded');
            throw new MailRateLimitedException($retryAfter);
        }
        $to = trim($to);
        $fromEmail = trim((string)($this->config['from_email'] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->recordFailure($to, $subject, 'invalid_address', 'Invalid recipient or sender address');
            return false;
        }
        $subject = $this->cleanHeader($subject);
        $headers = $this->cleanHeaders($headers);
        $replyTo = $headers['Reply-To'] ?? null;
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            unset($headers['Reply-To']);
        }
        $html = $isHtml ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $text = $textBody ?? ($isHtml ? trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : $body);
        $message = $this->buildMessage($to, $subject, $fromEmail, (string)($this->config['from_name'] ?? 'Site'), $headers, $text, $html);

        $mailer = strtolower((string)($this->config['mailer'] ?? 'smtp'));
        // SMTP — основной транспорт; если хост не задан (локальная разработка без SMTP),
        // переключаемся на запасной mail() вместо падения с ошибкой.
        if ($mailer === 'smtp' && trim((string)($this->config['smtp']['host'] ?? '')) === '') {
            $mailer = 'mail';
        }
        try {
            $ok = match ($mailer) {
                'smtp' => $this->sendViaSmtp($to, $fromEmail, $message),
                'mail' => $this->sendViaMail($to, $subject, $fromEmail, $message),
                'sendmail' => $this->sendViaSendmail($message),
                default => throw new \RuntimeException('Unsupported mail transport'),
            };
        } catch (\Throwable $e) {
            $this->recordFailure($to, $subject, 'transport_error', $e->getMessage());
            $ok = false;
        }
        if (!$ok) {
            $this->recordFailure($to, $subject, 'delivery_failed', 'Transport rejected the message');
            return false;
        }
        LogService::write('mail status=sent recipient_domain=' . $this->domainOf($to) . ' code=250', 'info', 'mail.log');
        Events::trigger('mail.sent', $to, $subject, 'sent');
        return true;
    }

    /**
     * Отправка через сокеты по протоколу SMTP.
     */
    private function sendViaSmtp(string $to, string $from, string $message): bool
    {
        $smtp = $this->config['smtp'] ?? [];
        $host = trim((string)($smtp['host'] ?? ''));
        $port = max(1, (int)($smtp['port'] ?? 587));
        $timeout = max(1, (int)($smtp['timeout'] ?? 10));
        $readTimeout = max(1, (int)($smtp['read_timeout'] ?? 15));
        if ($host === '') throw new \RuntimeException('SMTP host is not configured');
        $crypto = strtolower((string)($smtp['encryption'] ?? ''));
        // Port 465 conventionally uses implicit TLS unless explicitly overridden.
        if ($crypto === '' && $port === 465) {
            $crypto = 'ssl';
        }
        $verify = (bool)($this->config['verify_peer'] ?? true);
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ]]);
        $address = $crypto === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client($address . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) throw new \RuntimeException("SMTP connect failed ({$errno}): {$errstr}");
        stream_set_timeout($socket, $readTimeout);
        try {
            $this->expectSmtp($socket, [220]);
            $this->smtpCommand($socket, 'EHLO ' . $this->safeEhloName(), [250]);
            if ($crypto === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (@stream_socket_enable_crypto($socket, true, $method) !== true) throw new \RuntimeException('SMTP STARTTLS negotiation failed');
                $this->smtpCommand($socket, 'EHLO ' . $this->safeEhloName(), [250]);
            } elseif ($crypto !== '' && $crypto !== 'ssl') {
                throw new \RuntimeException('Unsupported SMTP encryption mode');
            }
            $user = (string)($smtp['user'] ?? '');
            $pass = (string)($smtp['pass'] ?? '');
            if ($user !== '' || $pass !== '') {
                $caps = strtoupper($this->smtpLastResponse);
                if (preg_match('/(?:^|[\s=])AUTH(?:\s|=)[^\r\n]*\bPLAIN\b/m', $caps)) {
                    $this->smtpCommand($socket, 'AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}"), [235]);
                } elseif (preg_match('/(?:^|[\s=])AUTH(?:\s|=)[^\r\n]*\bLOGIN\b/m', $caps)) {
                    $this->smtpCommand($socket, 'AUTH LOGIN', [334]);
                    $this->smtpCommand($socket, base64_encode($user), [334]);
                    $this->smtpCommand($socket, base64_encode($pass), [235]);
                } else {
                    throw new \RuntimeException('SMTP server does not offer AUTH LOGIN or PLAIN');
                }
            }
            $this->smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->smtpCommand($socket, 'DATA', [354]);
            $wire = str_replace(["\r\n", "\r"], "\n", $message);
            $wire = str_replace("\n", "\r\n", $wire);
            $wire = preg_replace('/(^|\r\n)\./', '$1..', $wire) ?? $wire;
            if (!str_ends_with($wire, "\r\n")) $wire .= "\r\n";
            $this->writeAll($socket, $wire . ".\r\n");
            $this->expectSmtp($socket, [250]);
            $this->smtpCommand($socket, 'QUIT', [221]);
            return true;
        } finally {
            fclose($socket);
        }
    }

    private string $smtpLastResponse = '';

    private function smtpCommand($socket, string $command, array $expected): string
    {
        if (str_contains($command, "\r") || str_contains($command, "\n")) {
            throw new \RuntimeException('SMTP command write failed');
        }
        $this->writeAll($socket, $command . "\r\n");
        return $this->expectSmtp($socket, $expected);
    }

    /** Write the complete SMTP payload even when the stream performs partial writes. */
    private function writeAll($socket, string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('SMTP write failed');
            }
            $offset += $written;
        }
    }

    private function expectSmtp($socket, array $expected): string
    {
        $response = '';
        $code = null;
        for ($i = 0; $i < 100; $i++) {
            $line = fgets($socket, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new \RuntimeException(!empty($meta['timed_out']) ? 'SMTP read timeout' : 'SMTP connection closed while reading');
            }
            $response .= $line;
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                $code = (int)$m[1];
                if ($m[2] === ' ') break;
            }
        }
        $this->smtpLastResponse = $response;
        if ($code === null || !in_array($code, $expected, true)) {
            throw new \RuntimeException('SMTP unexpected response: ' . trim($response));
        }
        return $response;
    }

    private function safeEhloName(): string
    {
        $host = (string)($_SERVER['SERVER_NAME'] ?? parse_url((string)($this->config['site_url'] ?? ''), PHP_URL_HOST) ?? 'localhost');
        return preg_match('/^[a-zA-Z0-9.-]+$/', $host) ? $host : 'localhost';
    }

    private function buildMessage(string $to, string $subject, string $fromEmail, string $fromName, array $headers, string $text, string $html): string
    {
        $domain = $this->domainOf($fromEmail);
        if ($domain === '') {
            $domain = 'localhost';
        }
        $boundary = '=_alt_' . bin2hex(random_bytes(18));
        $date = date(DATE_RFC2822);
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        $encodedName = '=?UTF-8?B?' . base64_encode($this->cleanHeader($fromName)) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($this->cleanHeader($subject)) . '?=';
        $replyTo = isset($headers['Reply-To']) ? trim((string)$headers['Reply-To']) : '';

        $lines = [
            'To: ' . $to,
            'From: ' . $encodedName . ' <' . $fromEmail . '>',
        ];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
            $lines[] = 'Reply-To: ' . $replyTo;
        }
        $lines[] = 'Subject: ' . $encodedSubject;
        $lines[] = 'Date: ' . $date;
        $lines[] = 'Message-ID: ' . $messageId;
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $lines[] = '';
        $lines[] = 'This is a multi-part message in MIME format.';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = rtrim(chunk_split(base64_encode($this->normalizeNewlines($text)), 76, "\r\n"));
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/html; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = rtrim(chunk_split(base64_encode($this->normalizeNewlines($html)), 76, "\r\n"));
        $lines[] = '--' . $boundary . '--';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function sendViaMail(string $to, string $subject, string $from, string $message): bool
    {
        [$headerBlock] = explode("\r\n\r\n", $message, 2) + [''];
        $body = substr($message, strlen($headerBlock) + 4);
        $headerBlock = preg_replace('/^To:.*\r\n/m', '', $headerBlock) ?? $headerBlock;
        $headerBlock = preg_replace('/^Subject:.*\r\n/m', '', $headerBlock) ?? $headerBlock;
        $encodedSubject = '=?UTF-8?B?' . base64_encode($this->cleanHeader($subject)) . '?=';
        return @mail($to, $encodedSubject, $body, $headerBlock, '-f' . escapeshellarg($from));
    }

    private function sendViaSendmail(string $message): bool
    {
        $path = trim((string)($this->config['sendmail_path'] ?? ''));
        if ($path === '' || !is_file($path) || !is_executable($path)) {
            throw new \RuntimeException('Configured sendmail binary is unavailable');
        }
        $pipes = [];
        $process = @proc_open([$path, '-i', '-t'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start sendmail process');
        }
        fwrite($pipes[0], $this->normalizeNewlines($message));
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new \RuntimeException('sendmail exited with status ' . $status . ($stderr !== '' ? ': ' . trim($stderr) : ''));
        }
        return true;
    }

    private function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    private function cleanHeaders(array $headers): array
    {
        $safe = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }
            $name = $this->cleanHeader($name);
            if ($name !== '') {
                $safe[$name] = $this->cleanHeader((string)$value);
            }
        }
        return $safe;
    }

    private function normalizeNewlines(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    private function domainOf(string $address): string
    {
        $at = strrpos($address, '@');
        return $at === false ? '' : strtolower(substr($address, $at + 1));
    }

    private function recordFailure(string $to, string $subject, string $code, string $reason): void
    {
        $domain = $this->domainOf($to);
        $safeCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $code) ?? 'error';
        LogService::write('mail status=failed recipient_domain=' . ($domain !== '' ? $domain : 'unknown') . ' code=' . $safeCode . ' reason=' . $this->cleanHeader($reason), 'error', 'mail.log');
        Events::trigger('mail.failed', $domain, $safeCode, $this->cleanHeader($reason));
    }

    private function checkRateLimits(): array
    {
        return MailRateState::checkAndRecord($this->config);
    }

    public function getLastRetryAfter(): int
    {
        return 0;
    }
}