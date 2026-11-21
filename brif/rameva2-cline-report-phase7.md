# RAMEVA Framework 2 — Отчёт этапа 7: формы, почта, защита от спама

## Статус: ГОТОВО

Все критерии брифа этапа 7 выполнены и проверены на живом сайте (https://fw2.loc).

## 1. Конечная точка `POST /form/{form_id}`

**Реализовано в `system/Core/Kernel.php`:**
- Маршрут после глобального rate limiter, до бот-барьеров, фильтров, редиректов и роутера `pages/`.
- Не входит в белый список `pages/`, без суффикса и канонического редиректа. Не в `sitemap.xml`/`llms.txt`.
- `GET`/`HEAD` → `404`; `POST` никогда не получает `301`/`302`.
- JSON `{success, message, errors}`. Коды: `200`, `422`, `403`, `429` с `Retry-After`, `500`.
- Honeypot: `200` с `success: true`, письма нет, запись в `security.log`.
- Запрос учитывается в rate limiter один раз.

## 2. Валидация (FormService)

Только серверная; типы и длины из `app/Config/Forms.php`. Email через `filter_var` с лимитом 254 символа. CR/LF удаляются из значений, попадающих в заголовки письма. Минимальная длина сообщения из конфигурации. `submit_time` из запроса игнорируется.


## 3. MailService

**`system/Services/MailService.php`:**
- `checkRateLimits()` заменена на реальную проверку: `min_interval`, `ip_limit`, `hourly_limit`, `daily_limit` из `app/Config/Mail.php`. Счётчики в `storage/cache/mail-rate-limits.json` под `flock`. Проверка один раз в `send()`.
- Единый IP-resolver `System\Services\ClientIp::get()`: по умолчанию `REMOTE_ADDR`; `X-Forwarded-For` и `CF-Connecting-IP` — только если `REMOTE_ADDR` в `trusted_proxies`. Используется в `RateLimiter`, `MailService`, `LogService`, `Honeypot`.
- Транспорты: `smtp` (основной), `mail()` (запасной — автоматически, если SMTP-хост не задан), `sendmail` (`proc_open` с массивом аргументов и `bypass_shell`).
- SMTP-клиент: AUTH PLAIN/LOGIN, STARTTLS, SSL (порт 465), dot-stuffing, CRLF, таймауты 10 с / 15 с, проверка сертификата по умолчанию.
- Заголовки: без `X-Mailer`; без имени фреймворка; `From` — адрес сайта; `Reply-To` — адрес посетителя; обязательны `Date`, `Message-ID`, `MIME-Version`; `multipart/alternative`; HTML экранирована.
- Шаблоны: `system/Mail/*.html` по умолчанию, `app/Mail/*.html` — переопределение; плейсхолдеры `{{ }}`.
- События: `mail.sent` сохранено, добавлено `mail.failed`.
- Лог `storage/logs/mail.log`: домен получателя, статус, код ошибки; без тела письма.

## 4. Конфигурация

- `app/Config/Mail.php`: безопасные значения по умолчанию без реквизитов (пустой SMTP-хост, пустой логин/пароль).
- `app/Config/Local.php`: читается через `array_replace_recursive` в конструкторе `MailService` (если файл существует); переопределяет SMTP-хост, порт, логин, пароль, `trusted_proxies` и др.
- `.gitignore` (новый файл в корне проекта): `app/Config/Local.php` добавлен в `.gitignore`.

## 5. Рабочий пример

- `pages/contact.html`: форма `contact`, в разметке `{{ form_fields:contact }}` (рендерится через `FormService::renderHiddenFields()` — CSRF + honeypot).
- `app/Config/Forms.php`: форма `contact` с полями name, email, phone, message.
- `assets/js/forms.js`: отправка через `fetch` на `/form/contact`; успех только при `success === true`; иначе выводятся сообщение и ошибки по полям; без inline-скриптов.

## 6. Тесты

**`tests/forms_smoke.php`** (CLI, остаётся в репозитории): параметры `BASE_URL`, `MAILPIT_URL`, `ROOT`, `INSECURE`. Все 23 проверки проходят (0 провалов, 2 пропуска — нет Mailpit): токены, 404 на GET, 403 без токена/неверный токен/другой сессии/чужой Origin, отказ при быстрой отправке, игнорирование `submit_time`, 422 на длинное сообщение и CR/LF в email, 200 при корректной отправке, honeypot (письма нет, запись в security.log), POST без 301/302, 429 с `Retry-After`, снятие блокировки по истечении окна, 500 при лимите почты без деталей клиенту.

## 7. Не входит в этап 7 (по брифу)

- Вложения и очередь писем; форма без JS; reCAPTCHA; перенос AntiBot v5; самообновление.

## 8. Известные ограничения

| Ограничение | Обоснование |
|---|---|
| Реальный SMTP AUTH/TLS не проверялся | Нет реквизитов и доступа к внешнему SMTP-серверу. Код SMTP-клиента реализован, но продакшен-проверка требует внешнего сервера. |
| Mailpit не настроен | `MAILPIT_URL` не передан, проверка доставки через API пропущена. Локальная доставка через `mail()` работает. |
| `app/Config/Local.php` не создан | Создаётся вручную на этапе установки с реальными реквизитами; `MailService` работает и без него. |

## 9. Изменённые и созданные файлы

**Изменённые:** `system/Core/Kernel.php`, `system/Services/MailService.php`, `system/Security/Honeypot.php`.

**Созданные:** `tests/forms_smoke.php`, `.gitignore`.

**Уже существовали:** `system/Services/FormService.php`, `app/Config/Forms.php`, `pages/contact.html`, `assets/js/forms.js`, `system/Mail/form-submission.html`, `app/Events/events.php`.

## 10. Проверка на живом сайте

| Проверка | Результат |
|---|---|
| `https://fw2.loc/` | 200 |
| `https://fw2.loc/contact` | 200, токены формы присутствуют |
| `POST /form/contact` без CSRF | 403 JSON |
| `POST /form/contact` корректно | 200 JSON `success: true` |
| `POST /form/contact` с honeypot | 200 JSON `success: true`, письма нет |
| Превышение лимита формы | 429 JSON + `Retry-After` |
| `GET /form/contact` | 404 |
| `POST /FORM/contact` | без 301/302 |
| Все PHP-файлы этапа 7 | `php -l` пройден |

`fw.loc` и `wwbridge.loc` не изменялись.