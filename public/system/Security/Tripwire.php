<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Security
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Security;

use System\Core\Installer;
use System\Services\Cache;
use System\Services\LogService;
use System\Services\MailService;
use System\Services\SessionService;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс Tripwire контролирует целостность ядра фреймворка (каталог system/).
 *
 * Принципы:
 * - только обнаружение и уведомление; автоматического восстановления нет:
 *   восстановление из встроенной копии — сам по себе риск цепочки поставок,
 *   если эту копию можно подменить;
 * - область контроля — только system/: каталоги app/, pages/, assets/ меняет
 *   пользователь в обычной работе, их отслеживание давало бы ложные срабатывания;
 * - хэши не считаются на каждый запрос: проверка запускается точкой входа
 *   public/cron.php (расписание задаёт админ) либо фоновым триггером после
 *   отправки ответа посетителю и не чаще, чем заданный интервал;
 * - манифест хранится не в открытом виде, а как PHP-файл под тем же Guard,
 *   что и конфигурация приложения.
 *
 * Результаты проверки: storage/logs/integrity.log (отдельно от лога приложения)
 * и письмо администратору через MailService.
 */
class Tripwire
{
    /** @var string Файл эталонного манифеста (относительно storage/) */
    private const MANIFEST_FILE = '/integrity.manifest.php';

    /** @var string Файл состояния последней проверки (относительно storage/) */
    private const STATE_FILE = '/integrity.state.php';

    /** @var string Файл блокировки, чтобы одновременно шла только одна фоновая проверка */
    private const LOCK_FILE = '/integrity.lock';

    /** @var string Отдельный журнал мониторинга целостности */
    private const LOG_CHANNEL = 'integrity.log';

    /** @var string Алгоритм хэширования */
    private const HASH_ALGO = 'sha256';

    /**
     * Пересобирает эталонный манифест system/.
     *
     * @return array{status: string, files: int}
     */
    public static function build(): array
    {
        $files = self::scan();

        $manifest = [
            'generated_at'     => time(),
            'generated_at_iso' => date('c'),
            'algo'             => self::HASH_ALGO,
            'scope'            => 'system/',
            'files'            => $files,
            'perms'            => self::scanPerms(),
        ];

        $content = "<?php\n"
            . "// Эталонный манифест целостности system/ (Tripwire).\n"
            . "// Файл создаётся автоматически: пересобирается cron.php?action=build.\n"
            . "if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }\n"
            . "return " . var_export($manifest, true) . ";\n";

        $written = (bool)@file_put_contents(self::storagePath(self::MANIFEST_FILE), $content, LOCK_EX);

        return [
            'status' => $written ? 'built' : 'error',
            'files'  => count($files),
        ];
    }

    /**
     * Сверяет текущее содержимое system/ с эталонным манифестом.
     *
     * @return array{status: string, changed: array<int, string>, removed: array<int, string>, added: array<int, string>, checked: int, manifest_time: int}
     */
    public static function check(): array
    {
        $manifest = self::readManifest();

        if ($manifest === null) {
            // Манифеста нет или он повреждён. Это не повод молча «перебазировать»
            // эталон: удаление манифеста — сам по себе признак вмешательства,
            // поэтому состояние фиксируется как расхождение и уходит уведомление.
            $result = self::result('no_manifest', [], [], [], 0, 0);
            self::report($result);
            self::writeState($result);

            return $result;
        }

        $manifestFiles = (array)$manifest['files'];
        $current       = self::scan();

        $changed = [];
        $removed = [];
        $added   = [];

        foreach ($manifestFiles as $relativePath => $expectedHash) {
            if (!array_key_exists($relativePath, $current)) {
                $removed[] = (string)$relativePath;
                continue;
            }

            if (!hash_equals((string)$expectedHash, (string)$current[$relativePath])) {
                $changed[] = (string)$relativePath;
            }
        }

        foreach (array_keys($current) as $relativePath) {
            if (!array_key_exists($relativePath, $manifestFiles)) {
                $added[] = (string)$relativePath;
            }
        }

        sort($changed);
        sort($removed);
        sort($added);

        // Права доступа сверяются отдельно от содержимого: «тот же файл с
        // другими правами» — самостоятельный тип события, а не изменение
        // содержимого (манифесты старых версий поля perms не содержат).
        $permsChanged = [];
        $manifestPerms = $manifest['perms'] ?? null;

        if (is_array($manifestPerms)) {
            $currentPerms = self::scanPerms();

            foreach ($manifestPerms as $relativePath => $expectedMode) {
                if (!array_key_exists($relativePath, $currentPerms)) {
                    continue; // удалённые файлы уже учтены выше
                }

                if ((int)$expectedMode !== (int)$currentPerms[$relativePath]) {
                    $permsChanged[] = sprintf(
                        '%s (%04o -> %04o)',
                        (string)$relativePath,
                        (int)$expectedMode,
                        (int)$currentPerms[$relativePath]
                    );
                }
            }

            sort($permsChanged);
        }

        $status = (empty($changed) && empty($removed) && empty($added) && empty($permsChanged))
            ? 'ok'
            : 'mismatch';

        $result = self::result(
            $status,
            $changed,
            $removed,
            $added,
            count($current),
            (int)($manifest['generated_at'] ?? 0),
            $permsChanged
        );

        if ($status === 'mismatch') {
            self::report($result);
        }

        self::writeState($result);

        return $result;
    }

    /**
     * Хэширует все файлы каталога system/.
     *
     * @return array<string, string> Относительный путь => хэш ('' — файл не читается)
     */
    private static function scan(): array
    {
        $hashes = [];

        if (!is_dir(SYSTEMPATH)) {
            return $hashes;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(SYSTEMPATH, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $hash = @hash_file(self::HASH_ALGO, $path);

            $hashes[self::relativePath($path)] = $hash === false ? '' : $hash;
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * Режимы доступа файлов system/ (права — отдельный тип расхождения).
     *
     * @return array<string, int> Относительный путь => маска прав (например, 0644)
     */
    private static function scanPerms(): array
    {
        $perms = [];

        if (!is_dir(SYSTEMPATH)) {
            return $perms;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(SYSTEMPATH, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $mode = @fileperms($path);

            $perms[self::relativePath($path)] = $mode === false ? 0 : ($mode & 0777);
        }

        ksort($perms);

        return $perms;
    }

    /**
     * Путь файла относительно каталога system/ (всегда со слэшами «/»).
     */
    private static function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', SYSTEMPATH), '/') . '/';
        $path = str_replace('\\', '/', $path);

        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }

    /**
     * Дешёвая проверка посторонних PHP-файлов — без хеширования.
     *
     * Области и разрешённые имена:
     * - корень сайта: index.php, cron.php, installer.php;
     * - assets/ целиком: .php не должно быть вообще;
     * - storage/uploads/ целиком: .php не должно быть вообще.
     *
     * Вызывается чаще полной сверки system/, так как не требует пересчёта
     * хэшей. Любая находка — событие критичности выше обычного расхождения:
     * пишется в integrity.log и уходит письмом независимо от настроек
     * уведомлений Tripwire (notify_admin не учитывается).
     */
    public static function checkStrayPhpFiles(): array
    {
        $found = [];

        $rootAllowed = ['index.php', 'cron.php', 'installer.php'];

        foreach ((array)@scandir(ROOTPATH) as $entry) {
            if (!is_string($entry) || $entry === '' || $entry[0] === '.') {
                continue;
            }

            $path = rtrim(ROOTPATH, '/\\') . '/' . $entry;

            if (!is_file($path) || strtolower((string)pathinfo($entry, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            if (!in_array(strtolower($entry), $rootAllowed, true)) {
                $found[] = $entry;
            }
        }

        foreach (['/assets', '/storage/uploads'] as $dir) {
            $base = rtrim(ROOTPATH, '/\\') . $dir . '/';

            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }

                if (strtolower((string)$file->getExtension()) !== 'php') {
                    continue;
                }


                // index.php — guardia-файл, легитимен (закрывает листинг).
                if (strtolower((string)$file->getBasename()) === 'index.php') {
                    continue;
                }

                $found[] = ltrim($dir, '/') . '/' . str_replace('\\', '/', substr((string)$file->getPathname(), strlen($base)));
            }
        }

        sort($found);

        $result = [
            'status'     => empty($found) ? 'ok' : 'stray_php',
            'found'      => $found,
            'checked_at' => time(),
        ];

        // Отметка пишется всегда, а не только при находке: иначе при чистом
        // каталоге last_stray_check не обновлялся бы и проверка выполнялась
        // на каждом запросе.
        if (!empty($found)) {
            self::reportStray($found);
        }

        self::writeStrayState($result);

        return $result;
    }

    /**
     * Запускает дешёвую проверку посторонних PHP-файлов, если истёк её
     * собственный интервал (по умолчанию — час). Дешевле полной сверки,
     * поэтому выполняется чаще.
     */
    public static function runStrayCheckIfDue(): void
    {
        $config = self::config();

        if (empty($config['enabled']) || empty($config['stray_check'])) {
            return;
        }

        $interval = (int)($config['stray_check_interval'] ?? 3600);
        $state    = self::readState();
        $last     = (int)($state['last_stray_check'] ?? 0);

        if ($interval > 0 && $last > 0 && (time() - $last) < $interval) {
            return;
        }

        self::checkStrayPhpFiles();
    }

    /**
     * Публичная фиксация одного постороннего файла.
     *
     * Используется ядром, когда запрос пришёл к постороннему .php
     * непосредственно (в обход периодической проверки): событие должно быть
     * зафиксировано немедленно, а не на следующем цикле stray-проверки.
     */
    public static function reportStrayFile(string $relativePath): void
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($relativePath === '') {
            return;
        }

        self::reportStray([$relativePath]);

        $result = [
            'status'     => 'stray_php',
            'found'      => [$relativePath],
            'checked_at' => time(),
        ];

        self::writeStrayState($result);
    }

    /**
     * Фиксирует находку посторонних PHP-файлов: журнал и письмо без учёта
     * общих настроек уведомлений.
     *
     * @param array<int, string> $found
     */
    private static function reportStray(array $found): void
    {
        $summary = 'Integrity: КРИТИЧНО — посторонние PHP-файлы: ' . self::describeList($found);

        LogService::write($summary, 'critical', self::LOG_CHANNEL);

        $mailConfigFile = APPPATH . '/Config/Mail.php';
        $mailConfig = file_exists($mailConfigFile) ? (require $mailConfigFile) : [];
        $to = (string)($mailConfig['admin_email'] ?? '');

        if ($to === '') {
            return;
        }

        $body = '<p>Обнаружены PHP-файлы там, где их не должно быть (корень сайта вне '
            . 'разрешённого списка, assets/, storage/uploads/).</p>'
            . '<p>' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Сайт: ' . htmlspecialchars(self::siteUrl(), ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Время: ' . date('Y-m-d H:i:s') . '</p>'
            . '<p>Автоматическое удаление не выполняется — проверьте файлы вручную.</p>';

        $mail = new MailService($mailConfig);
        $mail->send($to, 'RAMEVA: КРИТИЧНО — посторонний PHP-файл', $body, true);
    }

    /**
     * Отметка о выполнении дешёвой проверки (в том же файле состояния).
     *
     * @param array $result
     */
    private static function writeStrayState(array $result): void
    {
        $state = self::readState();

        $state['last_stray_check']     = time();
        $state['last_stray_check_iso'] = date('c');
        $state['last_stray_found']     = (array)($result['found'] ?? []);

        self::saveState($state);
    }

    /**
     * Фоновая проверка по расписанию.
     *
     * Вызывается из system/Boot.php после отправки ответа посетителю, поэтому
     * выполняется в фоне: сначала завершается соединение с клиентом, и только
     * потом считается хэш каталога system/.
     */
    public static function runBackgroundCheckIfDue(): void
    {
        $config = self::config();

        if (empty($config['enabled']) || empty($config['background_check'])) {
            return;
        }

        $interval = (int)($config['check_interval'] ?? 86400);

        if ($interval <= 0 || !self::isDue($interval)) {
            return;
        }

        $lock = self::acquireLock();

        if ($lock === null) {
            // Проверку уже выполняет другой процесс
            return;
        }

        // Ответ посетителю сформирован (kernel->launch() уже отработал),
        // поэтому завершаем соединение и продолжаем в фоне
        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }

        try {
            self::check();
        } finally {
            self::releaseLock($lock);
        }

        self::reportPostureIfDegraded();
    }

    /**
     * Постоянная видимость зафиксированного режима degraded.
     *
     * Админ-панели в фреймворке нет, поэтому «видно постоянно» реализовано как
     * периодическая запись в security.log: состояние degraded напоминает о себе
     * каждый интервал полной проверки, а не только на экране установки.
     * Запись не дублируется чаще одного раза в сутки.
     */
    private static function reportPostureIfDegraded(): void
    {
        if (!class_exists(Installer::class)) {
            return;
        }

        if (Installer::getSecurityPosture() !== 'degraded') {
            return;
        }

        $state = self::readState();
        $last  = (int)($state['last_posture_notice'] ?? 0);

        if ($last > 0 && (time() - $last) < 86400) {
            return;
        }

        $reason = Installer::getDegradedReason() ?? 'причина не указана';

        LogService::write(
            'installer: режим безопасности degraded — защита внутренних каталогов не подтверждена (' . $reason . ')',
            'warning',
            'security.log'
        );

        $state['last_posture_notice']     = time();
        $state['last_posture_notice_iso'] = date('c');

        self::saveState($state);
    }

    /**
     * Пора ли выполнять фоновую проверку.
     */
    private static function isDue(int $interval): bool
    {
        $state = self::readState();
        $lastCheck = (int)($state['last_check'] ?? 0);

        return $lastCheck === 0 || (time() - $lastCheck) >= $interval;
    }

    /**
     * Блокировка, чтобы фоновая проверка не выполнялась параллельно.
     *
     * @return resource|null
     */
    private static function acquireLock()
    {
        $handle = @fopen(self::storagePath(self::LOCK_FILE), 'c');

        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private static function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    /**
     * Фиксирует расхождение: отдельный журнал integrity.log и письмо администратору.
     *
     * @param array $result Результат проверки
     */
    private static function report(array $result): void
    {
        $summary = self::summary($result);

        // integrity.log пишется первым: LogService ограничивает частоту записей
        // с одного IP в пределах секунды, поэтому событие мониторинга не должно
        // оказаться в очереди после чужой записи (например, mail.log из MailService).
        LogService::write($summary, 'error', self::LOG_CHANNEL);

        self::notifyAdmin($summary);
    }

    /**
     * Уведомление администратора через уже готовый MailService.
     */
    private static function notifyAdmin(string $summary): void
    {
        $config = self::config();

        if (empty($config['notify_admin'])) {
            return;
        }

        $mailConfigFile = APPPATH . '/Config/Mail.php';
        $mailConfig = file_exists($mailConfigFile) ? (require $mailConfigFile) : [];
        $to = (string)($mailConfig['admin_email'] ?? '');

        if ($to === '') {
            return;
        }

        $body = '<p>Обнаружено расхождение содержимого каталога <b>system/</b> с эталонным манифестом.</p>'
            . '<p>' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>Сайт: ' . htmlspecialchars(self::siteUrl(), ENT_QUOTES, 'UTF-8') . '<br>'
            . 'Время проверки: ' . date('Y-m-d H:i:s') . '</p>'
            . '<p>Автоматическое восстановление файлов не выполняется — проверьте изменения вручную.</p>';

        $mail = new MailService($mailConfig);
        $mail->send($to, 'RAMEVA: нарушение целостности system/', $body, true);
    }

    /**
     * Текстовая сводка результата (для журнала, письма и ответа cron.php).
     *
     * @param array $result Результат проверки
     */
    private static function summary(array $result): string
    {
        $status = (string)($result['status'] ?? 'unknown');

        if ($status === 'no_manifest') {
            return 'Integrity: эталонный манифест system/ отсутствует — требуется пересборка (cron.php?action=build)';
        }

        if ($status === 'ok') {
            return sprintf('Integrity: system/ без изменений, проверено файлов: %d', (int)($result['checked'] ?? 0));
        }

        if ($status === 'built') {
            return sprintf('Integrity: манифест system/ пересобран, файлов: %d', (int)($result['checked'] ?? 0));
        }

        return sprintf(
            'Integrity: расхождение system/ — изменено: %s, удалено: %s, добавлено: %s, права: %s',
            self::describeList((array)($result['changed'] ?? [])),
            self::describeList((array)($result['removed'] ?? [])),
            self::describeList((array)($result['added'] ?? [])),
            self::describeList((array)($result['perms_changed'] ?? []))
        );
    }

    /**
     * Список файлов с количеством и коротким перечислением.
     *
     * @param array<int, string> $items
     */
    private static function describeList(array $items): string
    {
        if (empty($items)) {
            return '0';
        }

        $preview = implode(', ', array_slice($items, 0, 10));

        if (count($items) > 10) {
            $preview .= ', ...';
        }

        return count($items) . ' [' . $preview . ']';
    }

    /**
     * Собирает структуру результата проверки.
     *
     * @param array<int, string> $changed
     * @param array<int, string> $removed
     * @param array<int, string> $added
     */
    private static function result(
        string $status,
        array $changed,
        array $removed,
        array $added,
        int $checked,
        int $manifestTime,
        array $permsChanged = []
    ): array {
        return [
            'status'        => $status,
            'changed'       => $changed,
            'removed'       => $removed,
            'added'         => $added,
            'perms_changed' => $permsChanged,
            'checked'       => $checked,
            'manifest_time' => $manifestTime,
        ];
    }

    /**
     * Обработка вызова точки входа public/cron.php.
     *
     * @param array<string, string> $params Параметры вызова: key, action (check|build)
     * @param bool $cli true, если запуск из командной строки
     * @return int Код выхода: 0 — успех, 1 — отказ в доступе, 2 — расхождение, 3 — модуль выключен
     */
    public static function handleCronRequest(array $params, bool $cli = false): int
    {
        // Защита точки входа от перебора ключа
        $limiter = new RateLimiter([
            'enabled'        => true,
            'max_requests'   => 30,
            'decay_seconds'  => 60,
            'block_duration' => 900,
        ]);

        if ($limiter->isLimitExceeded()) {
            return self::deny($cli);
        }

        $config = self::config();

        if (empty($config['enabled'])) {
            return self::deny($cli);
        }

        $storedHash = (string)($config['token_hash'] ?? '');
        $given = (string)($params['key'] ?? '');

        // Пустой ключ в конфигурации означает, что точка входа закрыта целиком
        if ($storedHash === '' || $given === '' || !hash_equals($storedHash, hash('sha256', $given))) {
            LogService::write('Integrity: обращение к cron.php без корректного ключа', 'warning', 'security.log');

            return self::deny($cli);
        }

        $action = (string)($params['action'] ?? 'check');

        if ($action === 'diagnose') {
            // Повторная диагностика окружения после установки (пункт 9 брифа).
            // Ключ тот же, что и у остальных действий: отдельный секрет не нужен.
            $previous    = Installer::getState();
            $diagnostics = Installer::reDiagnose($previous);

            if (($diagnostics['status'] ?? '') === 'rate_limited') {
                return self::respond($cli, 'diagnose: too frequent', 0);
            }

            return self::respond($cli, sprintf(
                'diagnose: overall=%s installed=%s',
                (string)($diagnostics['overall'] ?? 'unknown'),
                Installer::isInstalled() ? 'yes' : 'no'
            ), 0);
        }

        if ($action === 'stray') {
            $stray = self::checkStrayPhpFiles();

            return self::respond($cli, sprintf(
                'integrity: stray check %s, found=%d',
                $stray['status'],
                count($stray['found'])
            ), $stray['status'] === 'ok' ? 0 : 2);
        }

        if ($action === 'build') {
            $buildResult = self::build();

            self::writeState([
                'status'  => 'built',
                'checked' => (int)$buildResult['files'],
            ]);

            return self::respond($cli, sprintf('integrity: built files=%d', $buildResult['files']), 0);
        }

        if ($action === 'gc') {
            $gcCache = new Cache();
            $deletedCache = $gcCache->gc();

            $deletedSessions = 0;
            $sessionDir = STORAGEPATH . '/sessions';
            if (is_dir($sessionDir)) {
                $appConfigFile = APPPATH . '/Config/App.php';
                $appConfig = file_exists($appConfigFile) ? (require $appConfigFile) : [];
                $maxLife = (int)($appConfig['session_gc_maxlifetime'] ?? 1440);
                $cutoff = time() - $maxLife;
                foreach (glob($sessionDir . '/*') as $sessFile) {
                    if (is_file($sessFile) && filemtime($sessFile) < $cutoff) {
                        if (@unlink($sessFile)) {
                            $deletedSessions++;
                        }
                    }
                }
            }

            $deletedLogs = 0;
            $logDir = STORAGEPATH . '/logs';
            if (is_dir($logDir)) {
                $cutoff30 = time() - (30 * 86400);
                foreach (glob($logDir . '/*.bak') as $bakFile) {
                    if (is_file($bakFile) && filemtime($bakFile) < $cutoff30) {
                        if (@unlink($bakFile)) {
                            $deletedLogs++;
                        }
                    }
                }
            }

            $total = $deletedCache + $deletedSessions + $deletedLogs;

            return self::respond($cli, sprintf(
                'gc: cache=%d sessions=%d logs=%d total=%d',
                $deletedCache, $deletedSessions, $deletedLogs, $total
            ), 0);
        }

        $result = self::check();

        if ($result['status'] === 'ok') {
            return self::respond($cli, sprintf('integrity: ok files=%d', $result['checked']), 0);
        }

        if ($result['status'] === 'no_manifest') {
            return self::respond($cli, 'integrity: manifest missing', 2);
        }

        return self::respond($cli, sprintf(
            'integrity: mismatch changed=%d removed=%d added=%d',
            count($result['changed']),
            count($result['removed']),
            count($result['added'])
        ), 2);
    }

    /**
     * Отказ в доступе: обычный 403 без каких-либо опознавательных признаков.
     */
    private static function deny(bool $cli): int
    {
        if (!$cli) {
            http_response_code(403);
        }

        return 1;
    }

    /**
     * Ответ точки входа: текстовая строка без HTML и упоминаний фреймворка.
     */
    private static function respond(bool $cli, string $message, int $exitCode): int
    {
        if (!$cli) {
            header('Content-Type: text/plain; charset=UTF-8');
            echo $message;
        } else {
            echo $message . PHP_EOL;
        }

        return $exitCode;
    }

    /**
     * Состояние последней проверки (по нему считается интервал фоновых запусков).
     *
     * Поля сливаются с уже сохранёнными: в одном файле лежат отметки разных
     * проверок (полная сверка, stray-проверка, напоминание о degraded), и
     * перезапись «с нуля» стирала бы чужие отметки.
     *
     * @param array $result Усечённый результат проверки
     */
    private static function writeState(array $result): void
    {
        $state = self::readState();

        $state['last_check']     = time();
        $state['last_check_iso'] = date('c');
        $state['last_status']    = (string)($result['status'] ?? 'unknown');
        $state['last_summary']   = self::summary($result);

        self::saveState($state);
    }

    /**
     * Записывает файл состояния.
     *
     * @param array<string, mixed> $state
     */
    private static function saveState(array $state): void
    {
        $content = "<?php\n"
            . "// Состояние последней проверки целостности system/ (Tripwire).\n"
            . "if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }\n"
            . "return " . var_export($state, true) . ";\n";

        @file_put_contents(self::storagePath(self::STATE_FILE), $content, LOCK_EX);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readState(): array
    {
        $file = self::storagePath(self::STATE_FILE);

        if (!file_exists($file)) {
            return [];
        }

        $state = require $file;

        return is_array($state) ? $state : [];
    }

    /**
     * @return array<string, mixed>|null null — манифеста нет или он повреждён
     */
    private static function readManifest(): ?array
    {
        $file = self::storagePath(self::MANIFEST_FILE);

        if (!file_exists($file)) {
            return null;
        }

        $manifest = require $file;

        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            return null;
        }

        return $manifest;
    }

    /**
     * Секция integrity из конфигурации безопасности.
     *
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        $configFile = APPPATH . '/Config/Security.php';
        $config = file_exists($configFile) ? (require $configFile) : [];

        return is_array($config['integrity'] ?? null) ? $config['integrity'] : [];
    }

    /**
     * Абсолютный путь к служебному файлу в storage/.
     */
    private static function storagePath(string $relative): string
    {
        return rtrim(STORAGEPATH, '/\\') . $relative;
    }

    /**
     * Адрес сайта для письма администратору.
     */
    private static function siteUrl(): string
    {
        $configFile = APPPATH . '/Config/App.php';
        $config = file_exists($configFile) ? (require $configFile) : [];

        return (string)($config['siteurl'] ?? ($_SERVER['HTTP_HOST'] ?? 'unknown'));
    }
}
