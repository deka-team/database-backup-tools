<?php

use App\Jobs\BackupDatabaseJob;
use App\Models\Database;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/


Schedule::call(function(){
    $databases = Database::active()->get();
    foreach ($databases as $database) {
        BackupDatabaseJob::dispatch($database);
    }
})
->name('backup-database-daily')
->description('Backup database daily')
->dailyAt('17:00');