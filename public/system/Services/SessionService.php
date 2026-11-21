<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Services
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Services;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс SessionService — единственное место в фреймворке для инициализации сессии.
 *
 * Централизованное управление сессией обеспечивает:
 * - Единый вызов session_start() с правильными параметрами
 * - Безопасные настройки cookie (HttpOnly, SameSite, Secure)
 * - Strict mode для защиты от фиксации сессии
 * - Хранение в storage/sessions/
 *
 * Все остальные компоненты (FormService, MailService, контроллеры)
 * должны использовать SessionService::start() вместо прямого вызова session_start().
 */
class SessionService
{
    /** @var bool Флаг инициализации */
    private static bool $started = false;

    /** @var array Настройки сессии */
    private static array $config = [];

    /**
     * Инициализирует сессию с безопасными настройками.
     * Должен вызываться ровно один раз на запрос.
     *
     * @return void
     */
    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        // Загружаем конфигурацию приложения для определения HTTPS
        $appConfigFile = APPPATH . '/Config/App.php';
        $appConfig = file_exists($appConfigFile) ? (require $appConfigFile) : [];

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
            || (self::isTrustedProxy() && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $cookieSecure = $isHttps && ($appConfig['session_secure_cookie'] ?? true);

        // Подготовка директории для хранения сессий
        $sessionPath = STORAGEPATH . '/sessions';
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0755, true);
        }

        // Настройки сессии ДО session_start()
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
        ini_set('session.save_path', $sessionPath);
        ini_set('session.gc_maxlifetime', (string)($appConfig['session_gc_maxlifetime'] ?? 1440));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        // Имя куки — не раскрываем фреймворк
        $cookieName = $appConfig['session_name'] ?? 'RAMEVA_SESSID';
        session_name($cookieName);

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            $result = session_start();
            if ($result === false) {
                LogService::write('SessionService: session_start() не удалось', 'error', 'app.log');
                http_response_code(500);
                echo 'Ошибка сервера: не удалось инициализировать сессию.';
                exit;
            }
        }

        self::$started = true;
    }

    /**
     * Проверяет, запущена ли сессия.
     */
    public static function isStarted(): bool
    {
        return self::$started || session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Регенерация ID сессии (например, после логина).
     *
     * @param bool $deleteOld Удалять ли старую сессию
     */
    public static function regenerate(bool $deleteOld = true): void
    {
        if (!self::isStarted()) {
            self::start();
        }
        session_regenerate_id($deleteOld);
    }

    /**
     * Уничтожает сессию полностью.
     */
    public static function destroy(): void
    {
        if (self::isStarted()) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            session_destroy();
            self::$started = false;
        }
    }

    /**
     * Устанавливает значение в сессии.
     */
    public static function set(string $key, mixed $value): void
    {
        if (!self::isStarted()) {
            self::start();
        }
        $_SESSION[$key] = $value;
    }

    /**
     * Получает значение из сессии.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::isStarted()) {
            return $default;
        }
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Проверяет наличие ключа в сессии.
     */
    public static function has(string $key): bool
    {
        if (!self::isStarted()) {
            return false;
        }
        return array_key_exists($key, $_SESSION);
    }

    /**
     * Удаляет ключ из сессии.
     */
    public static function forget(string $key): void
    {
        if (self::isStarted()) {
            unset($_SESSION[$key]);
        }    }

    private static function isTrustedProxy(): bool
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return false;
        }

        $secFile = APPPATH . '/Config/Security.php';
        $secConfig = is_file($secFile) ? (require $secFile) : [];
        $trusted = (array)($secConfig['trusted_proxies'] ?? []);

        foreach ($trusted as $proxy) {
            $proxy = trim((string)$proxy);
            if ($proxy === '') {
                continue;
            }
            if (str_contains($proxy, '/')) {
                [$net, $mask] = explode('/', $proxy, 2) + [null, null];
                if ($net !== null && $mask !== null && self::ipInCidrLocal($remote, $net, (int)$mask)) {
                    return true;
                }
            } elseif ($proxy === $remote) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidrLocal(string $ip, string $cidr, int $mask): bool
    {
        if (strpos($cidr, ':') !== false || strpos($ip, ':') !== false) {
            $ipBin = inet_pton($ip);
            $netBin = inet_pton($cidr);
            if ($ipBin === false || $netBin === false) {
                return false;
            }
            $ipBin = str_pad(base_convert(bin2hex($ipBin), 16, 2), 128, '0', STR_PAD_LEFT);
            $netBin = str_pad(base_convert(bin2hex($netBin), 16, 2), 128, '0', STR_PAD_LEFT);
        } else {
            $ipLong = ip2long($ip);
            $netLong = ip2long($cidr);
            if ($ipLong === false || $netLong === false) {
                return false;
            }
            $ipBin = str_pad(decbin($ipLong), 32, '0', STR_PAD_LEFT);
            $netBin = str_pad(decbin($netLong), 32, '0', STR_PAD_LEFT);
        }

        return strncmp($ipBin, $netBin, $mask) === 0;
    }
}
