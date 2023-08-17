<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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

    public function url(): Attribute
    {
        $url = route('download', ['backup' => $this->id, 'name' => $this->name]);

        return Attribute::make(
            get: fn() => $url,
        );
    }
}
