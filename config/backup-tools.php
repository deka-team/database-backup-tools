<?php return [
    'binary_path' => env('BINARY_PATH', '/usr/bin'),
    'mysqldump' => env('MYSQLDUMP_PATH', '/usr/bin/mysqldump'),
    'mysql' => env('MYSQL_PATH', '/usr/bin/mysql'),
    'gzip' => env('GZIP_PATH', '/usr/bin/gzip'),
    'backup' => [
        'disk' => env('BACKUP_DISK', 'minio'),
        'prefix' => env('BACKUP_PREFIX', 'backup'),
        'max_files' => env('BACKUP_MAX_FILES', 3),
    ],
];