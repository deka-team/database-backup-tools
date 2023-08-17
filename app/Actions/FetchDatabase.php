<?php
namespace App\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class FetchDatabase
{
    public static function fetch()
    {
        return Arr::pluck(DB::select('SHOW DATABASES'), 'Database');
    }
}