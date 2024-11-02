<?php

namespace App\Models;

use App\Actions\FormatFileSize;
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
        if($this->disk === 'minio'){
            /** @disregard P1013 */
            $url = Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes(10));
        }else{
            $url = route('download', ['backup' => $this->id, 'name' => $this->name]);
        }

        return Attribute::make(
            get: fn() => $url,
        );
    }

    public function readableSize(): Attribute
    {
        return Attribute::make(
            get: fn () => FormatFileSize::format($this->size),
        );
    }

    public static function boot(): void
    {
        parent::boot();

        static::deleting(function($model){
            /** Delete File Before Record Deleted */
            $storage = Storage::disk($model->disk);

            if($storage->exists($model->path)){
                $storage->delete($model->path);
            }
        });
    }
}
