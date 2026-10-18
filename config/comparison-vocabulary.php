<?php

// Comparison aliases only; never substitute these strings into verified evidence.
return [
    'education_levels' => [
        'none' => ['no formal qualification', 'بدون مؤهل'],
        'basic_education' => ['basic education', 'primary education', 'تعليم أساسي', 'ابتدائية'],
        'preparatory' => ['preparatory education', 'إعدادية', 'الشهادة الإعدادية'],
        'technical_diploma' => ['technical diploma', 'industrial diploma', 'electrical diploma', 'diploma', 'دبلوم فني', 'دبلوم صنايع', 'دبلوم صناعي'],
        'general_secondary' => ['general secondary', 'high school', 'ثانوية عامة'],
        'technical_institute' => ['technical institute', 'معهد فني'],
        'bachelor' => ['bachelor', 'bachelors', "bachelor's degree", 'bachelor degree', 'بكالوريوس', 'ليسانس'],
        'postgraduate_diploma' => ['postgraduate diploma', 'دبلوم دراسات عليا'],
        'master' => ['master', 'masters', "master's degree", 'ماجستير'],
        'doctorate' => ['doctorate', 'phd', 'ph.d', 'دكتوراه'],
    ],
    'education_fields' => [
        'electrical' => ['electrical', 'electricity', 'electrical department', 'كهرباء', 'قسم الكهرباء', 'هندسة كهربائية'],
        'mechanical' => ['mechanical', 'mechanical engineering', 'ميكانيكا', 'هندسة ميكانيكية'],
        'electronics' => ['electronics', 'إلكترونيات'],
        'industrial' => ['industrial engineering', 'هندسة صناعية'],
        'computer_science' => ['computer science', 'علوم الحاسب'],
        'business' => ['business administration', 'إدارة أعمال'],
        'accounting' => ['accounting', 'محاسبة'],
    ],
    'certifications' => [
        'electrical safety' => ['safety_training', 'electrical safety training', 'تدريب السلامة الكهربائية', 'السلامة الكهربائية'],
    ],
    'shift' => [
        'available' => ['available for shift work', 'willing to work shifts', 'willing to work rotating shifts', 'available for rotating shifts', 'ready to work shifts', 'متاح للعمل بنظام الورديات', 'مستعد للعمل بنظام الورديات', 'مستعد للعمل بالورديات', 'متاح للعمل بالورديات'],
        'not_available' => ['not available for shift work', 'not available for rotating shifts', 'cannot work rotating shifts', 'unwilling to work shifts', 'will not work shifts', 'غير متاح للعمل بنظام الورديات', 'لست مستعد للعمل بنظام الورديات', 'لا أرغب في العمل بالورديات', 'cannot work shifts', 'not willing to work shifts', 'not willing to work rotating shifts', 'unwilling to work rotating shifts', 'refuse to work shifts', 'غير متاح للعمل بالورديات', 'لا أستطيع العمل بالورديات', 'غير مستعد للعمل بنظام الورديات', 'لا أرغب في العمل بنظام الورديات', 'أرفض العمل بنظام الورديات'],
    ],
    // Bare answers/enums require an exact match; "yes" in unrelated prose is insufficient.
    'boolean' => [
        'available' => ['available', 'true', 'yes', 'نعم', 'متاح', 'موافق'],
        'not_available' => ['not_available', 'false', 'no', 'unavailable', 'لا', 'غير متاح', 'غير موافق'],
    ],
    'uncertainty' => ['not sure', 'unsure', 'maybe', 'might', 'perhaps', 'if', 'depending', 'subject to', 'provided that', 'قد', 'إذا', 'بشرط', 'غير متأكد', 'ربما', 'قد أوافق'],
    'negation' => ['not', 'no', 'never', 'without', 'cannot', 'لم', 'لا', 'ليس', 'غير', 'بدون', 'لست', 'مش'],
];
