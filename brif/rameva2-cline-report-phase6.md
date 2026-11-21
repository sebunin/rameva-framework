# Rameva 2 — отчёт, этап 6: установщик/диагностика, усиление Tripwire, служебные файлы

## Что выполнено

### Часть A — установщик и диагностика окружения

**`system/Core/Installer.php`** (новый класс)
- `getState()`, `isInstalled()`, `getSecurityPosture()`, `getDegradedReason()` — состояние из `storage/installer.state.php` (PHP под Guard).
- `runDiagnostics()` — PHP ≥8.1, обязательные/опциональные расширения, права на запись в `storage/`, определение веб-сервера.
- `writeHtaccessFiles()` — `.htaccess` (`Require all denied`) в `pages/`, `app/`, `system/`, `storage/` (Apache). Не перезаписывает, если запрет уже есть.
- `verifyProtection()` — реальный HTTP-запрос к `/pages/index.html`, `/app/Config/App.php`, `/system/Boot.php`, `/storage/logs/app.log`; успех, если хотя бы один → 403/404.
- `nginxSnippet()` — готовый location-блок для Nginx.
- `finalize()` — перевод в боевой режим (`secure`/`degraded`), запись состояния, отпечатка окружения, чистка ключа установки, запись в `security.log`.
- `reDiagnose()` — повторная диагностика: сравнение отпечатка окружения (st_dev, SAPI, ОС — не версии PHP). При расхождении бойевой режим снимается. Интервал ≥1 с.
- `ensureSetupKey()`/`checkSetupKey()` — ключ установщика в `storage/installer.key` (0600), не попадает в HTML.
- `ensureCronToken()` — ключ для `cron.php` в `app/Config/Security.php` (`integrity.token`).

**`system/Core/Kernel.php`**
- `launch()`: 0.1 — служебные файлы корня; 0.2 — если не установлено, отдаётся установщик; 0.3 — после установки `/installer` → 404. Затем черный список, RateLimiter, Honeypot, AntiBot, канонический редирект, фильтры, маршрутизация.
- `renderInstaller()`: без ключа — нейтральная страница «Сайт временно недоступен»; с ключом — полный интерфейс. Ключ в HTML не попадает.
- `handleInstallerAction()`: AJAX-действия `run_diagnostics`, `verify_protection`, `ensure_token`, `finalize`, `check_state`. Параметры из JSON-тела, `$_POST` и `$_GET`. POST без ключа → пустой 403.
- `serveRootFile()`: `robots.txt`, `sitemap.xml`, `llms.txt`, `favicon.ico` — по буквальному имени без суффикса/редиректа. Внутренние каталоги → 404. Посторонний `.php` в корне → 403 + запись в `integrity.log`.

**`system/Pages/installer.html`** (новый шаблон)
- Гейт ввода ключа, шаги диагностики, защита каталогов, ключ cron.php, завершение. Кнопка «Проверить защиту» — реальная HTTP-проверка. Ключ cron.php показывается один раз с командами для планировщика.

**`system/Pages/installer_gate.html`** (новый шаблон)
- Нейтральная страница без технических деталей для посетителя без ключа.

**`cron.php`** — добавлен `action=diagnose` (повторная диагностика по ключу). Без ключа — 403.

### Часть B — усиление Tripwire

**`system/Security/Tripwire.php`**
- `build()` — манифест содержит `'perms'` (`fileperms() & 0777`) рядом с хэшем.
- `check()` — расхождение по правам — отдельный тип события `perms_changed`, отдельно от `changed`/`removed`/`added`.
- `checkStrayPhpFiles()` — дешёвая проверка без хеширования: корень (кроме `index.php`, `cron.php`, `installer.php`), `assets/`, `storage/uploads/`. Найденный `.php` — запись в `integrity.log` со статусом `stray_php` + уведомление администратору независимо от `notify_admin`. Guardia-файл `storage/uploads/index.php` исключён.
- `reportStrayFile()` — немедленная фиксация постороннего `.php` из `Kernel::serveRootFile()`.
- `handleCronRequest()` — поддержка `action=diagnose` и `action=stray`. Неверный ключ — 403 без тела; RateLimiter (30/мин, блок 900 с).

**`app/Config/Security.php`**
- Секция `security_posture` (`mode`/`reason`/`fixed_at`) — заполняется установщиком. `degraded` видно постоянно.
- `stray_check` / `stray_check_interval` — настройка дешёвой проверки посторонних `.php`.

### Часть C — служебные файлы в корне

- `robots.txt`, `sitemap.xml`, `llms.txt`, `favicon.ico` — отдаются по буквальному пути при любой настройке `suffix`.
- Прочие файлы корня — как есть, если не `.php` и не внутренний каталог. Посторонний `.php` в корне → 403 + críticaя запись в `integrity.log`.

### Исправленные дефекты этапа

1. Без ключа установщик отдавал полный технический интерфейс (пути, Nginx-конфиг, команды cron). Исправлено: только нейтральная заглушка.
2. POST без ключа возвращал JSON с признаком `Forbidden`. Исправлено: пустой 403 без тела.
3. На Apache+PHP `php://input` приходит искажённым. Исправлено: параметры читаются также из `$_GET`; фронтенд дублирует их в query-строку.
4. `checkStrayPhpFiles` флагал легитимный guardia-файл `storage/uploads/index.php`. Исправлено: исключён.

