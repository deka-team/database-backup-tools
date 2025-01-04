<?php

namespace App\Jobs;

use App\Actions\BackupDatabase;
use App\Models\Database;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackupDatabaseJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Database $database,
    )
    {}

    /**
     * Get the display name for the queued job.
     *
     * @return string
     */
    public function displayName()
    {
        return "Backup Database {$this->database->name}";
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        BackupDatabase::backup($this->database);
    }
}
