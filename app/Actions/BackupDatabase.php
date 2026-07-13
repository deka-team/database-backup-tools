<?php
namespace App\Actions;

use App\Models\Database;
use Illuminate\Database\Connection;
use Illuminate\Process\Pipe;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BackupDatabase
{
    public static function backup(Database | string $database, bool $view = false)
    {
        $databaseModel = $database instanceof Database ? $database : null;
        $dbName = (string) ($databaseModel ? $databaseModel->database : $database);

        $backupNamePrefix = $databaseModel?->name ?: $dbName;

        $timestamp = Carbon::now()->format('Y-m-d__H-i-s');
        $backupName = "{$backupNamePrefix}__{$timestamp}";

        $appUrl = parse_url(config('app.url'));
        $appHost = $appUrl['host'] ?? $appUrl['path'] ?? null;
        $backupDisk = config('backup-tools.backup.disk', 'local');
        $prefix = config('backup-tools.backup.prefix', 'backup');
        $mysqldump = config('backup-tools.mysqldump', '/usr/bin/mysqldump');
        $gzip = config('backup-tools.gzip', '/usr/bin/gzip');
        $dbHost = $databaseModel?->host ?? env('DB_BACKUP_HOST', config('database.connections.mysql.host'));
        $dbUsername = $databaseModel?->username ?? config('database.connections.mysql.username');
        $dbPassword = $databaseModel?->password ?? config('database.connections.mysql.password');

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

        $connection = self::connection(
            host: $dbHost,
            port: $dbPort,
            database: $dbName,
            username: $dbUsername,
            password: $dbPassword
        );

        if($databaseModel?->is_selective){
            $listTable = $databaseModel->tables ?? [];
            $listView = $databaseModel->views ?? [];
        }else{
            $listTable = self::getListTable($connection);    
            $listView = self::getListView($connection);
        }

        $filters = self::normalizeBackupFilters($databaseModel);
        $tableFilters = self::resolveTableFilters($connection, $listTable, $filters);
        $backupMeta = count($filters) > 0 ? [
            'backup_filters' => [
                'enabled' => true,
                'logic' => 'AND',
                'filters' => $filters,
                'tables' => $tableFilters,
            ],
        ] : null;

        $listTableString = self::shellArgs($listTable);
        $listViewString = self::shellArgs($listView);

        $baseMysqldump = implode(' ', [
            escapeshellarg($mysqldump),
            '--defaults-extra-file=' . escapeshellarg($configFullPath),
            '-h ' . escapeshellarg($dbHost),
            '-P ' . escapeshellarg((string) $dbPort),
            '-u ' . escapeshellarg($dbUsername),
            escapeshellarg($dbName),
        ]);

        $cmd1 = "{$baseMysqldump} --no-data --skip-triggers --tables {$listTableString} > " . escapeshellarg($fullPathSql);

        $cmd2 = count($listView) > 0 ? "{$baseMysqldump} --no-data --tables {$listViewString} | sed -E 's/DEFINER=[^ *]+/DEFINER=CURRENT_USER/g' >> " . escapeshellarg($fullPathSql) : null;

        $dataDumpCommands = self::buildDataDumpCommands($baseMysqldump, $listTable, $tableFilters, $fullPathSql);

        $cmd4 = "{$baseMysqldump} --no-create-info --no-data --add-drop-trigger --triggers | sed -E 's/DEFINER=[^ *]+/DEFINER=CURRENT_USER/g' >> " . escapeshellarg($fullPathSql);

        $cmd5 = 'cat ' . escapeshellarg($fullPathSql) . ' | ' . escapeshellarg($gzip) . ' > ' . escapeshellarg($fullPathGz);
        $cmd6 = 'rm ' . escapeshellarg($fullPathSql);

        $commands = array_merge([$cmd1, $cmd2], $dataDumpCommands, [$cmd4, $cmd5, $cmd6]);

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

            if(!$databaseModel){
                /** @disregard */
                $databaseModel = Database::firstOrCreate([
                    'name' => $backupNamePrefix,
                    'database' => $dbName,
                ]);
            }

            $databaseModel->backups()->create([
                'name' => basename($fullPathGz),
                'path' => $backupPath,
                'disk' => $backupDisk,
                'size' => $backupSize,
                'meta' => $backupMeta,
            ]);

            $databaseModel->touch();

            foreach($databaseModel->backups()->latest()->get() as $index => $backup){
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

    public static function normalizeBackupFilters(?Database $database): array
    {
        if(!$database?->backup_filter_enabled){
            return [];
        }

        $filters = [];

        foreach($database->backup_filters ?? [] as $filter){
            $column = trim((string) Arr::get($filter, 'column'));
            $value = Arr::get($filter, 'value');

            if($column === '' || $value === null || $value === ''){
                continue;
            }

            self::validateFilterColumn($column);

            $filters[] = [
                'column' => $column,
                'value' => (string) $value,
            ];
        }

        return $filters;
    }

    public static function validateFilterColumn(string $column): void
    {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)){
            throw new \InvalidArgumentException("Invalid backup filter column: {$column}");
        }
    }

    public static function resolveTableFilters(Connection $connection, array $tables, array $filters): array
    {
        if(count($filters) === 0){
            return [];
        }

        $tableFilters = [];

        foreach($tables as $table){
            $columns = self::getTableColumnNames($connection, $table);
            $matchedFilters = array_values(array_filter(
                $filters,
                fn(array $filter) => in_array($filter['column'], $columns, true)
            ));

            $tableFilters[$table] = [
                'filtered' => count($matchedFilters) > 0,
                'filters' => $matchedFilters,
                'where' => count($matchedFilters) > 0
                    ? self::buildWhereClause($connection, $matchedFilters)
                    : null,
            ];
        }

        return $tableFilters;
    }

    public static function buildWhereClause(Connection $connection, array $filters): string
    {
        $conditions = array_map(function(array $filter) use ($connection){
            return self::quoteIdentifier($filter['column']) . ' = ' . self::quoteValue($connection, $filter['value']);
        }, $filters);

        return implode(' AND ', $conditions);
    }

    public static function buildDataDumpCommands(string $baseMysqldump, array $tables, array $tableFilters, string $fullPathSql): array
    {
        $commands = [];

        foreach($tables as $table){
            $where = $tableFilters[$table]['where'] ?? null;
            $command = "{$baseMysqldump} --no-create-info --hex-blob";

            if($where){
                $command .= ' --where=' . escapeshellarg($where);
            }

            $command .= ' --tables ' . escapeshellarg((string) $table) . ' >> ' . escapeshellarg($fullPathSql);

            $commands[] = $command;
        }

        return $commands;
    }

    public static function shellArgs(array $values): string
    {
        return implode(' ', array_map(fn($value) => escapeshellarg((string) $value), $values));
    }

    public static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public static function quoteValue(Connection $connection, string $value): string
    {
        return $connection->getPdo()->quote($value);
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
        return $connection->select('SHOW COLUMNS FROM ' . self::quoteIdentifier($table));
    }

    public static function getTableColumnNames(Connection $connection, string $table): array
    {
        return Arr::pluck(self::getTableColumns($connection, $table), 'Field');
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
