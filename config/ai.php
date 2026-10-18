<?php

return [
    'default' => 'recruit_local',
    'providers' => [
        'recruit_local' => ['driver' => 'openai-compatible', 'url' => env('AI_BASE_URL', 'http://127.0.0.1:8080/v1'), 'key' => env('AI_API_KEY')],
        // The launcher injects the cloud key into the process. Never put it in a config cache.
        'recruit_cloud' => ['driver' => 'openai-compatible', 'url' => env('AI_CLOUD_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai/'), 'key' => null],
    ],
];
