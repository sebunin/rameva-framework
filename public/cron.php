<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Public
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 *
 * Точка входа для внешнего расписания (Tripwire — контроль целостности system/).
 *
 * Сам фреймворк не может создать себе расписание: он только предоставляет эту
 * точку входа, а вызов добавляет администратор в планировщике своей панели
 * (в ISPmanager это раздел «Планировщик CRON»).
 *
 * Примеры вызова:
 *   wget -q -O /dev/null "https://site.ru/cron.php?key=КЛЮЧ&action=check"
 *   php /path/to/public/cron.php КЛЮЧ build
 *
 * Ключ можно передать в заголовке X-Rameva-Key (предпочтительный способ):
 *   curl -H "X-Rameva-Key: ТОКЕН" "https://site.ru/cron.php?action=check"
 *
 * Если ключ передан в query-строке, в security.log фиксируется уведомление
 * об устаревшем способе (не чаще раза в час).
 *
 * action=check (по умолчанию) — сверка хэшей system/ с эталонным манифестом;
 * action=build — пересборка манифеста (при установке и после обновления ядра).
 *
 * Ключ доступа задаётся в app/Config/Security.php (секция integrity, token_hash).
 * Пустое значение ключа полностью закрывает точку входа. При неверном ключе
 * возвращается обычный 403 без опознавательных признаков.
 *
 * Коды выхода: 0 — успех, 1 — отказ в доступе, 2 — расхождение/нет манифеста.
 */

declare(strict_types=1);

// Б1. Константа PHP Guard выставляется ДО загрузки Boot.
define('RAMEVA_CORE', true);

// Ядро и роутер для этой точки входа не нужны: только bootstrap фреймворка
define('RAMEVA_NO_AUTOLAUNCH', true);

require_once __DIR__ . '/system/Boot.php';

$cli = (PHP_SAPI === 'cli');

// D1: Ключ сначала ищется в заголовке X-Rameva-Key, и только если его нет —
// в query-параметре / argv.
$headerKey = (string)($_SERVER['HTTP_X_RAMEVA_KEY'] ?? '');
if ($headerKey === '' && !$cli && function_exists('getallheaders')) {
    $headers = getallheaders();
    $headerKey = (string)($headers['X-Rameva-Key'] ?? '');
}

$givenKey = $headerKey !== '' ? $headerKey : (string)($_GET['key'] ?? ($argv[1] ?? ''));

// D2: При использовании ключа в query-строке — запись в security.log (notice,
// не чаще раза в час).
if ($headerKey === '' && $givenKey !== '' && !$cli) {
    $logMarker = STORAGEPATH . '/.cron_query_key_at';
    $now = time();
    $shouldLog = false;
    if (!is_file($logMarker)) {
        $shouldLog = true;
    } else {
        $last = (int)@file_get_contents($logMarker);
        if ($now - $last >= 3600) {
            $shouldLog = true;
        }
    }
    if ($shouldLog) {
        @file_put_contents($logMarker, (string)$now, LOCK_EX);
        \System\Services\LogService::write('cron.php: ключ передан в query-строке (устаревший способ, используйте заголовок X-Rameva-Key)', 'notice', 'security.log');
    }
}

$params = [
    'key'    => $givenKey,
    'action' => (string)($_GET['action'] ?? ($argv[2] ?? 'check')),
];

exit(\System\Security\Tripwire::handleCronRequest($params, $cli));
