<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Core
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Core;

use System\Http\Router;
use System\Rendering\Renderer;
use System\Security\Honeypot;
use System\Security\AntiBot;
use System\Security\RateLimiter;
use System\Services\LogService;
use System\Core\Events;
use System\Core\Installer;
use System\Services\FormService;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс Kernel управляет полным жизненным циклом входящего HTTP-запроса:
 * 1. Проверка состояния установки (Installer);
 * 2. Статичные служебные файлы в корне (robots.txt, sitemap.xml, llms.txt, favicon.ico);
 * 3. Черный список сканерских путей (Blacklist) -> 403;
 * 4. Ограничение частоты запросов (RateLimiter) -> 429;
 * 5. Honeypot (ловушка сканеров / фейковый WordPress);
 * 6. AntiBot (challenge-проверка отпечатков браузера);
 * 7. Проверка канонического суффикса URL -> 301 Moved Permanently;
 * 8. Глобальные фильтры (before);
 * 9. Маршрутизация по белому списку pages/;
 * 10. Выполнение контроллера и рендеринг страницы (с поддержкой Layouts);
 * 11. Глобальные фильтры (after) и отправка ответа.
 */
class Kernel
{
    private Router $router;
    private Renderer $renderer;
    private Honeypot $honeypot;
    private AntiBot $antibot;
    private RateLimiter $rateLimiter;
    private \System\Services\SeoService $seoService;

    private array $securityConfig;
    private array $filters = [];

    public function __construct()
    {
        $secFile = APPPATH . '/Config/Security.php';
        $this->securityConfig = file_exists($secFile) ? (require $secFile) : [];

        $filtersFile = APPPATH . '/Config/Filters.php';
        $this->filters = file_exists($filtersFile) ? (require $filtersFile) : [];

        $this->router      = new Router();
        $this->renderer    = new Renderer();
        $this->honeypot    = new Honeypot();
        $this->antibot     = new AntiBot();
        $this->rateLimiter = new RateLimiter();
        $this->seoService  = new \System\Services\SeoService();
    }

    /**
     * Точка входа в обработку HTTP-запроса.
     */
    public function launch(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // 0.1. Физические файлы сайта. Служебные файлы (robots.txt, sitemap.xml,
        //      llms.txt, favicon.ico) отдаются по буквальному имени до любых
        //      проверок: суффикс и канонический редирект к ним не применяются.
        //      Прочие физические файлы в корне — как есть, если это не .php и не
        //      внутренний каталог (см. serveRootFile()).
        if ($this->serveRootFile($uri)) {
            return;
        }

        // 0.2. Проверка состояния установки — пока не установлено, любой запрос
        //      отдаёт установщик, а не содержимое сайта.
        if (!Installer::isInstalled()) {
            $this->renderInstaller();
            return;
        }

        // 0.3. После установки установщик недоступен напрямую.
        if ($this->isInstallerRoute($uri)) {
            $this->renderNotFound();
            return;
        }

        // 1. Быстрый черный список путей (обрывается до любых тяжелых проверок)
        if ($this->isBlacklisted($uri)) {
            LogService::write("Blacklisted path accessed: {$uri}", 'warning', 'security.log');
            http_response_code(403);
            exit;
        }

        // 2. Ограничение частоты запросов (Rate Limiting)
        if ($this->rateLimiter->isLimitExceeded()) {
            http_response_code(429);
            header('Retry-After: 300');
            echo 'Too Many Requests';
            exit;
        }

        // System form endpoint: after the global limiter, before generic bot
        // barriers, redirects, filters, and the pages/ router.
        if (preg_match('#^/form/([a-zA-Z][a-zA-Z0-9_-]{0,63})/?$#i', (string)parse_url($uri, PHP_URL_PATH), $formMatch)) {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                $this->renderNotFound();
                return;
            }
            $service = new FormService();
            [$status, $payload] = $service->processSubmission($formMatch[1], is_array($_POST) ? $_POST : []);
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            if ($status === 429) header('Retry-After: 60');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        // 3. Барьер Honeypot (ловушка сканеров WordPress и probe-путей)
        if ($this->honeypot->handleRequest()) {
            return;
        }

        // 4. Барьер AntiBot (JS-челлендж фингерпринтинга)
        if (!$this->antibot->verify()) {
            $this->antibot->renderChallenge();
            return;
        }

        // 5. Проверка каноничности суффикса URL (301 Permanent Redirect)
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $canonicalRedirect = in_array($method, ['GET', 'HEAD'], true)
            ? $this->router->checkCanonicalRedirect($uri)
            : null;
        if ($canonicalRedirect !== null) {
            http_response_code(301);
            header('Location: ' . $canonicalRedirect);
            exit;
        }

        // 6. Выполнение глобальных фильтров ДО контроллера (before filters)
        $this->runBeforeFilters();

        // 7. Разрешение маршрута по белому списку pages/
        $route = $this->router->resolve($uri);

        if ($route === null) {
            $this->renderNotFound();
            return;
        }

        // 8. Выполнение контроллера (если есть) и рендеринг страницы
        try {
            $viewData = [];
            if (!empty($route['controller']) && file_exists($route['controller'])) {
                $controllerResult = (function ($file) {
                    return require $file;
                })($route['controller']);

                if (is_array($controllerResult)) {
                    $viewData = $controllerResult;
                }
            }
            // Автоматическая генерация SEO/GEO метаданных и подстановка в {{ meta }}
            $rawPageContent = file_exists($route['html']) ? (string)file_get_contents($route['html']) : '';

            if (preg_match('/\{\{\s*form_fields:([a-zA-Z][a-zA-Z0-9_-]{0,63})\s*\}\}/', $rawPageContent)) {
                \System\Services\SessionService::start();
                header('Cache-Control: private, no-store');
            }

            // Тот же заголовок, что и в og:title/twitter:title, отдаётся шаблону,
            // чтобы <title> и og:title не расходились
            $pageMetadata = \System\Rendering\PageMetadata::collect($rawPageContent);
            $viewData['_controller_keys'] = array_keys($viewData);
            $viewData = array_replace($pageMetadata, $viewData);
            $viewData['seo_title'] = $this->seoService->resolveTitle($viewData);

            // Системные директивы рендеринга не являются видимым текстом страницы
            // и не должны попадать в SEO description/schema.
            $seoPageContent = preg_replace('/\{\{\s*form_fields:[a-zA-Z][a-zA-Z0-9_-]{0,63}\s*\}\}/', '', $rawPageContent) ?? $rawPageContent;
            $viewData['meta'] = $this->seoService->generateHeadTags($uri, $viewData, $seoPageContent);
            $viewData['_raw'] = array_merge($viewData['_raw'] ?? [], ['meta']);

            $assetFile = STORAGEPATH . '/cache/assets.php';
            $this->renderer->setNormalizer(new \System\Rendering\LinkNormalizer(
                is_file($assetFile) ? require $assetFile : [],
                $this->router->getRoutes(),
                [$this->router, 'buildCanonicalPath']
            ));
            $output = $this->renderer->renderFile($route['html'], $viewData);

            // 9. Выполнение глобальных фильтров ПОСЛЕ рендера (after filters)
            $this->runAfterFilters($output);

            Events::trigger('system.rendered', $uri, $route);

            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo $output;
        } catch (\Throwable $e) {
            LogService::write("Runtime error: " . $e->getMessage() . "\n" . $e->getTraceAsString(), 'error', 'error.log');
            $this->renderServerError($e);
        }
    }

    /**
     * Выполняет глобальные фильтры Before.
     */
    private function runBeforeFilters(): void
    {
        $beforeClasses = $this->filters['before'] ?? [];
        foreach ($beforeClasses as $filterClass) {
            if (class_exists($filterClass)) {
                $filter = new $filterClass();
                if ($filter instanceof \System\Http\FilterInterface) {
                    $filter->before();
                }
            }
        }
    }

    /**
     * Выполняет глобальные фильтры After.
     */
    private function runAfterFilters(string &$output): void
    {
        $afterClasses = $this->filters['after'] ?? [];
        foreach ($afterClasses as $filterClass) {
            if (class_exists($filterClass)) {
                $filter = new $filterClass();
                if ($filter instanceof \System\Http\FilterInterface) {
                    $filter->after($output);
                }
            }
        }
    }

    /**
     * Проверяет URI на совпадение с черным списком.
     */
    private function isBlacklisted(string $uri): bool
    {
        $blacklist = $this->securityConfig['blacklist'] ?? [];
        if (empty($blacklist['enabled'])) {
            return false;
        }

        $path = strtolower(parse_url($uri, PHP_URL_PATH) ?? '/');
        $patterns = $blacklist['patterns'] ?? [];

        foreach ($patterns as $pattern) {
            if (str_contains($path, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Рендерит страницу 404 (сначала проверяется app/Pages/404.html, затем system/Pages/404.html).
     */
    private function renderNotFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');

        $userHtml = APPPATH . '/Pages/404.html';
        $userPhp  = APPPATH . '/Pages/404.php';
        $sysHtml  = SYSTEMPATH . '/Pages/404.html';
        $sysPhp   = SYSTEMPATH . '/Pages/404.php';

        $targetHtml = file_exists($userHtml) ? $userHtml : (file_exists($sysHtml) ? $sysHtml : null);
        $targetPhp  = file_exists($userPhp)  ? $userPhp  : (file_exists($sysPhp)  ? $sysPhp  : null);

        $viewData = ['message' => 'Запрошенная страница не найдена на сервере.'];
        if ($targetPhp !== null) {
            $res = (function ($f) { return require $f; })($targetPhp);
            if (is_array($res)) {
                $viewData = array_merge($viewData, $res);
            }
        }

        if ($targetHtml !== null) {
            echo $this->renderer->renderFile($targetHtml, $viewData);
        } else {
            echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>';
        }
    }

    /**
     * Рендерит страницу 500 (сначала проверяется app/Pages/500.html, затем system/Pages/500.html).
     */
    private function renderServerError(\Throwable $e): void
    {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');

        $userHtml = APPPATH . '/Pages/500.html';
        $sysHtml  = SYSTEMPATH . '/Pages/500.html';

        $targetHtml = file_exists($userHtml) ? $userHtml : (file_exists($sysHtml) ? $sysHtml : null);
        $viewData = [
            'message' => 'Произошла непредвиденная ошибка при обработке запроса.',
            'error'   => $e->getMessage(),
        ];

        if ($targetHtml !== null) {
            echo $this->renderer->renderFile($targetHtml, $viewData);
        } else {
            echo '<!DOCTYPE html><html><head><title>500 Server Error</title></head><body><h1>500 Internal Server Error</h1></body></html>';
        }
    }

    /**
     * Проверяет, является ли URI маршрутом установщика.
     */
    private function isInstallerRoute(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        return $path === '/installer' || $path === '/installer.php' || $path === '/installer/';
    }

    /**
     * Рендерит страницу установщика.
     *
     * Технические детали (абсолютные пути, версии модулей) обычному посетителю
     * не показываются: без верного ключа установки отдаётся только экран ввода
     * ключа, а сами проверки выполняются уже после его подтверждения.
     */
    private function renderInstaller(): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');

        $setupKey = Installer::ensureSetupKey();
        $given    = (string)($_GET['key'] ?? '');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Installer::checkSetupKey($given)) {
                // Обычный 403 без тела и без опознавательных признаков: по ответу
                // нельзя понять, что здесь установщик и что ключ существует.
                LogService::write(
                    'installer: POST без корректного ключа установки',
                    'warning',
                    'security.log'
                );

                http_response_code(403);
                exit;
            }

            $this->handleInstallerAction();
            return;
        }

        $unlocked = $setupKey['token'] !== '' && Installer::checkSetupKey($given);

        // Без верного ключа посетитель не должен видеть ничего технического:
        // ни путей, ни списка проверок, ни готового конфига Nginx, ни команд
        // для планировщика. Отдаётся нейтральная заглушка «ведутся работы»,
        // неотличимая от обычной страницы обслуживания.
        if (!$unlocked) {
            $gateHtml = SYSTEMPATH . '/Pages/installer_gate.html';

            echo file_exists($gateHtml)
                ? (string)file_get_contents($gateHtml)
                : '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Сайт временно недоступен</title></head>'
                    . '<body><h1>Сайт временно недоступен</h1></body></html>';

            return;
        }

        $installerHtml = SYSTEMPATH . '/Pages/installer.html';

        if (!file_exists($installerHtml)) {
            echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Установка</title></head>'
                . '<body><h1>Установка</h1><p>Шаблон установщика не найден.</p></body></html>';
            return;
        }

        $html = (string)file_get_contents($installerHtml);

        // Ключ не попадает в HTML никогда: в шаблон передаётся только признак
        // «доступ разрешён». Сам ключ берётся из storage/ при каждом запросе,
        // поэтому подделанная страница установщика бесполезна без файла на сервере.
        $boot = 'window.RAMEVA_INSTALLER = ' . json_encode(
            ['unlocked' => $unlocked],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';';

        $html = str_replace('</head>', '<script>' . $boot . '</script>' . "\n" . '</head>', $html);

        echo $html;
    }

    /**
     * Обрабатывает POST-действия установщика (AJAX).
     */
    private function handleInstallerAction(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $rawInput = file_get_contents('php://input');
        $input  = json_decode((string)$rawInput, true);
        $input  = is_array($input) ? $input : [];
        // GET-параметры доступны как надёжный резерв: на некоторых связках
        // Apache+PHP тело запроса (php://input) приходит искажённым.
        $input  = array_merge((array)($_GET ?: []), $input);
        // Поддержка и JSON-тела, и обычных POST-параметров (form-urlencoded),
        // и action через query-строку.
        $post   = $_POST ?: [];
        $action = (string)($input['action'] ?? ($post['action'] ?? ($_GET['action'] ?? '')));

        switch ($action) {
            case 'run_diagnostics':
            case 'run_diagnostics_protection':
                $result = Installer::runDiagnostics();
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                break;

            case 'verify_protection':
                // Повторная HTTP-проверка после того, как администратор применил
                // конфиг Nginx: доверять факту «файл записан» нельзя.
                $details = [];
                $blocked = 0;

                foreach (['/pages/index.html', '/app/Config/App.php', '/system/Boot.php', '/storage/logs/app.log'] as $path) {
                    $code = Installer::selfCheckCode($path);
                    $ok   = in_array($code, [403, 404], true);

                    if ($ok) {
                        $blocked++;
                    }

                    $details[$path] = $code;
                }

                echo json_encode([
                    'success' => $blocked > 0,
                    'codes'   => $details,
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'ensure_token':
                $token = Installer::ensureCronToken();
                $state = Installer::getState();

                echo json_encode([
                    'token'    => $token['token'],
                    'created'  => $token['created'],
                    'base'     => (string)($state['site_url'] ?? ''),
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'finalize':
                $diagnostics = $input['diagnostics'] ?? [];
                $posture     = (string)($input['posture'] ?? 'degraded');
                $reason      = isset($input['reason']) ? (string)$input['reason'] : null;

                if (!is_array($diagnostics)) {
                    $diagnostics = [];
                }

                if ($posture !== 'secure') {
                    $posture = 'degraded';

                    if ($reason === null || trim($reason) === '') {
                        http_response_code(400);
                        echo json_encode(['error' => 'Degraded posture requires a reason (reason)'], JSON_UNESCAPED_UNICODE);
                        break;
                    }
                }

                $success = Installer::finalize($diagnostics, $posture, $reason);

                echo json_encode([
                    'success' => $success,
                    'posture' => $posture,
                    'reason'  => $reason,
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'check_state':
                $state = Installer::getState();
                echo json_encode([
                    'installed' => (bool)($state['installed'] ?? false),
                    'posture'   => (string)($state['security_posture'] ?? 'not_installed'),
                    'reason'    => $state['degraded_reason'] ?? null,
                ], JSON_UNESCAPED_UNICODE);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action']);
        }

        exit;
    }

    /**
     * Отдаёт физический файл, если запрошен именно он.
     *
     * Правила:
     * - служебные файлы корня (robots.txt, sitemap.xml, llms.txt, favicon.ico)
     *   отдаются по буквальному имени — суффикс и канонический редирект к ним
     *   не применяются ни при какой настройке suffix;
     * - прочие файлы (верификация сторонних сервисов, картинки, assets/)
     *   отдаются как есть — но только вне внутренних каталогов;
     * - посторонний .php в корне: 403 и запись в integrity.log как критичное
     *   событие (немедленно, отдельно от расписания полной сверки system/).
     *
     * @return bool true, если запрос обслужен и ядру делать больше нечего
     */
    private function serveRootFile(string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        if ($path === '/' || $path === '') {
            return false;
        }

        $relative = ltrim(rawurldecode($path), '/');

        // Внутренние каталоги не отдаются никогда: это исходные шаблоны с
        // плейсхолдерами, конфигурация и код ядра. Проверка идёт по нормализованному
        // пути, поэтому /System/, /system/ и /system// эквивалентны.
        //
        // На Apache/Nginx эти каталоги закрыты на уровне сервера (.htaccess,
        // location-блок) и запрос сюда вообще не доходит. Ответ 404 здесь —
        // дополнительный слой: если серверная защита не настроена, запрос всё
        // равно не получит ни шаблон, ни конфигурацию.
        if ($this->isInternalPath($relative)) {
            http_response_code(404);

            return true;
        }

        $filePath = ROOTPATH . '/' . $relative;

        if (!is_file($filePath)) {
            return false;
        }

        // Точка входа фреймворка: index.php исполняется только через фронт-контроллер
        // (сюда управление не доходит), cron.php — своя точка входа.
        $basename = strtolower(basename($filePath));

        if ($basename === 'index.php' || $basename === 'cron.php') {
            return false;
        }

        if (strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION)) === 'php') {
            // Посторонний PHP-файл в корне: не исполняется и не отдаётся.
            http_response_code(403);
            $this->reportStrayRootPhp($relative);

            return true;
        }

        $this->sendFile($filePath);

        return true;
    }

    /**
     * Относится ли путь к внутренним каталогам фреймворка.
     */
    private function isInternalPath(string $relative): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $relative));
        $normalized = (string)preg_replace('#/+#', '/', $normalized);

        foreach (['pages/', 'app/', 'system/', 'storage/'] as $prefix) {
            if ($normalized === rtrim($prefix, '/') || str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Фиксирует посторонний PHP-файл в корне: критичное событие в
     * integrity.log и уведомление администратору, независимо от настроек
     * уведомлений Tripwire.
     */
    private function reportStrayRootPhp(string $relative): void
    {
        \System\Security\Tripwire::reportStrayFile($relative);
    }

    /**
     * Отправляет файл с правильными заголовками.
     */
    private function sendFile(string $filePath): void
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'txt'  => 'text/plain; charset=UTF-8',
            'xml'  => 'application/xml; charset=UTF-8',
            'ico'  => 'image/x-icon',
            'html' => 'text/html; charset=UTF-8',
            'css'  => 'text/css; charset=UTF-8',
            'js'   => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'ttf'  => 'font/ttf',
            'eot'  => 'application/vnd.ms-fontobject',
        ];
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=3600');
        readfile($filePath);
        exit;
    }
}
