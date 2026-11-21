<?php
/** Rameva 2 — CLI-проверка SEO приоритетов, удаления дублей и JSON-LD. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RAMEVA_CORE', true);
define('ROOTPATH', dirname(__DIR__) . '/public');
define('APPPATH', ROOTPATH . '/app');
define('SYSTEMPATH', ROOTPATH . '/system');
spl_autoload_register(static function ($class): void { $file = SYSTEMPATH . '/' . str_replace('\\', '/', substr($class, 7)) . '.php'; if (str_starts_with($class, 'System\\') && is_file($file)) require $file; });
$html = '<html><head><title>Page title</title><meta name="description" content="Page description"><link rel="canonical" href="https://wrong.test/"><meta property="og:title" content="Page OG"><meta name="twitter:title" content="Page Twitter"><script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage","name":"Preserved"}</script></head><body><main><h1>Page heading</h1></main></body></html>';
$fails = 0;
foreach ([[], ['title'=>'Controller title', 'description'=>'Controller description']] as $controller) {
    foreach ([null, 'main'] as $layout) {
        $data = array_replace(\System\Rendering\PageMetadata::collect($html), $controller);
        $data['_controller_keys'] = array_keys($controller);
        $data['_layout'] = $layout;
        $seo = new \System\Services\SeoService();
        $data['seo_title'] = $seo->resolveTitle($data);
        $data['meta'] = $seo->generateHeadTags('/about', $data, $html);
        $data['_raw'] = ['meta'];
        $out = (new \System\Rendering\Renderer())->renderPage($html, $data);
        $tests = [
            'one title'=>substr_count($out, '<title>') === 1,
            'one description'=>substr_count($out, 'name="description"') === 1,
            'one canonical'=>substr_count($out, 'rel="canonical"') === 1,
            'one og'=>substr_count($out, 'property="og:title"') === 1,
            'one twitter'=>substr_count($out, 'name="twitter:title"') === 1,
            'priority'=>str_contains($out, $controller === [] ? 'Page OG' : 'Controller title'),
            'description'=>str_contains($out, $controller['description'] ?? 'Page description'),
            'jsonld'=>substr_count($out, '"@type":"WebPage"') === 1 && str_contains($out, 'Preserved'),
            'canonical regenerated'=>!str_contains($out, 'wrong.test'),
        ];
        foreach ($tests as $name=>$ok) { echo ($ok?'PASS ':'FAIL ') . ($layout ?? 'no-layout') . ' ' . $name . "\n"; if (!$ok) $fails++; }
    }
}
echo "FAIL=$fails\n";
exit($fails ? 1 : 0);