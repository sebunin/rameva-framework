<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Core
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

// Б2. PHP Guard: если константа не выставлена точкой входа (прямой доступ
// к Boot.php через веб), отдаём 404 без тела и без опознавательных признаков.
if (!defined('RAMEVA_CORE')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        header('Content-Length: 0');
        exit;
    }
    // В CLI константа не требуется — Boot может подключаться из скриптов.
    define('RAMEVA_CORE', true);
}

/**
 * 1.1. Подавление заголовка X-Powered-By до любого другого кода.
 *
 * PHP выставляет этот заголовок сам (директива expose_php), и часть ответов
 * формируется раньше глобальных фильтров: 403 по чёрному списку, challenge
 * AntiBot, 301 канонического редиректа. Поэтому заголовок снимается здесь,
 * а не в SecurityHeadersFilter — иначе версия PHP утекает в ранних ответах.
 */
if (PHP_SAPI !== 'cli') {
    header('X-Powered-By: -');
}

// 2. Динамическое вычисление путей (без жесткой привязки к расположению index.php)
if (!defined('SYSTEMPATH')) {
    define('SYSTEMPATH', __DIR__);
}
if (!defined('ROOTPATH')) {
    define('ROOTPATH', dirname(SYSTEMPATH));
}
if (!defined('APPPATH')) {
    define('APPPATH', ROOTPATH . '/app');
}
if (!defined('PAGESPATH')) {
    define('PAGESPATH', ROOTPATH . '/pages');
}
if (!defined('STORAGEPATH')) {
    define('STORAGEPATH', ROOTPATH . '/storage');
}

// 3. PSR-4 Автозагрузчик классов
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'System\\' => SYSTEMPATH . '/',
        'App\\'    => APPPATH . '/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// 4. Инициализация системных настроек из конфигурации
$appConfig = file_exists(APPPATH . '/Config/App.php') ? (require APPPATH . '/Config/App.php') : [];

if (!empty($appConfig['timezone'])) {
    date_default_timezone_set((string)$appConfig['timezone']);
}

if (!empty($appConfig['debug'])) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
}

// 5. Автозагрузка всех хелперов (*_helper.php)
\System\Helpers\HelperLoader::loadAll();

// 6. Подключение пользовательских подписок на события (если файл существует)
if (file_exists(APPPATH . '/Events/events.php')) {
    require_once APPPATH . '/Events/events.php';
}

// 7. Оповещение о завершении загрузки bootstrap
\System\Core\Events::trigger('system.booted');

// 8. Запуск ядра фреймворка
//
// Альтернативные точки входа (public/cron.php и подобные) определяют
// RAMEVA_NO_AUTOLAUNCH: им нужен только bootstrap, ядро они запускают сами.
// В CLI ядро не запускается никогда — там нет HTTP-запроса.
if (PHP_SAPI === 'cli' || (defined('RAMEVA_NO_AUTOLAUNCH') && RAMEVA_NO_AUTOLAUNCH)) {
    return;
}

$kernel = new \System\Core\Kernel();
$kernel->launch();

// 9. Фоновые проверки целостности (Tripwire): ответ посетителю уже отдан,
//    поэтому они не влияют на время ответа.
//
//    9.1. Дешёвая проверка посторонних .php (корень вне разрешённого списка,
//         assets/, storage/uploads/) — без хеширования, поэтому чаще полной
//         сверки (свой интервал, app/Config/Security.php → integrity.
//         stray_check_interval).
//    9.2. Полная сверка хэшей и прав system/ по истечении check_interval.
\System\Security\Tripwire::runStrayCheckIfDue();
\System\Security\Tripwire::runBackgroundCheckIfDue();
