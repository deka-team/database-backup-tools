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
            $dbName = $database->database;
        }

        $backupNamePrefix = $database?->name ?: $dbName;

        $timestamp = Carbon::now()->format('Y-m-d__H-i-s');
        $backupName = "{$backupNamePrefix}__{$timestamp}";

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

        $dbPort = self::parsePort($dbHost);
        $dbHost = self::parseHost($dbHost);

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

        if($database->is_selective){
            $listTable = $database->tables ?? [];
            $listView = $database->views ?? [];
        }else{
            $connection = self::connection(
                host: $dbHost,
                port: $dbPort,
                database: $dbName,
                username: $dbUsername,
                password: $dbPassword
            );
    
            $listTable = self::getListTable($connection);    
            $listView = self::getListView($connection);
        }

        $listTableString = implode(' ', $listTable);
        $listViewString = implode(' ', $listView);

        $baseMysqldump = "{$mysqldump} --defaults-extra-file={$configFullPath} -h {$dbHost} -P {$dbPort} -u {$dbUsername} {$dbName}";

        $cmd1 = "{$baseMysqldump} --no-data --skip-triggers --tables {$listTableString} > {$fullPathSql}";

        $cmd2 = count($listView) > 0 ? "{$baseMysqldump} --no-data --tables {$listViewString} | sed -E 's/DEFINER=[^ *]+/DEFINER=CURRENT_USER/g' >> {$fullPathSql}" : null;

        $cmd3 = "{$baseMysqldump} --no-create-info --hex-blob --tables {$listTableString} >> {$fullPathSql}";

        $cmd4 = "cat {$fullPathSql} | {$gzip} > $fullPathGz";
        $cmd5 = "rm {$fullPathSql}";

        $commands = [$cmd1, $cmd2, $cmd3, $cmd4, $cmd5];

        $output = Process::pipe(function(Pipe $pipe) use ($commands) {
            foreach($commands as $command){
                if($command){
                    $pipe->forever()->run($command);
                }
            }
        });

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

            if(is_string($database)){
                /** @disregard */
                $database = Database::firstOrCreate([
                    'name' => $backupNamePrefix,
                    'database' => $dbName,
                ]);
            }

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

    public static function parseHost(string $host)
    {
        $dbHostAndPort = parse_url($host);
        return ($dbHostAndPort['host'] ?? $dbHostAndPort['path']) ?: "127.0.0.1";
    }

    public static function parsePort(string $host)
    {
        $dbHostAndPort = parse_url($host);
        return (int) ($dbHostAndPort['port'] ?? 3306);
    }

    public static function connection(string $host, string $database, string $username, ?string $password = null, ?int $port = null, string $driver = 'mysql')
    {
        $port = $port ?? self::parsePort($host);
        $host = self::parseHost($host);

        return DB::build([
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ]);
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

    public static function getListView(Connection $connection)
    {
        $result = $connection->select('SHOW FULL TABLES WHERE Table_Type = "VIEW"');
        $firstColumn = head(array_keys((array) ($result[0] ?? [])));

        if($firstColumn){
            return Arr::pluck($result, $firstColumn);
        }else{
            return [];
        }
    }

    public static function getListTableOptions(string $host, string $database, string $username, ?string $password, ?int $port = null)
    {
        $connection = self::connection(
            host: $host,
            database: $database,
            username: $username,
            password: $password,
            port: $port
        );
        $options = self::getListTable($connection);

        return array_combine($options, $options);
    }

    public static function getListViewOptions(string $host, string $database, string $username, ?string $password, ?int $port = null)
    {
        $connection = self::connection(
            host: $host,
            database: $database,
            username: $username,
            password: $password,
            port: $port
        );

        $options = self::getListView($connection);

        return array_combine($options, $options);
    }

    public static function getTableColumns(Connection $connection, string $table)
    {
        return $connection->select("SHOW COLUMNS FROM {$table}");
    }

    public static function isBinaryColumn($columnInfo)
    {
        $type = strtolower($columnInfo->Type);
        return Str::contains($type, 'binary') || Str::contains($type, 'blob');
    }

    public static function isGeneratedColumn($columnInfo)
    {
        return (Str::contains(strtolower($columnInfo->Extra), 'generated')
        || Str::contains(strtolower($columnInfo->Extra), 'virtual')
        || Str::contains(strtolower($columnInfo->Extra), 'stored')) && $columnInfo->Type !== 'timestamp';
    }
}
