<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Services
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Services;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Исключение превышения почтовых лимитов.
 * Сообщает клиенту 429 с заголовком Retry-After.
 */
class MailRateLimitedException extends \Exception
{
    public int $retryAfter;

    public function __construct(int $retryAfter, string $message = 'Mail rate limit exceeded')
    {
        parent::__construct($message, 429);
        $this->retryAfter = $retryAfter;
    }
}