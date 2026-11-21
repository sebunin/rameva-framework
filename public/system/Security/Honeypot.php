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

use System\Services\LogService;
use System\Services\ClientIp;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс Honeypot реализует стратегию "Миража" для противодействия автоматическим сканерам.
 *
 * Анализирует URI на наличие типичных запросов к уязвимостям (например, /wp-login.php,
 * xmlrpc.php, /wp-admin/) и отдаёт фейковые правдоподобные ответы, отвлекая и истощая
 * ресурсы атакующих ботов, либо блокирует сканирование без раскрытия фреймворка.
 */
class Honeypot
{
    /** @var array Известные пути сканирования CMS WordPress и админ-панелей */
    private array $wpProbePaths = [
        '/wp-login.php',
        '/wp-admin',
        '/wp-admin/',
        '/xmlrpc.php',
        '/wp-includes/',
        '/wp-content/',
        '/wp-config.php',
        '/wp-json/',
        '/administrator/',
        '/admin.php',
        '/phpmyadmin/',
    ];

    /** @var array Легитимные поисковые роботы, которых нельзя блокировать */
    private array $seoBots = [
        'googlebot',
        'yandexbot',
        'bingbot',
        'duckduckbot',
        'baiduspider',
        'applebot',
        'telegrambot',
    ];

    /** @var array Известные сканеры уязвимостей */
    private array $scannerBots = [
        'nikto',
        'sqlmap',
        'nmap',
        'masscan',
        'wpscan',
        'acunetix',
        'gobuster',
        'dirb',
        'ffuf',
        'burpsuite',
    ];

    /** @var array Конфигурация */
    private array $config;

    /**
     * Конструктор Honeypot.
     *
     * @param array|null $config Конфигурация
     */
    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $configFile = APPPATH . '/Config/Security.php';
            $allConfig = file_exists($configFile) ? (require $configFile) : [];
            $config = $allConfig['honeypot'] ?? [];
        }
        $this->config = $config;
    }

    /**
     * Проверяет запрос на принадлежность к сканерам уязвимостей.
     *
     * @return bool True, если запрос перехвачен honeypot-ловушкой
     */
    public function handleRequest(): bool
    {
        if (empty($this->config['enabled'])) {
            return false;
        }

        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // 1. Пропускаем легитимных SEO-ботов
        foreach ($this->seoBots as $bot) {
            if (str_contains($userAgent, $bot)) {
                return false;
            }
        }

        // 2. Блокируем известные сканеры
        foreach ($this->scannerBots as $scanner) {
            if (str_contains($userAgent, $scanner)) {
                $this->logActivity('Scanner detected', $path);
                http_response_code(403);
                exit;
            }
        }

        // 3. Перехватываем типичные probe-пути (WordPress, phpMyAdmin и т.д.)
        foreach ($this->wpProbePaths as $probe) {
            if (str_starts_with($path, $probe)) {
                $this->logActivity('Probe detected: ' . $probe, $path);

                if (!empty($this->config['fake_wp'])) {
                    $this->renderFakeWpResponse($path);
                    return true;
                }

                http_response_code(403);
                exit;
            }
        }

        return false;
    }

    /**
     * Имитирует ответ WordPress для дезинформации сканеров.
     */
    private function renderFakeWpResponse(string $path): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Powered-By: PHP/7.4.33');
        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="en-US">' . "\n";
        echo '<head><title>Log In &lsaquo; WordPress</title></head>' . "\n";
        echo '<body class="login login-action-login wp-core-ui">' . "\n";
        echo '<div id="login"><form name="loginform" id="loginform" action="/wp-login.php" method="post">' . "\n";
        echo '<p><label for="user_login">Username or Email Address</label><input type="text" name="log" id="user_login" class="input" size="20" /></p>' . "\n";
        echo '<p><label for="user_pass">Password</label><input type="password" name="pwd" id="user_pass" class="input" size="20" /></p>' . "\n";
        echo '<p class="submit"><input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="Log In" /></p>' . "\n";
        echo '</form></div>' . "\n";
        echo '</body></html>';
        exit;
    }

    /**
     * Логирует активность сканеров в файл журнала honeypot.
     */
    private function logActivity(string $reason, string $path): void
    {
        $ip = ClientIp::get();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'none';
        LogService::write("{$reason} | IP: {$ip} | URI: {$path} | UA: {$ua}", 'warning', 'honeypot.log');
    }
}
