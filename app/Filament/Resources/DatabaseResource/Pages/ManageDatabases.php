<?php

namespace App\Filament\Resources\DatabaseResource\Pages;

use App\Filament\Resources\DatabaseResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ManageDatabases extends ManageRecords
{
    protected static string $resource = DatabaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->after(function(Model $record){
                    $success = $record->testConnection();

                    Notification::make()
                        ->title(new HtmlString("Test Connection <strong>{$record->name}</strong>"))
                        ->body($success ? 'Connection successful' : 'Connection failed')
                        ->status($success ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }
}
