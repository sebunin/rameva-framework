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
 * Класс LogService обеспечивает безопасное логирование с защитой от флуда и авторотацией.
 */
class LogService
{
    /** @var array Настройки по умолчанию */
    private static array $config = [
        'max_file_size'           => 10485760, // 10 MB
        'max_age_days'            => 30,       // 30 дней хранения
        'throttle_interval'       => 1,        // Мин. 1 сек между записями с одного IP
        'throttle_max_per_minute' => 60,       // Максимум 60 записей в минуту с IP
    ];

    /** @var array Счетчики для троттлинга */
    private static array $throttleData = [
        'last_time' => [],
        'counters'  => [],
    ];

    /**
     * Записывает сообщение в файл журнала.
     *
     * @param string $message Текст сообщения
     * @param string $level Уровень логирования ('error', 'warning', 'info', 'debug')
     * @param string|null $channel Имя файла журнала (по умолчанию 'app.log')
     * @return bool
     */
    public static function write(string $message, string $level = 'error', ?string $channel = null): bool
    {
        $channel = $channel ?? 'app.log';
        $logDir = STORAGEPATH . '/logs';

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Keep request logs and rate limits aligned behind explicitly trusted proxies.
        $ip = ClientIp::get();
        if (self::isThrottled($ip)) {
            return false;
        }

        $logFile = $logDir . '/' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $channel);
        self::checkRotation($logFile);

        $timestamp = date('Y-m-d H:i:s');
        $formatted = sprintf(
            "[%s] [%s] [IP: %s] %s%s",
            $timestamp,
            strtoupper($level),
            $ip,
            trim($message),
            PHP_EOL
        );

        return (bool)@file_put_contents($logFile, $formatted, FILE_APPEND | LOCK_EX);
    }

    /**
     * Проверяет троттлинг запросов на запись по IP.
     */
    private static function isThrottled(string $ip): bool
    {
        $ip = MailRateState::ipKey($ip);
        $now = time();
        $lastTime = self::$throttleData['last_time'][$ip] ?? 0;

        if (($now - $lastTime) < self::$config['throttle_interval']) {
            return true;
        }

        $currentMinute = (int)($now / 60);
        $counterKey = $ip . '_' . $currentMinute;
        $count = (self::$throttleData['counters'][$counterKey] ?? 0) + 1;
        self::$throttleData['counters'][$counterKey] = $count;
        self::$throttleData['last_time'][$ip] = $now;

        return $count > self::$config['throttle_max_per_minute'];
    }

    /**
     * Выполняет ротацию лог-файла при превышении максимального размера.
     */
    private static function checkRotation(string $filePath): void
    {
        if (file_exists($filePath) && filesize($filePath) > self::$config['max_file_size']) {
            $rotated = $filePath . '.' . date('Ymd_His') . '.bak';
            @rename($filePath, $rotated);
        }
    }
}
