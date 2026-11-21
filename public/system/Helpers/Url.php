<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Helpers
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Helpers;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс Url предоставляет методы безопасного построения URL-адресов
 * с поддержкой суффиксов, query-параметров и базового домена.
 */
class Url
{
    /**
     * Формирует канонический URL для страницы или маршрута.
     *
     * @param string $path Путь страницы (например, 'about' или '/contacts')
     * @param array $params Query-параметры
     * @param bool $absolute Возвращать ли абсолютный URL с протоколом и доменом
     * @return string
     */
    public static function to(string $path = '/', array $params = [], bool $absolute = false): string
    {
        $cleanPath = '/' . ltrim($path, '/');
        if ($cleanPath === '//') {
            $cleanPath = '/';
        }

        $configFile = APPPATH . '/Config/App.php';
        $config = file_exists($configFile) ? (require $configFile) : [];
        $suffix = $config['suffix'] ?? null;

        // Добавляем суффикс к непустому пути (не к корню /)
        if (!empty($suffix) && $cleanPath !== '/') {
            $ext = str_starts_with((string)$suffix, '.') ? (string)$suffix : '.' . $suffix;
            if (!str_ends_with($cleanPath, $ext)) {
                $cleanPath .= $ext;
            }
        }

        if (!empty($params)) {
            $cleanPath .= '?' . http_build_query($params);
        }

        if ($absolute) {
            $siteUrl = rtrim((string)($config['siteurl'] ?? self::base()), '/');
            return $siteUrl . $cleanPath;
        }

        return $cleanPath;
    }

    /**
     * Возвращает базовый URL текущего сайта (протокол + хост).
     *
     * @return string
     */
    public static function base(): string
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $scheme = $isHttps ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return "{$scheme}://{$host}";
    }

    /**
     * Возвращает текущий запрошенный URI.
     *
     * @return string
     */
    public static function current(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }
}
