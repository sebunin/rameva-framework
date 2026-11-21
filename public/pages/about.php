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

// Контроллер страницы About
return [
    '_layout'       => 'main', // Указываем шаблон из app/Layouts/main.html
    '_raw'          => ['helper_output'], // Не экранировать HTML из хелпера
    'title'         => 'О проекте RAMEVA 2',
    'heading'       => 'Наша миссия',
    'text'          => 'RAMEVA 2 создана для максимальной скорости, безопасности и автономности.',
    'description'   => 'RAMEVA 2 — современный микрофреймворк для легковесных сайтов и GEO-сервисов.',
    'image'         => 'assets/images/about-og.jpg',
    'schema_type'   => 'Article',
    'schema_data'   => [
        'headline' => 'Наша миссия и принципы RAMEVA 2',
        // datePublished обязателен для типа Article в схеме Schema.org;
        // берём дату последнего изменения этого файла, чтобы значение не
        // расходилось с реальностью при правках страницы
        'datePublished' => date('Y-m-d', filemtime(__FILE__) ?: time()),
        'author'   => [
            '@type' => 'Organization',
            'name'  => 'RAMEVA Team',
        ],
    ],
    'speakable'     => ['#mission-text', '.badge'],

    'year'          => '2026',
    'helper_output' => function_exists('format_badge') ? format_badge('Active Framework') : 'Helper not loaded',
];
