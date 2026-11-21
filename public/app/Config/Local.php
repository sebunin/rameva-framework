<?php
/** Локальная тестовая доставка в Mailpit OSPanel; не включать в дистрибутив. */
if (!defined('RAMEVA_CORE')) { http_response_code(403); exit; }
return [
    'mailer' => 'smtp',
    'smtp' => ['host' => '127.0.0.1', 'port' => 1025, 'user' => '', 'pass' => '', 'encryption' => null],
];