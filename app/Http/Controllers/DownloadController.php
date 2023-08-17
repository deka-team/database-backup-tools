<?php
namespace App\Http\Controllers;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;

class DownloadController extends Controller
{
    public function __invoke(Backup $backup, ?string $name)
    {
        return Storage::disk($backup->disk)->download($backup->path, $backup->name);
    }
}