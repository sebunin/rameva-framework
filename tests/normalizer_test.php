<?php
/** Rameva 2 — CLI-регрессия нормализации готовых страниц. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('RAMEVA_CORE', true);
require __DIR__ . '/../public/system/Rendering/LinkNormalizer.php';
use System\Rendering\LinkNormalizer;
$assets = ['css/style.css', 'js/app.js', 'images/logo.svg', 'images/a.png', 'images/b.png', 'fonts/a.woff2'];
$routes = array_fill_keys(['/', '/about', '/contact', '/company', '/company/info'], []);
$normalizer = new LinkNormalizer($assets, $routes, static fn($p) => $p);
$fails = 0; $count = 0;
$check = static function ($name, $actual, $expected) use (&$fails, &$count): void {
    $count++; $ok = $actual === $expected;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) { $fails++; echo var_export([$actual, $expected], true) . "\n"; }
};
foreach (['css/style.css', './css/style.css', 'assets/css/style.css', '../assets/css/style.css'] as $url) $check($url, $normalizer->normalize('<link href="' . $url . '">'), '<link href="/assets/css/style.css">');
$check('query', $normalizer->normalize('<img src="images/logo.svg?v=2">'), '<img src="/assets/images/logo.svg?v=2">');
$check('srcset', $normalizer->normalize('<img srcset="images/a.png 1x, images/b.png 2x">'), '<img srcset="/assets/images/a.png 1x, /assets/images/b.png 2x">');
$check('inline css', $normalizer->normalize('<i style="background:url(images/a.png)">'), '<i style="background:url(/assets/images/a.png)">');
$check('style block', $normalizer->normalize("<style>@font-face{src:url('fonts/a.woff2')}</style>"), "<style>@font-face{src:url('/assets/fonts/a.woff2')}</style>");
foreach (['/css/style.css', 'https://cdn.example/x.css', '//cdn.example/x.js', 'data:image/png;base64,abc', '#top', '?x=1', 'mailto:a@b.test', 'tel:123', 'javascript:alert(1)', '{{ image }}', 'images/none.png'] as $url) {
    $input = '<img src="' . $url . '">'; $check('unchanged ' . $url, $normalizer->normalize($input), $input);
}
foreach (['script', 'pre', 'code'] as $tag) { $input = '<' . $tag . '><img src="images/a.png"></' . $tag . '>'; $check($tag, $normalizer->normalize($input), $input); }
$input = '<!-- <img src="images/a.png"> -->'; $check('comment', $normalizer->normalize($input), $input);
foreach (['index.html'=>'/', 'about.html'=>'/about', './about.html#team'=>'/about#team', '../about.html'=>'/about', 'company/info.html'=>'/company/info', 'company/index.html'=>'/company', 'company/'=>'/company', '/about.html'=>'/about', 'missing.html'=>'missing.html'] as $a=>$b) $check($a, $normalizer->normalize('<a href="'.$a.'">'), '<a href="'.$b.'">');
$suffix = new LinkNormalizer($assets, $routes, static fn($p) => $p === '/' ? '/' : $p . '.html');
foreach (['about.html'=>'/about.html', 'index.html'=>'/', 'company/index.html'=>'/company.html'] as $a=>$b) $check('suffix '.$a, $suffix->normalize('<a href="'.$a.'">'), '<a href="'.$b.'">');
$input = '<img src="images/a.png"><base href="/other/">'; $check('base', $normalizer->normalize($input), $input);
$input = str_repeat('<a href="about.html">A</a><img src="images/a.png">', 1024);
$start = hrtime(true); $result = $normalizer->normalize($input); $ms = (hrtime(true)-$start)/1e6;
$check('idempotence', $normalizer->normalize($result), $result);
echo 'BENCH bytes=' . strlen($input) . ' ms=' . round($ms, 3) . "\nTOTAL=$count FAIL=$fails\n";
exit($fails ? 1 : 0);