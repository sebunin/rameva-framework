# Rameva 2 — отчёт этапа 8E

Все PHP-файлы проверены через `php -l` — синтаксических ошибок нет.

## Изменённые файлы

- `public/app/Config/Security.php`
- `public/system/Core/Kernel.php`
- `public/system/Boot.php`
- `public/cron.php`
- `public/system/Security/Tripwire.php`
- `public/system/Core/Installer.php`

---

## A. Приманка WordPress — исправление регрессии R-24

### A1 — Удаление WP-путей из blacklist.patterns ✅

Удалены все пути, за которые отвечает `Honeypot` (`wp-login.php`, `wp-admin`, `wp-admin/`, `xmlrpc.php`, `wp-includes/`, `wp-content/`, `wp-config.php`, `wp-json/`, `administrator/`, `admin.php`, `phpmyadmin/`). Оставлены только паттерны, которыми Honeypot не занимается:

```
.env, .git, vendor/phpunit, eval-stdin.php, phpmyadmin, pma, alfa-rex, wso.php, shell.php
```

### A2 — Сегментное сравнение в `Kernel::isBlacklisted()` ✅

Заменено сравнение подстрокой на сравнение по сегментам пути:

- **Паттерн с точкой** (`.env`, `.git`): точное совпадение сегмента, или префиксное совпадение (`.env` → `.environment`, `.env.local`). Исключение: `.git` не блокирует `.github` (разные каталоги).
- **Паттерн без точки**: точное совпадение целого сегмента (`pma` не блокирует `pmarket`).
- **Многосегментный паттерн** (`vendor/phpunit`): проверка подпути через нормализованное вложение.

### A3 — Комментарий обновлён ✅

В `Security.php` комментарий блока `blacklist` описывает сегментное сравнение.

---

## B. Глобальная обработка ошибок

### B1 — Обработчики в `Boot.php` ✅

Зарегистрированы `set_exception_handler`, `set_error_handler` (для `E_WARNING` и выше), `register_shutdown_function`. Все пишут в логи через `LogService` без раскрытия путей сервера.

### B2 — Нет путей сервера в ответе при `debug=false` ✅

При `debug=false` возвращается нейтральная страница 500 без текста исключения и без путей сервера.

---

## C. Ключ cron.php — хранить хеш, не сам ключ

### C1 — `token_hash` вместо `token` ✅

В `Security.php` секция `integrity` содержит `token_hash` (sha256 от сырого токена). Doc-комментарий обновлён с инструкцией `php -r "echo hash('sha256', 'ВАШ_ТОКЕН');"`.

### C2 — `Installer::ensureCronToken()` пишет `token_hash` ✅

Генерирует сырой токен, считает `sha256`, записывает хеш в `Security.php`. Существующий `token_hash` не перезаписывается.

### C3 — `Tripwire::handleCronRequest()` сравнивает через хеш ✅

Используется `hash_equals($storedHash, hash('sha256', $given))`.

---

## D. Ключ через заголовок `X-Rameva-Key`

### D1 — Приоритет заголовка ✅

В `cron.php` ключ сначала ищется в `X-Rameva-Key`, затем в `$_GET['key']`/`$argv`.

### D2 — Лог при ключе в query ✅

Запись в `security.log` с уровнем `notice`, не чаще раза в час (через маркер-файл `.cron_query_key_at`).

### D3 — Документация ✅

В `cron.php` и `Security.php` добавлен пример через `curl -H "X-Rameva-Key: ТОКЕН"`.

---

## E. Автосоздание `storage/`

### E1 — `Installer::ensureStorageSkeleton()` ✅

Создаёт `cache`, `logs`, `session`, `sessions`, `uploads`, `debugbar` с заглушкой `index.php` (403).

### E2 — Вызов из `Boot.php` ✅

Вызывается один раз за запрос перед созданием `Kernel`.

### E3 — `.htaccess` для Apache ✅

Если веб-сервер — Apache и `.htaccess` отсутствует, записывается `HTACCESS_CONTENT`.

---

## Результаты проверок

| Файл | `php -l` |
|------|----------|
| `system/Core/Kernel.php` | Ошибок не обнаружено |
| `app/Config/Security.php` | Ошибок не обнаружено |
| `system/Boot.php` | Ошибок не обнаружено |
| `cron.php` | Ошибок не обнаружено |
| `system/Security/Tripwire.php` | Ошибок не обнаружено |
| `system/Core/Installer.php` | Ошибок не обнаружено |

---

## Что не входит в этап

D2/D3 (расширенный список расширений и событие `stray_sensitive` для Tripwire), синхронизация `deploy/nginx/*.conf` с `Installer::nginxSnippet()`, `tests/security_matrix.php`, throttling `Router::sourceSignature()` — следующим брифом.