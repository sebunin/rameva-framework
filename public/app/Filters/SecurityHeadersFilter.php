<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Filters
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace App\Filters;

use System\Http\FilterInterface;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Фильтр SecurityHeaders устанавливает базовые защитные HTTP-заголовки
 * для противодействия clickjacking и MIME-sniffing.
 *
 * Принцип безопасности: не раскрывать имя и версию фреймворка во внешних заголовках.
 */
class SecurityHeadersFilter implements FilterInterface
{
    public function before(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
    }

    public function after(string &$output): void
    {
        // После генерации контента
    }
}

