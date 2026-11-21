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
 * Класс LlmsGenerator формирует файл llms.txt для языковых моделей (LLM)
 * на основе карты маршрутов pages/.
 */
class LlmsGenerator
{
    /**
     * Генерирует llms.txt в корне сайта.
     *
     * @param array<string, array{html: string, controller: ?string}> $routes Карта маршрутов
     * @param string|null $outputPath Путь сохранения (по умолчанию ROOTPATH . '/llms.txt')
     * @return bool
     */
    public static function generate(array $routes, ?string $outputPath = null): bool
    {
        $outputPath = $outputPath ?? (ROOTPATH . '/llms.txt');

        $configFile = APPPATH . '/Config/App.php';
        $appConfig = file_exists($configFile) ? (require $configFile) : [];

        $siteName = $appConfig['sitename'] ?? 'RAMEVA Framework Website';
        $siteDesc = $appConfig['meta_description'] ?? 'Fast and lightweight PHP website.';
        $priorityList = $appConfig['llms_priority'] ?? ['/', '/about'];

        $md = [];
        $md[] = "# {$siteName}";
        $md[] = "";
        $md[] = "> {$siteDesc}";
        $md[] = "";
        $md[] = "## Pages";
        $md[] = "";

        // 1. Сначала выводим приоритетные страницы из конфигурации
        $handled = [];
        foreach ($priorityList as $pRoute) {
            if (isset($routes[$pRoute])) {
                $md[] = self::formatRouteMarkdown($pRoute, $routes[$pRoute]);
                $handled[$pRoute] = true;
            }
        }

        // 2. Затем выводим все остальные страницы
        foreach ($routes as $path => $routeInfo) {
            if (isset($handled[$path])) {
                continue;
            }
            if (self::isServiceRoute($path, $routeInfo)) {
                continue;
            }

            $md[] = self::formatRouteMarkdown($path, $routeInfo);
            $handled[$path] = true;
        }

        $content = implode("\n", $md) . "\n";
        return (bool)@file_put_contents($outputPath, $content, LOCK_EX);
    }

    /**
     * Формирует Markdown-строку для отдельного маршрута.
     */
    private static function formatRouteMarkdown(string $path, array $routeInfo): string
    {
        $url = Url::to($path, [], true);
        $title = ucfirst(trim($path, '/'));
        if ($path === '/') {
            $title = 'Home';
        }

        $desc = '';
        // Если есть контроллер, пробуем получить заголовок или описание
        if (!empty($routeInfo['controller']) && file_exists($routeInfo['controller'])) {
            $cData = (function ($f) { return require $f; })($routeInfo['controller']);
            if (is_array($cData)) {
                if (!empty($cData['title'])) {
                    $title = (string)$cData['title'];
                }
                if (!empty($cData['description'])) {
                    $desc = ' — ' . (string)$cData['description'];
                } elseif (!empty($cData['text'])) {
                    $desc = ' — ' . mb_substr(strip_tags((string)$cData['text']), 0, 100, 'UTF-8');
                }
            }
        }

        return "- [{$title}]({$url}){$desc}";
    }

    private static function isServiceRoute(string $path, array $routeInfo): bool
    {
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
