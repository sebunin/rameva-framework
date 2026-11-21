<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Config
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

return [
    /**
     * Глобальные фильтры, выполняемые ДО передачи управления контроллеру страницы.
     */
    'before' => [
        \App\Filters\SecurityHeadersFilter::class,
    ],

    /**
     * Глобальные фильтры, выполняемые ПОСЛЕ генерации контента страницы.
     */
    'after' => [
    ],
];
