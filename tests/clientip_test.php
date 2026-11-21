<?php
/** Rameva 2 — CLI-тест ClientIp::get(). */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('RAMEVA_CORE', true);
define('ROOTPATH', __DIR__ . '/../public');
define('APPPATH', ROOTPATH . '/app');
define('STORAGEPATH', ROOTPATH . '/storage');
define('PAGESPATH', ROOTPATH . '/pages');
define('SYSTEMPATH', ROOTPATH . '/system');

require __DIR__ . '/../public/system/Boot.php';
use System\Services\ClientIp;

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

// Тест 1: цепочка X-Forwarded-For с подделанным левым адресом и доверенным прокси справа
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';           // реальный клиент, не доверенный прокси
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 10.0.0.1'; // подделанный слева, доверенный справа
$_SERVER['HTTP_CF_CONNECTING_IP'] = '';
$cfg = APPPATH . '/Config/Mail.php';
@file_put_contents($cfg, "<?php\nreturn ['trusted_proxies' => ['10.0.0.1']];\n");
$result = ClientIp::get();
check('XFF: поддельный левый + доверенный справа — не меняет IP клиента', $result === '203.0.113.50', $result, '203.0.113.50');

// Тест 2: недоверенный REMOTE_ADDR — заголовки игнорируются
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';           // не доверенный прокси
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
$result = ClientIp::get();
check('Недоверенный REMOTE_ADDR — заголовки игнорируются', $result === '198.51.100.23', $result, '198.51.100.23');

// Тест 3: невалидный адрес в X-Forwarded-For — результат REMOTE_ADDR
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
$result = ClientIp::get();
check('Невалидный адрес в XFF — результат REMOTE_ADDR', $result === '203.0.113.50', $result, '203.0.113.50');

// Тест 4: доверенный прокси слева, клиент справа — берётся правый недоверенный
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';                // доверенный прокси
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 203.0.113.99';
$result = ClientIp::get();
check('Доверенный прокси слева, клиент справа — берётся клиент', $result === '203.0.113.99', $result, '203.0.113.99');

// Тест 5: все адреса в XFF доверенные — возвращается REMOTE_ADDR
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 10.0.0.2';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '';
@file_put_contents($cfg, "<?php\nreturn ['trusted_proxies' => ['10.0.0.1', '10.0.0.2']];\n");
$result = ClientIp::get();
check('Все адреса XFF доверенные — возвращается REMOTE_ADDR', $result === '10.0.0.1', $result, '10.0.0.1');

// Тест 6: отсутствует X-Forwarded-For — возвращается REMOTE_ADDR
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$result = ClientIp::get();
check('Без XFF — возвращается REMOTE_ADDR', $result === '192.168.1.100', $result, '192.168.1.100');

echo "\nИтог: PASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
