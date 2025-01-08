<?php

namespace App\Filament\Resources;

use App\Actions\BackupDatabase;
use App\Actions\FormatFileSize;
use App\Filament\Actions\BackupDatabaseBulkAction;
use App\Filament\Resources\DatabaseResource\Pages;
use App\Filament\Resources\DatabaseResource\RelationManagers;
use App\Models\Backup;
use App\Models\Database;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

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
                    ->maxLength(100)
                    ->helperText('Untuk Prefix Nama File Backup'),
                Forms\Components\TextInput::make('database')
                    ->maxLength(100)
                    ->helperText('Nama Database'),
                Forms\Components\TextInput::make('host')
                    ->maxLength(255)
                    ->helperText('Jika menggunakan port, gunakan format host:port'),
                Forms\Components\TextInput::make('username')
                    ->extraInputAttributes([
                        "autocomplete" => "new-username"
                    ])
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->extraInputAttributes([
                        "autocomplete" => "new-password"
                    ])
                    ->maxLength(255)
                    ->revealable(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Auto Backup')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_selective')
                    ->label('Selective Backup')
                    ->live()
                    ->afterStateUpdated(function(Set $set, ?bool $state){
                        if(!$state){
                            $set('tables', []);
                            $set('views', []);
                        }

                    })
                    ->columnSpanFull(),

                Forms\Components\Select::make('tables')
                    ->multiple()
                    ->options(function(Get $get){
                        $host = $get('host');
                        $database = $get('database');
                        $username = $get('username');
                        $password = $get('password');

                        if(!$host || !$database || !$username){
                            return [];
                        }

                        try{
                            $options = BackupDatabase::getListTableOptions(host: $host, database: $database, username: $username, password: $password);
                            return $options;
                        }catch(\Exception $e){
                            return [];
                        }
                    })
                    ->columnSpanFull()
                    ->visible(fn(Get $get) => $get('is_selective'))
                    ->required(),
                Forms\Components\Select::make('views')
                    ->multiple()
                    ->options(function(Get $get){
                        $host = $get('host');
                        $database = $get('database');
                        $username = $get('username');
                        $password = $get('password');

                        if(!$host || !$database || !$username){
                            return [];
                        }

                        try{
                            $options = BackupDatabase::getListViewOptions(host: $host, database: $database, username: $username, password: $password);
                            return $options;
                        }catch(\Exception $e){
                            return [];
                        }
                    })
                    ->columnSpanFull()
                    ->visible(fn(Get $get) => $get('is_selective')),
            ])
            ->columns(1)
            ->inlineLabel();
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
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Auto Backup'),
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
                    Tables\Actions\Action::make('test-connection')
                        ->action(function(Model $record){
                            $success = $record->testConnection();

                            Notification::make()
                                ->title(new HtmlString("Test Connection <strong>{$record->name}</strong>"))
                                ->body($success ? 'Connection successful' : 'Connection failed')
                                ->status($success ? 'success' : 'danger')
                                ->send();
                        })
                        ->icon('heroicon-o-check-circle'),
                    Tables\Actions\Action::make('backup')
                        ->action(function(Model $record){
                            try{
                                BackupDatabase::backup($record);
                                Notification::make()
                                    ->title('Backup Successfully')
                                    ->success()
                                    ->send();
                            }catch(\Exception $e){
                                Notification::make()
                                    ->title('Backup Failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->icon('heroicon-o-bolt')
                        ->requiresConfirmation(),
                    Tables\Actions\Action::make('download-latest')
                        ->label('Download Latest')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (Model $record) => $record?->latestBackupUrl, shouldOpenInNewTab: true),
                    Tables\Actions\ReplicateAction::make()
                        ->excludeAttributes([
                            'backups_count',
                            'backups_sum_size',
                        ])
                        ->icon('heroicon-o-square-2-stack')
                        ->modalDescription('Are you sure you want to replicate this database?')
                        ->modalWidth('md'),
                    Tables\Actions\EditAction::make()
                        ->after(function(Model $record){
                            $success = $record->testConnection();

                            Notification::make()
                                ->title(new HtmlString("Test Connection <strong>{$record->name}</strong>"))
                                ->body($success ? 'Connection successful' : 'Connection failed')
                                ->status($success ? 'success' : 'danger')
                                ->send();
                        }),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
                BackupDatabaseBulkAction::make(),
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
