<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Events
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

use System\Core\Events;
use System\Services\LogService;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Пользовательские подписчики на события фреймворка
 */

// 1. Событие успешной отправки почты
Events::on('mail.sent', function (string $to, string $subject, string $body): void {
    LogService::write("Event [mail.sent]: Email to '{$to}' with subject '{$subject}' sent successfully.", 'info', 'mail_events.log');
});

// 2. Событие завершения инициализации ядра
Events::on('system.booted', function (): void {
    // Пользовательская логика при старте системы
});
