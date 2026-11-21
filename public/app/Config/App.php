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

// PHP Guard: Защита от прямого доступа к файлу конфигурации
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

return [
    /**
     * Основной язык интерфейса и страниц
     */
    'language' => 'ru',

    /**
     * Название веб-сайта
     */
    'sitename' => 'RAMEVA Framework 2',

    /**
     * Базовый канонический URL сайта
     */
    'siteurl' => 'https://fw2.loc',

    /**
     * Позиция названия сайта в заголовке <title>:
     * 0 - не выводить название сайта,
     * 1 - выводить до названия страницы,
     * 2 - выводить после названия страницы
     */
    'sitename_titles' => 1,

    /**
     * Заголовок по умолчанию (до 70 символов)
     */
    'title' => 'RAMEVA Framework 2 — Быстрый и безопасный PHP-фреймворк',

    /**
     * Изображение Open Graph по умолчанию (1200x630)
     */
    'image' => 'assets/images/og_default.jpg',

    /**
     * Мета-описание страницы по умолчанию
     */
    'meta_description' => 'RAMEVA Framework 2 — легковесный автономный PHP-фреймворк.',

    /**
     * Ключевые слова по умолчанию
     */
    'meta_keywords' => 'rameva, framework, php, lightweight, microframework',

    /**
     * Авторские права / создатель
     */
    'meta_author' => 'Rameva LLC',
    'meta_rights' => 'All rights reserved.',

    /**
     * Режим технического обслуживания (offline)
     */
    'offline' => false,
    'offline_message' => 'Сайт закрыт на техническое обслуживание.<br>Пожалуйста, зайдите позже.',

    /**
     * Режим отладки (debug mode)
     */
    'debug' => true,

    /**
     * Уровень вывода ошибок: 'development' | 'production'
     */
    'environment' => 'development',

    /**
     * Часовой пояс приложения
     */
    'timezone' => 'Europe/Moscow',

    /**
     * Список приоритетных страниц для файла llms.txt (идут в начале файла
     * в заданном порядке, остальные страницы добавляются после них).
     */
    'llms_priority' => [
        '/',
        '/about',
    ],

    /**
     * Суффикс для URL-адресов страниц: 'html', 'htm' или null / false (без суффикса)
     */
    'suffix' => null,

    /**
     * I1: Интервал проверки подписи источников маршрутов (секунды).
     *   0 — проверять подпись на каждом запросе.
     *   >0 — кэшировать карту маршрутов на заданное количество секунд.
     *   По умолчанию: null (авто: 0 при debug, 60 при production).
     */
    'route_check_interval' => null,
];
