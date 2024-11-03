<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class Database extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'host',
        'username',
        'password',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'meta' => 'json'
    ];

    public function scopeActive(Builder $query)
    {
        $query->where('is_active', true);
    }

    public function getHost(): string
    {
        $dbHostAndPort = parse_url($this->host);

        return ($dbHostAndPort['host'] ?? $dbHostAndPort['path']) ?: "127.0.0.1";
    }

    public function getPort(): int
    {
        $dbHostAndPort = parse_url($this->host);

        return (int) ($dbHostAndPort['port'] ?? 3306);
    }

    public function testConnection(): bool
    {
        $host = $this->getHost();
        $port = $this->getPort();
        $database = $this->name;
        $username = $this->username ?? config('database.connections.mysql.username');
        $password = $this->password ?? config('database.connections.mysql.password');

        try {
            $config = [
                'driver'    => 'mysql',
                'host'      => $host,
                'port'      => $port,
                'database'  => $database,
                'username'  => $username,
                'password'  => $password,
            ];

            Config::set('database.connections.temp', $config);
            DB::connection('temp')->getPdo();

            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            // Clean up the temporary connection
            DB::purge('temp');
        }
    }

    public function latestBackupUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => optional($this->backups->sortByDesc('created_at')->first())->url,
        );
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
