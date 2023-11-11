<?php

namespace App\Filament\Resources;

use App\Actions\FormatFileSize;
use App\Filament\Resources\BackupResource\Pages;
use App\Filament\Resources\BackupResource\RelationManagers;
use App\Models\Backup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BackupResource extends Resource
{
    protected static ?string $model = Backup::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('readableSize')
                    ->label('Size')
                    ->summarize(
                        Summarizer::make()
                            ->using(fn(Builder $query) => $query->sum('size'))
                            ->formatStateUsing(fn(null|string $state) => FormatFileSize::format($state))
                    )
                    ->sortable(query: fn(Builder $query, string $direction) => $query->orderBy('size', $direction)),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('database')
                    ->relationship('database', 'name')
                    ->multiple()
                    ->preload(),

            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->url(fn (Model $record) => $record?->url, shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrow-down-tray'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->emptyStateActions([

            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBackups::route('/'),
            'create' => Pages\CreateBackup::route('/create'),
            'edit' => Pages\EditBackup::route('/{record}/edit'),
        ];
    }
}
