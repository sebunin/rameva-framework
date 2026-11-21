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
 * Класс SeoService отвечает за автоматическое формирование SEO/GEO метаданных.
 */
class SeoService
{
    private array $appConfig;

    public function __construct()
    {
        $configFile = APPPATH . '/Config/App.php';
        $this->appConfig = file_exists($configFile) ? (require $configFile) : [];
    }

    /**
     * Генерирует полный блок метаданных для секции <head>.
     */
    public function generateHeadTags(string $uri, array $viewData, string $pageRawContent = ''): string
    {
        $controllerKeys = $viewData['_controller_keys'] ?? array_keys($viewData);
        $viewData = array_replace(\System\Rendering\PageMetadata::collect($pageRawContent), $viewData);
        $tags = [];
        $title = $this->resolveTitle($viewData);
        $tags[] = '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        $canonicalUrl = $this->resolveCanonical($uri, $viewData);
        $tags[] = '<link rel="canonical" href="' . htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') . '">';

        $description = $this->resolveDescription($viewData, $pageRawContent);
        if (!empty($description)) {
            $tags[] = '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '">';
        }

        // OpenGraph
        $tags[] = '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">';
        if (!empty($description)) {
            $tags[] = '<meta property="og:description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '">';
        }
        $tags[] = '<meta property="og:url" content="' . htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') . '">';
        $tags[] = '<meta property="og:type" content="' . htmlspecialchars((string)($viewData['og_type'] ?? 'website'), ENT_QUOTES, 'UTF-8') . '">';

        $imageUrl = $this->resolveImage($viewData);
        if (!empty($imageUrl)) {
            $tags[] = '<meta property="og:image" content="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '">';
        }

        // Twitter Card
        $tags[] = '<meta name="twitter:card" content="summary_large_image">';
        $tags[] = '<meta name="twitter:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">';
        if (!empty($description)) {
            $tags[] = '<meta name="twitter:description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '">';
        }
        if (!empty($imageUrl)) {
            $tags[] = '<meta name="twitter:image" content="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '">';
        }

        // Schema.org (JSON-LD)
        $schemaHtml = $this->resolveSchema($canonicalUrl, $title, $description, $imageUrl, $viewData);
        [, $existingTypes] = \System\Rendering\PageMetadata::jsonLd($pageRawContent);
        [, $generatedTypes] = \System\Rendering\PageMetadata::jsonLd($schemaHtml);
        if (array_intersect($existingTypes, $generatedTypes) !== []) $schemaHtml = '';
        if (!empty($schemaHtml)) {
            $tags[] = $schemaHtml;
        }

        // Собственные social-теги сохраняются, кроме полей, явно заданных контроллером.
        $social = (array)($viewData['_page_social'] ?? []);
        $overrides = ['title' => ['og:title', 'twitter:title'], 'description' => ['og:description', 'twitter:description'], 'image' => ['og:image', 'twitter:image'], 'og_image' => ['og:image', 'twitter:image'], 'canonical' => ['og:url'], 'og_type' => ['og:type']];
        foreach ($controllerKeys as $key) foreach ($overrides[$key] ?? [] as $tag) unset($social[$tag]);
        foreach ($social as $key => $value) {
            if (!preg_match('~^(?:og|twitter):[a-z0-9:_-]+$~i', $key)) continue;
            $attribute = str_starts_with($key, 'og:') ? 'property' : 'name';
            $replacement = '<meta ' . $attribute . '="' . $key . '" content="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
            $found = false;
            foreach ($tags as &$tag) {
                if (str_contains($tag, $attribute . '="' . $key . '"')) { $tag = $replacement; $found = true; break; }
            }
            unset($tag);
            if (!$found) $tags[] = $replacement;
        }
        return implode("\n    ", $tags);
    }

    public function resolveTitle(array $data): string
    {
        $pageTitle = (string)($data['title'] ?? ($this->appConfig['title'] ?? 'RAMEVA 2'));
        $siteName  = (string)($data['sitename'] ?? ($this->appConfig['sitename'] ?? ''));
        $position  = (int)($this->appConfig['sitename_titles'] ?? 0);

        // Не дублируем название сайта, если оно уже присутствует в заголовке страницы
        if ($siteName === '' || $position === 0 || mb_stripos($pageTitle, $siteName) !== false) {
            return $pageTitle;
        }

        if ($position === 1) {
            return "{$siteName} — {$pageTitle}";
        }

        return "{$pageTitle} — {$siteName}";
    }

    public function resolveCanonical(string $uri, array $data): string
    {
        if (!empty($data['canonical'])) {
            return (string)$data['canonical'];
        }

        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '/';

        return Url::to($path, [], true);
    }

    public function resolveDescription(array $data, string $rawContent = ''): string
    {
        if (!empty($data['description'])) {
            return (string)$data['description'];
        }

        if (!empty($data['text'])) {
            $clean = strip_tags((string)$data['text']);
            return mb_substr($clean, 0, 160, 'UTF-8');
        }

        if (!empty($rawContent)) {
            $htmlToExtract = $rawContent;
            if (preg_match('/<main[^>]*>(.*?)<\/main>/is', $rawContent, $m)) {
                $htmlToExtract = $m[1];
            } elseif (preg_match('/<body[^>]*>(.*?)<\/body>/is', $rawContent, $m)) {
                $htmlToExtract = $m[1];
            }

            // Подставляем значения плейсхолдеров страницы, чтобы в описание
            // не попали неразрешённые {{ heading }}-подобные конструкции
            $htmlToExtract = (string)preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/',
                static function ($pm) use ($data): string {
                    $key = $pm[1];
                    $val = array_key_exists($key, $data) ? $data[$key] : '';
                    return is_scalar($val) ? (string)$val : '';
                },
                $htmlToExtract
            );

            $htmlToExtract = preg_replace('/<(script|style|header|footer|nav)[^>]*>.*?<\/\1>/is', '', $htmlToExtract) ?? $htmlToExtract;
            $clean = trim(preg_replace('/\s+/', ' ', strip_tags($htmlToExtract)));

            if (!empty($clean)) {
                return mb_substr($clean, 0, 160, 'UTF-8') . (mb_strlen($clean, 'UTF-8') > 160 ? '...' : '');
            }
        }

        return (string)($this->appConfig['meta_description'] ?? '');
    }

    public function resolveImage(array $data): string
    {
        $img = $data['image'] ?? ($data['og_image'] ?? ($this->appConfig['image'] ?? ''));
        if (empty($img)) {
            return '';
        }

        if (str_starts_with((string)$img, 'http://') || str_starts_with((string)$img, 'https://')) {
            return (string)$img;
        }

        $base = rtrim((string)($this->appConfig['siteurl'] ?? Url::base()), '/');
        return $base . '/' . ltrim((string)$img, '/');
    }

    public function resolveSchema(string $url, string $title, string $description, string $image, array $data): string
    {
        if (isset($data['schema']) && $data['schema'] instanceof Schema) {
            return $data['schema']->build();
        }

        if (!empty($data['schema_type'])) {
            $type = (string)$data['schema_type'];
            $schema = Schema::create($type)
                ->set('name', $title)
                ->set('url', $url);

            if (!empty($description)) {
                $schema->set('description', $description);
            }
            if (!empty($image)) {
                $schema->set('image', $image);
            }

            if (!empty($data['schema_data']) && is_array($data['schema_data'])) {
                foreach ($data['schema_data'] as $k => $v) {
                    $schema->set($k, $v);
                }
            }

            if (!empty($data['speakable']) && is_array($data['speakable'])) {
                $schema->withSpeakable($data['speakable']);
            }

            return $schema->build();
        }

        $defaultSchema = Schema::webPage([
            'name'        => $title,
            'url'         => $url,
            'description' => $description,
        ]);

        if (!empty($image)) {
            $defaultSchema->set('image', $image);
        }

        return $defaultSchema->build();
    }
}