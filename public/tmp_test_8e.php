// Тест сегментного сравнения isBlacklisted
function testBlacklist(string $uri, array $patterns): bool {
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $segments = array_filter(explode('/', $path), 'strlen');
    
    foreach ($patterns as $pattern) {
        $patternLower = strtolower($pattern);
        foreach ($segments as $segment) {
            $segmentLower = strtolower($segment);
            if (str_starts_with($patternLower, '.')) {
                if (str_starts_with($segmentLower, $patternLower)) {
                    return true;
                }
            } else {
                if ($segmentLower === $patternLower) {
                    return true;
                }
            }
        }
    }
    return false;
}

$patterns = ['.env', '.git', 'vendor/phpunit', 'eval-stdin.php', 'phpmyadmin', 'pma', 'alfa-rex', 'wso.php', 'shell.php'];

$tests = [
    '/wp-login.php' => false,   // Не должно блокироваться (Honeypot обрабатывает)
    '/.env' => true,            // Должно блокироваться
    '/.environment' => true,    // .env совпадает с .environment
    '/.github/workflows' => false, // .git НЕ должен совпадать с .github
    '/pmarket-test' => false,   // pma не должен совпадать с pmarket-test
    '/pma' => true,             // pma точное совпадение
    '/.git' => true,            // .git точное совпадение
    '/.git/config' => true,     // .git совпадает с .git/... (prefix)
    '/shell.php' => true,       // shell.php точное совпадение
];

$allPass = true;
foreach ($tests as $uri => $expected) {
    $result = testBlacklist($uri, $patterns);
    $status = $result === $expected ? 'PASS' : 'FAIL';
    if ($status === 'FAIL') $allPass = false;
    echo "$status: $uri => " . ($result ? 'blocked' : 'not blocked') . " (expected: " . ($expected ? 'blocked' : 'not blocked') . ")\n";
}
echo $allPass ? "\nВсе тесты прошли!\n" : "\nЕсть проваленные тесты!\n";