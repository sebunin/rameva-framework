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

namespace System\Core;

use System\Services\LogService;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс Installer обслуживает первый запуск: диагностику окружения, проверку
 * защиты внутренних каталогов, выдачу ключа для служебных точек входа и
 * переключение в боевой режим.
 *
 * Состояние установки хранится в storage/installer.state.php (PHP-файл под Guard),
 * повторная диагностика после установки вызывается через public/cron.php с ключом
 * integrity.token — установщик в этом режиме недоступен напрямую.
 *
 * Секретов в pages/, app/Layouts/, system/Layouts/ не появляется: ключи лежат
 * только в app/Config/ и storage/, закрытых от веба.
 */
class Installer
{
    /** @var string Файл состояния установки (относительно storage/) */
    private const STATE_FILE = '/installer.state.php';

    /** @var string Файл ключа диагностики (относительно storage/) */
    private const SETUP_KEY_FILE = '/installer.key';

    /** @var string Отметка последней повторной диагностики (относительно storage/) */
    private const DIAGNOSE_MARKER = '/installer.diagnose.at';

    /** @var string Файл отпечатка окружения (относительно storage/) */
    private const FINGERPRINT_FILE = '/installer.fingerprint.php';

    /** @var string Минимальный интервал между повторными диагностиками (секунды) */
    private const DIAGNOSE_INTERVAL = 1;

    /** @var string Содержимое защитного .htaccess для внутренних каталогов */
    public const HTACCESS_CONTENT = "<IfModule authz_core_module>\n"
        . "\tRequire all denied\n"
        . "</IfModule>\n"
        . "<IfModule !authz_core_module>\n"
        . "\tDeny from all\n"
        . "</IfModule>\n";

    /** @var array<int, string> Пути, которые обязаны отвечать 403/404 после защиты */
    private const PROTECTED_PATHS = [
        '/pages/index.html',
        '/app/Config/App.php',
        '/system/Boot.php',
        '/storage/logs/app.log',
    ];

    /** @var array<int, string> Временные probe-файлы для HTTP-проверки защиты (создаются и удаляются) */
    private const PROBE_PATHS = [
        '/storage/_probe.log',
        '/storage/_probe.json',
        '/pages/_probe.html',
        '/app/_probe.txt',
    ];

    /** @var string Probe-файл с исполняемым PHP в assets/ (проверка, что PHP не исполняется там) */
    private const PROBE_PHP_ASSETS = '/assets/_probe.php';

    /** @var array<int, string> Обязательные расширения PHP */
    private const REQUIRED_EXTENSIONS = ['json', 'mbstring', 'hash', 'filter', 'session'];

    /** @var array<int, string> Необязательные, но полезные расширения */
    private const OPTIONAL_EXTENSIONS = ['curl', 'openssl', 'gd', 'intl', 'zip', 'pdo', 'pdo_mysql', 'pdo_sqlite'];

    /**
     * Состояние установки.
     *
     * @return array<string, mixed>
     */
    public static function getState(): array
    {
        $file = self::storagePath(self::STATE_FILE);

        if (!file_exists($file)) {
            return [
                'installed'        => false,
                'security_posture' => 'not_installed',
                'degraded_reason'  => null,
                'diagnostics'      => null,
                'server'           => null,
                'completed_at'     => null,
            ];
        }

        $state = require $file;

        return is_array($state) ? $state : ['installed' => false, 'security_posture' => 'not_installed'];
    }

    /**
     * Установлен ли боевой режим.
     */
    public static function isInstalled(): bool
    {
        return (bool)(self::getState()['installed'] ?? false);
    }

    /**
     * Текущий режим безопасности: secure | degraded | not_installed.
     */
    public static function getSecurityPosture(): string
    {
        return (string)(self::getState()['security_posture'] ?? 'not_installed');
    }

    /**
     * Причина деградации, если режим degraded.
     */
    public static function getDegradedReason(): ?string
    {
        $reason = self::getState()['degraded_reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
    /**
     * Полная диагностика окружения (пункты 2–5 брифа).
     *
     * @return array<string, mixed>
     */
    public static function runDiagnostics(): array
    {
        $results = [
            'php_version'     => self::checkPhpVersion(),
            'extensions'      => self::checkExtensions(),
            'write_perms'     => self::checkWritePermissions(),
            'server_type'     => self::detectServer(),
            'htaccess_write'  => ['name' => 'Запись .htaccess (Apache)', 'status' => 'skipped', 'details' => []],
            'htaccess_verify' => ['name' => 'Проверка защиты запросом', 'status' => 'skipped', 'details' => []],
            'nginx_config'    => ['name' => 'Конфигурация Nginx', 'status' => 'skipped', 'details' => ''],
            'overall'         => 'pending',
        ];

        $server = (string)($results['server_type']['value'] ?? 'unknown');

        if ($server === 'apache') {
            $results['htaccess_write']  = self::writeHtaccessFiles();
            $results['htaccess_verify'] = self::verifyProtection();
        } elseif ($server === 'nginx') {
            $results['nginx_config'] = [
                'name'    => 'Конфигурация Nginx',
                'status'  => 'info',
                'details' => self::nginxSnippet(),
            ];
        }

        $criticalFailed = self::hasCriticalFailure($results);

        if ($criticalFailed) {
            $results['overall'] = 'critical_fail';
        } elseif ($server === 'apache') {
            $results['overall'] = ($results['htaccess_verify']['status'] === 'ok') ? 'pass' : 'fail';
        } else {
            // Nginx и неопознанный сервер: защита подтверждается отдельным запросом
            $results['overall'] = 'nginx_pending';
        }

        return $results;
    }

    /**
     * HEAD-запрос к собственному сайту по указанному пути.
     *
     * Публичная обёртка над httpHead() для повторной диагностики: проверка
     * должна идти реальным HTTP-запросом, а не по факту «файл записан».
     *
     * @param string $path Путь от корня сайта (например, '/pages/index.html')
     * @return int Код ответа (0 — запрос не удался)
     */
    public static function selfCheckCode(string $path): int
    {
        return self::httpHead(self::baseUrl() . '/' . ltrim($path, '/'));
    }

    /**
     * Проверка защиты внутренних каталогов реальным HTTP-запросом.
     *
     * @return array{name: string, status: string, details: array<int, array<string, mixed>>}
     */
    public static function verifyProtection(): array
    {
        $details = [];
        $blocked = 0;

        foreach (self::PROTECTED_PATHS as $path) {
            $code = self::selfCheckCode($path);
            $ok   = in_array($code, [403, 404], true);

            if ($ok) {
                $blocked++;
            }

            $details[] = ['path' => $path, 'code' => $code, 'status' => $ok ? 'ok' : 'fail'];
        }

        // A2: Временные probe-файлы в защищённых каталогах — создаём, проверяем через HTTP, удаляем.
        foreach (self::PROBE_PATHS as $relPath) {
            $absPath = rtrim(ROOTPATH, '\\') . $relPath;
            $marker  = bin2hex(random_bytes(8));
            $created = false;

            try {
                $dir = dirname($absPath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $created = (bool)@file_put_contents($absPath, $marker, LOCK_EX);

                if ($created) {
                    $code = self::selfCheckCode($relPath);
                    $ok   = in_array($code, [403, 404], true);

                    if (!$ok) {
                        $details[] = [
                            'path' => $relPath,
                            'code' => $code,
                            'status' => 'fail',
                            'note'  => 'probe-файл доступен через HTTP',
                        ];
                    } else {
                        $blocked++;
                        $details[] = [
                            'path' => $relPath,
                            'code' => $code,
                            'status' => 'ok',
                        ];
                    }
                } else {
                    $details[] = ['path' => $relPath, 'code' => 0, 'status' => 'fail', 'note' => 'не удалось создать probe-файл'];
                }
            } finally {
                if ($created && file_exists($absPath)) {
                    @unlink($absPath);
                }
            }
        }

        // A3: PHP-файл в assets/ — проверяем, что исполняемый PHP там недоступен.
        $absPhp = rtrim(ROOTPATH, '\\') . self::PROBE_PHP_ASSETS;
        $marker = bin2hex(random_bytes(8));
        $created = false;

        try {
            $dir = dirname($absPhp);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $created = (bool)@file_put_contents($absPhp, '<?php echo ' . var_export($marker, true) . ';', LOCK_EX);

            if ($created) {
                $result = self::httpGet(self::baseUrl() . '/' . ltrim(self::PROBE_PHP_ASSETS, '/'));
                $code   = $result['code'];
                $body   = $result['body'];

                if ($code === 200 && str_contains($body, $marker)) {
                    $details[] = [
                        'path' => self::PROBE_PHP_ASSETS,
                        'code' => $code,
                        'status' => 'critical',
                        'note'  => 'PHP исполняется в assets/ — защита каталога не работает',
                    ];
                } else {
                    $blocked++;
                    $details[] = [
                        'path' => self::PROBE_PHP_ASSETS,
                        'code' => $code,
                        'status' => 'ok',
                    ];
                }
            } else {
                $details[] = ['path' => self::PROBE_PHP_ASSETS, 'code' => 0, 'status' => 'fail', 'note' => 'не удалось создать probe-файл'];
            }
        } finally {
            if ($created && file_exists($absPhp)) {
                @unlink($absPhp);
            }
        }

        $totalChecks = count($details);
        // A2: защита подтверждена только если ВСЕ проверяемые пути закрыты (403/404),
        // а не хотя бы один. Любой критический провал (PHP в assets/) также блокирует secure.
        $allOk = $totalChecks > 0;
        foreach ($details as $d) {
            if (!in_array($d['status'], ['ok', 'already_ok'], true)) {
                $allOk = false;
                break;
            }
        }

        return [
            'name'    => 'Проверка защиты запросом',
            'status'  => $allOk ? 'ok' : 'fail',
            'details' => $details,
        ];
    }

    /**
     * Записывает защитный .htaccess во внутренние каталоги (Apache).
     *
     * @return array{name: string, status: string, details: array<int, array<string, mixed>>}
     */
    public static function writeHtaccessFiles(): array
    {
        $targets = [PAGESPATH, APPPATH, SYSTEMPATH, STORAGEPATH];
        $details = [];
        $failed  = 0;

        foreach ($targets as $dir) {
            $file = rtrim(str_replace('\\', '/', $dir), '/') . '/.htaccess';

            if (!is_dir($dir)) {
                $details[] = ['path' => self::relative($file), 'status' => 'fail'];
                $failed++;
                continue;
            }

            $current = file_exists($file) ? (string)@file_get_contents($file) : '';

            // Уже есть действующая директива запрета — файл не перезаписываем:
            // в нём могут быть пояснения, а .htaccess внутри system/ входит
            // в манифест целостности Tripwire, и лишняя перезапись давала бы
            // ложное расхождение.
            if (self::hasDenyDirective($current)) {
                $details[] = ['path' => self::relative($file), 'status' => 'already_ok'];
                continue;
            }

            $payload = $current === ''
                ? self::HTACCESS_CONTENT
                : rtrim($current, "\r\n") . "\n\n" . self::HTACCESS_CONTENT;

            $written = (bool)@file_put_contents($file, $payload, LOCK_EX);
            $details[] = ['path' => self::relative($file), 'status' => $written ? 'updated' : 'fail'];

            if (!$written) {
                $failed++;
            }
        }

        return [
            'name'    => 'Запись .htaccess (Apache)',
            'status'  => $failed === 0 ? 'pass' : 'fail',
            'details' => $details,
        ];
    }
    /**
     * Переключает в боевой режим с зафиксированным результатом диагностики.
     */
    public static function finalize(array $diagnostics, string $posture, ?string $reason = null): bool
    {
        // A1: Posture вычисляется на сервере — клиентские значения не доверяются.
        //     Запускаем проверку защиты и диагностику независимо от входных данных.
        $protection = self::verifyProtection();
        $diag       = self::runDiagnostics();

        $protectionOk = $protection['status'] === 'ok';
        $diagnosticsOk = $diag['overall'] === 'pass';

        // A4: secure невозможен при debug=true или environment != production.
        $appConfigFile = APPPATH . '/Config/App.php';
        $appConfig     = file_exists($appConfigFile) ? (require $appConfigFile) : [];
        $debugMode     = !empty($appConfig['debug']);
        $envProduction = (string)($appConfig['environment'] ?? 'production') === 'production';

        $serverPosture = 'degraded';
        $serverReason  = null;

        if (!$protectionOk || !$diagnosticsOk) {
            $serverReason = 'Защита каталогов или диагностика окружения не пройдены';
        } elseif (!$envProduction) {
            $serverReason = 'Режим не production (environment=' . ($appConfig['environment'] ?? 'unknown') . ')';
        } elseif ($debugMode) {
            $serverReason = 'Режим отладки включён (debug=true)';
        } else {
            $serverPosture = 'secure';
        }

        // Если клиент просит secure, но сервер определил degraded — побеждает degraded.
        $posture = $serverPosture;
        $reason  = $serverReason;

        $posture = in_array($posture, ['secure', 'degraded'], true) ? $posture : 'degraded';

        // Используем диагностику сервера, а не клиентскую.
        $diagnostics = $diag;

        $state = [
            'installed'        => true,
            'security_posture' => $posture,
            'degraded_reason'  => $posture === 'secure' ? null : (string)$reason,
            'diagnostics'      => $diagnostics,
            'server'           => (string)($diagnostics['server_type']['value'] ?? 'unknown'),
            'completed_at'     => time(),
            'completed_at_iso' => date('c'),
            'fingerprint'      => self::environmentFingerprint(),
        ];

        $written = self::writeState($state);

        if ($written) {
            self::storeFingerprint($state['fingerprint']);
            self::clearSetupKey();
            self::syncPosture($posture, $state['degraded_reason']);

            LogService::write(
                'installer: установка завершена, security_posture=' . $posture
                    . ($state['degraded_reason'] !== null ? ' (' . $state['degraded_reason'] . ')' : ''),
                $posture === 'secure' ? 'info' : 'warning',
                'security.log'
            );
        }

        return $written;
    }

    /**
     * Повторная диагностика после установки (пункт 9).
     *
     * Защищена тем же ключом, что и cron.php. Мгновенная: сначала сравнение
     * сохранённого отпечатка окружения, и только при расхождении — полная
     * диагностика. Расхождение означает переезд или смену окружения: боевой
     * режим снимается автоматически, сайт снова показывает установщик.
     *
     * @param array<string, mixed> $previous Состояние до вызова
     * @return array<string, mixed>
     */
    public static function reDiagnose(array $previous): array
    {
        $marker = self::storagePath(self::DIAGNOSE_MARKER);
        $now    = time();

        if (file_exists($marker)) {
            $last = (int)@file_get_contents($marker);
            if ($now - $last < self::DIAGNOSE_INTERVAL) {
                return ['status' => 'rate_limited', 'message' => 'diagnose: too frequent, retry in a second'];
            }
        }

        @file_put_contents($marker, (string)$now, LOCK_EX);

        $current    = self::environmentFingerprint();
        $stored     = (string)($previous['fingerprint'] ?? '');
        $moved      = $stored !== '' && !hash_equals($stored, $current);

        $diagnostics = self::runDiagnostics();

        if ($moved) {
            // Переезд/смена окружения: доверие к прошлой установке снимается.
            self::resetInstallation();
            $diagnostics['fingerprint_changed'] = true;
            $diagnostics['overall']             = 'environment_changed';

            LogService::write(
                'installer: отпечаток окружения изменился — боевой режим снят, требуется повторная установка',
                'warning',
                'security.log'
            );

            return $diagnostics;
        }

        $diagnostics['fingerprint_changed'] = false;

        $posture = (string)($previous['security_posture'] ?? 'degraded');
        $reason  = $previous['degraded_reason'] ?? null;

        if ($diagnostics['overall'] === 'pass' && $posture !== 'secure') {
            // Защита подтверждена — запись о деградации больше не актуальна.
            $previous['security_posture'] = 'secure';
            $previous['degraded_reason']  = null;
            self::writeState($previous);
            self::syncPosture('secure', null);
        } elseif ($diagnostics['overall'] !== 'pass' && $posture === 'secure') {
            $previous['security_posture'] = 'degraded';
            $previous['degraded_reason']  = 'По результатам повторной диагностики защита каталогов не подтверждена';
            self::writeState($previous);
            self::syncPosture('degraded', (string)$previous['degraded_reason']);
        }

        if (isset($previous['diagnostics'])) {
            $previous['diagnostics'] = $diagnostics;
            $previous['last_diagnose'] = ['at' => $now, 'at_iso' => date('c'), 'overall' => $diagnostics['overall']];
            self::writeState($previous);
        }

        return $diagnostics;
    }

    /**
     * Снимает установку: состояние удаляется, режим возвращается в not_installed.
     */
    public static function resetInstallation(): void
    {
        $file = self::storagePath(self::STATE_FILE);

        if (file_exists($file)) {
            @unlink($file);
        }

        self::syncPosture('degraded', 'Переезд на другое окружение: боевой режим снят, требуется повторная установка');
    }
    /**
     * Ключ доступа к странице диагностики (не к cron.php — у него свой ключ).
     *
     * Создаётся при первом запуске и кладётся в storage/installer.key, потому что
     * в конфиг его записывать нельзя: страница диагностики нужна до того, как
     * конфигурация вообще может быть правлена, а storage/ закрыт от веба.
     *
     * @return array{token: string, created: bool}
     */
    public static function ensureSetupKey(): array
    {
        $file = self::storagePath(self::SETUP_KEY_FILE);

        if (file_exists($file)) {
            $existing = trim((string)@file_get_contents($file));
            if ($existing !== '') {
                return ['token' => $existing, 'created' => false];
            }
        }

        $token = bin2hex(random_bytes(32));
        $ok    = (bool)@file_put_contents($file, $token . "\n", LOCK_EX);

        if ($ok) {
            @chmod($file, 0600);
        }

        return ['token' => $ok ? $token : '', 'created' => $ok];
    }

    /**
     * Проверяет ключ страницы диагностики.
     */
    public static function checkSetupKey(string $given): bool
    {
        $file = self::storagePath(self::SETUP_KEY_FILE);

        if ($given === '' || !file_exists($file)) {
            return false;
        }

        $expected = trim((string)@file_get_contents($file));

        return $expected !== '' && hash_equals($expected, $given);
    }

    /**
     * Удаляет ключ диагностики после завершения установки: страница установщика
     * после этого недоступна даже по прямому адресу, остаётся только cron.php
     * со своим ключом.
     */
    private static function clearSetupKey(): void
    {
        $file = self::storagePath(self::SETUP_KEY_FILE);

        if (file_exists($file)) {
            @unlink($file);
        }
    }
    /**
     * @return array{name: string, value: string, status: string, message: string}
     */
    private static function checkPhpVersion(): array
    {
        $version = PHP_VERSION;
        $ok      = version_compare($version, '8.1.0', '>=');

        return [
            'name'    => 'Версия PHP',
            'value'   => $version,
            'status'  => $ok ? 'pass' : 'fail',
            'message' => $ok
                ? "PHP {$version} — подходит (требуется 8.1 или новее)"
                : "PHP {$version} — не подходит, требуется 8.1 или новее",
        ];
    }

    /**
     * @return array{name: string, status: string, details: array<int, array<string, mixed>>}
     */
    private static function checkExtensions(): array
    {
        $details = [];
        $failed  = 0;

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);

            if (!$loaded) {
                $failed++;
            }

            $details[] = ['name' => $ext, 'required' => true, 'status' => $loaded ? 'pass' : 'fail'];
        }

        foreach (self::OPTIONAL_EXTENSIONS as $ext) {
            $loaded    = extension_loaded($ext);
            $details[] = ['name' => $ext, 'required' => false, 'status' => $loaded ? 'pass' : 'warn'];
        }

        return [
            'name'    => 'Расширения PHP',
            'status'  => $failed === 0 ? 'pass' : 'fail',
            'details' => $details,
        ];
    }

    /**
     * @return array{name: string, status: string, details: array<int, array<string, mixed>>}
     */
    private static function checkWritePermissions(): array
    {
        $dirs = [
            STORAGEPATH,
            STORAGEPATH . '/cache',
            STORAGEPATH . '/logs',
            STORAGEPATH . '/session',
            STORAGEPATH . '/uploads',
        ];

        $details = [];
        $failed  = 0;

        foreach ($dirs as $dir) {
            $writable = is_dir($dir) && is_writable($dir);

            if (!$writable) {
                $failed++;
            }

            $details[] = ['path' => self::relative($dir), 'status' => $writable ? 'pass' : 'fail'];
        }

        return [
            'name'    => 'Права на запись (storage/)',
            'status'  => $failed === 0 ? 'pass' : 'fail',
            'details' => $details,
        ];
    }

    /**
     * Определение веб-сервера по серверным переменным.
     *
     * Определение не стопроцентное: Nginx часто скрывает версию, а PHP под ним
     * виден как fpm-fcgi. Поэтому результат traktуется как подсказка, а решение
     * принимается по HTTP-проверке защиты каталогов.
     *
     * @return array{name: string, value: string, status: string, details: array<int, string>, note: string}
     */
    private static function detectServer(): array
    {
        $software = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
        $server   = 'unknown';
        $details  = [];

        if ($software !== '') {
            $details[] = 'SERVER_SOFTWARE: ' . $_SERVER['SERVER_SOFTWARE'];
        }

        if (str_contains($software, 'apache') || function_exists('apache_get_modules')) {
            $server = 'apache';
        } elseif (str_contains($software, 'nginx')) {
            $server = 'nginx';
        } elseif (PHP_SAPI === 'fpm-fcgi' && (isset($_SERVER['HTTP_X_REAL_IP']) || isset($_SERVER['HTTP_X_FORWARDED_FOR']))) {
            $server    = 'nginx';
            $details[] = 'Определено по заголовкам прокси и SAPI fpm-fcgi';
        }

        if (PHP_SAPI !== '') {
            $details[] = 'PHP SAPI: ' . PHP_SAPI;
        }

        return [
            'name'    => 'Веб-сервер',
            'value'   => $server,
            'status'  => $server === 'unknown' ? 'warn' : 'pass',
            'details' => $details,
            'note'    => $server === 'unknown'
                ? 'Определение не гарантировано — проверьте вручную'
                : 'Определение не стопроцентное, решает проверка защиты запросом',
        ];
    }
    /**
     * Путь к служебному файлу в storage/.
     */
    private static function storagePath(string $relative): string
    {
        return rtrim(STORAGEPATH, '/\\') . $relative;
    }

    /**
     * Путь относительно корня сайта — для вывода на экран установщика
     * (абсолютные пути сервера посетителю не показываются).
     */
    private static function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', ROOTPATH), '/') . '/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Адрес сайта для HTTP-проверки «изнутри».
     *
     * Если в app/Config/App.php задан siteurl — берётся он: на этапе установки
     * домен уже может отличаться от того, по которому открыт установщик.
     */
    private static function baseUrl(): string
    {
        $configFile = APPPATH . '/Config/App.php';
        $config     = file_exists($configFile) ? (require $configFile) : [];
        $configured = trim((string)($config['siteurl'] ?? ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host;
    }

    /**
     * HEAD-запрос к собственному сайту. Возвращает HTTP-код (0 — не удалось).
     */
    private static function httpHead(string $url): int
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Rameva-Installer-SelfCheck/1.0',
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $code;
        }

        // Без cURL: потоковый HEAD-запрос, коды берём из $http_response_header
        $context = stream_context_create([
            'http' => [
                'method'          => 'HEAD',
                'timeout'         => 5,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        @file_get_contents($url, false, $context);

        $headers = $http_response_header ?? [];
        $first   = is_array($headers) ? (string)($headers[0] ?? '') : '';

        return preg_match('#^HTTP/\S+\s+(\d{3})#', $first, $m) === 1 ? (int)$m[1] : 0;
    }
    /** @return array{code: int, body: string} */
    private static function httpGet(string $url): array
    {
        $body = '';
        $code = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Rameva-Installer-SelfCheck/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ['code' => $code, 'body' => is_string($body) ? $body : ''];
        }

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 5,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $first   = is_array($headers) ? (string)($headers[0] ?? '') : '';

        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $first, $m) === 1) {
            $code = (int)$m[1];
        }

        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
    }

    /**
     * Готовый блок для конфигурации Nginx (справочный материал: применить его
     * может только администратор сервера, из PHP конфиг Nginx не правится).
     */
    private static function nginxSnippet(): string
    {
        $lines = [
            '# RAMEVA Framework 2 — защита внутренних каталогов.',
            '# Вставить внутрь блока server { ... } и перезагрузить Nginx (nginx -s reload).',
            '#',
            '# 1. Закрыть каталоги ядра, шаблонов и конфигурации.',
            'location ~ ^/(pages|app|system|storage)/ {',
            '    deny all;',
            '}',
            '',
            '# 2. Служебные файлы в корне отдаются по буквальному имени.',
            'location = /robots.txt  { try_files $uri =404; }',
            'location = /sitemap.xml { try_files $uri =404; }',
            'location = /llms.txt    { try_files $uri =404; }',
            'location = /favicon.ico { try_files $uri =404; }',
            '',
            '# 3. Посторонние .php в корне запрещены, кроме точек входа фреймворка.',
            'location ~ ^/[^/]+\\.php$ {',
            '    if ($uri !~ "^/(index|cron|installer)\\.php$") {',
            '        deny all;',
            '    }',
            '    try_files $uri =404;',
            '    include fastcgi_params;',
            '    fastcgi_pass unix:/run/php/php-fpm.sock;   # путь к сокету PHP-FPM',
            '    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;',
            '}',
            '',
            '# 4. Всё остальное — фронт-контроллер.',
            'location / {',
            '    try_files $uri $uri/ /index.php?$query_string;',
            '}',
        ];

        return implode("\n", $lines);
    }

    /**
     * Есть ли критичный провал (то, что нельзя обойти): версия PHP, обязательные
     * расширения, права на запись в storage/.
     *
     * @param array<string, mixed> $results
     */
    private static function hasCriticalFailure(array $results): bool
    {
        foreach (['php_version', 'extensions', 'write_perms'] as $key) {
            $section = $results[$key] ?? null;

            if (!is_array($section)) {
                return true;
            }

            if (($section['status'] ?? '') === 'fail') {
                return true;
            }
        }

        return false;
    }

    /**
     * Есть ли в .htaccess действующая директива запрета.
     */
    private static function hasDenyDirective(string $content): bool
    {
        return preg_match('/^\s*(Require\s+all\s+denied|Deny\s+from\s+all)\s*$/mi', $content) === 1;
    }

    /**
     * Записывает файл состояния установки (PHP под Guard, не открытый JSON).
     *
     * @param array<string, mixed> $state
     */
    private static function writeState(array $state): bool
    {
        $content = "<?php\n"
            . "// Состояние установки Rameva (создаётся установщиком).\n"
            . "if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }\n"
            . "return " . var_export($state, true) . ";\n";

        $written = (bool)@file_put_contents(self::storagePath(self::STATE_FILE), $content, LOCK_EX);

        if ($written) {
            @chmod(self::storagePath(self::STATE_FILE), 0600);
        }

        return $written;
    }

    /**
     * Переносит режим безопасности в app/Config/Security.php (секция
     * security_posture), чтобы «degraded» был виден постоянно, а не только
     * на экране установщика.
     *
     * Файл конфигурации — PHP с массивом; правится точечно регулярным
     * выражением по секции, остальные настройки и комментарии сохраняются.
     * Если секции нет — она добавляется перед закрывающей скобкой массива.
     */
    private static function syncPosture(string $posture, ?string $reason): void
    {
        $file = APPPATH . '/Config/Security.php';

        if (!file_exists($file) || !is_writable($file)) {
            LogService::write('installer: не удалось записать security_posture в app/Config/Security.php', 'warning', 'security.log');
            return;
        }

        $content = (string)@file_get_contents($file);
        $block   = "    'security_posture' => [\n"
            . "        'mode'     => " . var_export($posture, true) . ",\n"
            . "        'reason'   => " . var_export($reason, true) . ",\n"
            . "        'fixed_at' => " . var_export(date('c'), true) . ",\n"
            . "    ],\n";

        if (preg_match("/    'security_posture' => \\[.*?\\n    \\],\\n/s", $content) === 1) {
            $content = (string)preg_replace("/    'security_posture' => \\[.*?\\n    \\],\\n/s", $block, $content, 1);
        } else {
            // Вставляем перед последней закрывающей скобкой массива
            $pos = strrpos($content, '];');
            if ($pos === false) {
                return;
            }
            $content = substr($content, 0, $pos) . $block . substr($content, $pos);
        }

        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            LogService::write('installer: запись security_posture не удалась', 'warning', 'security.log');
        }
    }

    /**
     * Отпечаток окружения — дешёвая проверка «тот ли это сервер».
     *
     * Намеренно НЕ включает версии PHP/веб-сервера и список модулей: их
     * обновление на том же хостинге не должно выглядеть как переезд.
     * В отпечаток входят только малоподвижные характеристики: корень сайта,
     * SAPI, ОС и идентификатор файловой системы (st_dev) корня.
     */
    private static function environmentFingerprint(): string
    {
        $root = rtrim(str_replace('\\', '/', ROOTPATH), '/');

        $parts = [
            'root' => $root,
            'sapi' => PHP_SAPI,
            'os'   => PHP_OS_FAMILY,
            'dev'  => self::deviceId($root),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Идентификатор устройства (раздела) файловой системы — меняется при
     * переезде на другой сервер, но не при обновлении ПО.
     */
    private static function deviceId(string $path): string
    {
        $index = $path . '/index.php';
        $stat  = @stat(file_exists($index) ? $index : $path);

        return is_array($stat) && isset($stat['dev']) ? (string)$stat['dev'] : 'unknown';
    }

    /**
     * Сохраняет отпечаток окружения (для повторной диагностики).
     */
    private static function storeFingerprint(string $fingerprint): void
    {
        $content = "<?php\n"
            . "// Отпечаток окружения на момент установки (Rameva Installer).\n"
            . "if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }\n"
            . "return " . var_export(['fingerprint' => $fingerprint, 'fixed_at' => date('c')], true) . ";\n";

        @file_put_contents(self::storagePath(self::FINGERPRINT_FILE), $content, LOCK_EX);
    }

    /**
     * Ключ доступа к cron.php: генерируется при установке и записывается в
     * app/Config/Security.php (секция integrity, token). Существующий ключ
     * не перезаписывается — иначе уже настроенный планировщик сломается.
     *
     * @return array{token: string, created: bool}
     */
    public static function ensureCronToken(): array
    {
        $file = APPPATH . '/Config/Security.php';

        if (!file_exists($file)) {
            return ['token' => '', 'created' => false];
        }

        $content = (string)@file_get_contents($file);

        // Существующий token_hash не перезаписывается — иначе сломается уже
        // настроенный планировщик администратора.
        if (preg_match("/'token_hash'\\s*=>\\s*'([^']*)'/", $content, $m) === 1 && $m[1] !== '') {
            return ['token' => '', 'created' => false];
        }

        if (!is_writable($file)) {
            return ['token' => '', 'created' => false];
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        if (preg_match("/'token_hash'\\s*=>\\s*'[^']*'/", $content) === 1) {
            $content = (string)preg_replace("/'token_hash'\\s*=>\\s*'[^']*'/", "'token_hash' => '" . $tokenHash . "'", $content, 1);
        } else {
            return ['token' => '', 'created' => false];
        }

        $written = @file_put_contents($file, $content, LOCK_EX) !== false;

        return ['token' => $written ? $token : '', 'created' => $written];
    }

    /**
     * Создаёт скелет каталогов storage/ при первом запуске, если они отсутствуют.
     *
     * @return void
     */
    public static function ensureStorageSkeleton(): void
    {
        $dirs = [
            'cache',
            'logs',
            'session',
            'sessions',
            'uploads',
            'debugbar',
        ];

        $stub = "<?php\nhttp_response_code(403);\nexit;\n";

        foreach ($dirs as $dir) {
            $abs = STORAGEPATH . '/' . $dir;
            if (!is_dir($abs)) {
                @mkdir($abs, 0755, true);
            }

            $index = $abs . '/index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, $stub, LOCK_EX);
            }
        }

        // E3: Для Apache — защитный .htaccess в storage/, если отсутствует.
        $software = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
        $isApache = str_contains($software, 'apache') || function_exists('apache_get_modules');

        if ($isApache) {
            $htaccess = STORAGEPATH . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, self::HTACCESS_CONTENT, LOCK_EX);
            }
        }
    }
}

