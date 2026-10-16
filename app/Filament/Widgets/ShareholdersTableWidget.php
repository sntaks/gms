<?php

namespace App\Filament\Widgets;

use App\Models\Holder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class ShareholdersTableWidget extends BaseWidget
{
    protected static ?int $sort = 32;

    protected int|string|array $columnSpan = 1;

    protected ?string $pollingInterval = '60s';

    protected static ?string $heading = 'Shareholders Summary';

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Shareholders Summary')
            ->query(Holder::query()->with('wallet'))
            ->defaultSort('share', 'desc')
            ->striped()
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('share_percent')
                    ->label('Share %')
                    ->suffix('%')
                    ->badge()
                    ->color('info'),
                TextColumn::make('wallet.balance')
                    ->label('Wallet Balance')
                    ->prefix('KES ')
                    ->numeric(2),
            ])
            ->paginated([5, 10, 25]);
    }
}
