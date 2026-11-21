<?php
/**
 * Smoke-тест форм (CLI).
 *
 * Запуск:
 *   php tests/forms_smoke.php BASE_URL=https://fw2.loc [MAILPIT_URL=http://127.0.0.1:8025] [ROOT=public] [INSECURE=1]
 * Параметры можно передать и переменными окружения.
 *
 * BASE_URL    — адрес сайта (обязателен).
 * MAILPIT_URL — API Mailpit; если задан, проверяется факт доставки и заголовки письма.
 * ROOT        — путь к public/ (по умолчанию ../public). Нужен для чтения security.log и
 *               для сброса счётчиков лимитов между кейсами (только локальный запуск).
 * INSECURE    — 1 (по умолчанию): не проверять TLS-сертификат локального сайта.
 *
 * Код возврата: 0 — все проверки пройдены, 1 — есть провалы.
 * Требует расширение curl.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "Требуется расширение curl\n");
    exit(2);
}

$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_contains($arg, '=')) {
        [$k, $v] = explode('=', $arg, 2);
        $opt[strtoupper($k)] = $v;
    }
}
$cfg = static fn(string $k, string $d = ''): string => (string)($opt[$k] ?? getenv($k) ?: $d);

$base     = rtrim($cfg('BASE_URL'), '/');
$mailpit  = rtrim($cfg('MAILPIT_URL'), '/');
$root     = rtrim($cfg('ROOT', dirname(__DIR__) . '/public'), '/\\');
$insecure = $cfg('INSECURE', '1') !== '0';
if ($base === '') {
    fwrite(STDERR, "Укажите BASE_URL=https://example.test\n");
    exit(2);
}

$GLOBALS['__fails'] = 0;
$GLOBALS['__skips'] = 0;

function check(string $name, bool $ok, string $details = ''): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($ok || $details === '' ? '' : ' — ' . $details) . "\n";
    if (!$ok) {
        $GLOBALS['__fails']++;
    }
}

function skip(string $name, string $why): void
{
    echo '[SKIP] ' . $name . ' — ' . $why . "\n";
    $GLOBALS['__skips']++;
}

/** HTTP-запрос; возвращает [status, headers(lowercase), body]. */
function http(string $method, string $url, array $post = [], string $jar = '', array $headers = []): array
{
    global $insecure;
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
            if (str_contains($line, ':')) {
                [$n, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($n))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $respHeaders, is_string($body) ? $body : ''];
}

function newJar(): string
{
    $f = tempnam(sys_get_temp_dir(), 'fsmoke');
    return $f === false ? '' : $f;
}

/** Загружает страницу формы и возвращает токены клиента. */
function openForm(string $jar): array
{
    global $base;
    [$st, , $html] = http('GET', $base . '/contact', [], $jar);
    $csrf = preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
    $hp = preg_match('/name="(_f_[a-f0-9]+)"/', $html, $m2) ? $m2[1] : '';
    return ['status' => $st, 'csrf' => $csrf, 'hp' => $hp, 'shown' => time()];
}

function validData(array $t, array $over = []): array
{
    return array_merge([
        '_csrf'   => $t['csrf'],
        'name'    => 'Тест Тестов',
        'email'   => 'visitor@example.com',
        'phone'   => '+70000000000',
        'message' => 'Это тестовое сообщение для проверки формы.',
    ], $over);
}

function waitFill(array $t, int $seconds = 4): void
{
    $left = $seconds - (time() - $t['shown']);
    if ($left > 0) {
        sleep($left);
    }
}

function postForm(string $jar, array $data, string $path = '/form/contact', array $headers = []): array
{
    global $base;
    [$st, $h, $body] = http('POST', $base . $path, $data, $jar, $headers);
    return [$st, $h, json_decode($body, true), $body];
}

/** Сброс счётчиков лимитов между кейсами (локальный запуск). */
function resetLimits(): bool
{
    global $root;
    $dir = $root . '/storage/cache';
    if (!is_dir($dir)) {
        return false;
    }
    foreach (glob($dir . '/form-rate-*.json') ?: [] as $f) {
        @unlink($f);
    }
    @unlink($dir . '/mail-rate-limits.json');
    return true;
}

// ---------- Mailpit ----------
function mp(string $method, string $path): ?array
{
    global $mailpit;
    [$st, , $body] = http($method, $mailpit . $path);
    $data = json_decode($body, true);
    return $st >= 200 && $st < 300 ? (is_array($data) ? $data : []) : null;
}

function mpCount(): int
{
    $r = mp('GET', '/api/v1/messages');
    return (int)($r['total'] ?? 0);
}

function mpWait(int $expected, int $seconds = 6): int
{
    $n = mpCount();
    for ($i = 0; $i < $seconds && $n < $expected; $i++) {
        sleep(1);
        $n = mpCount();
    }
    return $n;
}

function mpLastRaw(): string
{
    global $mailpit;
    $r = mp('GET', '/api/v1/messages?limit=1');
    $id = $r['messages'][0]['ID'] ?? '';
    if ($id === '') {
        return '';
    }
    [, , $raw] = http('GET', $mailpit . '/api/v1/message/' . rawurlencode((string)$id) . '/raw');
    return $raw;
}

$hasMail = $mailpit !== '';
$canReset = is_dir($root . '/storage/cache');
$secLog = $root . '/storage/logs/security.log';

echo "== forms smoke: {$base} ==\n";
$canReset ? resetLimits() : null;
if ($hasMail) {
    mp('DELETE', '/api/v1/messages');
}

// 0. Страница формы выдаёт токен, GET на endpoint — 404
$jarA = newJar();
$ta = openForm($jarA);
check('страница /contact отдаёт токен и honeypot', $ta['status'] === 200 && $ta['csrf'] !== '' && $ta['hp'] !== '', 'status=' . $ta['status']);
[$st] = http('GET', $base . '/form/contact');
check('GET /form/contact → 404', $st === 404, 'status=' . $st);

// 1. Токен
waitFill($ta);
[$st] = postForm($jarA, validData($ta, ['_csrf' => '']));
check('нет токена → 403', $st === 403, 'status=' . $st);
[$st] = postForm($jarA, validData($ta, ['_csrf' => str_repeat('a', 64)]));
check('неверный токен → 403', $st === 403, 'status=' . $st);
$jarB = newJar();
openForm($jarB);
[$st] = postForm($jarB, validData($ta));
check('токен из другой сессии → 403', $st === 403, 'status=' . $st);

// 2. Origin
[$st] = postForm($jarA, validData($ta), '/form/contact', ['Origin: https://evil.example']);
check('чужой Origin → 403', $st === 403, 'status=' . $st);

// 3. Слишком быстро + submit_time игнорируется
$tf = openForm($jarA);
[$st, , $j] = postForm($jarA, validData($tf));
check('отправка быстрее минимального времени → отказ', $st === 422 || $st === 429, 'status=' . $st);
$tf = openForm($jarA);
[$st] = postForm($jarA, validData($tf, ['submit_time' => '0', 'shown_at' => '1', '_submit_time' => '0']));
check('submit_time из запроса не влияет (всё равно отказ)', $st === 422 || $st === 429, 'status=' . $st);

// 4. Сообщение слишком длинное → 422 (и корректная валидация)
$canReset ? resetLimits() : null;
$tl = openForm($jarA);
waitFill($tl);
[$st, , $j] = postForm($jarA, validData($tl, ['message' => str_repeat('x', 5001)]));
check('слишком длинное сообщение → 422', $st === 422 && isset($j['errors']->message) || ($st === 422 && !empty($j['errors']['message'])), 'status=' . $st);

// 5. CR/LF в email → 422
$canReset ? resetLimits() : null;
$te = openForm($jarA);
waitFill($te);
[$st] = postForm($jarA, validData($te, ['email' => "a@example.com\r\nBcc: x@evil.test"]));
check('CR/LF в email → 422', $st === 422, 'status=' . $st);

// 6. Корректная отправка + CRLF в name
$canReset ? resetLimits() : null;
$before = $hasMail ? mpCount() : 0;
$tv = openForm($jarA);
waitFill($tv);
[$st, , $j] = postForm($jarA, validData($tv, ['name' => "Bob\r\nBcc: x@evil.test"]));
check('корректная отправка → 200 success=true', $st === 200 && ($j['success'] ?? false) === true, 'status=' . $st);
if ($hasMail) {
    $n = mpWait($before + 1);
    check('письмо получено (Mailpit)', $n === $before + 1, "было {$before}, стало {$n}");
    $raw = mpLastRaw();
    check('CR/LF в name не добавил заголовков', $raw !== '' && !preg_match('/^Bcc:/mi', $raw));
    check('в письме нет X-Mailer', $raw !== '' && !preg_match('/^X-Mailer:/mi', $raw));
    check('в письме есть Date, Message-ID, multipart/alternative',
        (bool)preg_match('/^Date:/mi', $raw) && (bool)preg_match('/^Message-ID:/mi', $raw) && stripos($raw, 'multipart/alternative') !== false);
    check('в письме нет упоминаний фреймворка', stripos($raw, 'rameva') === false);
} else {
    skip('проверка письма и заголовков', 'MAILPIT_URL не задан');
}

// 7. Honeypot
$canReset ? resetLimits() : null;
$th = openForm($jarA);
waitFill($th);
$beforeHp = $hasMail ? mpCount() : 0;
$logBefore = is_file($secLog) ? (int)filesize($secLog) : -1;
[$st, , $j] = postForm($jarA, validData($th, [$th['hp'] => 'bot']));
check('honeypot → 200 success=true', $st === 200 && ($j['success'] ?? false) === true, 'status=' . $st);
if ($hasMail) {
    sleep(2);
    check('honeypot: письмо не отправлено', mpCount() === $beforeHp);
} else {
    skip('honeypot: письмо не отправлено', 'MAILPIT_URL не задан');
}
if ($logBefore >= 0 || is_file($secLog)) {
    clearstatcache();
    $tail = (string)@file_get_contents($secLog, false, null, max(0, $logBefore));
    check('honeypot записан в security.log', stripos($tail, 'honeypot') !== false);
} else {
    skip('honeypot записан в security.log', 'security.log недоступен локально (ROOT)');
}

// 8. POST не получает 301
foreach (['/FORM/contact', '/Form/contact/', '/form/contact.html'] as $p) {
    [$st] = http('POST', $base . $p, ['x' => '1'], $jarA);
    check("POST {$p} без 301/302", $st !== 301 && $st !== 302, 'status=' . $st);
}

// 9. Лимиты: 429 и снятие блокировки по истечении времени
if ($canReset) {
    resetLimits();
    $limit = 5;
    $got429 = false;
    $retry = '';
    for ($i = 0; $i <= $limit + 1 && !$got429; $i++) {
        resetLimitsMail();
        $t = openForm($jarA);
        waitFill($t);
        [$st, $h] = postForm($jarA, validData($t));
        if ($st === 429) {
            $got429 = true;
            $retry = $h['retry-after'] ?? '';
        }
    }
    check('превышение лимита формы → 429', $got429);
    check('429 содержит Retry-After', $got429 && $retry !== '', 'Retry-After=' . $retry);

    // Имитация истечения окна: сдвигаем метки времени назад.
    foreach (glob($root . '/storage/cache/form-rate-*.json') ?: [] as $f) {
        $times = json_decode((string)file_get_contents($f), true);
        if (is_array($times)) {
            file_put_contents($f, json_encode(array_map(static fn($x) => (int)$x - 7200, $times)));
        }
    }
    resetLimitsMail();
    $t = openForm($jarA);
    waitFill($t);
    [$st] = postForm($jarA, validData($t));
    check('блокировка снимается по истечении окна', $st === 200 || $st === 500, 'status=' . $st);

    // Лимиты MailService: min_interval блокирует вторую отправку подряд → 429 с Retry-After.
    @unlink($root . '/storage/mail-rate.state.php');
    $t1 = openForm($jarA);
    waitFill($t1);
    postForm($jarA, validData($t1));
    $t2 = openForm($jarA);
    waitFill($t2);
    [$st2, $h2, $j2] = postForm($jarA, validData($t2));
    check('лимит почты (min_interval) блокирует повтор → 429 с Retry-After', $st2 === 429 && ($h2['retry-after'] ?? '') !== '' && stripos((string)($j2['message'] ?? ''), 'smtp') === false, 'status=' . $st2 . ' retry=' . ($h2['retry-after'] ?? ''));
    resetLimits();
} else {
    skip('лимиты формы (429, снятие блокировки)', 'ROOT недоступен — нужен локальный запуск');
}

function resetLimitsMail(): void
{
    global $root;
    @unlink($root . '/storage/mail-rate.state.php');
}

@unlink($jarA);
@unlink($jarB);

echo "\nИтог: провалов {$GLOBALS['__fails']}, пропущено {$GLOBALS['__skips']}\n";
exit($GLOBALS['__fails'] > 0 ? 1 : 0);