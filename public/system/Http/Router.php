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

use System\Services\LogService;
use System\Services\LlmsGenerator;
use System\Services\RobotsGenerator;
use System\Services\SitemapGenerator;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс Router выполняет разрешение маршрутов на основе директории pages/.
 *
 * Конвенция pages/: один файл — одна страница:
 * - pages/index.html -> '/' (контроллер: pages/index.php)
 * - pages/{slug}.html -> '/{slug}' (контроллер: pages/{slug}.php)
 * - pages/{section}/index.html -> '/{section}' (контроллер: index.php или {section}.php)
 * - pages/{section}/{page}.html -> '/{section}/{page}' (контроллер: {page}.php или {section}.php)
 *
 * Поддержка дефисов в URL (аналог CodeIgniter4 translateUriToCamelCase / translateURIDashes):
 * - В URL разрешаются дефисы: /my-page, /about-us, /blog/my-post
 * - Файлы контроллеров должны использовать подчёркивания: my_page.php, about_us.php, blog/my_post.php
 * - При поиске контроллера дефисы в сегментах URL автоматически заменяются на подчёркивания
 * - Это позволяет иметь читаемые URL и валидные имена файлов PHP одновременно
 *
 * Примеры:
 *   pages/my-page.html + pages/my_page.php -> URL /my-page работает, контроллер my_page.php
 *   pages/blog/post-one.html + pages/blog/post_one.php -> URL /blog/post-one работает
 */
class Router
{
    /** @var array Карта белого списка маршрутов */
    private array $routes = [];

    /** @var string Путь к файлу кэша маршрутов */
    private string $cacheFile;

    /** @var string Нормализованный суффикс URL из конфигурации ('' — адреса без расширения) */
    private string $suffix = '';

    public function __construct()
    {
        $this->suffix    = $this->readConfiguredSuffix();
        $this->cacheFile = STORAGEPATH . '/cache/routes.php';
        $this->loadRoutes();
    }

    private function loadRoutes(): void
    {
        $configFile = APPPATH . '/Config/App.php';
        $config = file_exists($configFile) ? (require $configFile) : [];
        $debug = !empty($config['debug']);
        $routeCheckInterval = $this->resolveRouteCheckInterval($config);

        $assetsFile = STORAGEPATH . '/cache/assets.php';
        $signatureFile = STORAGEPATH . '/cache/routes.signature.php';
        $checkAtFile = STORAGEPATH . '/cache/.route_check_at';

        // Если интервал проверки > 0 и метка свежая — не пересчитываем подпись,
        // берём карту из кэша (если он есть). Если кэша нет — подпись считается
        // обязательно, иначе сайт не запустится на пустом кэше.
        if ($routeCheckInterval > 0 && is_file($checkAtFile)) {
            $lastCheck = (int)@file_get_contents($checkAtFile);
            if (time() - $lastCheck < $routeCheckInterval) {
                if (file_exists($this->cacheFile)) {
                    $cached = require $this->cacheFile;
                    if (is_array($cached)) {
                        $this->routes = $cached;
                        return;
                    }
                }
                // Кэша нет — подпись считается обязательно (и ниже обновится метка)
            }
        }

        $signature = $this->sourceSignature();
        $oldSignature = is_file($signatureFile) ? require $signatureFile : null;

        // Быстрый путь из кэша (без учёта интервала): debug=false, кэш есть, подписи совпадают
        if (!$debug && file_exists($this->cacheFile) && is_file($assetsFile) && $oldSignature === $signature) {
            $cached = require $this->cacheFile;
            if (is_array($cached)) {
                $this->routes = $cached;
                $this->updateRouteCheckMarker($checkAtFile);
                return;
            }
        }

        $this->routes = $this->buildRoutesMap();

        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $guard = "if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }";
        $export = "<?php\n// Auto-generated routes map by RAMEVA Framework\n{$guard}\nreturn " . var_export($this->routes, true) . ";\n";
        @file_put_contents($this->cacheFile, $export, LOCK_EX);
        $assets = [];
        $assetDir = ROOTPATH . '/assets';
        if (is_dir($assetDir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($assetDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $assets[] = str_replace('\\', '/', substr($file->getPathname(), strlen($assetDir) + 1));
                }
            }
        }
        sort($assets);
        @file_put_contents($assetsFile, "<?php\n// Generated asset inventory.\n{$guard}\nreturn " . var_export($assets, true) . ";\n", LOCK_EX);
        @file_put_contents($signatureFile, "<?php\n// Route/asset source signature.\n{$guard}\nreturn " . var_export($signature, true) . ";\n", LOCK_EX);

        // A4: обновляем метку времени после каждого реального вычисления подписи
        $this->updateRouteCheckMarker($checkAtFile);

        // Автоматическая генерация sitemap.xml, llms.txt и строки Sitemap в robots.txt
        // при компиляции карты маршрутов (не на каждый запрос)
        SitemapGenerator::generate($this->routes);
        LlmsGenerator::generate($this->routes);
        RobotsGenerator::generate();
    }

    /**
     * Сканирует pages/ и строит белый список маршрутов.
     *
     * @return array<string, array{html: string, controller: ?string}>
     */
    public function buildRoutesMap(): array
    {
        $routes = [];
        $pagesDir = PAGESPATH;

        if (!is_dir($pagesDir)) {
            return $routes;
        }

        // 1. Главная страница: pages/index.html (и опционально pages/index.php)
        if (file_exists($pagesDir . '/index.html')) {
            $routes['/'] = [
                'html'       => $pagesDir . '/index.html',
                'controller' => file_exists($pagesDir . '/index.php') ? $pagesDir . '/index.php' : null,
            ];
        }

        // 2. Сканируем директорию pages/
        $this->scanPagesDirectory($pagesDir, '', $routes);

        return $routes;
    }

    /**
     * Рекурсивно обходит pages/ и сопоставляет URL с файлами.
     */
    private function scanPagesDirectory(string $dir, string $urlPrefix, array &$routes): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        $folderName = basename($dir);
        // Нормализуем имя папки для поиска контроллера секции (дефисы -> подчёркивания)
        $normalizedFolderName = $this->normalizeSegment($folderName);
        $sectionController = file_exists($dir . '/' . $normalizedFolderName . '.php')
            ? ($dir . '/' . $normalizedFolderName . '.php')
            : null;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'index.php') {
                continue;
            }

            $fullPath = $dir . '/' . $item;

            if (is_dir($fullPath)) {
                $subPrefix = ($urlPrefix === '') ? ('/' . $item) : ($urlPrefix . '/' . $item);
                $this->scanPagesDirectory($fullPath, $subPrefix, $routes);
            } elseif (is_file($fullPath) && str_ends_with($item, '.html')) {
                $slug = substr($item, 0, -5);

                if ($slug === 'index') {
                    if ($urlPrefix === '') {
                        continue;
                    }
                    $routePath = $urlPrefix;
                } else {
                    $routePath = ($urlPrefix === '') ? ('/' . $slug) : ($urlPrefix . '/' . $slug);
                }

                $pageController = $dir . '/' . $slug . '.php';
                $resolvedController = null;

                // При поиске контроллера нормализуем slug: дефисы -> подчёркивания
                $controllerSlug = $this->normalizeSegment($slug);
                $controllerFile = $dir . '/' . $controllerSlug . '.php';

                if (file_exists($pageController)) {
                    $resolvedController = $pageController;
                } elseif ($sectionController !== null && file_exists($sectionController)) {
                    $resolvedController = $sectionController;
                } elseif (file_exists($controllerFile)) {
                    // Контроллер найден с нормализованным именем (my-page -> my_page)
                    $resolvedController = $controllerFile;
                }

                $routes[$routePath] = [
                    'html'       => $fullPath,
                    'controller' => $resolvedController,
                ];
            }
        }
    }

    /**
     * Проверяет каноничность входящего адреса и возвращает адрес для 301-редиректа.
     *
     * Модель (перенесена из RAMEVA Framework 1, Router::buildCanonicalPath):
     * - суффикс приклеивается ровно один раз и только к логическому имени маршрута,
     *   а не к имени файла: pages/company/index.html отвечает на /company,
     *   и суффикс (если включён) добавляется к «company», а не к слову «index»;
     * - суффикс снимается однократно, без циклов «.html.html.html»;
     * - при пустом настроенном суффиксе канонический адрес — без расширения,
     *   поэтому ЛЮБОЕ хвостовое расширение считается несовпадающим;
     * - незначащий сегмент «index» логическим именем не является, поэтому
     *   /index и /index.html ведут на главную.
     *
     * @param string $uri Путь запроса (с query-строкой)
     * @return string|null Адрес для 301 или null, если редирект не нужен
     */
    public function checkCanonicalRedirect(string $uri): ?string
    {
        $parsed  = parse_url($uri);
        $rawPath = $parsed['path'] ?? '/';
        $query   = isset($parsed['query']) ? ('?' . $parsed['query']) : '';

        if ($rawPath === '' || $rawPath === '/') {
            return null;
        }

        $canonicalPath = $this->buildCanonicalPath($this->normalizeLogicalPath($rawPath));

        // Адрес уже канонический — редирект не нужен
        if ($canonicalPath === $rawPath) {
            return null;
        }

        // Редиректим только на существующую страницу: 301 в никуда — это лишний
        // прыжок и 404 на следующем запросе, поэтому сразу отдаём 404 в ядре
        if (!$this->routeExists($canonicalPath)) {
            return null;
        }

        return $canonicalPath . $query;
    }

    /**
     * Разрешает входящий HTTP-запрос по белому списку маршрутов.
     */
    public function resolve(string $uri): ?array
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        return $this->resolveRoutePath($path);
    }

    /**
     * Находит маршрут по пути.
     *
     * Сначала выполняется точный поиск (со снятием настроенного суффикса и
     * концевого слеша), затем — поиск по логическому виду адреса. Второй шаг нужен
     * для запросов, которые не проходят через канонический редирект (например,
     * POST): они должны обслуживаться, а не отдавать 404.
     */
    private function resolveRoutePath(string $path): ?array
    {
        $path = $this->trimTrailingSlash($path);

        // 1. Точное совпадение (с учётом суффикса)
        $exact = $this->routeWithoutConfiguredSuffix($path);
        if (isset($this->routes[$exact])) {
            return $this->routes[$exact];
        }

        // 2. Логическое совпадение (без суффикса, без index)
        $logical = $this->normalizeLogicalPath($path);
        if ($logical !== $exact && isset($this->routes[$logical])) {
            return $this->routes[$logical];
        }

        // 3. Совпадение с нормализацией дефисов -> подчёркивания в сегментах
        // (поддержка URL с дефисами для контроллеров с подчёркиваниями в именах файлов)
        $normalizedPath = $this->normalizePathSegments($path);
        if ($normalizedPath !== $exact && $normalizedPath !== $logical && isset($this->routes[$normalizedPath])) {
            return $this->routes[$normalizedPath];
        }

        return null;
    }

    /**
     * Нормализует все сегменты пути: дефисы заменяет на подчёркивания.
     * Используется для поиска маршрута при запросе /my-page к контроллеру my_page.php.
     *
     * @param string $path Путь
     * @return string Путь с нормализованными сегментами
     */
    private function normalizePathSegments(string $path): string
    {
        $path = $this->trimTrailingSlash($path);
        if ($path === '/') {
            return '/';
        }

        $segments = explode('/', ltrim($path, '/'));
        $normalized = array_map([$this, 'normalizeSegment'], $segments);

        return '/' . implode('/', $normalized);
    }

    /**
     * Проверяет, резолвится ли путь в существующий маршрут.
     */
    private function routeExists(string $path): bool
    {
        return $this->resolveRoutePath($path) !== null;
    }

    /**
     * Приводит путь к логическому виду: без концевого слеша, без посторонних
     * расширений и без незначащего сегмента «index».
     */
    private function normalizeLogicalPath(string $path): string
    {
        $path = $this->trimTrailingSlash($path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        $segments = explode('/', ltrim($path, '/'));
        $last     = count($segments) - 1;

        // Расширение/суффикс снимается только у последнего сегмента и один раз
        $segments[$last] = $this->stripTrailingExtensions($segments[$last]);

        // «index» — имя действия по умолчанию, а не логическое имя маршрута
        if ($segments[$last] === 'index') {
            array_pop($segments);
        }

        $segments = array_values(array_filter($segments, static function (string $segment): bool {
            return $segment !== '';
        }));

        if (empty($segments)) {
            return '/';
        }

        return '/' . implode('/', $segments);
    }

    /**
     * Строит канонический путь из логического: суффикс добавляется один раз
     * и только к последнему значимому сегменту.
     */
    public function buildCanonicalPath(string $logicalPath): string
    {
        if ($logicalPath === '' || $logicalPath === '/') {
            return '/';
        }

        if ($this->suffix === '') {
            return $logicalPath;
        }

        $segments = explode('/', ltrim($logicalPath, '/'));
        $last     = count($segments) - 1;
        $segments[$last] .= $this->suffix;

        return '/' . implode('/', $segments);
    }

    /**
     * Снимает хвостовое расширение с сегмента пути.
     *
     * При настроенном суффиксе снимается только повторяющаяся цепочка этого
     * суффикса (doc.html.html -> doc). При пустом суффиксе каноническим является
     * адрес без расширения, поэтому снимается любое хвостовое расширение.
     */
    private function stripTrailingExtensions(string $segment): string
    {
        if ($segment === '') {
            return '';
        }

        if ($this->suffix !== '') {
            $quoted = preg_quote($this->suffix, '/');
            if ($quoted === '') {
                return $segment;
            }

            $normalized = preg_replace('/(?:' . $quoted . ')+$/u', '', $segment);

            return is_string($normalized) ? $normalized : $segment;
        }

        $normalized = preg_replace('/(\.[a-zA-Z0-9]+)+$/', '', $segment);

        return is_string($normalized) ? $normalized : $segment;
    }

    /**
     * Нормализует сегмент пути: дефисы в URL заменяются на подчёркивания
     * для поиска файлов контроллеров (PHP не допускает дефисы в именах файлов классов).
     * Аналог CodeIgniter4 translateUriToCamelCase / translateURIDashes,
     * но используем snake_case для имён файлов.
     *
     * @param string $segment Сегмент пути
     * @return string Нормализованный сегмент
     */
    private function normalizeSegment(string $segment): string
    {
        // Заменяем дефисы на подчёркивания для поиска файлов контроллеров
        return str_replace('-', '_', $segment);
    }
    private function trimTrailingSlash(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            return rtrim($path, '/');
        }

        return $path;
    }

    /**
     * Снимает настроенный суффикс с пути (для точного поиска в карте маршрутов).
     */
    private function routeWithoutConfiguredSuffix(string $path): string
    {
        if ($this->suffix === '' || $path === '/' || !str_ends_with($path, $this->suffix)) {
            return $path;
        }

        return substr($path, 0, -strlen($this->suffix));
    }

    /**
     * Читает суффикс URL из конфигурации приложения и приводит его к виду '.html'.
     *
     * @return string '' — суффикс не используется
     */
    private function readConfiguredSuffix(): string
    {
        $configFile = APPPATH . '/Config/App.php';
        $config = file_exists($configFile) ? (require $configFile) : [];
        $suffix = $config['suffix'] ?? null;

        if ($suffix === null || $suffix === false || $suffix === '' || $suffix === 0 || $suffix === '0') {
            return '';
        }

        $suffix = trim((string)$suffix);

        // Допустимы только буквы и цифры после точки: 'html', '.htm' и т.п.
        if (!preg_match('/^\.?[a-z0-9]+$/i', $suffix)) {
            LogService::write("Invalid URL suffix in app/Config/App.php: '{$suffix}' — суффикс проигнорирован", 'warning');
            return '';
        }

        return '.' . ltrim($suffix, '.');
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    /** Имена, размеры и mtime учитывают добавление/удаление вложенных ассетов. */
    private function sourceSignature(): string
    {
        $entries = [];
        foreach ([PAGESPATH, ROOTPATH . '/assets', APPPATH . '/Config'] as $dir) {
            if (!is_dir($dir)) continue;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !$file->isLink()) $entries[] = $file->getPathname() . ':' . $file->getMTime() . ':' . $file->getSize();
            }
        }
        sort($entries);
        return hash('sha256', implode("\n", $entries));
    }

    /**
     * Резолвит параметр route_check_interval из конфигурации.
     * null → 0 при debug=true, 60 при debug=false (авто).
     * Любое целое число — используется как есть (осознанный override).
     *
     * @param array $config Конфигурация приложения
     * @return int Интервал в секундах (0 — проверять на каждом запросе)
     */
    private function resolveRouteCheckInterval(array $config): int
    {
        $interval = $config['route_check_interval'] ?? null;
        if ($interval === null) {
            return !empty($config['debug']) ? 0 : 60;
        }
        return (int)$interval;
    }

    /**
     * Обновляет метку времени последней проверки подписи маршрутов.
     *
     * @param string $checkAtFile Путь к файлу метки
     * @return void
     */
    private function updateRouteCheckMarker(string $checkAtFile): void
    {
        $cacheDir = dirname($checkAtFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($checkAtFile, (string)time(), LOCK_EX);
    }
}