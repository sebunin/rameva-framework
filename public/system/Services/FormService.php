<?php
/**
 * RAMEVA Framework 2
 *
 * @package Rameva\Services
 */

declare(strict_types=1);

namespace System\Services;

use System\Core\Events;
use System\Services\MailRateLimitedException;

if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

/** Form CSRF, origin, timing, honeypot, validation and response handling. */
class FormService
{
    private const CSRF_SESSION_KEY = '_rameva_form_csrf';
    private const TIMES_SESSION_KEY = '_rameva_form_times';
    private const CSRF_FIELD = '_csrf';

    private array $forms;
    private array $settings;

    public function __construct(?array $forms = null)
    {
        if ($forms === null) {
            $file = APPPATH . '/Config/Forms.php';
            $forms = is_file($file) ? (require $file) : [];
        }
        $this->forms = is_array($forms['forms'] ?? null) ? $forms['forms'] : $forms;
        $this->settings = is_array($forms['settings'] ?? null) ? $forms['settings'] : [];
    }

    /** Return the server-minted hidden controls for a form. */
    public function renderHiddenFields(string $formId): string
    {
        if (!$this->validFormId($formId) || !isset($this->forms[$formId])) {
            return '';
        }
        SessionService::start();
        $token = $_SESSION[self::CSRF_SESSION_KEY] ?? '';
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_SESSION_KEY] = $token;
        }
        $_SESSION[self::TIMES_SESSION_KEY][$formId] = time();
        $honeypot = $this->honeypotField($formId);
        $id = htmlspecialchars($formId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $field = htmlspecialchars($honeypot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $value = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<input type="hidden" name="_csrf" value="' . $value . '">' .
            '<div aria-hidden="true" style="position:absolute!important;left:-10000px!important;width:1px!important;height:1px!important;overflow:hidden!important">' .
            '<label for="hp-' . $id . '">Leave this field empty</label>' .
            '<input type="text" id="hp-' . $id . '" name="' . $field . '" value="" tabindex="-1" autocomplete="off"></div>';
    }

    /**
     * Validates and sends a configured form. Returns [status, response].
     * The caller is responsible for counting the request once in the global limiter.
     * @return array{0:int,1:array{success:bool,message:string,errors:array<string,mixed>}}
     */
    public function processSubmission(string $formId, array $input): array
    {
        if (!$this->validFormId($formId) || !isset($this->forms[$formId]) || !is_array($this->forms[$formId])) {
            return [404, $this->response(false, 'Форма не найдена')];
        }
        SessionService::start();
        $form = $this->forms[$formId];
        $originFailure = $this->validateOrigin();
        if ($originFailure !== null) {
            return [403, $this->response(false, $originFailure)];
        }
        $expected = $_SESSION[self::CSRF_SESSION_KEY] ?? '';
        $provided = $input[self::CSRF_FIELD] ?? '';
        if (!is_string($expected) || $expected === '' || !is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
            return [403, $this->response(false, 'Проверка безопасности не пройдена')];
        }
        $honeypot = $this->honeypotField($formId);
        if (!empty($input[$honeypot])) {
            LogService::write('Form honeypot triggered: ' . $formId, 'warning', 'security.log');
            return [200, $this->response(true, 'Спасибо! Сообщение принято')];
        }

        $shownAt = (int)($_SESSION[self::TIMES_SESSION_KEY][$formId] ?? 0);
        $now = time();
        $maxAge = max(1, (int)($form['max_age'] ?? $this->settings['max_age'] ?? 7200));
        $minTime = max(0, (int)($form['min_fill_seconds'] ?? $this->settings['min_fill_seconds'] ?? 3));
        unset($_SESSION[self::TIMES_SESSION_KEY][$formId]);
        if ($shownAt <= 0 || ($now - $shownAt) > $maxAge || ($now - $shownAt) < $minTime) {
            return [422, $this->response(false, 'Форма устарела или заполнена слишком быстро', ['form' => 'Пожалуйста, обновите страницу и попробуйте снова'])];
        }
        if (!$this->allowFormRate($formId)) {
            return [429, $this->response(false, 'Слишком много отправок. Попробуйте позже')];
        }

        $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
        $data = [];
        $errors = [];
        foreach ($fields as $name => $rules) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $name) || !is_array($rules)) {
                continue;
            }
            $raw = $input[$name] ?? '';
            if (!is_scalar($raw)) {
                $errors[$name] = 'Некорректное значение';
                continue;
            }
            $value = trim(str_replace(["\r", "\n", "\0"], ' ', (string)$raw));
            $max = max(1, (int)($rules['max_length'] ?? 2000));
            if (strlen($value) > $max || (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $max)) {
                $errors[$name] = 'Слишком длинное значение';
                continue;
            }
            if (!empty($rules['required']) && $value === '') {
                $errors[$name] = 'Поле обязательно';
                continue;
            }
            if ($value !== '' && ($rules['type'] ?? '') === 'email' && (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
                $errors[$name] = 'Введите корректный email';
                continue;
            }
            $valueLength = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($name === 'message' && $value !== '' && $valueLength < max(0, (int)($rules['min_length'] ?? 0))) {
                $errors[$name] = 'Сообщение слишком короткое';
                continue;
            }
            $data[$name] = $value;
        }
        if ($errors !== []) {
            return [422, $this->response(false, 'Проверьте заполнение формы', $errors)];
        }

        $email = $this->renderEmail($formId, $form, $data);
        try {
            $mail = new MailService();
            $replyTo = isset($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL) ? $data['email'] : '';
            $ok = $mail->send((string)($form['to'] ?? ''), $email['subject'], $email['html'], true, $replyTo === '' ? [] : ['Reply-To' => $replyTo], $email['text']);
        } catch (MailRateLimitedException $e) {
            return [429, $this->response(false, 'Слишком много отправок. Попробуйте позже', ['retry_after' => $e->retryAfter])];
        } catch (\Throwable $e) {
            LogService::write('Form mail failure (' . $formId . '): ' . $e->getMessage(), 'error', 'mail.log');
            $ok = false;
        }
        if (!$ok) {
            return [500, $this->response(false, 'Не удалось отправить сообщение. Попробуйте позже')];
        }
        Events::trigger('form.submitted', $formId, $data);
        return [200, $this->response(true, (string)($form['success_message'] ?? 'Спасибо! Сообщение отправлено'))];
    }

    private function renderEmail(string $id, array $form, array $data): array
    {
        $values = [];
        foreach ($data as $key => $value) {
            $values[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $values['form_id'] = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = str_replace(["\r", "\n"], '', (string)($form['subject'] ?? 'Сообщение с сайта'));
        $template = (string)($form['template'] ?? 'form-submission.html');
        if (!preg_match('/^[a-zA-Z0-9_-]+\.html$/', $template)) {
            $template = 'form-submission.html';
        }
        $path = APPPATH . '/Mail/' . $template;
        if (!is_file($path)) {
            $path = SYSTEMPATH . '/Mail/' . $template;
        }
        if (is_file($path)) {
            $html = (string)file_get_contents($path);
            $html = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static fn($m) => $values[$m[1]] ?? '', $html) ?? $html;
        } else {
            $rows = '';
            foreach ($values as $key => $value) {
                $rows .= '<p><strong>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . ':</strong> ' . nl2br($value, false) . '</p>';
            }
            $html = '<!doctype html><html><body>' . $rows . '</body></html>';
        }
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    private function validateOrigin(): ?string
    {
        $source = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($source === '') $source = $_SERVER['HTTP_REFERER'] ?? '';
        if ($source === '') return null;
        $sourceHost = strtolower((string)parse_url($source, PHP_URL_HOST));
        $expectedHost = strtolower((string)parse_url('//' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        $sourcePort = parse_url($source, PHP_URL_PORT);
        $expectedPort = parse_url('//' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_PORT);
        $sourceScheme = strtolower((string)parse_url($source, PHP_URL_SCHEME));
        $requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $sameOrigin = $sourceHost !== '' && $expectedHost !== '' && hash_equals($expectedHost, $sourceHost)
            && ($sourcePort === null || $expectedPort === null || $sourcePort === $expectedPort)
            && ($sourceScheme === '' || $sourceScheme === $requestScheme);
        return $sameOrigin ? null : 'Источник запроса не разрешён';
    }

    private function allowFormRate(string $formId): bool
    {
        $config = $this->forms[$formId];
        $ip = ClientIp::get();
        $limit = max(1, (int)($config['limit'] ?? $this->settings['limit'] ?? 5));
        $window = max(1, (int)($config['window_seconds'] ?? $this->settings['window_seconds'] ?? 3600));
        $dir = STORAGEPATH . '/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        $file = $dir . '/form-rate-' . hash('sha256', $formId . '|' . $ip) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) return false;
        try {
            if (!flock($handle, LOCK_EX)) return false;
            $raw = stream_get_contents($handle);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            $now = time();
            $times = array_values(array_filter(is_array($state) ? $state : [], static fn($t) => is_int($t) && $t > $now - $window));
            if (count($times) >= $limit) return false;
            $times[] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($times));
            fflush($handle);
            flock($handle, LOCK_UN);
            return true;
        } finally {
            fclose($handle);
        }
    }

    private function honeypotField(string $formId): string
    {
        try {
            $key = \System\Services\InstallationKey::getKey();
        } catch (\Throwable $e) {
            $key = (string)($_SERVER['SERVER_NAME'] ?? 'local');
        }
        return '_f_' . substr(hash_hmac('sha256', $formId, $key), 0, 16);
    }

    private function validFormId(string $id): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $id) === 1;
    }

    private function response(bool $success, string $message, array $errors = []): array
    {
        return ['success' => $success, 'message' => $message, 'errors' => (object)$errors];
    }
}