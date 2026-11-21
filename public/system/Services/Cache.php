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
 * Класс Cache реализует надёжное файловое кэширование с поддержкой TTL,
 * сериализации и автоматической очистки.
 */
class Cache
{
    /** @var string Директория для хранения файлов кэша */
    private string $cacheDir;

    /** @var int Время жизни кэша по умолчанию в секундах */
    private int $defaultTtl;

    /** @var array In-memory кэш для предотвращения повторного чтения с диска в рамках одного запроса */
    private static array $memoryCache = [];

    /**
     * Конструктор кэша.
     *
     * @param string|null $cacheDir Путь к директории хранения кэша
     * @param int $defaultTtl Время жизни по умолчанию (в секундах)
     */
    public function __construct(?string $cacheDir = null, int $defaultTtl = 3600)
    {
        $this->cacheDir = $cacheDir ?? (STORAGEPATH . '/cache');
        $this->defaultTtl = $defaultTtl;
        $this->ensureCacheDirectory();
    }

    /**
     * Получает значение из кэша.
     *
     * @param string $key Ключ кэша
     * @param mixed $default Значение по умолчанию, если ключ не найден или устарел
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $sanitizedKey = $this->sanitizeKey($key);

        if (array_key_exists($sanitizedKey, self::$memoryCache)) {
            return self::$memoryCache[$sanitizedKey];
        }

        $filePath = $this->getFilePath($sanitizedKey);
        if (!file_exists($filePath)) {
            return $default;
        }

        $data = @include $filePath;
        if ($data === false) {
            return $default;
        }
        if ($data === false || !is_array($data) || !isset($data['expires'], $data['value'])) {
            @unlink($filePath);
            return $default;
        }

        if ($data['expires'] !== 0 && time() > $data['expires']) {
            @unlink($filePath);
            return $default;
        }

        self::$memoryCache[$sanitizedKey] = $data['value'];
        return $data['value'];
    }

    /**
     * Сохраняет значение в кэш.
     *
     * @param string $key Ключ кэша
     * @param mixed $value Значение для сохранения
     * @param int|null $ttl Время жизни в секундах (null = по умолчанию, 0 = бессрочно)
     * @return bool
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $sanitizedKey = $this->sanitizeKey($key);
        $ttl = $ttl ?? $this->defaultTtl;
        $expires = ($ttl === 0) ? 0 : (time() + $ttl);

        $payload = "<?php\n// Generated cache state.\nif (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }\nreturn " . var_export([
            'expires' => $expires,
            'value'   => $value,
        ], true) . ";\n";

        $filePath = $this->getFilePath($sanitizedKey);
        $tempPath = $filePath . '.' . uniqid('tmp', true);

        if (@file_put_contents($tempPath, $payload, LOCK_EX) !== false) {
            if (@rename($tempPath, $filePath)) {
                self::$memoryCache[$sanitizedKey] = $value;
                return true;
            }
            @unlink($tempPath);
        }

        return false;
    }

    /**
     * Удаляет элемент из кэша.
     *
     * @param string $key Ключ кэша
     * @return bool
     */
    public function delete(string $key): bool
    {
        $sanitizedKey = $this->sanitizeKey($key);
        unset(self::$memoryCache[$sanitizedKey]);

        $filePath = $this->getFilePath($sanitizedKey);
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }

        return true;
    }

    /** @var array Не трогать эти файлы при сборке мусора */
    private static array $protectedFiles = ['index.php', 'routes.php', 'assets.php', 'routes.signature.php'];

    /**
     * Сбор мусора: удаляет истёкшие *.cache.php и все файлы *.cache без .php (старый формат).
     * Не трогает защищённые служебные файлы.
     *
     * @return int Число удалённых файлов
     */
    public function gc(): int
    {
        $deleted = 0;
        $files = glob($this->cacheDir . '/*');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $basename = basename($file);
            if (in_array($basename, self::$protectedFiles, true)) {
                continue;
            }

            $shouldDelete = false;

            // Истёкшие *.cache.php
            if (str_ends_with($basename, '.cache.php')) {
                $data = @include $file;
                if (is_array($data) && isset($data['expires']) && $data['expires'] !== 0 && time() > (int)$data['expires']) {
                    $shouldDelete = true;
                }
            }
            // Старый формат *.cache (без .php)
            elseif (str_ends_with($basename, '.cache') && !str_ends_with($basename, '.cache.php')) {
                $shouldDelete = true;
            }

            if ($shouldDelete && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Очищает весь кэш в директории.
     *
     * @return bool
     */
    public function clear(): bool
    {
        self::$memoryCache = [];
        $files = glob($this->cacheDir . '/*.cache.php');
        if ($files === false) {
            return false;
        }

        $allOk = true;
        foreach ($files as $file) {
            if (is_file($file)) {
                $allOk = @unlink($file) && $allOk;
            }
        }

        return $allOk;
    }

    /**
     * Проверяет существование и создаёт директорию кэша при необходимости.
     */
    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Формирует безопасное имя ключа.
     */
    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $key);
    }

    /**
     * Возвращает полный путь к файлу кэша.
     */
    private function getFilePath(string $sanitizedKey): string
    {
        return $this->cacheDir . '/' . md5($sanitizedKey) . '.cache.php';
    }
}
