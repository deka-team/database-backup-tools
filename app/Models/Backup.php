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
        'size',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'size' => 'float',
        'meta' => 'json',
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

    public function readableSize(): Attribute
    {
        $size = $this->size;
        $b = $size;
        $kb = round($size / 1024, 1);
        $mb = round($kb / 1024, 1);
        $gb = round($mb / 1024, 1);

        $result = null;

        if ($kb == 0) {
            $result = $b . " bytes";
        } else if ($mb == 0) {
            $result = $kb . "KB";
        } else if ($gb == 0) {
            $result = $mb . "MB";
        } else {
            $result = $gb . "GB";
        }

        return Attribute::make(
            get: fn () => $result,
        );
    }
}
