<?php

namespace App\Console\Commands;

use App\Actions\BackupDatabase;
use Illuminate\Console\Command;

class DatabaseBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup Database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $output = BackupDatabase::backup($this->argument('name'));

        dd($output);
    }
}
