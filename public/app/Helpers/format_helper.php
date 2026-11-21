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

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

if (!function_exists('format_badge')) {
    /**
     * Форматирует текстовый бейдж.
     */
    function format_badge(string $text, string $type = 'success'): string
    {
        return '<span class="badge badge-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}

if (!function_exists('format_price')) {
    /**
     * Форматирует цену с разделителями тысяч.
     */
    function format_price(float|int $amount, string $currency = '₽'): string
    {
        return number_format((float)$amount, 0, '.', ' ') . ' ' . $currency;
    }
}
