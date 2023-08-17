<?php

namespace App\Console\Commands;

use App\Actions\FetchDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List Available DB';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $output = FetchDatabase::fetch();

        foreach($output as $name){
            $this->info($name);
        }
    }
}
