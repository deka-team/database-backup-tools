<?php

namespace App\Models;

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
        'meta',
    ];

    protected $casts = [
        'meta' => 'json'
    ];

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
