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
     * Тип СУБД (драйвер PDO: mysql, pgsql, sqlite)
     */
    'driver' => 'mysql',

    /**
     * Хост сервера БД
     */
    'host' => 'localhost',

    /**
     * Порт сервера БД
     */
    'port' => 3306,

    /**
     * Имя базы данных
     */
    'database' => '',

    /**
     * Имя пользователя базы данных
     */
    'username' => 'root',

    /**
     * Пароль пользователя базы данных
     */
    'password' => '',

    /**
     * Кодировка соединения
     */
    'charset' => 'utf8mb4',

    /**
     * Префикс таблиц (если используется)
     */
    'prefix' => '',
];
