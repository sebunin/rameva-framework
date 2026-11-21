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

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс AntiBot реализует вызов challenge-проверок браузера
 * и валидацию клиентского отпечатка (Canvas, WebGL, AudioContext).
 */
class AntiBot
{
    /** @var string Имя cookie для хранения валидного токена проверки */
    public const COOKIE_NAME = '_ab_challenge';

    /** @var int Время жизни токена в секундах (1 час) */
    public const TOKEN_LIFETIME = 3600;

    /** @var array Настройки антибота */
    private array $config;

    /**
     * Конструктор AntiBot.
     *
     * @param array|null $config Конфигурация антибота
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $configFile = APPPATH . '/Config/Security.php';
            $allConfig = file_exists($configFile) ? (require $configFile) : [];
            $config = $allConfig['antibot'] ?? [];
        }
        $this->config = $config;
    }

    /**
     * Проверяет, включена ли антибот защита.
     */
    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    /**
     * Проверяет, пройдена ли клиентом challenge-проверка.
     *
     * @return bool True, если клиент валиден и имеет корректный токен
     */
    public function verify(): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $cookieName = $this->config['cookie_name'] ?? self::COOKIE_NAME;
        $token = $_COOKIE[$cookieName] ?? null;

        if (!$token || !is_string($token)) {
            return false;
        }

        $secretKey = $this->config['secret_key'] ?? 'rameva_secret';
        $parts = explode(':', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$timestamp, $ip, $hash] = $parts;
        $now = time();

        if (($now - (int)$timestamp) > ($this->config['token_lifetime'] ?? self::TOKEN_LIFETIME)) {
            return false;
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($clientIp !== $ip) {
            return false;
        }

        $expectedHash = hash_hmac('sha256', "{$timestamp}:{$ip}", $secretKey);
        return hash_equals($expectedHash, $hash);
    }

    /**
     * Генерирует подписанный токен успешного прохождения проверки.
     *
     * @return string
     */
    public function issueToken(): string
    {
        $timestamp = time();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $secretKey = $this->config['secret_key'] ?? 'rameva_secret';
        $hash = hash_hmac('sha256', "{$timestamp}:{$ip}", $secretKey);

        return "{$timestamp}:{$ip}:{$hash}";
    }

    /**
     * Отдает клиенту HTML-страницу проверки со встроенным (инлайн) JS-кодом.
     * Внешние пути к системным файлам в браузере не раскрываются.
     */
    public function renderChallenge(): void
    {
        $jsPath = __DIR__ . '/challenge.js';
        $jsContent = file_exists($jsPath) ? (string)file_get_contents($jsPath) : '';

        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');

        echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head><meta charset=\"utf-8\"><title>Security Check</title></head>\n<body>\n";
        echo "<noscript><p>JavaScript is required to access this website.</p></noscript>\n";
        if ($jsContent !== '') {
            echo "<script>\n" . $jsContent . "\n</script>\n";
        }
        echo "</body>\n</html>";
        exit;
    }
}
