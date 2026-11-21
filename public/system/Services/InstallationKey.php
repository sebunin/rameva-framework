<?php
/** RAMEVA Framework 2 — ключ установки (Guard-файл). */

declare(strict_types=1);

namespace System\Services;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Единая точка доступа к ключу установки.
 *
 * Ключ хранится в storage/installer.key как PHP-файл, возвращающий строку.
 * Если файла нет, создаёт его (random_bytes(32), атомарная запись под flock).
 * Установщик использует уже созданный ключ и не перегенерирует его.
 * Если ключ получить невозможно — кидает RuntimeException (не Fatal).
 */
final class InstallationKey
{
    private const FILE_PATH = 'storage/installer.key';

    /** @var string|null Кэшированное значение ключа */
    private static ?string $key = null;

    /**
     * Возвращает ключ установки.
     *
     * @return string Ключ (64 шестнадцатеричных символа)
     * @throws \RuntimeException Если каталог storage недоступен для записи и ключа нет
     */
    public static function getKey(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $path = self::resolvePath();
        $key = self::readKeyFile($path);

        if ($key === '') {
            $key = self::createKeyFile($path);
        }

        self::$key = $key;
        return $key;
    }

    /**
     * Абсолютный путь к файлу ключа.
     */
    private static function resolvePath(): string
    {
        $root = rtrim(str_replace('\\', '/', ROOTPATH), '/');
        return $root . '/' . self::FILE_PATH;
    }

    /**
     * Читает ключ из PHP-файла под Guard.
     */
    private static function readKeyFile(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return '';
        }

        // Файл должен быть PHP-выражением: return '...';
        $value = @include $path;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        // Фолбэк: если файл не PHP, пробуем взять содержимое как есть (для совместимости)
        return trim($content) === '' ? '' : trim($content);
    }

    /**
     * Создаёт файл ключа атомарно под flock.
     */
    private static function createKeyFile(string $path): string
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create storage directory for installation key');
        }

        $token = bin2hex(random_bytes(32));
        $content = "<?php\n"
            . "// Ключ установки Rameva (создан автоматически).\n"
            . "if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }\n"
            . "return '" . $token . "';\n";

        $tmp = $path . '.' . uniqid('tmp', true);
        $written = @file_put_contents($tmp, $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException('Cannot write installation key to temporary file');
        }

        $renamed = @rename($tmp, $path);
        if (!$renamed) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot rename installation key file');
        }

        @chmod($path, 0600);
        return $token;
    }
}
