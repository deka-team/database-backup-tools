<?php

use App\Actions\BackupDatabase;
use App\Models\Database;

it('normalizes enabled backup filters', function () {
    $database = new Database([
        'backup_filter_enabled' => true,
        'backup_filters' => [
            ['column' => 'tahun', 'value' => 2026],
            ['column' => '', 'value' => 'ignored'],
            ['column' => 'status', 'value' => 'aktif'],
        ],
    ]);

    expect(BackupDatabase::normalizeBackupFilters($database))->toBe([
        ['column' => 'tahun', 'value' => '2026'],
        ['column' => 'status', 'value' => 'aktif'],
    ]);
});

it('rejects invalid backup filter columns', function () {
    BackupDatabase::validateFilterColumn('tahun;DROP');
})->throws(InvalidArgumentException::class);

it('builds per table data dump commands with optional where clauses', function () {
    $commands = BackupDatabase::buildDataDumpCommands(
        baseMysqldump: '/usr/bin/mysqldump app_db',
        tables: ['orders', 'users'],
        tableFilters: [
            'orders' => ['where' => "`tahun` = '2026'"],
        ],
        fullPathSql: '/tmp/backup.sql',
    );

    expect($commands)->toHaveCount(2)
        ->and($commands[0])->toContain('--where=' . escapeshellarg("`tahun` = '2026'"))
        ->and($commands[0])->toContain('--tables ' . escapeshellarg('orders'))
        ->and($commands[1])->not->toContain('--where=')
        ->and($commands[1])->toContain('--tables ' . escapeshellarg('users'));
});
