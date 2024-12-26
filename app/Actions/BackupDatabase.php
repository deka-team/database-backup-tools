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
use Illuminate\Database\Connection;

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

        $driver = 'mysql';
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

        $connection = DB::build([
            'driver' => $driver,
            'host' => $dbHost,
            'port' => $dbPort,
            'database' => $dbName,
            'username' => $dbUsername,
            'password' => $dbPassword,
        ]);

        $listTable = self::getListTable($connection);

        $cmdData = [
            // disable foreign key checks
            "echo \"SET foreign_key_checks = 0;\" >> {$fullPathSql}",
        ];

        foreach($listTable as $table){

            // skip pulse_* tables
            if(Str::startsWith($table, 'pulse_')){
                continue;
            }

            $columns = self::getTableColumns($connection, $table);
            $insertedColumns = [];
            foreach($columns as $column){
                if(!self::isGeneratedColumn($column)){
                    $insertedColumns[] = $column->Field;
                }else{
                    dd($column);
                }
            }

            try{
                $output = BackupDumper::make($connection->table($table), $insertedColumns)->compile();
            }catch(\Exception $e){
                throw $e;
            }


            if(empty($output)){
                continue;
            }

            $output = "-- START INSERT INTO {$table}\n\n{$output}\n-- END INSERT INTO {$table}\n\n";

            $cmdData[] = str_replace("`", "\`", "echo \"{$output}\" >> {$fullPathSql}");
        }

        $cmdData[] = "echo \"SET foreign_key_checks = 1;\" >> {$fullPathSql}";

        $cmd1 = "{$mysqldump} --defaults-extra-file={$configFullPath} -h {$dbHost} -P {$dbPort} -u {$dbUsername} {$dbName} --no-data > {$fullPathSql}";
        $cmd2 = "cat {$fullPathSql} | {$gzip} > $fullPathGz";
        $cmd3 = "rm {$fullPathSql}";

        $output = Process::pipe(array_filter([
            $cmd1,
            ...$cmdData,
            $cmd2,
            $cmd3,
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

            /** @disregard */
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

    public static function getListTable(Connection $connection)
    {
        $result = $connection->select('SHOW FULL TABLES WHERE Table_Type = "BASE TABLE"');
        $firstColumn = head(array_keys((array) ($result[0] ?? [])));

        if($firstColumn){
            return Arr::pluck($result, $firstColumn);
        }else{
            return [];
        }
    }

    public static function getTableColumns(Connection $connection, string $table)
    {
        return $connection->select("SHOW COLUMNS FROM {$table}");
    }

    public static function isGeneratedColumn($columnInfo)
    {
        return (Str::contains(strtolower($columnInfo->Extra), 'generated')
        || Str::contains(strtolower($columnInfo->Extra), 'virtual')
        || Str::contains(strtolower($columnInfo->Extra), 'stored')) && $columnInfo->Type !== 'timestamp';
    }
}
