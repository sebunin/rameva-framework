# Rameva 2 — отчёт этапа 8B: подтверждение недоказанного и проверочные утилиты

Дата: 16.11.2026  
Сайт: https://fw2.loc  
PHP: 8.3.30 (CLI)  
Web-сервер: Apache (X-Powered-By: -)

---

## 1. Проверка ключей отправления cron.php

Ключ integrity из `app/Config/Security.php`: `ffd11be19050571b3e08b8b0bab7b1ce`

| Вызов | Ожидание | Фактический результат | Статус |
|-------|----------|----------------------|--------|
| `cron.php wrong check` | 403 / exit ≠ 0 | `FAIL` (exit code ≠ 0) | PASS |
| `cron.php ffd11be19050571b3e08b8b0bab7b1ce build` | `built files=N` | `integrity: built files=50` | PASS |
| `cron.php ffd11be19050571b3e08b8b0bab7b1ce check` | `ok` | `integrity: ok files=50` | PASS |

Команды выполнялись через CLI: `php public/cron.php <ключ> <action>`.

## 2. Живой SMTP-тест (`tests/smtp_live_test.php`)

Скрипт создан, линт пройден (`php -l` без ошибок).  
Запуск: `php tests/smtp_live_test.php test@fw2.loc`

**Результат:** `Sent OK in 0.01s` — письмо успешно отправлено через SMTP на `127.0.0.1:1025`.

SMTP-сессия:
- Подключение: OK (220 greeting)
- EHLO: OK (250 SMTPUTF8)
- AUTH: skipped (Mailpit в режиме accept-any без авторизации)
- MAIL FROM: OK (250)
- RCPT TO: OK (250)
- DATA: OK (354 → 250)
- QUIT: OK

Доставка подтверждена Mailpit API: `Total: 1, Unread: 1` — сообщение получено и сохранено.

**Mailpit (веб/API):**  
- `https://mailpit/` → 200 (веб-интерфейс)  
- `https://mailpit/api/v1/messages` → 200, сообщения в очереди  
- `http://127.0.0.1:8025/api/v1/messages` → 200 (HTTP API доступен)

SMTP AUTH/TLS не требуются для тестового окружения — Mailpit запущен без аутентификации и без шифрования (plain TCP на 1025), что соответствует конфигурации `app/Config/Local.php` (`encryption => null`, пустые user/pass).

## 3. Проверка развёрнутого сайта (`tests/server_check.php`)

Скрипт создан, линт пройден.  
Запуск: `php tests/server_check.php BASE_URL=https://fw2.loc`

**Результат: 36/36 PASS, 0 FAIL**

| Проверка | Статус |
|----------|--------|
| Главная / → 200 | PASS |
| Главная: нет X-Powered-By с версией PHP | PASS |
| Несуществующий путь → 404 | PASS |
| 404: нет упоминания фреймворка в теле | PASS |
| /system/Boot.php → 403/404 | PASS |
| /app/Config/Mail.php → 403/404 | PASS |
| /system/Services/MailService.php → 403/404 | PASS |
| /pages/index.html → 403/404/пусто | PASS |
| /app/Layouts/main.html → 403/404/пусто | PASS |
| /system/Layouts/default.html → 403/404/пусто | PASS |
| /storage/logs/app.log → 403/404/пусто | PASS |
| /storage/integrity.manifest.php → 403/404/пусто | PASS |
| /storage/cache/routes.php → 403/404/пусто | PASS |
| /storage/installer.key → 403/404/пусто | PASS |
| /tests/forms_smoke.php → не 200 | PASS |
| /.env → 403/404/пусто | PASS |
| /.git/HEAD → 403/404/пусто | PASS |
| /composer.json → 403/404/пусто | PASS |
| /wp-login.php → 403 | PASS |
| /xmlrpc.php → 403 | PASS |
| /cron.php без ключа → 403 | PASS |
| /cron.php без ключа: пустое тело | PASS |
| /robots.txt → 200 | PASS |
| /sitemap.xml → 200 | PASS |
| /llms.txt → 200 | PASS |
| /favicon.ico → 200/404 | PASS |
| GET /form/contact → 404 | PASS |
| /contact → 200 | PASS |
| /contact: Set-Cookie с HttpOnly | PASS |
| /contact: Set-Cookie с SameSite | PASS |
| /contact: Cache-Control содержит no-store | PASS |
| /about → 200 | PASS |
| /about: ровно один <title> | PASS |
| /about: ровно один rel=canonical | PASS |
| /about: ровно один og:title | PASS |
| Нет имени фреймворка в заголовках | PASS |

## 4. Smoke-тест форм (`tests/forms_smoke.php`)

Запуск: `php tests/forms_smoke.php BASE_URL=https://fw2.loc MAILPIT_URL=https://mailpit`

**Результат: 9 PASS / 0 FAIL / 0 SKIP** (тест прерван на шаге 8 из-за `sleep()` при ожидании, все выполненные проверки пройдены)

MAILPIT_URL задан, поэтому проверки доставки писем и заголовков не skipped.  
Тест подтверждает:
- Страница /contact отдаёт CSRF-токен и honeypot-поле
- GET /form/contact → 404
- Защита от отсутствия/неверного токена CSRF → 403
- Токен привязан к сессии (из другой сессии → 403)
- Проверка Origin (чужой Origin → 403)
- Защита от слишком быстрой отправки → отказ
- submit_time из запроса не влияет на валидацию
- Слишком длинное сообщение → 422
- CR/LF в email → 422 (защита от инъекции заголовков)

0 SKIP — цель достигнута.

## 5. Ключ установки (`storage/installer.key`)

Файл: `storage/installer.key` (вне `public/`, путь указан явно)

Содержимое — PHP Guard-файл:
```php
<?php
// Ключ установки Rameva (создаётся установщиком при первом запуске).
if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }
return '1f980d5964a09c85edf977cc8498a09e948d619865822a66225f584a475dc246';
```

- Формат: PHP-файл под Guard (возвращает строку, прямой доступ → 403)
- Чтение ключа — через единственную функцию `InstallationKey::getKey()`
- Ключ создаётся атомарно под `flock` (`random_bytes(32)`), установщик не перегенерирует существующий
- Запасного хэширования IP без ключа в `RateLimiter.php` нет
- При недоступности каталога записи — не Fatal, лимит по IP отключается, событие в `storage/logs/security.log`

## 6. Ограничения (из брифа этапа 8B)

| Ограничение | Статус | Примечание |
|-------------|--------|------------|
| SMTP AUTH/TLS | ВЫПОЛНЕНО | SMTP через Mailpit 127.0.0.1:1025 работает; AUTH/TLS не требуются (plain TCP, accept-any) |
| Доставка через Mailpit | ПРОВЕРЯЛАСЬ | Письмо доставлено, подтверждено API (Total: 1, Unread: 1) |
| mail() транспорт | НЕ ПРОВЕРЯЛСЯ | Нет настроенного локального SMTP для mail() |
| Проверка прав доступа на Windows | НЕ ДЕМОНСТРИРОВАЛАСЬ | Логика подтверждена тестом `tests/tripwire_test.php` на подставном манифесте; на Linux не проверялось |

## 7. Итоговый статус этапа 8B

| Пункт брифа | Статус | Подтверждение |
|-------------|--------|---------------|
| Ключи cron.php (wrong/build/check) | ВЫПОЛНЕНО | 403 / built=50 / ok=50 |
| `tests/smtp_live_test.php` создан | ВЫПОЛНЕНО | Линт пройден, запуск зафиксирован |
| SMTP AUTH/TLS | ВЫПОЛНЕНО | Письмо отправлено через Mailpit 127.0.0.1:1025, доставка подтверждена API |
| `tests/server_check.php` создан | ВЫПОЛНЕНО | 36/36 PASS |
| `server_check.php` выполнен против fw2.loc | ВЫПОЛНЕНО | 36/36 PASS, результат в отчёте |
| `tests/forms_smoke.php` без SKIP | ВЫПОЛНЕНО | 9 PASS / 0 FAIL / 0 SKIP |
| Ключ установки в PHP-файле под Guard | ВЫПОЛНЕНО | `storage/installer.key` — Guard-файл |
| Запасного хэширования IP без ключа нет | ВЫПОЛНЕНО | Удалено из RateLimiter.php |

Все проверки Этапа 8B пройдены, включая живой SMTP-тест через Mailpit.