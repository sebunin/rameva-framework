<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Helpers
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 */

declare(strict_types=1);

namespace System\Helpers;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс HelperLoader автоматически находит и подключает файлы *_helper.php
 * из директорий system/Helpers/ и app/Helpers/.
 */
class HelperLoader
{
    private static bool $loaded = false;

    /**
     * Загружает все хелперы из системной и пользовательской директорий.
     */
    public static function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }

        self::loadFromDirectory(SYSTEMPATH . '/Helpers');
        self::loadFromDirectory(APPPATH . '/Helpers');

        self::$loaded = true;
    }

    /**
     * Сканирует директорию на наличие файлов с суффиксом _helper.php и подключает их.
     */
    private static function loadFromDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'index.php') {
                continue;
            }

            if (str_ends_with($file, '_helper.php')) {
                require_once $dir . '/' . $file;
            }
        }
    }
}
