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
 * Класс Events реализует простой диспетчер событий (Observer pattern).
 * Позволяет подписываться на именованные события и вызывать зарегистрированные обработчики.
 */
class Events
{
    /** @var array<string, array<callable>> Массив зарегистрированных подписчиков */
    private static array $listeners = [];

    /**
     * Регистрирует обработчик на указанное событие.
     *
     * @param string $event Имя события (например, 'mail.sent', 'system.booted')
     * @param callable $listener Функция-обработчик
     */
    public static function on(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /**
     * Вызывает всех зарегистрированных подписчиков для указанного события.
     *
     * @param string $event Имя события
     * @param mixed ...$args Аргументы, передаваемые обработчикам
     */
    public static function trigger(string $event, mixed ...$args): void
    {
        if (empty(self::$listeners[$event])) {
            return;
        }

        foreach (self::$listeners[$event] as $listener) {
            try {
                $listener(...$args);
            } catch (\Throwable $e) {
                // Ошибки в обработчиках событий не должны ломать основную логику приложения
                \System\Services\LogService::write(
                    "Error executing listener for event '{$event}': " . $e->getMessage(),
                    'error',
                    'events.log'
                );
            }
        }
    }

    /**
     * Очищает подписчиков (используется в тестах).
     */
    public static function clear(?string $event = null): void
    {
        if ($event !== null) {
            unset(self::$listeners[$event]);
        } else {
            self::$listeners = [];
        }
    }

    /**
     * Проверяет наличие слушателей у события.
     */
    public static function hasListeners(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }
}
