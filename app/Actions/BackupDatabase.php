<?php
namespace App\Actions;

use App\Models\Backup;
use App\Models\Database;
use Filament\Notifications\Notification;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Pipe;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
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

        $appUrl = parse_url(config('app.url'));
        $appHost = $appUrl['host'] ?? $appUrl['path'] ?? null;
        $backupDisk = config('backup-tools.backup.disk', 'local');
        $prefix = config('backup-tools.backup.prefix', 'backup');
        $mysqldump = config('backup-tools.mysqldump', '/usr/bin/mysqldump');
        $mysql = config('backup-tools.mysql', '/usr/bin/mysql');
        $gzip = config('backup-tools.gzip', '/usr/bin/gzip');
        $dbHost = $database?->host ?? env('DB_BACKUP_HOST', config('database.connections.mysql.host'));
        $dbUsername = $database?->username ?? config('database.connections.mysql.username');
        $dbPassword = $database?->password ?? config('database.connections.mysql.password');

        $dbHostAndPort = parse_url($dbHost);

        $dbPort = $dbHostAndPort['port'] ?? 3306;
        $dbHost = $dbHostAndPort['host'] ?? $dbHostAndPort['path'] ?? $dbHost;

        $sqlPath = "{$prefix}/{$backupName}.sql";
        $backupPath = "{$sqlPath}.gz";
        /** @var Storage $localStorage */

        $localStorage = Storage::disk('local');
        $fullPathSql = $localStorage->path($sqlPath);
        $fullPathGz = $localStorage->path($backupPath);

        if(!$localStorage->exists($prefix)){
            mkdir($localStorage->path($prefix));
        }

        $configPath = Str::of("{$dbHost}:{$dbPort}")
            ->replace([".", ":"], "-")
            ->slug()
            ->prepend($prefix, "/")
            ->append(".cnf")
            ->toString();
        $localStorage->put($configPath, <<<PLAIN
        [mysqldump]
        # The following password will be sent to mysqldump
        password="$dbPassword"
        PLAIN);

        $configFullPath = $localStorage->path($configPath);

        $inlinePassword = $dbPassword ? "-p{$dbPassword}" : '';
        // change list table using mysql cli command instead
        $listTable = value(function() use ($mysql, $dbHost, $dbPort, $dbUsername, $inlinePassword, $dbName){
            $output = Process::run("{$mysql} -h {$dbHost} -P {$dbPort} -u {$dbUsername} {$inlinePassword} -e 'SHOW FULL TABLES FROM {$dbName} WHERE Table_Type = \"BASE TABLE\"'");

            return array_map(function($item){
                $items = explode("\t", $item);
                return trim($items[0] ?? '');
            }, explode("\n", $output->output()));
        });

        $pulseExists = count(Arr::where($listTable, function($item){
            return Str::startsWith($item, 'pulse_');
        })) > 0;

        $listTable = value(function($result){
            $result = array_filter($result, function ($item) {
                // remove Tables_in_dbName
                return !Str::startsWith($item, 'Tables_in_') && !empty($item) && !Str::startsWith($item, 'pulse_');
            });

            return implode(' ', $result);
        }, $listTable);

        $cmd1 = "{$mysqldump} --defaults-extra-file={$configFullPath} --complete-insert=FALSE -h {$dbHost} -P {$dbPort} -u {$dbUsername} {$dbName} {$listTable} > {$fullPathSql}";
        $cmd2 = $pulseExists
                    ? "{$mysqldump} --defaults-extra-file={$configFullPath} -h {$dbHost} -P {$dbPort} -u {$dbUsername} {$dbName} pulse_aggregates pulse_entries pulse_values --no-data >> {$fullPathSql}"
                    : null;
        $cmd3 = "cat {$fullPathSql} | {$gzip} > $fullPathGz";
        $cmd4 = "rm {$fullPathSql}";

        Log::info("List Table : {$listTable}");

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

        if($error = $output->errorOutput()){
            throw new \Exception($error);
        }

        if($output->successful()){

            $backupSize = $localStorage->size($backupPath);

            if($backupDisk === 'minio'){
                // transfer to minio
                $minio = Storage::disk('minio');
                $minioPath = implode('/', array_filter([$appHost, basename($backupPath)]));
                $minio->put($minioPath, $localStorage->get($backupPath));

                $backupPath = $minioPath;
                $backupDisk = 'minio';

                // delete from local
                $localStorage->delete($backupPath);
            }

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
