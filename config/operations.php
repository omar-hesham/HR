<?php

return [
    'backup_target' => env('BACKUP_TARGET', ''),
    'backup_key' => env('BACKUP_ENCRYPTION_KEY', ''),
    'restore_connection' => [
        'driver' => 'mysql', 'host' => env('RESTORE_DB_HOST', '127.0.0.1'), 'port' => env('RESTORE_DB_PORT', 3306),
        'database' => env('RESTORE_DB_DATABASE', ''), 'username' => env('RESTORE_DB_USERNAME', ''), 'password' => env('RESTORE_DB_PASSWORD', ''),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci', 'strict' => true,
    ],
    'ledger_target' => env('DELETION_LEDGER_TARGET', ''),
];
