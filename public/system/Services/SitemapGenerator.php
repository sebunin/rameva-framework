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

use System\Helpers\Url;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс SitemapGenerator отвечает за построение файла sitemap.xml
 * на основе скомпилированной карты маршрутов pages/.
 */
class SitemapGenerator
{
    /**
     * Генерирует sitemap.xml из карты маршрутов и сохраняет его в корень public/.
     *
     * @param array<string, array{html: string, controller: ?string}> $routes Карта маршрутов
     * @param string|null $outputPath Путь сохранения (по умолчанию ROOTPATH . '/sitemap.xml')
     * @return bool
     */
    public static function generate(array $routes, ?string $outputPath = null): bool
    {
        $outputPath = $outputPath ?? (ROOTPATH . '/sitemap.xml');

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($routes as $path => $routeInfo) {
            // Исключаем служебные маршруты
            if (self::isServiceRoute($path, $routeInfo)) {
                continue;
            }

            $loc = Url::to($path, [], true);
            $lastmod = date('Y-m-d');

            if (!empty($routeInfo['html']) && file_exists($routeInfo['html'])) {
                $lastmod = date('Y-m-d', filemtime($routeInfo['html']));
            }

            $priority = ($path === '/') ? '1.0' : '0.8';
            $changefreq = ($path === '/') ? 'daily' : 'weekly';

            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '</loc>';
            $xml[] = '    <lastmod>' . $lastmod . '</lastmod>';
            $xml[] = '    <changefreq>' . $changefreq . '</changefreq>';
            $xml[] = '    <priority>' . $priority . '</priority>';
            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';
        $content = implode("\n", $xml) . "\n";

        return (bool)@file_put_contents($outputPath, $content, LOCK_EX);
    }

    /**
     * Проверяет, является ли маршрут служебным (не включается в sitemap).
     */
    private static function isServiceRoute(string $path, array $routeInfo): bool
    {
        // Исключаем страницы ошибок и системные страницы
        if (in_array($path, ['/404', '/500', '/error', '/offline'], true)) {
            return true;
        }

        $html = $routeInfo['html'] ?? '';
        if (str_contains($html, 'system/Pages') || str_contains($html, 'system\\Pages')) {
            return true;
        }

        return false;
    }
}
