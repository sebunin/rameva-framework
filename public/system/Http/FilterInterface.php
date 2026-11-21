<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Http
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Http;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Интерфейс глобального HTTP-фильтра (Middleware).
 */
interface FilterInterface
{
    /**
     * Выполняется ДО передачи управления контроллеру страницы.
     * Если метод завершает выполнение (например, exit или редирект), ядро останавливает дальнейшую обработку.
     */
    public function before(): void;

    /**
     * Выполняется ПОСЛЕ рендеринга страницы.
     * Может модифицировать отправляемый контент по ссылке или устанавливать HTTP-заголовки.
     *
     * @param string $output Сгенерированный HTML страницы
     */
    public function after(string &$output): void;
}
