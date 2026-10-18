<?php

return [
    'driver' => env('AI_DRIVER', 'fake'), 'timeout' => (int) env('AI_TIMEOUT_SECONDS', 600),
    'model' => env('AI_MODEL', 'local-model'), 'cloud_model' => env('AI_CLOUD_MODEL', 'gemini-3.8-flash'),
    'identity' => [
        'name_labels' => ['name', 'full name', 'الاسم', 'الاسم بالكامل'],
        'generic_words' => ['cv', 'resume', 'curriculum', 'vitae', 'profile', 'application', 'synthetic', 'test', 'candidate', 'engineer', 'technician', 'accountant', 'experience', 'education', 'skills', 'name', 'الاسم', 'سيره', 'ذاتيه', 'مهندس', 'فني', 'خبره', 'السيره', 'الذاتيه'],
    ],
    'mask_patterns' => [
        '/(?<![@\p{L}\p{N}])(?:[a-z0-9\-]+\.)+[a-z]{2,63}(?:\/[^\s<>]*)?/iu',
        '/(?<![\p{L}\p{N}])(?:address|location|residence|signature|signed|العنوان|عنوان|السكن|التوقيع)\h*[:\-][^\r\n]*/iu',
        '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}/u', '/(?<!\d)[23]\d{13}(?!\d)/u',
        '/(?<![\p{L}\d])(?:\+|00)\d[\d ()\-.]{6,}\d(?!\d)/u', '/(?<!\d)0(?:1[0125]\d{8}|[2-9][\d ()\-.]{6,}\d)(?!\d)/u',
        '~(?:https?://|www\.)[^\s<>]+~iu', '/(?<![\p{L}\d])@[\p{L}\d_\.]+/u',
        '/^\h*(?:address|location|residence|signature|signed|العنوان|عنوان|السكن|التوقيع)\h*[:\-].*$/imu',
    ],
];
