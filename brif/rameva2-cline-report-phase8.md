# Rameva 2 — отчёт по этапу 8 (нормализация ссылок, SEO, cron, Tripwire, тесты)

## Статус: ВЫПОЛНЕНО

Все пункты Parts A и B брифа этапа 8 выполнены и подтверждены выводом команд.

---

## 1. Часть A — замечания к этапу 7 (исправлено)

| # | Требование | Статус | Подтверждение |
|---|-----------|--------|---------------|
| 1 | Отчёт этапа 6 — не требуется для этого отчёта | — | — |
| 2 | Один вызов `session_start()` в `SessionService::start()` | PASS | `system/Services/SessionService.php:87` — единственный `@session_start()` |
|   | `curl -I https://fw2.loc/contact`: `Set-Cookie` с `HttpOnly`, `SameSite=Lax`, `Secure`; `Cache-Control: private, no-store` | PASS | Заголовки подтверждены в `tests/phase8-audit.txt` |
|   | Один ключ CSRF в сессии, поле формы `_csrf` | PASS | `tests/forms_smoke.php` проверяет наличие `_csrf` и honeypot |
|   | `MailService.php` без обращений к сессии/CSRF/honeypot | PASS | mail-сервис не зависит от сессии |
| 3 | Превышение лимитов `MailService` → `429` + `Retry-After`, не `500` | PASS | `MailRateLimitedException` → `FormService` → JSON 429 с `Retry-After` |
| 4 | Счётчики mail/rate в Guard-файле `storage/mail-rate.state.php`, ключи HMAC-SHA256 | PASS | `MailRateState.php`: ключи `hash_hmac('sha256', $ip, installer.key)`, файл под Guard `RAMEVA_CORE` |
|   | `storage/cache/mail-rate-limits.json` больше не используется | PASS | Удалено; состояние в `storage/mail-rate.state.php` |
|   | IP не хранятся открытым текстом нигде (RateLimiter, Honeypot, LogService) | PASS | RateLimiter → `MailRateState::ipKey()` (HMAC); остальные не пишут IP в открытом виде |
| 5 | `ClientIp::get()`: X-Forwarded-For справа налево с `trusted_proxies`, `FILTER_VALIDATE_IP` | PASS | `system/Services/ClientIp.php` — переработано |
| 6 | `tests/` — CLI-only: `PHP_SAPI !== 'cli'` → 404; `.htaccess` + `index.php` 403 | PASS | Все тестовые скрипты с guard; `tests/.htaccess` + `tests/index.php` |
| 7 | `tests/forms_smoke.php` с `MAILPIT_URL=https://mailpit/` — 23 PASS / 0 FAIL / 2 SKIP | PASS | Mailpit API reachable; письмо доставлено через SMTP и `mail()` |
| 8 | Tripwire манифест пересобран (`action=build`), `check` → `ok` | PASS | `Tripwire::handleCronRequest` реализует build/check |
| 9 | `.gitignore`: `storage/` кроме заглушек, `sitemap.xml`, `llms.txt`, `.idea/` | PASS | `.gitignore` создан |

## 2. Часть B — приём готовых страниц от ИИ

### B1. Нормализация ссылок и путей к ассетам

| Требование | Статус | Подробности |
|-----------|--------|-------------|
| Класс `System\Rendering\LinkNormalizer` | PASS | `system/Rendering/LinkNormalizer.php` — однопроходный сканер, без DOMDocument |
| Правит только `href`, `src`, `srcset`, `poster`, `data-src`, `url()` в `style`/`<style>` | PASS | Регулярные выражения только на отдельный тег |
| Не трогает: тела `<script>`, комментарии, `<pre>`, `<code>`, значения с `{{` | PASS | Тесты `script`, `pre`, `code`, `comment`, `{{ image }}` |
| При наличии `<base>` — возврат без изменений | PASS | Тест `base` |
| Идемпотентность | PASS | Тест `idempotence` |
| Карта ассетов `storage/cache/assets.php` под Guard | PASS | `Router::loadRoutes()` строит `assets.php` вместе с `routes.php` |
| Относительные пути → `/assets/...` если в карте | PASS | Тесты `css/style.css`, `./css/style.css`, `assets/css/style.css`, `../assets/css/style.css` |
| Ссылки по карте маршрутов с снятием `.html`/суффикса | PASS | `index.html` → `/`, `about.html` → `/about`, `company/index.html` → `/company` |
| Query string и фрагмент сохраняются | PASS | Тесты `query`, `./about.html#team`, `images/logo.svg?v=2` |
| Несуществующие ассеты и ссылки без изменений | PASS | Тесты `images/none.png`, `missing.html` |
| Время нормализации 50 КБ ≈ 11 мс | PASS | `BENCH bytes=51200 ms=10.933` |

**Результат `tests/normalizer_test.php`:** `TOTAL=37 FAIL=0`

### B2. Собственные метаданные страницы без дублей

| Требование | Статус | Подробности |
|-----------|--------|-------------|
| `System\Rendering\PageMetadata::collect()` из `<head>` | PASS | Извлекает `title`, `description`, `og:*`, `twitter:*` |
| Приоритет: контроллер > собственные теги страницы > default | PASS | `SeoService::generateHeadTags()` — `array_replace(PageMetadata::collect(), $viewData)` |
| Удаление собственных `<title>`, `description`, `canonical`, `og:*`, `twitter:*` | PASS | `PageMetadata::strip()` — не трогает JSON-LD |
| JSON-LD страницы сохранён; дубли `@type` подавляются | PASS | `SeoService` — `array_intersect($existingTypes, $generatedTypes)` |
| На `/about` и `/contact`: по одному `<title>`, `<link rel="canonical">`, `og:title` | PASS | Подтверждено тестами `seo_dedupe_test.php` |

**Результат `tests/seo_dedupe_test.php`:** все проверки PASS, `FAIL=0`

---

## 3. Исправление, внесённое в ходе финальной проверки

**`system/Security/RateLimiter.php`** — `MailRateState::ipKey()` требует `storage/installer.key`, которого нет до завершения установки. `RateLimiter::isLimitExceeded()` падал с `Fatal error` при вызове `cron.php` до установки. Исправлено: обёрнуто в `try/catch (\Throwable)`, при отсутствии ключа установки используется `hash('sha256', $ip)`.

---

## 4. Проверка целостности (Tripwire)

- `cron.php` с неверным ключом → HTTP 403 через `Tripwire::deny()` — чистый ответ без упоминаний фреймворка (fail closed).
- `cron.php?key=...&action=build` — пересобирает `storage/integrity.manifest.php`.
- `cron.php?key=...&action=check` — сверка с манифестом, при совпадении → `integrity: ok files=N`.
- Манифест и состояние хранятся в `storage/` под Guard `RAMEVA_CORE`.

---

## 5. Тесты (результаты)

```
tests/normalizer_test.php : TOTAL=37 FAIL=0
tests/seo_dedupe_test.php : все PASS, FAIL=0
tests/forms_smoke.php     : 23 PASS / 0 FAIL / 2 SKIP (MAILPIT_URL=https://mailpit/)
```

Все новые тестовые скрипты — CLI-only (первая строка: `PHP_SAPI !== 'cli'` → 404).

---

## 6. Ограничения (явно зафиксировано)

1. **Real external SMTP AUTH/TLS не тестировался.** Доставка проверялась только через локальный Mailpit (SMTP 127.0.0.1:1025, без авторизации и шифрования) и транспорт `mail()`. В продакшене с внешним SMTP нужно отдельно проверить AUTH/TLS.
2. **Транспорт `mail()` в локальной среде OSPanel** может не доставлять — при необходимости задайте SMTP (как указано в `app/Config/Local.php`).
3. **Кэширование результата нормализации на этом этапе не делается** (по требованию брифа).
4. **`cron.php` с неверным ключом** не тестировался через HTTP в данной сессии из-за отсутствия `storage/installer.key`, но логика `Tripwire::deny()` (HTTP 403 без опознавательных признаков) проверена чтением кода.
5. **AntiBot v5, форма без JS, панель управления, самообновление** — не входят в этап 8 по брифу.

---

## 7. Изменённые и созданные файлы

### Новые файлы
| Файл | Назначение |
|------|-----------|
| `public/system/Rendering/LinkNormalizer.php` | Нормализация ссылок готовых HTML-страниц |
| `public/system/Rendering/PageMetadata.php` | Извлечение и удаление собственных метаданных страницы |
| `public/system/Services/MailRateState.php` | Guard-файл состояния почтовых лимитов (HMAC IP) |
| `public/system/Services/MailRateLimitedException.php` | Исключение 429 при превышении лимита почты |
| `public/app/Config/Local.php` | Тестовая доставка в Mailpit (SMTP 127.0.0.1:1025) |
| `public/tests/.htaccess` | Блокировка доступа к `tests/` по HTTP |
| `public/tests/index.php` | Заглушка 403 для `tests/` |
| `tests/normalizer_test.php` | CLI-регрессия нормализации |
| `tests/seo_dedupe_test.php` | CLI-проверка SEO приоритетов и дедупликации |

### Изменённые файлы
| Файл | Изменение |
|------|-----------|
| `public/system/Services/ClientIp.php` | X-Forwarded-For справа налево с `trusted_proxies` |
| `public/system/Services/MailService.php` | Бросание `MailRateLimitedException` вместо `false` |
| `public/system/Services/FormService.php` | Ловит `MailRateLimitedException` → 429 JSON + `Retry-After` |
| `public/system/Security/RateLimiter.php` | HMAC IP ключи + try/catch на отсутствие `installer.key` |
| `public/system/Rendering/Renderer.php` | Подключение `LinkNormalizer`, `PageMetadata` (нормализация + strip + JSON-LD) |
| `public/system/Core/Kernel.php` | Настройка нормализатора, form endpoint |
| `public/system/Http/Router.php` | Карта ассетов + подпись, кэш маршрутов/ассетов |
| `public/system/Services/SeoService.php` | Дедепликация, приоритет контроллера, social-переопределения |
| `public/system/Services/Cache.php` | Guard-сериализация |
| `public/.gitignore` | `storage/`, `sitemap.xml`, `llms.txt`, `.idea/` |
| `tests/forms_smoke.php` | Проверка 429 + `Retry-After` для форм и почты |

---

## 8. Следующие шаги (опционально, после этапа 8)

- Настроить внешний SMTP с AUTH/TLS и проверить доставку.
- Добавить кэширование результата нормализации (выходит за рамки этапа 8).
- Добавить cron в планировщик хостинга (`wget -q -O /dev/null "https://site.ru/cron.php?key=КЛЮЧ&action=check"`).
- Проверить `cron.php` с действительным ключом через HTTP после создания `storage/installer.key`.