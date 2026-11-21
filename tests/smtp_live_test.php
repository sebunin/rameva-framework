<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RAMEVA_CORE', true);
define('ROOTPATH', __DIR__ . '/../public');
define('APPPATH', ROOTPATH . '/app');
require __DIR__ . '/../public/system/Boot.php';
$to = $argv[1] ?? '';
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "usage: php tests/smtp_live_test.php user@example.com\n"); exit(2); }
$cfg = is_file(APPPATH . '/Config/Mail.php') ? (require APPPATH . '/Config/Mail.php') : [];
$loc = APPPATH . '/Config/Local.php';
if (is_file($loc)) { $l = (array)(require $loc); if (is_array($l)) $cfg = array_replace_recursive($cfg, $l); }
$host = (string)($cfg['smtp']['host'] ?? '');
$port = (int)($cfg['smtp']['port'] ?? 587);
$enc = (string)($cfg['smtp']['encryption'] ?? '');
$timeout = (int)($cfg['smtp']['timeout'] ?? 10);
$user = (string)($cfg['smtp']['user'] ?? '');
$pass = (string)($cfg['smtp']['pass'] ?? '');
$from = (string)($cfg['from_email'] ?? 'no-reply@fw2.loc');
$fromName = (string)($cfg['from_name'] ?? 'Site');
echo "SMTP test to $to from $from via $host:$port enc=$enc\n";
if ($host === '') { echo "FAIL: no SMTP host\n"; exit(1); }
$t0 = microtime(true);
try {
    $ctx = stream_context_create([]);
    $sock = $enc === 'ssl' ? stream_socket_client("ssl://$host:$port", $e, $es, $timeout, STREAM_CLIENT_CONNECT, $ctx) : stream_socket_client("tcp://$host:$port", $e, $es, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) throw new Exception("connect: $es ($e)");
    stream_set_timeout($sock, $timeout);
    $g = fgets($sock, 1024);
    if ($g === false || strpos($g, '220') !== 0) throw new Exception("greeting: " . trim($g));
    fwrite($sock, "EHLO fw2.loc\r\n");
    $r = fgets($sock, 1024);
    // SMTP multi-line responses start with "250-"; final line starts with "250 "
    while ($r !== false && strpos($r, '-') === 3 && strlen($r) > 3 && ctype_digit(substr($r, 0, 3))) {
        $r = fgets($sock, 1024);
    }
    if ($r === false || strpos($r, '250') !== 0) throw new Exception("EHLO: " . trim($r));
    echo "EHLO OK: " . trim($r) . "\n";
    $starttls = stripos($r, 'starttls') !== false;
    if ($enc === 'tls' || ($enc === '' && $starttls)) {
        fwrite($sock, "STARTTLS\r\n");
        $r = fgets($sock, 1024);
        if ($r === false || strpos($r, '220') !== 0) throw new Exception("STARTTLS: " . trim($r));
        $sock = stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$sock) throw new Exception("crypto");
        fwrite($sock, "EHLO fw2.loc\r\n");
        $r = fgets($sock, 1024);
    }
    $auth = stripos($r, 'auth') !== false;
    if ($user !== '' && $pass !== '' && $auth) {
        fwrite($sock, "AUTH LOGIN\r\n");
        $r = fgets($sock, 1024);
        if ($r === false || strpos($r, '334') !== 0) throw new Exception("AUTH start: " . trim($r));
        fwrite($sock, base64_encode($user) . "\r\n");
        $r = fgets($sock, 1024);
        if ($r === false || strpos($r, '334') !== 0) throw new Exception("user: " . trim($r));
        fwrite($sock, base64_encode($pass) . "\r\n");
        $r = fgets($sock, 1024);
        if ($r === false || strpos($r, '235') !== 0) throw new Exception("auth: " . trim($r));
        echo "AUTH OK\n";
    } else {
        echo "AUTH skipped\n";
    }
    fwrite($sock, "MAIL FROM:<$from>\r\n");
    $r = fgets($sock, 1024);
    while ($r !== false && strpos($r, '-') === 3 && strlen($r) > 3 && ctype_digit(substr($r, 0, 3))) {
        $r = fgets($sock, 1024);
    }
    if ($r === false || strpos($r, '250') !== 0) throw new Exception("MAIL FROM: " . trim($r));
    fwrite($sock, "RCPT TO:<$to>\r\n");
    $r = fgets($sock, 1024);
    while ($r !== false && strpos($r, '-') === 3 && strlen($r) > 3 && ctype_digit(substr($r, 0, 3))) {
        $r = fgets($sock, 1024);
    }
    if ($r === false || strpos($r, '250') !== 0) throw new Exception("RCPT TO: " . trim($r));
    fwrite($sock, "DATA\r\n");
    $r = fgets($sock, 1024);
    while ($r !== false && strpos($r, '-') === 3 && strlen($r) > 3 && ctype_digit(substr($r, 0, 3))) {
        $r = fgets($sock, 1024);
    }
    if ($r === false || strpos($r, '354') !== 0) throw new Exception("DATA start: " . trim($r));
    $msg = "Date: " . date('r') . "\r\nFrom: $fromName <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode('Test from Rameva 2') . "?=\r\n\r\nTest message.\r\n.\r\n";
    fwrite($sock, $msg);
    $r = fgets($sock, 1024);
    if ($r === false || strpos($r, '250') !== 0) throw new Exception("DATA end: " . trim($r));
    fwrite($sock, "QUIT\r\n");
    fgets($sock, 1024);
    fclose($sock);
    echo "Sent OK in " . number_format(microtime(true) - $t0, 2) . "s\n";
    exit(0);
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
