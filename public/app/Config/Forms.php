<?php
declare(strict_types=1);

if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

return [
    'settings' => [
        'min_fill_seconds' => 3,
        'max_age' => 7200,
        'limit' => 5,
        'window_seconds' => 3600,
    ],
    'forms' => [
        'contact' => [
            'to' => 'admin@fw2.loc',
            'subject' => 'Новое сообщение с сайта',
            'template' => 'form-submission.html',
            'success_message' => 'Спасибо! Ваше сообщение отправлено.',
            'limit' => 5,
            'window_seconds' => 3600,
            'fields' => [
                'name' => ['type' => 'text', 'required' => true, 'max_length' => 120],
                'email' => ['type' => 'email', 'required' => true, 'max_length' => 254],
                'phone' => ['type' => 'text', 'required' => false, 'max_length' => 32],
                'message' => ['type' => 'text', 'required' => true, 'min_length' => 10, 'max_length' => 5000],
            ],
        ],
    ],
];