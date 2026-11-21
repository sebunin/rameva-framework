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

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс Debug предоставляет методы отладки и замера производительности.
 */
class Debug
{
    /** @var array Временные метки для замеров */
    private static array $timers = [];

    /** @var array Собранные сообщения отладки */
    private static array $messages = [];

    /**
     * Запускает таймер с указанным именем.
     */
    public static function startTimer(string $name): void
    {
        self::$timers[$name] = microtime(true);
    }

    /**
     * Останавливает таймер и возвращает затраченное время в миллисекундах.
     */
    public static function stopTimer(string $name): float
    {
        if (!isset(self::$timers[$name])) {
            return 0.0;
        }

        $elapsed = (microtime(true) - self::$timers[$name]) * 1000.0;
        unset(self::$timers[$name]);
        return round($elapsed, 2);
    }

    /**
     * Добавляет отладочное сообщение.
     */
    public static function log(mixed $data, string $label = 'DEBUG'): void
    {
        self::$messages[] = [
            'time'    => microtime(true),
            'label'   => $label,
            'content' => $data,
        ];
    }

    /**
     * Возвращает накопленные отладочные сообщения.
     */
    public static function getLogs(): array
    {
        return self::$messages;
    }
}
