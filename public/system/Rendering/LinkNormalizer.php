<?php
/**
 * Rameva 2 — нормализация ссылок готовых HTML-страниц.
 * Сканер сохраняет исходную разметку: меняются только значения URL-атрибутов
 * и CSS url(). JavaScript, комментарии и примеры кода не интерпретируются.
 */
declare(strict_types=1);

namespace System\Rendering;

if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }

final class LinkNormalizer
{
    private array $assets;
    private array $routes;
    private \Closure $canonical;

    /** $canonical — тот же построитель канонического пути, который использует Router. */
    public function __construct(array $assets, array $routes, callable $canonical)
    {
        $this->assets = array_fill_keys(array_is_list($assets) ? $assets : array_keys($assets), true);
        $this->routes = array_fill_keys(array_keys($routes), true);
        $this->canonical = \Closure::fromCallable($canonical);
    }

    public function normalize(string $html): string
    {
        $output = '';
        $offset = 0;
        $length = strlen($html);
        while ($offset < $length) {
            $start = strpos($html, '<', $offset);
            if ($start === false) { $output .= substr($html, $offset); break; }
            $output .= substr($html, $offset, $start - $offset);
            if (substr($html, $start, 4) === '<!--') {
                $end = strpos($html, '-->', $start + 4);
                $end = $end === false ? $length : $end + 3;
                $output .= substr($html, $start, $end - $start);
                $offset = $end;
                continue;
            }
            $end = self::tagEnd($html, $start);
            $tag = substr($html, $start, $end - $start);
            $offset = $end;
            if (!preg_match('~^<\s*([a-z][a-z0-9:-]*)\b~i', $tag, $match)) {
                $output .= $tag;
                continue;
            }
            $name = strtolower($match[1]);
            // Даже поздний <base> отменяет все предшествующие изменения.
            if ($name === 'base') { return $html; }
            $output .= $this->attributes($tag);
            if (in_array($name, ['script', 'style', 'pre', 'code', 'textarea'], true)) {
                if (preg_match('~</' . $name . '\s*>~i', $html, $close, PREG_OFFSET_CAPTURE, $offset)) {
                    $stop = $close[0][1];
                    $body = substr($html, $offset, $stop - $offset);
                    $output .= $name === 'style' ? $this->css($body) : $body;
                    $output .= $close[0][0];
                    $offset = $stop + strlen($close[0][0]);
                } else {
                    $output .= substr($html, $offset);
                    break;
                }
            }
        }
        return $output;
    }

    /** Конец одного тега с учётом > внутри кавычек. */
    public static function tagEnd(string $html, int $start): int
    {
        $quote = null;
        for ($i = $start + 1, $n = strlen($html); $i < $n; $i++) {
            $char = $html[$i];
            if ($quote !== null) { if ($char === $quote) $quote = null; }
            elseif ($char === '"' || $char === "'") $quote = $char;
            elseif ($char === '>') return $i + 1;
        }
        return strlen($html);
    }

    private function attributes(string $tag): string
    {
        // Регулярное выражение применяется только к одному уже выделенному тегу,
        // не ко всему HTML: в скрипт или текст страницы попасть невозможно.
        return preg_replace_callback('~(\s)([a-z_:][a-z0-9_:.-]*)(\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', function (array $m): string {
            $name = strtolower($m[2]);
            $quote = str_starts_with($m[0], $m[1] . $m[2] . $m[3] . '"') ? '"' : (str_starts_with($m[0], $m[1] . $m[2] . $m[3] . "'") ? "'" : '');
            $value = $quote === '"' ? $m[4] : ($quote === "'" ? $m[5] : ($m[6] ?? ''));
            if (str_contains($value, '{{')) return $m[0];
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $changed = match ($name) {
                'href', 'src', 'poster', 'data-src' => $this->url($decoded, $name === 'href'),
                'srcset' => $this->srcset($decoded),
                'style' => $this->css($decoded),
                default => $decoded,
            };
            if ($changed === $decoded) return $m[0];
            return $m[1] . $m[2] . $m[3] . $quote . htmlspecialchars($changed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $quote;
        }, $tag) ?? $tag;
    }

    private function url(string $value, bool $link = false): string
    {
        if ($value === '' || str_contains($value, '{{') || preg_match('~^(?:[#?]|//|[a-z][a-z0-9+.-]*:)~i', $value)) return $value;
        $parts = preg_split('~([?#].*)~s', $value, 2, PREG_SPLIT_DELIM_CAPTURE);
        $path = $parts[0];
        $tail = $parts[1] ?? '';
        $relative = !str_starts_with($path, '/');
        $clean = preg_replace('~^(?:(?:\./|\.\./))+~', '', $path) ?? $path;
        if ($relative) {
            $asset = preg_replace('~^assets/~', '', $clean);
            if (isset($this->assets[$asset])) return '/assets/' . $asset . $tail;
        }
        if (!$link) return $value;
        $logical = '/' . ltrim($clean, '/');
        // Суффикс снимает сам Router; html/htm/php — входные имена AI-страниц.
        $logical = preg_replace('~\.(?:html?|php)$~i', '', rtrim($logical, '/')) ?? $logical;
        $logical = preg_replace('~(?:^|/)index$~i', '', $logical) ?? $logical;
        $logical = '/' . trim($logical, '/');
        if (!isset($this->routes[$logical])) return $value;
        return ($this->canonical)($logical) . $tail;
    }

    private function srcset(string $value): string
    {
        // data: URL содержит собственные запятые: не разбиваем его как список.
        return preg_replace_callback('~(^|,)(\s*)([^\s,]+)([^,]*)~', function (array $m): string {
            if (str_starts_with(strtolower($m[3]), 'data:')) return $m[0];
            return $m[1] . $m[2] . $this->url($m[3]) . $m[4];
        }, $value) ?? $value;
    }

    /** CSS-сканер пропускает строки и комментарии; правит только функцию url(). */
    private function css(string $css): string
    {
        $out = '';
        for ($i = 0, $n = strlen($css); $i < $n;) {
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                $end = $end === false ? $n : $end + 2;
            } elseif ($css[$i] === '"' || $css[$i] === "'") {
                $quote = $css[$i]; $end = $i + 1;
                while ($end < $n) { if ($css[$end] === '\\') $end += 2; elseif ($css[$end++] === $quote) break; }
            } elseif (strncasecmp(substr($css, $i, 4), 'url(', 4) === 0) {
                $end = $i + 4; $quote = null;
                for (; $end < $n; $end++) {
                    if ($css[$end] === '\\') { $end++; continue; }
                    if ($quote !== null) { if ($css[$end] === $quote) $quote = null; }
                    elseif ($css[$end] === '"' || $css[$end] === "'") $quote = $css[$end];
                    elseif ($css[$end] === ')') break;
                }
                if ($end >= $n) { $out .= substr($css, $i); break; }
                $inside = substr($css, $i + 4, $end - $i - 4);
                $token = trim($inside); $q = '';
                if (strlen($token) >= 2 && ($token[0] === '"' || $token[0] === "'") && $token[-1] === $token[0]) { $q = $token[0]; $token = substr($token, 1, -1); }
                $url = $this->url($token);
                $out .= substr($css, $i, 4) . ($url === $token ? $inside : str_replace($q . $token . $q, $q . $url . $q, $inside)) . ')';
                $i = $end + 1;
                continue;
            } else { $out .= $css[$i++]; continue; }
            $out .= substr($css, $i, $end - $i); $i = $end;
        }
        return $out;
    }
}