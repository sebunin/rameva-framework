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
 * Класс RobotsGenerator поддерживает robots.txt в актуальном состоянии.
 *
 * Что гарантируется:
 * - строка Sitemap указывает на sitemap.xml текущего домена из конфигурации;
 * - внутренние каталоги фреймворка (/pages/, /app/, /system/) закрыты от обхода
 *   роботами: их содержимое — исходные шаблоны, доступные по прямым адресам
 *   как обычные статические файлы.
 *
 * Пользовательские правила сохраняются: файл не перезаписывается целиком,
 * дописываются только отсутствующие директивы внутри группы «User-agent: *»,
 * а строка Sitemap приводится к актуальному виду (дубли удаляются).
 */
class RobotsGenerator
{
    /** @var array<int, string> Пути, закрытые от индексации */
    private const DISALLOW_PATHS = ['/pages/', '/app/', '/system/'];

    /**
     * Обновляет robots.txt в корне сайта.
     *
     * @param string|null $outputPath Путь к файлу (по умолчанию ROOTPATH . '/robots.txt')
     * @return bool
     */
    public static function generate(?string $outputPath = null): bool
    {
        $outputPath  = $outputPath ?? (ROOTPATH . '/robots.txt');
        $sitemapLine = 'Sitemap: ' . self::resolveBaseUrl() . '/sitemap.xml';

        $content = file_exists($outputPath) ? (string)@file_get_contents($outputPath) : '';

        // Файла нет или он пуст — создаём минимальную рабочую основу
        if (trim($content) === '') {
            return (bool)@file_put_contents($outputPath, self::defaultContent($sitemapLine), LOCK_EX);
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if (!is_array($lines)) {
            $lines = [$content];
        }

        $lines = self::ensureDisallowDirectives($lines);
        $lines = self::ensureSitemapLine($lines, $sitemapLine);

        $updated = rtrim(implode("\n", $lines)) . "\n";

        if ($updated === $content) {
            return true;
        }

        return (bool)@file_put_contents($outputPath, $updated, LOCK_EX);
    }

    /**
     * Содержимое robots.txt по умолчанию.
     */
    private static function defaultContent(string $sitemapLine): string
    {
        $lines = array_merge(['User-agent: *', 'Allow: /'], self::disallowLines(), ['', $sitemapLine]);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Дописывает отсутствующие Disallow-директивы в группу «User-agent: *».
     *
     * Директивы robots.txt действуют в пределах своей группы (до следующей
     * строки User-agent), поэтому они добавляются именно в группу «*», а если
     * такой группы нет — создаётся новая группа в конце файла; правила
     * конкретных роботов при этом не затрагиваются.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function ensureDisallowDirectives(array $lines): array
    {
        $missing = self::missingDisallowLines($lines);

        if (empty($missing)) {
            return $lines;
        }

        $groupStart = self::findWildcardGroupStart($lines);

        // Группы «User-agent: *» нет — создаём её в конце файла
        if ($groupStart === null) {
            $lines[] = '';
            $lines[] = 'User-agent: *';

            foreach ($missing as $line) {
                $lines[] = $line;
            }

            return $lines;
        }

        // Конец группы: следующая строка User-agent или пустая строка
        $insertAt = count($lines);
        $total    = count($lines);

        for ($i = $groupStart + 1; $i < $total; $i++) {
            $trimmed = trim($lines[$i]);

            if ($trimmed === '' || stripos($trimmed, 'user-agent:') === 0) {
                $insertAt = $i;
                break;
            }
        }

        array_splice($lines, $insertAt, 0, $missing);

        return $lines;
    }

    /**
     * Возвращает Disallow-строки, которых ещё нет в файле.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function missingDisallowLines(array $lines): array
    {
        $missing = [];

        foreach (self::disallowLines() as $line) {
            $found = false;

            foreach ($lines as $existing) {
                if (strcasecmp(trim($existing), $line) === 0) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $missing[] = $line;
            }
        }

        return $missing;
    }

    /**
     * @return array<int, string>
     */
    private static function disallowLines(): array
    {
        $lines = [];

        foreach (self::DISALLOW_PATHS as $path) {
            $lines[] = 'Disallow: ' . $path;
        }

        return $lines;
    }

    /**
     * Индекс строки «User-agent: *» или null, если такой группы нет.
     *
     * @param array<int, string> $lines
     */
    private static function findWildcardGroupStart(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^user-agent:\s*\*$/i', trim($line)) === 1) {
                return (int)$index;
            }
        }

        return null;
    }

    /**
     * Приводит строку Sitemap к актуальному виду: первая найденная строка
     * обновляется, дубли удаляются, при отсутствии — добавляется в конец.
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private static function ensureSitemapLine(array $lines, string $sitemapLine): array
    {
        $hasSitemap = false;

        foreach ($lines as $index => $line) {
            if (stripos(trim($line), 'sitemap:') !== 0) {
                continue;
            }

            if (!$hasSitemap) {
                $hasSitemap  = true;
                $lines[$index] = $sitemapLine;
                continue;
            }

            unset($lines[$index]);
        }

        if ($hasSitemap) {
            return array_values($lines);
        }

        $lines[] = '';
        $lines[] = $sitemapLine;

        return array_values($lines);
    }

    /**
     * Определяет базовый адрес сайта: из конфигурации, иначе — из текущего запроса.
     */
    private static function resolveBaseUrl(): string
    {
        $configFile = APPPATH . '/Config/App.php';
        $config = file_exists($configFile) ? (require $configFile) : [];

        $baseUrl = rtrim((string)($config['siteurl'] ?? ''), '/');

        if ($baseUrl === '') {
            $baseUrl = rtrim(Url::base(), '/');
        }

        return $baseUrl;
    }
}
