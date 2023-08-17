<?php return [
    'binary_path' => env('BINARY_PATH', '/usr/bin'),
    'mysqldump' => env('MYSQLDUMP_PATH', '/usr/bin/mysqldump'),
    'gzip' => env('GZIP_PATH', '/usr/bin/gzip'),
    'backup' => [
        'disk' => env('BACKUP_DISK', 'local'),
        'prefix' => env('BACKUP_PREFIX', 'backup'),
    ],
];