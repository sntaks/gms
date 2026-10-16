<?php

namespace App\Filament\Resources\WalletTransactions;

use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Resources\WalletTransactions\Tables\WalletTransactionsTable;
use App\Models\WalletTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = '🤝 Shareholders';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermissionTo('wallet-transactions.view') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return WalletTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletTransactions::route('/'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
