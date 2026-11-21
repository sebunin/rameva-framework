# Rameva 2 — внутренний справочник по ядру (`system/`)

Рабочий документ для проектирования и последующего справочника по фреймворку. Составлен 20.11.2026 по архиву `system.zip` (50 файлов, 7396 строк).

**Как читать статусы.** «Проверено» — код прочитан построчно. «Каталог» — только сигнатуры и docblock, извлечённые автоматически, поведение не проверялось. Документ нужно обновлять при каждой фазе и хранить в репозитории (`docs/REFERENCE.md`), а при новой сессии прикладывать вместе с кодом.

**Целостность источника.** Присланные `system/` (50 файлов) побайтно совпали с эталонным манифестом Tripwire от 16.11.2026 19:04 — читался тот же код, что проверялся на стенде.

**Прочитано (проверено):** `index.php`, `cron.php`, `.htaccess` (корень и `storage/`), `app/Config/*`, `app/Filters/*`, `app/Layouts/main.html`, `app/Events/events.php`, состояния и логи `storage/`.

**Дополнительно прочитано:** `deploy/nginx/rameva2-server-block.example.conf`, отчёты фаз 6, 7, 8, 8B, `tests/server_check.php`.

**Не прочитано (нужно для полной картины):** брифы фаз 6–8B (только список), `pages/`, `assets/`, остальные файлы `tests/`; тела `Renderer`, `LinkNormalizer`, `PageMetadata`, `Schema`, `SeoService`, `FormService`, `MailService`, `Honeypot` (кроме `handleRequest`), `AntiBot` (кроме работы с ключом), `Tripwire` (кроме stray-проверки).

---

## 1. Архитектура (проверено)

### 1.1 Загрузка (`system/Boot.php`)
1. Определяет `RAMEVA_CORE` (**сам**, если не задана — см. дефект R-04).
2. Выставляет `X-Powered-By: -` до любого вывода.
3. Константы путей: `SYSTEMPATH`, `ROOTPATH` (= родитель `system/`), `APPPATH`, `PAGESPATH`, `STORAGEPATH`.
4. PSR-4 автозагрузчик (собственный, без Composer): `System\` → `system/`, `App\` → `app/`.
5. Читает `app/Config/App.php`: `timezone`, `debug` (при `debug=false`: `display_errors=0`, `error_reporting(0)`).
6. `HelperLoader::loadAll()` (`*_helper.php` из `system/Helpers` и `app/Helpers`), `app/Events/events.php`, событие `system.booted`.
7. Запуск `Kernel::launch()` (не запускается в CLI и при `RAMEVA_NO_AUTOLAUNCH`).
8. После ответа: `Tripwire::runStrayCheckIfDue()` и `Tripwire::runBackgroundCheckIfDue()`.

### 1.2 Жизненный цикл запроса (`Kernel::launch`)
| № | Шаг | Результат |
|---|-----|-----------|
| 0.1 | `serveRootFile`: внутренние каталоги (`pages/ app/ system/ storage/`) → 404; посторонний `.php` → 403 + событие Tripwire; прочие существующие файлы отдаются через `sendFile` | до проверки установки |
| 0.2 | Установка не завершена → установщик | |
| 0.3 | Путь установщика после установки → 404 | |
| 1 | Чёрный список: подстрока пути (в нижнем регистре) из `Security.blacklist.patterns` | 403 |
| 2 | `RateLimiter` | 429 + `Retry-After: 300` |
| — | `POST /form/{id}` → `FormService::processSubmission`, ответ JSON | |
| 3 | `Honeypot::handleRequest` | 403 или фальшивый WordPress |
| 4 | `AntiBot::verify` / `renderChallenge` | JS-челлендж |
| 5 | Каноничный суффикс URL (GET/HEAD) | 301 |
| 6 | Фильтры `before` (`app/Config/Filters.php`) | |
| 7 | `Router::resolve` по белому списку `pages/` | нет маршрута → 404 |
| 8 | `controller.php` (`require`, возвращает массив) → метаданные → SEO-теги → `Renderer` | |
| 9 | Фильтры `after`, событие `system.rendered`, вывод | |

Ошибки на шагах 6–9 ловятся `catch (\Throwable)` → `error.log` + страница 500.

### 1.3 Кэш (`Services/Cache`)
- Формат записи: PHP-файл `storage/cache/<md5(ключ)>.cache.php` с Guard и `return ['expires'=>..., 'value'=>...]`; запись атомарна (tmp + `rename` под `LOCK_EX`). Чтение — через `include`.
- `expires = 0` — бессрочно. Истёкшая запись удаляется **только при повторном чтении того же ключа**. Фонового сбора мусора нет.
- `clear()` удаляет только `*.cache.php`.
- Файлы `*.cache` без `.php` — остатки старого формата (`a:2:{s:7:"expires";...}`, `serialize`). Ни один код в `system/` их больше не пишет и не читает.
- Кэш карты маршрутов — отдельные файлы `routes.php`, `assets.php`, `routes.signature.php` (строит `Router`). Сигнатура — SHA-256 от списка `путь:mtime:размер` всех файлов `pages/`, `assets/`, `app/Config/`; считается **на каждом запросе**. При изменении пересобираются карта, `sitemap.xml`, `llms.txt`, строка Sitemap в `robots.txt`. При `debug=true` карта пересобирается всегда.
- Готового HTML страниц кэш не хранит: страницы рендерятся на каждый запрос.

### 1.4 Сессии (`Services/SessionService`)
- Стартуют только когда нужны: страница содержит `{{form_fields:ID}}` (Kernel) или работает `FormService`. Обычные посетители сессию не получают.
- Файлы в `storage/sessions/` (`sess_<id>`, формат PHP, не под Guard). Сборка мусора — штатная PHP: `gc_probability=1`, `gc_divisor=100`, `gc_maxlifetime` из `App.php` (`session_gc_maxlifetime`, по умолчанию 1440 с). Выполняется при ~1% вызовов `session_start()`, поэтому на редко посещаемом сайте старые файлы могут лежать долго.
- Cookie: `HttpOnly`, `SameSite=Lax`, `Secure` при HTTPS (определяется в том числе по `X-Forwarded-Proto`), имя из `session_name` (по умолчанию `RAMEVA_SESSID`).

### 1.5 Установщик и защита (`Core/Installer`)
- Состояние: `storage/installer.state.php` (Guard). Режимы: `not_installed`, `secure`, `degraded`.
- `verifyProtection()` запрашивает собственный сайт методом HEAD по четырём путям: `/pages/index.html`, `/app/Config/App.php`, `/system/Boot.php`, `/storage/logs/app.log`; 403/404 считается «закрыто».
- `writeHtaccessFiles()` кладёт `Require all denied` в `pages/ app/ system/ storage/` (Apache).
- `installer.diagnose.at` — только Unix-время последней повторной диагностики (ограничение частоты).
- Ключ установки `storage/installer.key` — PHP-файл с Guard (`return '<64 hex>'`, `chmod 0600`); используется для HMAC IP-ключей (`MailRateState::ipKey`) и токенов.

### 1.6 Безопасность
- **RateLimiter**: ключи `rate_block_<ipKey>`, `rate_win_<ipKey>_<окно>` в `Cache`; при недоступности ключа установки ограничение отключается (fail-open, пишется в `security.log`).
- **ClientIp**: `REMOTE_ADDR`; заголовки `X-Forwarded-For` / `CF-Connecting-IP` учитываются только если `REMOTE_ADDR` входит в `trusted_proxies` (читается из `Mail.php` / `Local.php`, точное совпадение IP, без CIDR).
- **Honeypot**: пропускает по подстроке User-Agent «SEO-ботов», блокирует по подстроке UA «сканеров» (403), на пути-пробы (`wpProbePaths`) отдаёт фальшивый WordPress (`fake_wp`) либо 403.
- **Tripwire** (stray-проверка): раз в интервал ищет `.php` в корне (кроме `index.php`, `cron.php`, `installer.php`), в `assets/` и `storage/uploads/` (кроме файлов с именем `index.php`).

### 1.7 Логи (`Services/LogService`)
Файл `storage/logs/<канал>`; формат `[дата] [УРОВЕНЬ] [IP: x] сообщение`; троттлинг записей по IP (1 с и 60 в минуту, только в пределах запроса); ротация при 10 МБ переименованием в `*.bak`. Параметр `max_age_days` объявлен, но нигде не используется — старые `.bak` не удаляются.

---

## 2. Журнал выявленных дефектов (по коду; к исправлению)

| ID | Серьёзность | Суть |
|----|-------------|------|
| R-01 | низкая | В `storage/cache/` лежат файлы старого формата `*.cache`; `Cache::clear()` их не удаляет. Нужна разовая миграция/очистка. |
| R-02 | **высокая** | `RateLimiter` создаёт по одному файлу `rate_win_*` на каждый IP за каждое окно; удаляются только при повторном чтении, то есть практически никогда. Рост числа файлов не ограничен (инода, место), каждый файл исполняемый PHP и попадает в OPcache. Нужны: хранение счётчиков в одном файле/шарде и сборка мусора. |
| R-03 | **высокая** | `Installer::verifyProtection()` считает проверку пройденной, если закрыт хотя бы один из четырёх путей. На Nginx PHP-файлы закрывает Guard, а `pages/index.html` и `storage/logs/app.log` отдаются — установка всё равно получает статус «ok». Нужно требовать закрытия всех путей и проверять расширения `.log`, `.html`, `.json`, `.bak`, `.cache`. |
| R-04 | средняя | `Boot.php` сам определяет `RAMEVA_CORE`, поэтому прямой запрос `/system/Boot.php` проходит Guard и запускает ядро. Константу должны определять точки входа (`index.php`, `cron.php`), а `Boot.php` — проверять её. |
| R-05 | средняя | Stray-проверка пропускает любой файл с именем `index.php` в `assets/` и `storage/uploads/`: веб-шелл с таким именем не будет обнаружен. Нужна проверка содержимого/хэша заглушки. Проверка выполняется раз в интервал и постфактум; блокировки исполнения PHP в `assets/` на Nginx нет. |
| R-06 | средняя | `Router::sourceSignature()` обходит все файлы `pages/`, `assets/`, `app/Config/` на каждом запросе. Стоимость растёт с числом файлов в `assets/` (иконочные библиотеки — тысячи файлов). |
| R-07 | средняя | `Honeypot` доверяет User-Agent при пропуске «SEO-ботов»: сканер с UA `Googlebot` обходит ловушку. Нужна проверка по обратному DNS/диапазонам IP или отказ от пропуска. |
| R-08 | средняя | `ClientIp` понимает только точные IP в `trusted_proxies` и читает конфиг из `Mail.php`; при каждом вызове заново подключает файлы. Для схемы Nginx→Apache и Cloudflare нужны CIDR, отдельная настройка в `Security.php` и кэш в пределах запроса. |
| R-09 | средняя | Нет глобальных `set_error_handler` / `set_exception_handler` / `register_shutdown_function`: при `debug=false` ошибки вне `try` Kernel (Boot, фильтры до шага 8, фатальные) дают пустую страницу без записи в лог. |
| R-10 | низкая | Ротация логов не удаляет старые `*.bak` (`max_age_days` не реализован). |
| R-11 | низкая | `RateLimiter`: чтение-инкремент-запись без блокировки, при параллельных запросах счёт занижается. Поведение fail-open при недоступном ключе установки требует решения (разный риск для статичного сайта и для форм). |
| R-12 | низкая | `SessionService` определяет `Secure` по `X-Forwarded-Proto` без проверки доверенного прокси; `Installer::baseUrl()` при пустом `siteurl` использует заголовок `Host`. |
| R-13 | низкая | `Cache::get()` читает файл дважды (`file_get_contents` + `include`). |
| R-14 | средняя | `AntiBot` подписывает токен ключом из `Security.php` (`secret_key`, по умолчанию публичная строка из дистрибутива), а не ключом установки. При включённом AntiBot токен можно подделать и пройти challenge. |
| R-15 | **высокая** | `installer.state.php` записан со статусом `secure`, но `diagnostics => []` и `server => 'unknown'`: установка зафиксировала «безопасно» без сохранённых результатов проверок. Вместе с R-03 статус `secure` доказательной силы не имеет. |
| R-16 | средняя | В `App.php` по умолчанию `debug=true`, `environment=development`, а установщик это не проверяет и ставит `secure`. При `debug=true` карта маршрутов, `sitemap.xml`, `llms.txt` и `robots.txt` пересобираются **на каждом запросе**, ошибки выводятся посетителю. |
| R-17 | низкая | `app/Config/Mail.php` не защищён Guard (единственный конфиг без него) и содержит заглушку `trusted_proxies` (`10.0.0.1`, `10.0.0.2`); настройка прокси лежит в конфиге почты. |
| R-18 | средняя | Чёрный список ищет подстроку в пути: `pma` блокирует любой URL с этими буквами (`/pmarket`, `/company-pma…`), `.env` — `/jquery.environment.js`, `.git` — `/.github/`. Нужны сравнение по сегментам пути и границам имён. |
| R-19 | средняя | Ключ `cron.php` лежит открытым текстом в `Security.php` (`integrity.token`) и передаётся в строке запроса (попадает в журналы веб-сервера). Установщик тоже пишет `security_posture` в этот же конфиг, а `app/` Tripwire не проверяет. |
| R-20 | низкая | После правок Cline оставляет `*.bak` внутри `system/` (зафиксировано `Services/ClientIp.php.bak` в `integrity.log`). Нужно правило в брифе: резервные копии только вне веб-корня. |
| R-21 | низкая | В `mail.log` до срабатывания лимита подряд отправлено 7 писем при лимите формы `contact` 5 в час; состояние `mail-rate.state.php` хранит одну отметку. Логика окон `MailRateState` требует теста. |
| R-22 | низкая | `app/Config/Local.php` (SMTP-доступы) лежит в дереве, комментарий в нём требует «не включать в дистрибутив», но исключения из сборки нет. |
| R-23 | **высокая** | `Kernel::handleInstallerAction('finalize')` принимает `posture` и `diagnostics` из тела запроса и записывает их как есть: сервер не пересчитывает защиту. Статус `secure` определяет браузер (JS установщика), а не код. Это причина пустых `diagnostics` в `installer.state.php`. |
| R-24 | **высокая** | Приманка WordPress не срабатывает: чёрный список (шаг 1) содержит `wp-login.php`, `xmlrpc.php`, `wp-config.php`, `wp-admin`, `wp-includes`, `wp-content` и отвечает 403 раньше `Honeypot` (шаг 3). Фальшивая страница достижима только для `/wp-json/`, `/administrator/`, `/admin.php`, `/phpmyadmin/`. Тест `/wp-login.php → 403` в `server_check.php` закрепляет этот конфликт. |
| R-25 | средняя | Проверки слабее заявленного: `server_check.php` засчитывает «пусто» при любом статусе и работает только на Apache; нет проверок исполнения PHP в `assets/`, `.log`/`.bak`/`.json` вне `storage/`, регистра пути, двойного кодирования, `/index.php/x`. Отчёт 8B пишет «SMTP AUTH/TLS — ВЫПОЛНЕНО» при тесте без AUTH и TLS и «0 SKIP» при тесте, остановленном на шаге 8 из 25. |
| R-26 | средняя | `deploy/nginx/*.conf`: блок `location ~ \.php$` без `try_files $uri =404` (риск `/файл.jpg/x.php` при `cgi.fix_pathinfo=1`) и без запрета исполнения PHP в `assets/` и `uploads/`; нет запрета расширений `.bak .sql .log .zip .ini` вне закрытых каталогов; в комментариях устарело описание `public/.htaccess`. Файл не совпадает с `Installer::nginxSnippet()`. |
| R-27 | средняя | В отчётах (корень проекта) открытым текстом записаны ключ установки и ключ `cron.php`. Отчёты и брифы нельзя коммитить в репозиторий без очистки; ключи перегенерировать. |
| R-28 | низкая | Фальшивая страница WordPress отдаёт `X-Powered-By: PHP/7.4.33`, тогда как `Boot.php` на всех остальных ответах ставит `X-Powered-By: -`; страница примитивна (одна форма без стилей и скриптов WordPress) и опознаётся сканерами. |

---

## 3. Проектные решения к реализации (согласовать)

- **Поиск.** В CodeIgniter 4 встроенного поиска нет: ядро даёт только `like()` / `MATCH` на уровне БД, пакеты сторонние и тоже привязаны к моделям БД. Предложение: собственный модуль без БД — индекс страниц из `pages/` (заголовок, H1–H3, описание, текст `<main>`), токенизация с русской морфологией, инвертированный индекс в PHP-файле под Guard в `storage/`, перестройка вместе с картой маршрутов, ранжирование по весам полей, страница результатов `/search?q=` как системная страница с переопределением в `app/Pages`. Готовая библиотека TNTSearch (v5.3.0 от 11.08.2026, MIT) требует Composer и `pdo_sqlite`; брать из неё можно только отдельный класс русского стеммера.
- **Редиректы.** Файл `app/Config/Redirects.php` (массив «откуда → куда, код»), точное совпадение и префиксные правила, обработка в Kernel после чёрного списка и до маршрутизатора, защита от внешних адресов и циклов, учёт суффикса URL и канонического редиректа, журнал срабатываний, код 410 для удалённых страниц.
- **PHP.** Нижняя граница 8.3 (8.2 выходит из поддержки 31.12.2026), основной тест на 8.4, дополнительно 8.3 и 8.5.

---

## 4. Каталог классов и публичных методов (автоизвлечение; не проверено построчно)

### `system/Boot.php` (122 стр.)

### `system/Core/Db.php` (140 стр.)
- Класс: `System\Core\Db`
- Назначение (из docblock): Класс Db предоставляет безопасную обёртку для работы с базой данных через PDO.
  - `__construct(?array $config = null)` — Конструктор инициализирует подключение к базе данных на основе конфигурации.
  - `execute(string $query, ?array $params = null): \PDOStatement` — Выполняет SQL-запрос с параметрами.
  - `query(string $query, ?array $params = null): array` — Возвращает все строки результата выполнения запроса.
  - `queryRow(string $query, ?array $params = null): ?array` — Возвращает одну строку результата.
  - `queryValue(string $query, ?array $params = null): mixed` — Возвращает одно скалярное значение.
  - `lastInsertId(?string $name = null): string` — Возвращает ID последней вставленной записи.

### `system/Core/Debug.php` (76 стр.)
- Класс: `System\Core\Debug`
- Назначение (из docblock): Класс Debug предоставляет методы отладки и замера производительности.
  - `static startTimer(string $name): void` — Запускает таймер с указанным именем.
  - `static stopTimer(string $name): float` — Останавливает таймер и возвращает затраченное время в миллисекундах.
  - `static log(mixed $data, string $label = 'DEBUG'): void` — Добавляет отладочное сообщение.
  - `static getLogs(): array` — Возвращает накопленные отладочные сообщения.

### `system/Core/Events.php` (89 стр.)
- Класс: `System\Core\Events`
- Назначение (из docblock): Класс Events реализует простой диспетчер событий (Observer pattern). Позволяет подписываться на именованные события и вызывать зарегистрированные обработчики.
  - `static on(string $event, callable $listener): void` — Регистрирует обработчик на указанное событие.
  - `static trigger(string $event, mixed ...$args): void` — Вызывает всех зарегистрированных подписчиков для указанного события.
  - `static clear(?string $event = null): void` — Очищает подписчиков (используется в тестах).
  - `static hasListeners(string $event): bool` — Проверяет наличие слушателей у события.

### `system/Core/Installer.php` (874 стр.)
- Класс: `System\Core\Installer`
- Назначение (из docblock): Класс Installer обслуживает первый запуск: диагностику окружения, проверку защиты внутренних каталогов, выдачу ключа для служебных точек входа и переключение в боевой режим. Состояние установки хранится в storage/installer.state.php (PHP-файл под Guard), повторная диагностика после установки вызывае
  - `static getState(): array` — Состояние установки.
  - `static isInstalled(): bool` — Установлен ли боевой режим.
  - `static getSecurityPosture(): string` — Текущий режим безопасности: secure | degraded | not_installed.
  - `static getDegradedReason(): ?string` — Причина деградации, если режим degraded.
  - `static runDiagnostics(): array` — Полная диагностика окружения (пункты 2–5 брифа).
  - `static selfCheckCode(string $path): int` — HEAD-запрос к собственному сайту по указанному пути.
  - `static verifyProtection(): array` — Проверка защиты внутренних каталогов реальным HTTP-запросом.
  - `static writeHtaccessFiles(): array` — Записывает защитный .htaccess во внутренние каталоги (Apache).
  - `static finalize(array $diagnostics, string $posture, ?string $reason = null): bool` — Переключает в боевой режим с зафиксированным результатом диагностики.
  - `static reDiagnose(array $previous): array` — Повторная диагностика после установки (пункт 9).
  - `static resetInstallation(): void` — Снимает установку: состояние удаляется, режим возвращается в not_installed.
  - `static ensureSetupKey(): array` — Ключ доступа к странице диагностики (не к cron.php — у него свой ключ).
  - `static checkSetupKey(string $given): bool` — Проверяет ключ страницы диагностики.
  - `static ensureCronToken(): array` — Ключ доступа к cron.php: генерируется при установке и записывается в

### `system/Core/Kernel.php` (646 стр.)
- Класс: `System\Core\Kernel`
- Назначение (из docblock): Класс Kernel управляет полным жизненным циклом входящего HTTP-запроса: 1. Проверка состояния установки (Installer); 2. Статичные служебные файлы в корне (robots.txt, sitemap.xml, llms.txt, favicon.ico); 3. Черный список сканерских путей (Blacklist) -> 403; 4. Ограничение частоты запросов (RateLimite
  - `__construct()`
  - `launch(): void` — Точка входа в обработку HTTP-запроса.

### `system/Helpers/HelperLoader.php` (71 стр.)
- Класс: `System\Helpers\HelperLoader`
- Назначение (из docblock): Класс HelperLoader автоматически находит и подключает файлы *_helper.php из директорий system/Helpers/ и app/Helpers/.
  - `static loadAll(): void` — Загружает все хелперы из системной и пользовательской директорий.

### `system/Helpers/Url.php` (95 стр.)
- Класс: `System\Helpers\Url`
- Назначение (из docblock): Класс Url предоставляет методы безопасного построения URL-адресов с поддержкой суффиксов, query-параметров и базового домена.
  - `static to(string $path = '/', array $params = [], bool $absolute = false): string` — Формирует канонический URL для страницы или маршрута.
  - `static base(): string` — Возвращает базовый URL текущего сайта (протокол + хост).
  - `static current(): string` — Возвращает текущий запрошенный URI.

### `system/Http/FilterInterface.php` (42 стр.)
- Класс: `System\Http\FilterInterface`
- Назначение (из docblock): Интерфейс глобального HTTP-фильтра (Middleware).
  - `before(): void` — Выполняется ДО передачи управления контроллеру страницы.
  - `after(string &$output): void` — Выполняется ПОСЛЕ рендеринга страницы.

### `system/Http/Router.php` (485 стр.)
- Класс: `System\Http\Router`
- Назначение (из docblock): Класс Router выполняет разрешение маршрутов на основе директории pages/. Конвенция pages/: один файл — одна страница: - pages/index.html -> '/' (контроллер: pages/index.php) - pages/{slug}.html -> '/{slug}' (контроллер: pages/{slug}.php) - pages/{section}/index.html -> '/{section}' (контроллер: inde
  - `__construct()`
  - `buildRoutesMap(): array` — Сканирует pages/ и строит белый список маршрутов.
  - `checkCanonicalRedirect(string $uri): ?string` — Проверяет каноничность входящего адреса и возвращает адрес для 301-редиректа.
  - `resolve(string $uri): ?array` — Разрешает входящий HTTP-запрос по белому списку маршрутов.
  - `buildCanonicalPath(string $logicalPath): string` — Строит канонический путь из логического: суффикс добавляется один раз
  - `getRoutes(): array`

### `system/Rendering/LinkNormalizer.php` (167 стр.)
- Класс: `System\Rendering\LinkNormalizer`
- Назначение (из docblock): Rameva 2 — нормализация ссылок готовых HTML-страниц. Сканер сохраняет исходную разметку: меняются только значения URL-атрибутов и CSS url(). JavaScript, комментарии и примеры кода не интерпретируются.
  - `__construct(array $assets, array $routes, callable $canonical)` — $canonical — тот же построитель канонического пути, который использует Router.
  - `normalize(string $html): string`
  - `static tagEnd(string $html, int $start): int` — Конец одного тега с учётом > внутри кавычек.

### `system/Rendering/PageMetadata.php` (70 стр.)
- Класс: `System\Rendering\PageMetadata`
- Назначение (из docblock): Rameva 2 — извлечение собственных метаданных страницы до её рендеринга.
  - `static collect(string $html): array` — Только head полного документа служит источником метаданных.
  - `static attributes(string $tag): array`
  - `static strip(string $html): string` — Удаляет только управляемые SEO-теги из head, не затрагивая JSON-LD.
  - `static jsonLd(string $html): array` — Сохраняемые блоки и их верхнеуровневые типы (включая списки, не @graph).

### `system/Rendering/Renderer.php` (249 стр.)
- Класс: `System\Rendering\Renderer`
- Назначение (из docblock): Класс Renderer отвечает за легковесный runtime-рендеринг страниц. Логика работы с шаблонами (Layouts): 1. Имя шаблона указывается в данных контроллера через ключ '_layout' (например, '_layout' => 'default' или 'main'). 2. Шаблон ищется сначала в app/Layouts/{name}.html, если не найден — в system/Lay
  - `setNormalizer(LinkNormalizer $normalizer): void`
  - `renderFile(string $pageTemplatePath, array $data = []): string` — Рендерит HTML-файл страницы с опциональным слиянием с Layout.
  - `renderPage(string $pageContent, array $data = []): string` — Рендерит контент страницы с учетом Layout и плейсхолдеров.
  - `resolveLayoutPath(string $name): ?string` — Разрешает путь к файлу шаблона: приоритет app/Layouts -> system/Layouts.
  - `extractBodyContent(string $html): string` — Извлекает содержимое основного контента страницы для слияния с Layout:
  - `renderString(string $content, array $data = [], array $rawKeys = []): string` — Выполняет подстановку плейсхолдеров {{ var }} в строке.

### `system/Security/AntiBot.php` (138 стр.)
- Класс: `System\Security\AntiBot`
- Назначение (из docblock): Класс AntiBot реализует вызов challenge-проверок браузера и валидацию клиентского отпечатка (Canvas, WebGL, AudioContext).
  - `__construct(?array $config = null)` — Конструктор AntiBot.
  - `isEnabled(): bool` — Проверяет, включена ли антибот защита.
  - `verify(): bool` — Проверяет, пройдена ли клиентом challenge-проверка.
  - `issueToken(): string` — Генерирует подписанный токен успешного прохождения проверки.
  - `renderChallenge(): void` — Отдает клиенту HTML-страницу проверки со встроенным (инлайн) JS-кодом.

### `system/Security/AntiBotValidator.php` (82 стр.)
- Класс: `System\Security\AntiBotValidator`
- Назначение (из docblock): Класс AntiBotValidator выполняет серверную проверку данных фингерпринтинга, собранных скриптом antibot-challenge.js (Canvas, WebGL, AudioContext, тайминги).
  - `__construct(array $config = [])` — Конструктор валидатора.
  - `validate(array $payload): bool` — Валидирует переданный пакет данных проверки.

### `system/Security/Honeypot.php` (173 стр.)
- Класс: `System\Security\Honeypot`
- Назначение (из docblock): Класс Honeypot реализует стратегию "Миража" для противодействия автоматическим сканерам. Анализирует URI на наличие типичных запросов к уязвимостям (например, /wp-login.php, xmlrpc.php, /wp-admin/) и отдаёт фейковые правдоподобные ответы, отвлекая и истощая ресурсы атакующих ботов, либо блокирует ск
  - `__construct(?array $config = null)` — Конструктор Honeypot.
  - `handleRequest(): bool` — Проверяет запрос на принадлежность к сканерам уязвимостей.

### `system/Security/RateLimiter.php` (94 стр.)
- Класс: `System\Security\RateLimiter`
- Назначение (из docblock): Класс RateLimiter реализует ограничение частоты запросов по IP с временной блокировкой при превышении порога. Состояние хранится в storage/cache/.
  - `__construct(?array $config = null)`
  - `isLimitExceeded(): bool` — Проверяет запрос на превышение лимита частоты.

### `system/Security/Tripwire.php` (934 стр.)
- Класс: `System\Security\Tripwire`
- Назначение (из docblock): Класс Tripwire контролирует целостность ядра фреймворка (каталог system/). Принципы: - только обнаружение и уведомление; автоматического восстановления нет: восстановление из встроенной копии — сам по себе риск цепочки поставок, если эту копию можно подменить; - область контроля — только system/: ка
  - `static build(): array` — Пересобирает эталонный манифест system/.
  - `static check(): array` — Сверяет текущее содержимое system/ с эталонным манифестом.
  - `static checkStrayPhpFiles(): array` — Дешёвая проверка посторонних PHP-файлов — без хеширования.
  - `static runStrayCheckIfDue(): void` — Запускает дешёвую проверку посторонних PHP-файлов, если истёк её
  - `static reportStrayFile(string $relativePath): void` — Публичная фиксация одного постороннего файла.
  - `static runBackgroundCheckIfDue(): void` — Фоновая проверка по расписанию.
  - `static handleCronRequest(array $params, bool $cli = false): int` — Обработка вызова точки входа public/cron.php.

### `system/Services/Cache.php` (192 стр.)
- Класс: `System\Services\Cache`
- Назначение (из docblock): Класс Cache реализует надёжное файловое кэширование с поддержкой TTL, сериализации и автоматической очистки.
  - `__construct(?string $cacheDir = null, int $defaultTtl = 3600)` — Конструктор кэша.
  - `get(string $key, mixed $default = null): mixed` — Получает значение из кэша.
  - `set(string $key, mixed $value, ?int $ttl = null): bool` — Сохраняет значение в кэш.
  - `delete(string $key): bool` — Удаляет элемент из кэша.
  - `clear(): bool` — Очищает весь кэш в директории.

### `system/Services/Canonical.php` (86 стр.)
- Класс: `System\Services\Canonical`
- Назначение (из docblock): Класс Canonical нормализует и формирует канонические адреса страниц.
  - `__construct()` — Конструктор инициализирует канонический URL на основе текущего запроса.
  - `getUrl(): string` — Получает канонический URL для текущего запроса.
  - `renderTag(): string` — Формирует тег <link rel="canonical">.
  - `setUrl(string $url): void` — Принудительно устанавливает канонический адрес.

### `system/Services/ClientIp.php` (49 стр.)
- Класс: `System\Services\ClientIp`
- Назначение (из docblock): Resolve client IP, trusting forwarding headers only from configured proxies.
  - `static get(): string`

### `system/Services/FormService.php` (254 стр.)
- Класс: `System\Services\FormService`
- Назначение (из docblock): Form CSRF, origin, timing, honeypot, validation and response handling.
  - `__construct(?array $forms = null)`
  - `renderHiddenFields(string $formId): string` — Return the server-minted hidden controls for a form.
  - `processSubmission(string $formId, array $input): array` — Validates and sends a configured form. Returns [status, response].

### `system/Services/InstallationKey.php` (117 стр.)
- Класс: `System\Services\InstallationKey`
- Назначение (из docblock): Единая точка доступа к ключу установки. Ключ хранится в storage/installer.key как PHP-файл, возвращающий строку. Если файла нет, создаёт его (random_bytes(32), атомарная запись под flock). Установщик использует уже созданный ключ и не перегенерирует его. Если ключ получить невозможно — кидает Runtim
  - `static getKey(): string` — Возвращает ключ установки.

### `system/Services/LlmsGenerator.php` (127 стр.)
- Класс: `System\Services\LlmsGenerator`
- Назначение (из docblock): Класс LlmsGenerator формирует файл llms.txt для языковых моделей (LLM) на основе карты маршрутов pages/.
  - `static generate(array $routes, ?string $outputPath = null): bool` — Генерирует llms.txt в корне сайта.

### `system/Services/LogService.php` (114 стр.)
- Класс: `System\Services\LogService`
- Назначение (из docblock): Класс LogService обеспечивает безопасное логирование с защитой от флуда и авторотацией.
  - `static write(string $message, string $level = 'error', ?string $channel = null): bool` — Записывает сообщение в файл журнала.

### `system/Services/MailRateLimitedException.php` (36 стр.)
- Класс: `System\Services\MailRateLimitedException`
- Назначение (из docblock): Исключение превышения почтовых лимитов. Сообщает клиенту 429 с заголовком Retry-After.
  - `__construct(int $retryAfter, string $message = 'Mail rate limit exceeded')`

### `system/Services/MailRateState.php` (174 стр.)
- Класс: `System\Services\MailRateState`
- Назначение (из docblock): Состояние почтовых лимитов в Guard-файле (по образцу storage/integrity.state.php). Ключи по IP — HMAC-SHA256 от IP с ключом установки, а не открытый IP. Файл находится в storage внутри docroot; прямое выполнение закрыто Guard.
  - `static ipKey(string $ip): string`
  - `static checkAndRecord(array $config): array` — Проверяет лимиты и регистрирует отправку.
  - `static reset(): void`

### `system/Services/MailService.php` (361 стр.)
- Класс: `System\Services\MailService`
- Назначение (из docblock): Класс MailService обеспечивает отправку почты без внешних зависимостей с контролем лимитов и защитой от спама.
  - `__construct(?array $config = null)` — Конструктор почтового сервиса.
  - `send(string $to, string $subject, string $body, bool $isHtml = true, array $headers = [], ?string $textBody = null): bool` — Отправляет электронное письмо.
  - `getLastRetryAfter(): int`

### `system/Services/RobotsGenerator.php` (249 стр.)
- Класс: `System\Services\RobotsGenerator`
- Назначение (из docblock): Класс RobotsGenerator поддерживает robots.txt в актуальном состоянии. Что гарантируется: - строка Sitemap указывает на sitemap.xml текущего домена из конфигурации; - внутренние каталоги фреймворка (/pages/, /app/, /system/) закрыты от обхода роботами: их содержимое — исходные шаблоны, доступные по п
  - `static generate(?string $outputPath = null): bool` — Обновляет robots.txt в корне сайта.

### `system/Services/Schema.php` (579 стр.)
- Класс: `System\Services\Schema`
- Назначение (из docblock): RAMEVA Framework 2 Класс Schema — расширяемый генератор микроразметки Schema.org в формате JSON-LD. Перенесён из RAMEVA Framework 1 (app/Core/Schema.php) с сохранением всего функционала и с адаптацией к конфигурации второго фреймворка: - builder-паттерн: create()/context()/type()/set()/add()/fromArr
  - `static create(?string $type = null): self` — Создаёт новый экземпляр билдера.
  - `context(string $context = 'https://schema.org'): self` — Устанавливает @context.
  - `type(string $type): self` — Устанавливает @type (обязательно).
  - `set(string $key, mixed $value): self` — Устанавливает значение поля (перезаписывает, если существует).
  - `add(string $key, mixed $value): self` — Добавляет значение в массив поля.
  - `fromConfig(array $config): self` — Загружает данные из конфигурации с нормализацией значений.
  - `fromArray(array $data): self` — Загружает данные из произвольного массива.
  - `toArray(): array` — Возвращает данные схемы в виде массива.
  - `toJson(): string` — Строит JSON-LD строку.
  - `build(): string` — Строит полный <script> тег с JSON-LD.
  - `static renderMultiple(array $schemas): string` — Рендерит несколько схем в один HTML-блок.
  - `static webSite(array $override = []): self` — Создаёт схему WebSite с базовыми данными из конфигурации.
  - `static localBusiness(array $override = []): self` — Создаёт схему LocalBusiness из конфигурации.
  - `static article(array $data = []): self` — Создаёт схему Article.
  - `static product(array $data): self` — Создаёт схему Product.
  - `static breadcrumb(array $items): self` — Создаёт схему BreadcrumbList из списка элементов.
  - `static breadcrumbList(array $data = []): self` — Фабрика BreadcrumbList из готового массива данных.
  - `static organization(array $data = []): self` — Фабрика Organization — общая информация о компании (логотип, контакты, адрес).
  - `static webPage(array $data = []): self` — Фабрика WebPage — описание конкретной страницы (name, description, breadcrumb).
  - `static faqPage(array $data = []): self` — Фабрика FAQPage.
  - `static person(array $data = []): self` — Фабрика Person — автор или контактное лицо.
  - `static howTo(string $name, array $steps, array $custom = []): self` — Фабрика HowTo — пошаговая инструкция для расширенных сниппетов.
  - `withSpeakable(array $cssSelectors): self` — Добавляет спецификацию Speakable (для голосовых ассистентов и GEO-поиска).
  - `static jsonLd(array $data = []): string` — Утилита: сборка готового <script> тега из произвольного массива JSON-LD.

### `system/Services/SeoService.php` (237 стр.)
- Класс: `System\Services\SeoService`
- Назначение (из docblock): Класс SeoService отвечает за автоматическое формирование SEO/GEO метаданных.
  - `__construct()`
  - `generateHeadTags(string $uri, array $viewData, string $pageRawContent = ''): string` — Генерирует полный блок метаданных для секции <head>.
  - `resolveTitle(array $data): string`
  - `resolveCanonical(string $uri, array $data): string`
  - `resolveDescription(array $data, string $rawContent = ''): string`
  - `resolveImage(array $data): string`
  - `resolveSchema(string $url, string $title, string $description, string $image, array $data): string`

### `system/Services/SessionService.php` (186 стр.)
- Класс: `System\Services\SessionService`
- Назначение (из docblock): Класс SessionService — единственное место в фреймворке для инициализации сессии. Централизованное управление сессией обеспечивает: - Единый вызов session_start() с правильными параметрами - Безопасные настройки cookie (HttpOnly, SameSite, Secure) - Strict mode для защиты от фиксации сессии - Хранени
  - `static start(): void` — Инициализирует сессию с безопасными настройками.
  - `static isStarted(): bool` — Проверяет, запущена ли сессия.
  - `static regenerate(bool $deleteOld = true): void` — Регенерация ID сессии (например, после логина).
  - `static destroy(): void` — Уничтожает сессию полностью.
  - `static set(string $key, mixed $value): void` — Устанавливает значение в сессии.
  - `static get(string $key, mixed $default = null): mixed` — Получает значение из сессии.
  - `static has(string $key): bool` — Проверяет наличие ключа в сессии.
  - `static forget(string $key): void` — Удаляет ключ из сессии.

### `system/Services/SitemapGenerator.php` (94 стр.)
- Класс: `System\Services\SitemapGenerator`
- Назначение (из docblock): Класс SitemapGenerator отвечает за построение файла sitemap.xml на основе скомпилированной карты маршрутов pages/.
  - `static generate(array $routes, ?string $outputPath = null): bool` — Генерирует sitemap.xml из карты маршрутов и сохраняет его в корень public/.

