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
 * Класс Canonical нормализует и формирует канонические адреса страниц.
 */
class Canonical
{
    /** @var string|null Канонический URL */
    private ?string $canonicalUrl = null;

    /**
     * Конструктор инициализирует канонический URL на основе текущего запроса.
     */
    public function __construct()
    {
        $this->initFromRequest();
    }

    /**
     * Получает канонический URL для текущего запроса.
     */
    public function getUrl(): string
    {
        return $this->canonicalUrl ?? '';
    }

    /**
     * Формирует тег <link rel="canonical">.
     */
    public function renderTag(): string
    {
        if (empty($this->canonicalUrl)) {
            return '';
        }
        return '<link rel="canonical" href="' . htmlspecialchars($this->canonicalUrl, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }

    /**
     * Принудительно устанавливает канонический адрес.
     */
    public function setUrl(string $url): void
    {
        $this->canonicalUrl = $url;
    }

    /**
     * Инициализирует канонический адрес из текущего URI.
     */
    private function initFromRequest(): void
    {
        $configFile = APPPATH . '/Config/App.php';
        $config = file_exists($configFile) ? (require $configFile) : [];
        $siteUrl = rtrim($config['siteurl'] ?? '', '/');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Удаляем концевой слеш, кроме корня
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $this->canonicalUrl = $siteUrl . $path;
    }
}
