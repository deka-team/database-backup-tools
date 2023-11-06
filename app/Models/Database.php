<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
