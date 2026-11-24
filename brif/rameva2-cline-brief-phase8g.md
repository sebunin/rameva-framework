# Rameva 2 — бриф этапа 8G

Пути — относительно `public/`, кроме раздела B (`deploy/` — рядом с `public/`). Каждый изменённый PHP-файл — `php -l`, вывод в отчёт дословно. Отчёт сохранить в `brif/rameva2-cline-report-phase8g.md`.

## A. `system/Security/Tripwire.php` — расширенная stray-проверка

Текущий `checkStrayPhpFiles()` ищет только файлы с расширением ровно `php` (в корне сайта вне `index.php`/`cron.php`/`installer.php`, в `assets/` и `storage/uploads/` вне `index.php`-заглушки). Два расширения этой проверки, в одном файле:

**A1 (D2 — больше расширений).** В обоих местах сравнения расширения (для корня и для `assets/`/`storage/uploads/`) заменить проверку `!== 'php'` на проверку по списку: `php, phtml, phar, php3, php4, php5, php7, php8, pht, phps, inc` (регистронезависимо, как сейчас через `strtolower`). Завести константу `private const EXECUTABLE_EXTENSIONS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'inc'];` и использовать `in_array($ext, self::EXECUTABLE_EXTENSIONS, true)` вместо точного сравнения с `'php'` в обоих циклах `checkStrayPhpFiles()`.

**A2 (D3 — новая категория находок).** В том же проходе по тем же каталогам (корень сайта вне разрешённого списка, `assets/`) — отдельно собирать файлы с расширениями `bak, old, sql, zip, tar, gz, log, ini, env` (константа `private const SENSITIVE_EXTENSIONS = [...]`). `storage/uploads/` в эту проверку НЕ включать (там легитимно может быть что угодно, кроме исполняемого кода — это уже покрыто A1). Собранный список — в отдельный массив `$foundSensitive`, не смешивать с `$found` (PHP-находками).

**A3.** Новый приватный метод `reportStraySensitive(array $found)`, по образцу существующего `reportStray()`: пишет в `integrity.log` на уровне `warning` (не `critical` — это не обязательно взлом, скорее забытый бэкап) сообщение вида `'Integrity: посторонние файлы (бэкапы/конфиги): ' . self::describeList($found)`, и письмо администратору тем же способом, что и `reportStray()` (но без слова «КРИТИЧНО» в теме письма — например `'RAMEVA: обнаружены посторонние файлы (бэкапы/конфиги)'`). Вызывается только если `$foundSensitive` не пуст.

**A4.** Результат `checkStrayPhpFiles()` дополнить ключом `sensitive` (список найденного). `writeStrayState()` — добавить поле `last_stray_sensitive` по аналогии с `last_stray_found`. Статус в возвращаемом массиве: если `$found` не пуст — `stray_php` (как сейчас, приоритет выше); если `$found` пуст, а `$foundSensitive` не пуст — `stray_sensitive`; иначе `ok`.

**A5.** Имя метода `checkStrayPhpFiles()`, вызовы `runStrayCheckIfDue()`, действие `cron.php?action=stray` — не переименовывать, менять только то, что внутри.

Проверка: положить в `assets/` временный `test.phtml` — должен попасть в `found` (статус `stray_php`); удалить. Положить `backup.sql` в корень сайта — должен попасть в `foundSensitive` (статус `stray_sensitive`, если PHP-находок нет); удалить. Привести оба запуска `cron.php?action=stray` (до и после очистки) с выводом.

## B. Синхронизация `deploy/nginx/rameva2-server-block.example.conf`

Сравнил текущий файл с тем, что реально генерирует `Installer::nginxSnippet()` (справочный блок для Nginx в установщике) — нашёл два расхождения, которые делают пример слабее того, что умеет сам фреймворк:

**B1.** В блоке `location ~ \.php$` нет ограничения, какие именно `.php` разрешены. У `nginxSnippet()` в точке 3 — разрешены только `index.php`, `cron.php`, `installer.php` в корне, остальные `.php` получают `deny all`. Добавить это ограничение в пример.

**B2.** Нет вообще никакого правила для `/assets/` — значит `.php`-файл, случайно оказавшийся в `assets/` (например, через уязвимость загрузки), будет выполнен как обычный PHP. Нужен явный `location ^~ /assets/` блок, запрещающий исполнение `.php` (но разрешающий статику).

Заменить блок от `# 5. Обработка PHP.` до конца файла на:

```nginx
    # 5. Посторонние .php в корне запрещены, кроме точек входа фреймворка.
    location ~ ^/[^/]+\.php$ {
        if ($uri !~ "^/(index|cron|installer)\.php$") {
            return 404;
        }
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    # 6. assets/ — только статика, исполнение PHP запрещено в любом случае.
    location ^~ /assets/ {
        location ~ \.php$ { return 404; }
    }

    # 7. Точка входа внешнего расписания (Tripwire) — доступна, но требует
    #    ключ из app/Config/Security.php (integrity.token_hash).
    #    Пример вызова из CRON:
    #    wget -q -O /dev/null "https://fw2.loc/cron.php?key=ВАШ_КЛЮЧ&action=check"
    #    Предпочтительный способ (ключ не попадает в журналы сервера):
    #    curl -H "X-Rameva-Key: ВАШ_КЛЮЧ" "https://fw2.loc/cron.php?action=check"

    # 8. Отдача статики с кэшированием (необязательно).
    location ~* \.(css|js|jpg|jpeg|png|gif|svg|webp|ico|woff2?)$ {
        expires 30d;
        access_log off;
    }
}
```

**B3.** Это текстовый конфиг-файл, не PHP — `php -l` для него не нужен. Проверка: показать итоговый файл целиком в отчёте, визуально сверить с блоком выше (построчно совпадает).

## Что не входит в этап

Единый источник правды между `Installer::nginxSnippet()` и файлом в `deploy/` (генерация одного из другого) — отдельная задача, не сейчас. `tests/security_matrix.php` — следующим брифом.

## Отчёт

Таблица A1–A5, B1–B3: PASS/FAIL/NOT RUN с выводом команд. Список изменённых файлов.
