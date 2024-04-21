<?php

namespace App\Filament\Resources;

use App\Actions\BackupDatabase;
use App\Actions\BulkBackupDatabase;
use App\Actions\FormatFileSize;
use App\Filament\Resources\DatabaseResource\Pages;
use App\Filament\Resources\DatabaseResource\RelationManagers;
use App\Models\Backup;
use App\Models\Database;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DatabaseResource extends Resource
{
    protected static ?string $model = Database::class;

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();

        $query->with(['backups'])->withCount(['backups'])->withSum('backups', 'size');

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->maxLength(100),
                Forms\Components\TextInput::make('host')
                    ->maxLength(255),
                Forms\Components\TextInput::make('username')
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_active')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('size')
                    ->getStateUsing(fn(Model $record) => $record->backups?->sum('size'))
                    ->formatStateUsing(fn (null|string $state) => FormatFileSize::format($state))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('backups_sum_size', $direction))
                    ->summarize(
                        Summarizer::make()
                            ->using(fn (Builder $query) => Backup::whereIn('database_id', $query->pluck('id'))->sum('size'))
                            ->formatStateUsing(fn (null|string $state) => FormatFileSize::format($state))
                    ),
                Tables\Columns\TextColumn::make('file_count')
                    ->label('File')
                    ->getStateUsing(fn(Model $record) => $record->backups?->count())
                    ->formatStateUsing(fn (null|string $state) => number_format($state))
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('backups_count', $direction))
                    ->summarize(
                        Summarizer::make()
                            ->using(fn () => Backup::has('database')->count())
                            ->formatStateUsing(fn (null|string $state) => number_format($state))
                    ),
                Tables\Columns\ToggleColumn::make('is_active'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('backup')
                        ->action(function(Model $record){
                            BackupDatabase::backup($record->name);
                            Notification::make()
                                ->title('Backup Successfully')
                                ->success()
                                ->send();
                        })
                        ->icon('heroicon-o-bolt')
                        ->requiresConfirmation(),
                    Tables\Actions\Action::make('download-latest')
                        ->label('Download Latest')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (Model $record) => $record?->latestBackupUrl, shouldOpenInNewTab: true),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
                BulkBackupDatabase::make(),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDatabases::route('/'),
        ];
    }
}
