<?php
// Б3. PHP Guard: запрет прямого доступа к конфигурации почты через веб.
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

return ['trusted_proxies' => ['10.0.0.1', '10.0.0.2']];
