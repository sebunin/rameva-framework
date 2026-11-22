# Rameva 2 — бриф этапа 8E

Пути — относительно `public/`. Не трогать `fw.loc`, `wwbridge.loc`. Каждый изменённый PHP-файл — `php -l`, вывод в отчёт дословно. Не писать «выполнено» без вывода команды. Секреты (сырой токен, хеши) в отчёт не вставлять.

## A. Приманка WordPress — реальное исправление (критично, регрессия R-24)

Сейчас `Kernel::isBlacklisted()` обрывает запрос (шаг 1) раньше, чем `Honeypot::handleRequest()` (шаг 3), и `wp-login.php`, `xmlrpc.php`, `wp-config.php`, `wp-admin`, `wp-includes`, `wp-content` одновременно в `blacklist.patterns` в `Security.php`. В итоге при `honeypot.fake_wp = true` приманка не достижима для этих путей — обычный 403 раньше.

**A1.** Удалить из `blacklist.patterns` все пути, за которые отвечает `Honeypot` (список самого `Honeypot`, не угадывать — прочитать его `wpProbePaths`/аналог). Остаются только `.env`, `.git`, `vendor/phpunit`, `eval-stdin.php`, `phpmyadmin`, `pma`, `alfa-rex`, `wso.php`, `shell.php` — то, чем `Honeypot` не занимается.

**A2.** В `Kernel::isBlacklisted()` заменить сравнение подстрокой на сравнение по сегментам пути: паттерн без точки в начале — совпадает с целым сегментом (`pma` совпадает с сегментом `pma`, не с `pmarket`); паттерн, начинающийся с `.` (`.env`, `.git`) — совпадает с сегментом, начинающимся на этот паттерн (`.env` совпадает с `.environment`, но не с `.github` — проверить оба случая отдельно).

**A3.** Убрать из `Security.php` комментарий «F1: совпадение по сегментам» рядом с `blacklist`, раз он там был до фактического исправления — переписать после того, как A2 реально сделано.

Проверка (значения ожидаемых кодов, не пересказ):
- `GET /wp-login.php` с `fake_wp=true` → 200, тело — фальшивая страница WordPress, без обращения к чёрному списку.
- `GET /.env` → 403.
- `GET /pmarket-test` → не 403 (не должно попадать под `pma`).
- `GET /.github/workflows` → не 403 (не должно попадать под `.git`).

## B. Глобальная обработка ошибок

В `Boot.php` сейчас нет `set_error_handler`, `set_exception_handler`, `register_shutdown_function`. Ошибки вне блока `try` в `Kernel::launch()` (сам `Boot.php`, установщик, блэклист, рейт-лимитер, Honeypot, AntiBot, канонический редирект, before-фильтры) и фатальные ошибки дают пустой ответ без записи в лог.

**B1.** В `Boot.php`, после инициализации конфигурации (`debug`), зарегистрировать:
- `set_exception_handler` — ловит необработанные исключения, пишет в `error.log` через `LogService`, при `debug=false` отдаёт нейтральную страницу 500 (как `Kernel::renderServerError`, но без `\Throwable` в данных), при `debug=true` — как сейчас.
- `set_error_handler` для `E_WARNING` и выше — пишет в `app.log`, не прерывает выполнение.
- `register_shutdown_function` — проверяет `error_get_last()` на фатальные типы (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`), пишет в `error.log`, при `debug=false` и если заголовки ещё не отправлены — отдаёт 500 без тела с деталями.

**B2.** Ни один из обработчиков не выводит пути сервера или текст исключения в ответ при `debug=false`.

Проверка: временно вызвать `throw new \RuntimeException('test')` в точке до `Kernel::launch()` — убедиться в 500 без текста ошибки в ответе и в записи в `error.log`, затем откатить тестовую строку.

## C. Ключ `cron.php` — хранить хеш, не сам ключ

**C1.** В `Security.php`: заменить `'token' => '...'` на `'token_hash' => '...'` (sha256 от токена). Обновить doc-комментарий блока `integrity` (ручная установка: сгенерировать токен, посчитать `sha256`, вписать хеш — привести команду `php -r "echo hash('sha256', 'ВАШ_ТОКЕН');"`).

**C2.** `Installer::ensureCronToken()`: генерирует сырой токен как раньше, но в `Security.php` записывает `token_hash` (sha256 от него), не сам токен. Существующий `token_hash` не перезаписывается. Сырой токен возвращается методом как раньше (уходит в ответ AJAX `ensure_token` один раз, в браузер администратора — это не меняется).

**C3.** `Tripwire::handleCronRequest()`: вместо `hash_equals($token, $given)` — `hash_equals($storedHash, hash('sha256', $given))`, где `$storedHash` — значение `token_hash` из конфигурации.

Проверка: сгенерировать новый токен через установщик (`ensure_token`), вызвать `cron.php?key=<токен>&action=build` — ожидается успех (вывод "integrity: built..."); вызвать с неверным ключом — 403. Сам токен в отчёт не копировать, только факт успеха/отказа и код ответа.

## D. Ключ через заголовок (дополнительно к query-параметру)

**D1.** `cron.php`: ключ сначала ищется в заголовке `X-Rameva-Key` (через `getallheaders()` или `$_SERVER['HTTP_X_RAMEVA_KEY']`), и только если его нет — в `$_GET['key']`/`argv`.

**D2.** При использовании ключа из query-строки — запись в `security.log`: `"cron.php: ключ передан в query-строке (устаревший способ, используйте заголовок X-Rameva-Key)"`. Уровень `notice`, не чаще раза в час (не на каждый вызов).

**D3.** В doc-комментарии `cron.php` и `Security.php` добавить пример через заголовок: `curl -H "X-Rameva-Key: ТОКЕН" "https://site.ru/cron.php?action=check"`.

## E. Автосоздание структуры `storage/` при первом запуске

На свежем `git clone` нет `storage/cache`, `storage/logs`, `storage/session`, `storage/sessions`, `storage/uploads`, `storage/debugbar` — они не версионируются, никто их не создаёт.

**E1.** Новый метод, например `Installer::ensureStorageSkeleton()`: для каждой из перечисленных директорий — если нет, создать (`mkdir 0755`, рекурсивно); если нет `index.php` внутри — записать стандартную заглушку (`<?php\nhttp_response_code(403);\nexit;\n`).

**E2.** Вызывается из `Boot.php` один раз за запрос, до создания `Kernel` (дёшево — `is_dir`/`file_exists`, не пересоздаёт существующее).

**E3.** Если веб-сервер определён как Apache (см. `Installer::detectServer`) и в `storage/` нет `.htaccess` — записать туда `Installer::HTACCESS_CONTENT`.

Проверка: удалить локально (у себя, не в репозитории) все подпапки `storage/` кроме неё самой, сделать один HTTP-запрос к сайту, показать результат `ls storage/` — все шесть подпапок и заглушки на месте.

## Что не входит в этап

D2/D3 (расширенный список расширений и событие `stray_sensitive` для Tripwire), синхронизация `deploy/nginx/*.conf` с `Installer::nginxSnippet()`, `tests/security_matrix.php`, throttling `Router::sourceSignature()` — следующим брифом.

## Отчёт

Таблица A–E: PASS/FAIL/NOT RUN с выводом команд. Список изменённых файлов. Что не получилось и почему.
