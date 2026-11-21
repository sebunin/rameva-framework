<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Security
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Security;

use System\Services\Cache;
use System\Services\ClientIp;
use System\Services\LogService;
use System\Services\MailRateState;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс RateLimiter реализует ограничение частоты запросов по IP
 * с временной блокировкой при превышении порога.
 * Состояние хранится в storage/cache/.
 */
class RateLimiter
{
    private array $config;
    private Cache $cache;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $configFile = APPPATH . '/Config/Security.php';
            $all = file_exists($configFile) ? (require $configFile) : [];
            $config = $all['rate_limiter'] ?? [];
        }
        $this->config = $config;
        $this->cache = new Cache(STORAGEPATH . '/cache', 3600);
    }

    /**
     * Проверяет запрос на превышение лимита частоты.
     * Возвращает true, если лимит превышен и запрос заблокирован.
     */
    public function isLimitExceeded(): bool
    {
        if (empty($this->config['enabled'])) {
            return false;
        }

        $ip = ClientIp::get();
        try {
            $ipKey = MailRateState::ipKey($ip);
        } catch (\Throwable $e) {
            // Ключ установки недоступен: не считаем лимит по IP, пишем в лог.
            LogService::write('RateLimiter: установка ключа недоступна (' . $e->getMessage() . '), ограничение по IP отключено.', 'warning', 'security.log');
            return false;
        }
        $blockKey = 'rate_block_' . $ipKey;

        // 1. Проверяем, не заблокирован ли уже IP
        $blockedUntil = $this->cache->get($blockKey);
        if ($blockedUntil !== null && time() < (int)$blockedUntil) {
            return true;
        }

        // 2. Счётчик запросов в текущем окне времени
        $maxRequests = (int)($this->config['max_requests'] ?? 120);
        $decay = (int)($this->config['decay_seconds'] ?? 60);
        $blockDuration = (int)($this->config['block_duration'] ?? 300);

        $windowKey = 'rate_win_' . $ipKey . '_' . (int)(time() / $decay);
        $count = (int)$this->cache->get($windowKey, 0) + 1;
        $this->cache->set($windowKey, $count, $decay * 2);

        if ($count > $maxRequests) {
            // Устанавливаем блокировку
            $this->cache->set($blockKey, time() + $blockDuration, $blockDuration);
            LogService::write("Rate limit exceeded for IP {$ip} ({$count} req/{$decay}s). Blocked for {$blockDuration}s.", 'warning', 'security.log');
            return true;
        }

        return false;
    }
}
