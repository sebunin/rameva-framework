<?php
/** Rameva 2 — извлечение собственных метаданных страницы до её рендеринга. */
declare(strict_types=1);
namespace System\Rendering;
if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }

final class PageMetadata
{
    /** Только head полного документа служит источником метаданных. */
    public static function collect(string $html): array
    {
        $data = [];
        if (!preg_match('~<head\b[^>]*>(.*?)</head\s*>~is', $html, $head)) return $data;
        $head = preg_replace('~<!--.*?-->|<script\b[^>]*>.*?</script\s*>~is', '', $head[1]) ?? $head[1];
        if (preg_match('~<title\b[^>]*>(.*?)</title\s*>~is', $head, $m) && !str_contains($m[1], '{{')) {
            $data['title'] = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        preg_match_all('~<meta\b[^>]*>~is', $head, $tags);
        foreach ($tags[0] as $tag) {
            $a = self::attributes($tag);
            $key = strtolower($a['property'] ?? $a['name'] ?? '');
            if (!isset($a['content']) || str_contains($a['content'], '{{')) continue;
            if ($key === 'description') $data['description'] = $a['content'];
            elseif (str_starts_with($key, 'og:') || str_starts_with($key, 'twitter:')) $data['_page_social'][$key] = $a['content'];
        }
        return $data;
    }

    public static function attributes(string $tag): array
    {
        preg_match_all('~\s([\w:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~', $tag, $matches, PREG_SET_ORDER);
        $a = [];
        foreach ($matches as $m) $a[strtolower($m[1])] = html_entity_decode($m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $a;
    }

    /** Удаляет только управляемые SEO-теги из head, не затрагивая JSON-LD. */
    public static function strip(string $html): string
    {
        return preg_replace_callback('~(<head\b[^>]*>)(.*?)(</head\s*>)~is', static function (array $h): string {
            $body = preg_replace_callback('~<!--.*?-->|<script\b[^>]*>.*?</script\s*>|<title\b[^>]*>.*?</title\s*>|<(?:meta|link)\b[^>]*>~is', static function (array $m): string {
                if (preg_match('~^<(?:script|!)~i', $m[0])) return $m[0];
                if (preg_match('~^<title\b~i', $m[0])) return '';
                $a = self::attributes($m[0]);
                $key = strtolower($a['property'] ?? $a['name'] ?? '');
                if ($key === 'description' || str_starts_with($key, 'og:') || str_starts_with($key, 'twitter:') || in_array('canonical', preg_split('~\s+~', strtolower($a['rel'] ?? '')), true)) return '';
                return $m[0];
            }, $h[2]);
            return $h[1] . $body . $h[3];
        }, $html) ?? $html;
    }

    /** Сохраняемые блоки и их верхнеуровневые типы (включая списки, не @graph). */
    public static function jsonLd(string $html): array
    {
        $scripts = []; $types = [];
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $open = substr($m[0], 0, strpos($m[0], '>') + 1);
            if (strtolower(self::attributes($open)['type'] ?? '') !== 'application/ld+json') continue;
            $scripts[] = $m[0];
            $json = json_decode($m[1], true);
            if (!is_array($json)) continue;
            foreach (array_is_list($json) ? $json : [$json] as $item) {
                if (is_array($item)) foreach ((array)($item['@type'] ?? []) as $type) if (is_string($type)) $types[] = $type;
            }
        }
        return [$scripts, array_unique($types)];
    }
}