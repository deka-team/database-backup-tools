<?php
namespace App\Actions;

use App\Models\Backup;
use App\Models\Database;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class BackupDatabase
{
    public static function backup(string $dbName, bool $view = false)
    {
        $timestamp = Carbon::now()->format('Y-m-d__H-i-s');
        $backupName = "{$dbName}__{$timestamp}.sql.gz";

        $backupDisk = config('backup-tools.backup.disk', 'local');
        $prefix = config('backup-tools.backup.prefix', 'backup');
        $mysqldump = config('backup-tools.mysqldump', '/usr/bin/mysqldump');
        $gzip = config('backup-tools.gzip', '/usr/bin/gzip');
        $dbUsername = config('database.connections.mysql.username');
        $dbPassword = config('database.connections.mysql.password');

        $backupPath = "{$prefix}/{$backupName}";
        $storage = Storage::disk($backupDisk);
        $fullPath = $storage->path($backupPath);

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
            SQL), 'TABLE_NAME'));

        }, $dbName, $view);

        $command = "{$mysqldump} --defaults-extra-file={$configFullPath} -u {$dbUsername} {$dbName} {$listTable} | {$gzip} > {$fullPath}";

        $output = Process::run($command);

        if($output->successful()){

            $backupSize = $storage->size($backupPath);

            $database = Database::firstOrCreate([
                'name' => $dbName
            ]);

            $database->backups()->create([
                'name' => $backupName,
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
