<?php

namespace App\Filament\Actions;

use App\Actions\BackupDatabase;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class BackupDatabaseBulkAction extends BulkAction
{
    use CanCustomizeProcess;

    public static function getDefaultName(): ?string
    {
        return 'backup';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Backup Selected');

        $this->modalHeading(fn (): string => 'Backup Selected Database');

        $this->modalSubmitActionLabel('Backup');

        $this->successNotificationTitle('Backup Completed');

        $this->color('primary');

        $this->icon('heroicon-m-bolt');

        $this->requiresConfirmation();

        $this->modalIcon('heroicon-o-bolt');

        $this->action(function (): void {
            $this->process(static fn (Collection $records) => $records->each(fn (Model $record) => BackupDatabase::backup($record)));

            $this->success();
        });

        $this->deselectRecordsAfterCompletion();

        $this->hidden(function (HasTable $livewire): bool {
            $trashedFilterState = $livewire->getTableFilterState(TrashedFilter::class) ?? [];

            if (! array_key_exists('value', $trashedFilterState)) {
                return false;
            }

            if ($trashedFilterState['value']) {
                return false;
            }

            return filled($trashedFilterState['value']);
        });
    }
}
