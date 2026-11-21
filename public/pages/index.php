<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Pages
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

// Контроллер главной страницы: подготавливает переменные для index.html
return [
    'title'   => 'RAMEVA Framework 2 — Главная',
    'heading' => 'Добро пожаловать в RAMEVA Framework 2',
    'message' => 'Система успешно развернута, ядро инициализировано и работает через защищенный runtime-роутер.',
];
