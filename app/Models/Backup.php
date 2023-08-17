<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'disk',
        'path',
        'meta',
    ];

    protected $casts = [
        'meta' => 'json'
    ];

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }
}
