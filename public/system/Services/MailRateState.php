<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Services
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Services;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Состояние почтовых лимитов в Guard-файле (по образцу storage/integrity.state.php).
 *
 * Ключи по IP — HMAC-SHA256 от IP с ключом установки, а не открытый IP.
     * Файл находится в storage внутри docroot; прямое выполнение закрыто Guard.
 */
class MailRateState
{
    private const FILE_PATH = '';

    /** @var array Кэш состояния в памяти */
    private static array $cache = [];

    /** @var string Ключ HMAC для хэширования IP */
    private static ?string $hmacKey = null;

    private static function statePath(): string
    {
        return STORAGEPATH . '/mail-rate.state.php';
    }

    private static function hmacKey(): string
    {
        if (self::$hmacKey !== null) {
            return self::$hmacKey;
        }
        self::$hmacKey = \System\Services\InstallationKey::getKey();
        return self::$hmacKey;
    }

    public static function ipKey(string $ip): string
    {
        return hash_hmac('sha256', $ip, self::hmacKey());
    }

    /** @return array{by_ip: array<string, list<int>>, sent: list<int>} */
    private static function load(): array
    {
        if (self::$cache !== []) {
            return self::$cache;
        }
        $path = self::statePath();
        if (!is_file($path)) {
            self::$cache = ['by_ip' => [], 'sent' => []];
            return self::$cache;
        }
        $content = @file_get_contents($path);
        $data = $content !== false ? (array)@include $path : [];
        if (!isset($data['by_ip']) || !is_array($data['by_ip'])) {
            $data['by_ip'] = [];
        }
        if (!isset($data['sent']) || !is_array($data['sent'])) {
            $data['sent'] = [];
        }
        self::$cache = ['by_ip' => $data['by_ip'], 'sent' => $data['sent']];
        return self::$cache;
    }

    private static function save(array $state): bool
    {
        $path = self::statePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.' . uniqid('tmp', true);
        $export = "<?php\n// Состояние почтовых лимитов (Tripwire Guard).\nif (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }\nreturn " . var_export($state, true) . ";\n";
        if (@file_put_contents($tmp, $export, LOCK_EX) === false) {
            return false;
        }
        if (@rename($tmp, $path)) {
            self::$cache = $state;
            return true;
        }
        @unlink($tmp);
        return false;
    }

    /**
     * Проверяет лимиты и регистрирует отправку.
     *
     * @return array{0: bool, 1: int} [разрешено, секунд до сброса (0 если нет лимита)]
     */
    public static function checkAndRecord(array $config): array
    {
        $lock = @fopen(self::statePath() . '.lock.php', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock mail rate state');
        try {
        // Кэш прошлого вызова не должен обходить обновления другого PHP-процесса.
        self::$cache = [];
        $state = self::load();
        $now = time();
        $ip = ClientIp::get();
        $ipKey = self::ipKey($ip);

        $minimum   = max(0, (int)($config['min_interval'] ?? 0));
        $ipLimit   = max(0, (int)($config['ip_limit'] ?? 0));
        $hourly    = max(0, (int)($config['hourly_limit'] ?? 0));
        $daily     = max(0, (int)($config['daily_limit'] ?? 0));

        $sent = array_values(array_filter((array)$state['sent'], static fn($t) => is_int($t) && $t > $now - 86400));
        $byIp = [];
        foreach ((array)$state['by_ip'] as $knownIpKey => $times) {
            $times = array_values(array_filter((array)$times, static fn($t) => is_int($t) && $t > $now - 86400));
            if ($times !== []) {
                $byIp[$knownIpKey] = $times;
            }
        }

        $ipTimes = (array)($byIp[$ipKey] ?? []);
        $retryAfter = 0;

        if ($minimum > 0 && $ipTimes !== [] && ($now - max($ipTimes)) < $minimum) {
            $retryAfter = max(0, $minimum - ($now - max($ipTimes)));
            return [false, $retryAfter];
        }
        $hourIpTimes = array_filter($ipTimes, static fn($t) => $t > $now - 3600);
        if ($ipLimit > 0 && count($hourIpTimes) >= $ipLimit) {
            $retryAfter = max(0, 3600 - ($now - min($hourIpTimes)));
            return [false, $retryAfter];
        }
        $hourSent = array_filter($sent, static fn($t) => $t > $now - 3600);
        if ($hourly > 0 && count($hourSent) >= $hourly) {
            $retryAfter = max(0, 3600 - ($now - min($hourSent)));
            return [false, $retryAfter];
        }
        if ($daily > 0 && count($sent) >= $daily) {
            $retryAfter = max(0, 86400 - ($now - min($sent)));
            return [false, $retryAfter];
        }

        $sent[] = $now;
        $ipTimes[] = $now;
        $byIp[$ipKey] = $ipTimes;
        if (!self::save(['by_ip' => $byIp, 'sent' => $sent])) throw new \RuntimeException('Cannot save mail rate state');

        return [true, 0];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function reset(): void
    {
        $path = self::statePath();
        if (is_file($path)) {
            @unlink($path);
        }
        self::$cache = ['by_ip' => [], 'sent' => []];
    }
}