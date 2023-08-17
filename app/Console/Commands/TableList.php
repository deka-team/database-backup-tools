<?php

namespace App\Console\Commands;

use App\Actions\FetchTable;
use Illuminate\Console\Command;

class TableList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:table-list {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List Tables From a database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dbName = $this->argument('name');
        $output = FetchTable::fetch($dbName);

        foreach ($output as $name) {
            $this->info($name);
        }

        return $output;
    }
}
