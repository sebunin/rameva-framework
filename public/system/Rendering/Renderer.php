<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Rendering
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Rendering;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс Renderer отвечает за легковесный runtime-рендеринг страниц.
 *
 * Логика работы с шаблонами (Layouts):
 * 1. Имя шаблона указывается в данных контроллера через ключ '_layout' (например, '_layout' => 'default' или 'main').
 * 2. Шаблон ищется сначала в app/Layouts/{name}.html, если не найден — в system/Layouts/{name}.html.
 * 3. Если шаблон указан:
 *    - Если страница является полным HTML-документом (содержит <body>...</body>),
 *      извлекается ТОЛЬКО содержимое между <body> и </body>.
 *      Теги <head>, <title>, <meta>, а также header/footer страницы игнорируются.
 *    - В шаблоне плейсхолдер {{ body }} (или {{ content }}) заменяется на извлечённое содержимое страницы.
 *    - Все остальные плейсхолдеры шаблона заполняются данными контроллера.
 * 4. Если шаблон не указан: страница рендерится как есть, без извлечения body.
 */
class Renderer
{
    private ?LinkNormalizer $normalizer = null;

    public function setNormalizer(LinkNormalizer $normalizer): void
    {
        $this->normalizer = $normalizer;
    }
    /**
     * Рендерит HTML-файл страницы с опциональным слиянием с Layout.
     *
     * @param string $pageTemplatePath Полный путь к HTML-файлу страницы
     * @param array $data Ассоциативный массив переменных для подстановки
     * @return string
     */
    public function renderFile(string $pageTemplatePath, array $data = []): string
    {
        if (!file_exists($pageTemplatePath)) {
            return '';
        }

        $pageContent = (string)file_get_contents($pageTemplatePath);
        return $this->renderPage($pageContent, $data);
    }

    /**
     * Рендерит контент страницы с учетом Layout и плейсхолдеров.
     *
     * @param string $pageContent Исходный HTML страницы
     * @param array $data Переменные контроллера
     * @return string
     */
    public function renderPage(string $pageContent, array $data = []): string
    {
        if ($this->normalizer !== null) $pageContent = $this->normalizer->normalize($pageContent);
        [$pageJsonLd] = PageMetadata::jsonLd($pageContent);
        if (!empty($data['meta'])) $pageContent = PageMetadata::strip($pageContent);
        $layoutName = $data['_layout'] ?? null;

        // Если шаблон не указан — рендерим страницу как есть
        if (empty($layoutName)) {
            $rendered = $this->renderString($pageContent, $data, $data['_raw'] ?? []);
            return $this->injectSeoHead($rendered, $data, $pageContent);
        }

        $layoutPath = $this->resolveLayoutPath((string)$layoutName);

        // Если файл шаблона не найден — отдаём страницу как есть
        if ($layoutPath === null || !file_exists($layoutPath)) {
            $rendered = $this->renderString($pageContent, $data, $data['_raw'] ?? []);
            return $this->injectSeoHead($rendered, $data, $pageContent);
        }

        $layoutContent = (string)file_get_contents($layoutPath);
        if ($this->normalizer !== null) $layoutContent = $this->normalizer->normalize($layoutContent);
        if (!empty($data['meta'])) $layoutContent = PageMetadata::strip($layoutContent);

        // Извлекаем только содержимое <body> страницы, если страница — полноценный документ
        $extractedBody = $this->extractBodyContent($pageContent);
        foreach ($pageJsonLd as $script) {
            if (!str_contains($extractedBody, $script)) $extractedBody .= "\n" . $script;
        }

        // Сначала подставляем переменные страницы в тело страницы
        // (_raw контроллера действует и на плейсхолдеры страницы, а не только на Layout)
        $renderedBody = $this->renderString($extractedBody, $data, $data['_raw'] ?? []);

        // Помещаем полученный body в шаблон
        $layoutData = $data;
        $layoutData['body'] = $renderedBody;
        $layoutData['content'] = $renderedBody;

        // Рендерим итоговый шаблон со всеми переменными (включая {{ body }})
        $rawFields = array_merge(['body', 'content'], $data['_raw'] ?? []);
        $rendered = $this->renderString($layoutContent, $layoutData, $rawFields);

        return $this->injectSeoHead($rendered, $layoutData, $layoutContent);
    }

    /**
     * Гарантирует попадание сгенерированных метаданных ({{ meta }}) в <head> документа.
     *
     * Если шаблон (Layout или сама страница) содержит плейсхолдер {{ meta }},
     * подстановка уже выполнена на этапе рендера. Если плейсхолдера нет,
     * но контроллер передал готовый блок метаданных в ключе 'meta',
     * он вставляется непосредственно перед закрывающим тегом </head>.
     *
     * @param string $html Уже отрендеренный HTML
     * @param array $data Данные рендера
     * @param string $template Исходный шаблон (для проверки наличия плейсхолдера)
     * @return string
     */
    private function injectSeoHead(string $html, array $data, string $template): string
    {
        $headTags = $data['meta'] ?? '';
        if (!is_string($headTags) || $headTags === '') {
            return $html;
        }

        // Плейсхолдер уже отработал при подстановке переменных
        if (preg_match('/\{\{\s*meta\s*\}\}/', $template)) {
            return $html;
        }

        if (stripos($html, '</head>') === false) {
            return $html;
        }

        return preg_replace_callback('/<\/head>/i', static function () use ($headTags): string {
            return "    {$headTags}\n</head>";
        }, $html, 1) ?? $html;
    }

    /**
     * Разрешает путь к файлу шаблона: приоритет app/Layouts -> system/Layouts.
     *
     * @param string $name Имя шаблона (например, 'default' или 'main')
     * @return string|null
     */
    public function resolveLayoutPath(string $name): ?string
    {
        $cleanName = basename($name);
        if (!str_ends_with($cleanName, '.html')) {
            $cleanName .= '.html';
        }

        $userLayout = APPPATH . '/Layouts/' . $cleanName;
        if (file_exists($userLayout)) {
            return $userLayout;
        }

        $sysLayout = SYSTEMPATH . '/Layouts/' . $cleanName;
        if (file_exists($sysLayout)) {
            return $sysLayout;
        }

        return null;
    }

    /**
     * Извлекает содержимое основного контента страницы для слияния с Layout:
     * 1. Если в странице есть тег <main[^>]*>...</main>, извлекается ТОЛЬКО его содержимое.
     *    Любые теги <header>/<footer> внутри <main> (например, в статьях <article>) не затрагиваются.
     * 2. Если тега <main> нет, извлекается содержимое тега <body>...</body>,
     *    и удаляются ТОЛЬКО <header>, <footer> и <nav>, являющиеся прямыми потомками body
     *    верхнего уровня страницы, не затрагивая вложенные блоки в секциях и статьях.
     *
     * @param string $html Исходный HTML страницы
     * @return string
     */
    public function extractBodyContent(string $html): string
    {
        // 1. Приоритет тега <main>: извлекаем чистое основное содержимое
        if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $html, $matches)) {
            return trim($matches[1]);
        }

        // 2. Если <main> нет, берем содержимое <body>
        $content = $html;
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $content = $matches[1];
        }

        // Удаляем только шапку/подвал/навигацию верхнего уровня, если они идут до или после основного контента
        // Не трогаем вложенные <header>/<footer> внутри <article>, <section>, <div>
        $content = preg_replace('/^\s*<(?:header|nav)[^>]*>.*?<\/(?:header|nav)>\s*/is', '', $content) ?? $content;
        $content = preg_replace('/\s*<(?:footer|nav)[^>]*>.*?<\/(?:footer|nav)>\s*$/is', '', $content) ?? $content;

        return trim($content);
    }

    /**
     * Выполняет подстановку плейсхолдеров {{ var }} в строке.
     *
     * @param string $content Исходный HTML-контент
     * @param array $data Переменные для подстановки
     * @param array $rawKeys Ключи, значение которых не нужно экранировать через htmlspecialchars
     * @return string
     */
    public function renderString(string $content, array $data = [], array $rawKeys = []): string
    {
        $content = preg_replace_callback('/\{\{\s*form_fields:([a-zA-Z][a-zA-Z0-9_-]{0,63})\s*\}\}/', static function (array $matches): string {
            if (!headers_sent()) {
                header('Cache-Control: private, no-store');
            }
            return (new \System\Services\FormService())->renderHiddenFields($matches[1]);
        }, $content) ?? $content;

        if (empty($data)) {
            return $content;
        }

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function ($matches) use ($data, $rawKeys) {
            $key = $matches[1];
            if (array_key_exists($key, $data)) {
                $val = $data[$key];
                if (!is_scalar($val)) {
                    return '';
                }

                $strVal = (string)$val;
                // Ключи вроде body/content вставляются как «сырой» HTML
                if (in_array($key, $rawKeys, true)) {
                    return $strVal;
                }

                return htmlspecialchars($strVal, ENT_QUOTES, 'UTF-8');
            }
            return '';
        }, $content) ?? $content;
    }
}
