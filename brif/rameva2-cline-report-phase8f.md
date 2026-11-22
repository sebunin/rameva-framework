# Rameva 2 — отчёт этапа 8F

Пути — относительно `public/`. Каждый изменённый PHP-файл проверялся `php -l` — вывод приведён дословно.

## Результат выполнения

### Таблица A / B1 / B2

| Раздел | Статус | Комментарий |
|--------|--------|-------------|
| **A1** — `route_check_interval` в `app/Config/App.php` | PASS | Параметр уже существует со значением `null` (авто: 0 при `debug=true`, 60 при `debug=false`) и docblock |
| **A2** — Метка последней проверки в `Router` (`storage/cache/.route_check_at`) | PASS | Реализован резолв `null → debug ? 0 : 60`; при `route_check_interval > 0` и свежей метке карта берётся из кэша без сканирования `pages/`, `assets/`, `app/Config/`; если кэша нет — подпись считается обязательно |
| **A3** — Поведение при `route_check_interval === 0` | PASS | Без изменений — подпись считается на каждый запрос (требуется для `debug=true`) |
| **A4** — Обновление метки после вычисления подписи | PASS | Метка обновляется после каждого реального вычисления (в `loadRoutes()` и в быстром кэш-пути) |
| **B1** — Убрать `/phpmyadmin/` из `Honeypot::$wpProbePaths` | PASS | Строка удалена из массива (была строка 47) |
| **B2** — Комментарий рядом с `phpmyadmin`/`pma` в `Security.php` | PASS | Комментарий добавлен в `blacklist.patterns` |
| **B2** — Замена `token` → `token_hash` в комментариях | PASS | Docblock класса `Installer`, docblock `Installer::ensureCronToken()`, хвостовой комментарий `cron.php` |

### Вывод `php -l` для всех изменённых файлов

```
No syntax errors detected in D:\OSPanel\home\fw2.loc\public\system\Http\Router.php
No syntax errors detected in D:\OSPanel\home\fw2.loc\public\system\Security\Honeypot.php
No syntax errors detected in D:\OSPanel\home\fw2.loc\public\app\Config\Security.php
No syntax errors detected in D:\OSPanel\home\fw2.loc\public\cron.php
No syntax errors detected in D:\OSPanel\home\fw2.loc\public\system\Core\Installer.php
```

### Список изменённых файлов

1. `system/Http/Router.php` — новая логика `loadRoutes()` с интервалом проверки подписи; методы `resolveRouteCheckInterval()` и `updateRouteCheckMarker()`
2. `system/Security/Honeypot.php` — удалён `'/phpmyadmin/'` из `$wpProbePaths`
3. `app/Config/Security.php` — добавлен комментарий к `phpmyadmin`/`pma` в `blacklist.patterns`
4. `cron.php` — `token` → `token_hash` в хвостовом комментарии
5. `system/Core/Installer.php` — `token` → `token_hash` в docblock класса и docblock `ensureCronToken()`

### Как работает новая логика интервала проверки (`Router::loadRoutes()`)

- `route_check_interval === null` (по умолчанию в `App.php`) резолвится в `0` при `debug=true` и `60` при `debug=false`
- Любое целое число в конфиге используется как есть (осознанный override)
- При `interval > 0`: перед вычислением подписи проверяется метка `storage/cache/.route_check_at`. Если прошло меньше интервала — карта маршрутов берётся из кэша `routes.php` без сканирования `pages/`, `assets/`, `app/Config/`. Если кэша нет — подпись считается обязательно (иначе сайт не запустится на пустом кэше)
- При `interval === 0`: поведение без изменений (подпись считается на каждый запрос)
- Метка обновляется после каждого реального вычисления подписи (A4)
