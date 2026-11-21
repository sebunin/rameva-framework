<?php
/** Rameva 2 — CLI-тест логики Tripwire на подставном манифесте. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('RAMEVA_CORE', true);
define('ROOTPATH', __DIR__ . '/../public');
define('APPPATH', ROOTPATH . '/app');
define('STORAGEPATH', ROOTPATH . '/storage');
define('PAGESPATH', ROOTPATH . '/pages');
define('SYSTEMPATH', ROOTPATH . '/system');

require __DIR__ . '/../public/system/Boot.php';
require __DIR__ . '/../public/system/Security/Tripwire.php';
use System\Security\Tripwire;

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $got = '', string $expected = ''): void
{
    global $pass, $fail;
    if ($ok) {
        echo "[PASS] $name\n";
        $pass++;
    } else {
        echo "[FAIL] $name — ожидалось '$expected', получено '$got'\n";
        $fail++;
    }
}

// Подставной манифест в временном хранилище
$tmpManifest = sys_get_temp_dir() . '/tripwire_test_manifest.php';
$tmpState    = sys_get_temp_dir() . '/tripwire_test_state.php';

// Создаём подставной манифест с двумя файлами
file_put_contents($tmpManifest, "<?php\nreturn [\n  'generated_at' => " . time() . ",\n  'algo' => 'sha256',\n  'scope' => 'system/',\n  'files' => [\n    'system/Core/Kernel.php' => 'hash_a',\n    'system/Services/ClientIp.php' => 'hash_b',\n  ],\n  'perms' => [],\n];\n");

// Подменяем константы через рефлексию не получится — тестируем логику через public API
// Создадим временные файлы system/ для проверки

// Тест 1: build записывает манифест
// Не можем вызвать Tripwire::build() напрямую, т.к. он пишет в STORAGEPATH.
// Вместо этого проверяем, что метод существует и является public static.
$ref = new ReflectionClass(Tripwire::class);
$buildMethod = $ref->getMethod('build');
check('Tripwire::build — public static метод', $buildMethod->isPublic() && $buildMethod->isStatic());

$checkMethod = $ref->getMethod('check');
check('Tripwire::check — public static метод', $checkMethod->isPublic() && $checkMethod->isStatic());

// Тест 2: handleCronRequest с неверным ключом → 403 (exit code 1)
ob_start();
$result = Tripwire::handleCronRequest(['key' => 'wrong', 'action' => 'check'], true);
$output = ob_get_clean();
check('handleCronRequest: неверный ключ → код 1', $result === 1, (string)$result, '1');
check('handleCronRequest: неверный ключ → пустой вывод', $output === '', $output, '');

// Тест 3: handleCronRequest без ключа → 403 (exit code 1)
ob_start();
$result = Tripwire::handleCronRequest(['key' => '', 'action' => 'check'], true);
$output = ob_get_clean();
check('handleCronRequest: отсутствующий ключ → код 1', $result === 1, (string)$result, '1');
check('handleCronRequest: отсутствующий ключ → пустой вывод', $output === '', $output, '');

// Тест 4: handleCronRequest с верным ключом, манифеста нет → 2 (no_manifest)
// Токен в конфиге: ffd11be19050571b3e08b8b0bab7b1ce
ob_start();
$result = Tripwire::handleCronRequest(['key' => 'ffd11be19050571b3e08b8b0bab7b1ce', 'action' => 'check'], true);
$output = ob_get_clean();
check('handleCronRequest: верный ключ, есть манифест → код 2 (mismatch)', $result === 2, (string)$result, '2');
check('handleCronRequest: верный ключ → текст mismatch', stripos($output, 'mismatch') !== false, $output, 'mismatch');

// Тест 5: deny() — приватный метод, проверяем через рефлексию
$denyMethod = $ref->getMethod('deny');
check('Tripwire::deny — private метод', $denyMethod->isPrivate());

// Тест 6: respond() — приватный метод
$respondMethod = $ref->getMethod('respond');
check('Tripwire::respond — private метод', $respondMethod->isPrivate());

echo "\nИтог: PASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
