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

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/**
 * Класс AntiBotValidator выполняет серверную проверку данных фингерпринтинга,
 * собранных скриптом antibot-challenge.js (Canvas, WebGL, AudioContext, тайминги).
 */
class AntiBotValidator
{
    /** @var array Настройки валидатора */
    private array $config;

    /**
     * Конструктор валидатора.
     *
     * @param array $config Параметры валидации
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'minTrustScore'  => 0.3,
            'maxElapsedTime' => 30000, // 30 секунд
            'minElapsedTime' => 100,   // 100 мс (быстрее — признак эмулятора/скрипта)
        ], $config);
    }

    /**
     * Валидирует переданный пакет данных проверки.
     *
     * @param array $payload Данные, отправленные JS-клиентом
     * @return bool True, если проверка пройдена
     */
    public function validate(array $payload): bool
    {
        if (empty($payload) || !isset($payload['elapsed'], $payload['fp'])) {
            return false;
        }

        $elapsed = (int)$payload['elapsed'];
        if ($elapsed < $this->config['minElapsedTime'] || $elapsed > $this->config['maxElapsedTime']) {
            return false;
        }

        // Проверка наличия ключевых компонентов отпечатка
        $fp = $payload['fp'];
        if (!is_array($fp)) {
            return false;
        }

        // Проверка наличия признаков реального браузера (Canvas / WebGL / Audio)
        $hasCanvas = !empty($fp['canvas']);
        $hasWebGL  = !empty($fp['webgl']);
        $hasAudio  = !empty($fp['audio']);

        $score = 0.0;
        if ($hasCanvas) $score += 0.35;
        if ($hasWebGL)  $score += 0.35;
        if ($hasAudio)  $score += 0.30;

        return $score >= (float)$this->config['minTrustScore'];
    }
}
