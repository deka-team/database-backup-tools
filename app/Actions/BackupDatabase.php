<?php
namespace App\Actions;

use App\Models\Backup;
use App\Models\Database;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Pipe;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class BackupDatabase
{
    public static function backup(Database | string $database, bool $view = false)
    {
        if(is_string($database)){
            $dbName = $database;
        }else{
            $dbName = $database->name;
        }

        $timestamp = Carbon::now()->format('Y-m-d__H-i-s');
        $backupName = "{$dbName}__{$timestamp}";

        $backupDisk = config('backup-tools.backup.disk', 'local');
        $prefix = config('backup-tools.backup.prefix', 'backup');
        $mysqldump = config('backup-tools.mysqldump', '/usr/bin/mysqldump');
        $gzip = config('backup-tools.gzip', '/usr/bin/gzip');
        $dbHost = $database?->host ?? env('DB_BACKUP_HOST', config('database.connections.mysql.host'));
        $dbUsername = $database?->username ?? config('database.connections.mysql.username');
        $dbPassword = $database?->password ?? config('database.connections.mysql.password');

        $dbHostAndPort = parse_url($dbHost);

        $dbPort = $dbHostAndPort['port'] ?? 3306;
        $dbHost = $dbHostAndPort['host'] ?? $dbHostAndPort['path'] ?? $dbHost;

        $sqlPath = "{$prefix}/{$backupName}.sql";
        $backupPath = "{$sqlPath}.gz";
        /** @var Storage $storage */
        $storage = Storage::disk($backupDisk);
        $fullPathSql = $storage->path($sqlPath);
        $fullPathGz = $storage->path($backupPath);

        if(!$storage->exists($prefix)){
            mkdir($storage->path($prefix));
        }

        $configPath = "{$prefix}/mysqlpassword.cnf";
        $storage->put($configPath, <<<PLAIN
        [mysqldump]
        # The following password will be sent to mysqldump
        password="$dbPassword"
        PLAIN);

        $configFullPath = $storage->path($configPath);

        $listTable = value(function($dbName, $view){

            $type = ["BASE TABLE"];

            if($view){
                $type[] = "VIEW";
            }

            $condition = '("' . implode('", "', $type) . '")';

            return implode(' ', Arr::pluck(DB::select(<<<SQL
                SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = "$dbName" AND TABLE_TYPE IN $condition
                    AND TABLE_NAME NOT LIKE "pulse_%"
            SQL), 'TABLE_NAME'));

        }, $dbName, $view);

        $pulseExists = count(DB::select(<<<SQL
                SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = "$dbName" AND TABLE_NAME LIKE "pulse_%"
            SQL)) > 0;

        $cmd1 = "{$mysqldump} --defaults-extra-file={$configFullPath} -h {$dbHost} -P {$dbPort} -u {$dbUsername} {$dbName} {$listTable} > {$fullPathSql}";
        $cmd2 = $pulseExists
                    ? "{$mysqldump} --defaults-extra-file={$configFullPath} -h {$dbHost} -P {$dbPort} -u {$dbUsername} {$dbName} pulse_aggregates pulse_entries pulse_values --no-data >> {$fullPathSql}"
                    : null;
        $cmd3 = "cat {$fullPathSql} | {$gzip} > $fullPathGz";
        $cmd4 = "rm {$fullPathSql}";

        Log::info("CMD 1 : {$cmd1}");
        Log::info("CMD 2 : {$cmd2}");
        Log::info("CMD 3 : {$cmd3}");
        Log::info("CMD 4 : {$cmd4}");

        $output = Process::pipe(array_filter([
            $cmd1,
            $cmd2,
            $cmd3,
            $cmd4,
        ]));

        if($output->successful()){

            $backupSize = $storage->size($backupPath);

            $database = Database::firstOrCreate([
                'name' => $dbName
            ]);

            $database->backups()->create([
                'name' => basename($fullPathGz),
                'path' => $backupPath,
                'disk' => $backupDisk,
                'size' => $backupSize,
            ]);

            $database->touch();

            foreach($database->backups()->latest()->get() as $index => $backup){
                if($index >= intval(config('backup-tools.backup.max_files', 3))){
                    $backup->delete();
                }
            }
        }
    }
}
