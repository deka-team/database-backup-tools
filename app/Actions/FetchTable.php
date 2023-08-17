<?php
namespace App\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class FetchTable
{
    public static function fetch(string $database)
    {
        return Arr::pluck(DB::select(<<<SQL
            SELECT table_name FROM information_schema.tables WHERE table_schema = "{$database}"
        SQL), "TABLE_NAME");
    }
}