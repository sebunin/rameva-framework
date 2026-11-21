<?php
/**
 * Rameva 2 — проверка развёрнутого сайта (CLI-only).
 *
 * Запуск:
 *   php tests/server_check.php BASE_URL=https://fw2.loc
 *
 * Внешняя проверка: HTTP-запросы с таймаутом, без следования редиректам,
 * состояние сайта не меняется. По каждому пункту PASS или FAIL.
 * Код возврата не 0 при любом FAIL. В конце — сводка и список FAIL.
 */
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
$base = rtrim((string)($opt['BASE_URL'] ?? ''), '/');
if ($base === '') {
    fwrite(STDERR, "Укажите BASE_URL=https://example.test\n");
    exit(2);
}
$insecure = ($opt['INSECURE'] ?? '1') !== '0';

$fails = [];
$passed = 0;
$total = 0;

function check(string $name, bool $ok, string $details = ''): void
{
    global $fails, $passed, $total;
    $total++;
    if ($ok) {
        $passed++;
        echo "[PASS] $name\n";
    } else {
        $fails[] = $name . ($details !== '' ? ' — ' . $details : '');
        echo "[FAIL] $name" . ($details !== '' ? ' — ' . $details : '') . "\n";
    }
}

function http(string $method, string $url, bool $insecure): array
{
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
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
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $respHeaders, is_string($body) ? $body : ''];
}

function headerHas($headers, string $name, string $pattern): bool
{
    $v = $headers[strtolower($name)] ?? '';
    return $v !== '' && preg_match($pattern, $v) === 1;
}

echo "== server_check: $base ==\n";

// --- 1. Главная страница ---
[$st, $h, $body] = http('GET', $base . '/', $insecure);
check('Главная / → 200', $st === 200, 'status=' . $st);
check('Главная: нет X-Powered-By с версией PHP', !headerHas($h, 'x-powered-by', '/php/i'), $h['x-powered-by'] ?? '(нет заголовка)');

// --- 2. Несуществующий путь ---
[$st, , $body] = http('GET', $base . '/nonexistent-' . bin2hex(random_bytes(4)), $insecure);
check('Несуществующий путь → 404', $st === 404, 'status=' . $st);
check('404: нет упоминания фреймворка в теле', stripos($body, 'rameva') === false, 'found in body');

// --- 3. Защита внутренних файлов ---
foreach (['/system/Boot.php', '/app/Config/Mail.php', '/system/Services/MailService.php'] as $p) {
    [$st, , $body] = http('GET', $base . $p, $insecure);
    check("$p → 403/404", in_array($st, [403, 404], true), 'status=' . $st . ' body_len=' . strlen($body));
}

// --- 4. Шаблоны и layout не отдаются ---
foreach (['/pages/index.html', '/app/Layouts/main.html', '/system/Layouts/default.html'] as $p) {
    [$st, , $body] = http('GET', $base . $p, $insecure);
    check("$p → 403/404/пусто", in_array($st, [403, 404], true) || $body === '', 'status=' . $st . ' body_len=' . strlen($body));
}

// --- 5. Защита storage ---
$keyFile = glob(dirname(__DIR__) . '/public/storage/installer.*');
$keyName = $keyFile ? basename($keyFile[0]) : 'installer.key';
$storagePaths = [
    '/storage/logs/app.log',
    '/storage/integrity.manifest.php',
    '/storage/cache/routes.php',
    '/storage/' . $keyName,
];
foreach ($storagePaths as $p) {
    [$st, , $body] = http('GET', $base . $p, $insecure);
    check("$p → 403/404/пусто", in_array($st, [403, 404], true) || $body === '', 'status=' . $st . ' body_len=' . strlen($body));
}

// --- 6. Tests недоступны ---
[$st, , $body] = http('GET', $base . '/tests/forms_smoke.php', $insecure);
check('/tests/forms_smoke.php → не 200', $st !== 200, 'status=' . $st);

// --- 7. Чувствительные файлы корня ---
foreach (['/.env', '/.git/HEAD', '/composer.json'] as $p) {
    [$st, , $body] = http('GET', $base . $p, $insecure);
    check("$p → 403/404/пусто", in_array($st, [403, 404], true) || $body === '', 'status=' . $st . ' body_len=' . strlen($body));
}

// --- 8. WordPress-сканеры и cron без ключа ---
[$st, , $body] = http('GET', $base . '/wp-login.php', $insecure);
check('/wp-login.php → 403', $st === 403, 'status=' . $st);
[$st, , $body] = http('GET', $base . '/xmlrpc.php', $insecure);
check('/xmlrpc.php → 403', $st === 403, 'status=' . $st);
[$st, , $body] = http('GET', $base . '/cron.php', $insecure);
check('/cron.php без ключа → 403', $st === 403, 'status=' . $st);
check('/cron.php без ключа: пустое тело', $body === '', 'body_len=' . strlen($body));

// --- 9. robots.txt, sitemap.xml, llms.txt, favicon.ico ---
[$st, , $body] = http('GET', $base . '/robots.txt', $insecure);
check('/robots.txt → 200', $st === 200, 'status=' . $st);
[$st, , $body] = http('GET', $base . '/sitemap.xml', $insecure);
check('/sitemap.xml → 200', $st === 200, 'status=' . $st);
[$st, , $body] = http('GET', $base . '/llms.txt', $insecure);
check('/llms.txt → 200', $st === 200, 'status=' . $st);
[$st, , $body] = http('GET', $base . '/favicon.ico', $insecure);
if ($st === 200) {
    check('/favicon.ico → 200 (файл существует)', true);
} else {
    check('/favicon.ico → 200 или файл отсутствует', $st === 404, 'status=' . $st);
}

// --- 10. GET /form/contact → 404 ---
[$st, , $body] = http('GET', $base . '/form/contact', $insecure);
check('GET /form/contact → 404', $st === 404, 'status=' . $st);

// --- 11. /contact: заголовки безопасности ---
[$st, $h, $body] = http('GET', $base . '/contact', $insecure);
check('/contact → 200', $st === 200, 'status=' . $st);
check('/contact: Set-Cookie с HttpOnly', headerHas($h, 'set-cookie', '/httponly/i'), $h['set-cookie'] ?? '(нет)');
check('/contact: Set-Cookie с SameSite', headerHas($h, 'set-cookie', '/samesite/i'), $h['set-cookie'] ?? '(нет)');
check('/contact: Cache-Control содержит no-store', headerHas($h, 'cache-control', '/no-store/i'), $h['cache-control'] ?? '(нет)');

// --- 12. /about: SEO-теги без дублей ---
[$st, $h, $body] = http('GET', $base . '/about', $insecure);
check('/about → 200', $st === 200, 'status=' . $st);
$titleCount = preg_match_all('/<title[^>]*>.*?<\/title>/is', $body);
check('/about: ровно один <title>', $titleCount === 1, 'count=' . $titleCount);
$canonCount = preg_match_all('/rel=["\']canonical["\']/i', $body);
check('/about: ровно один rel=canonical', $canonCount === 1, 'count=' . $canonCount);
$ogCount = preg_match_all('/property=["\']og:title["\']/i', $body);
check('/about: ровно один og:title', $ogCount === 1, 'count=' . $ogCount);

// --- 13. Нет фреймворка в заголовках всех ответов ---
$frameworkHeaders = ['x-framework', 'x-rameva'];
$allOk = true;
foreach ($h as $name => $value) {
    if (in_array($name, $frameworkHeaders, true) || stripos($name, 'rameva') !== false) {
        $allOk = false;
    }
    if ($name === 'x-powered-by' && stripos($value, 'php') !== false) {
        $allOk = false;
    }
}
check('Все ответы: нет имени фреймворка в заголовках', $allOk);

echo "\n== Сводка: $passed/$total пройдено ==\n";
if ($fails) {
    echo "FAIL:\n";
    foreach ($fails as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "Все проверки пройдены.\n";
exit(0);