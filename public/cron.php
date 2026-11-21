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
 * action=check (по умолчанию) — сверка хэшей system/ с эталонным манифестом;
 * action=build — пересборка манифеста (при установке и после обновления ядра).
 *
 * Ключ доступа задаётся в app/Config/Security.php (секция integrity, token).
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

$params = [
    'key'    => (string)($_GET['key'] ?? ($argv[1] ?? '')),
    'action' => (string)($_GET['action'] ?? ($argv[2] ?? 'check')),
];

exit(\System\Security\Tripwire::handleCronRequest($params, $cli));
