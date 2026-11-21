<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Public
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

// Б1. Константа PHP Guard выставляется ДО загрузки Boot, чтобы система
// отдавала 403/404 без тела при прямом доступе к любому системному файлу.
define('RAMEVA_CORE', true);

// Единая точка входа приложения — делегирует загрузку системному bootstrap
require_once __DIR__ . '/system/Boot.php';

