<?php
namespace App\Actions;

use App\Models\Database;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class BackupDatabase
{
    public static function backup(string $dbName)
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

        $command = "{$mysqldump} --defaults-extra-file={$configFullPath} -u {$dbUsername} {$dbName} | {$gzip} > {$fullPath}";
        
        $output = Process::run($command);

        if($output->successful()){
            $database = Database::firstOrCreate([
                'name' => $dbName
            ]);

            $database->backups()->create([
                'name' => $backupName,
                'path' => $backupPath,
                'disk' => $backupDisk,
            ]);

            $database->touch();
        }
    }
}