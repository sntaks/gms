<?php

namespace App\Filament\Resources\Payouts;

use App\Enums\PayoutStatus;
use App\Filament\Resources\Payouts\Pages\CreatePayout;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Resources\Payouts\Schemas\PayoutForm;
use App\Filament\Resources\Payouts\Schemas\PayoutInfolist;
use App\Filament\Resources\Payouts\Tables\PayoutsTable;
use App\Models\Payout;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|UnitEnum|null $navigationGroup = '💸 Expenses & Payouts';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return PayoutForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PayoutInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayoutsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('status', PayoutStatus::Pending)->count();

        return $pending ? number_format($pending) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Payouts pending approval';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayouts::route('/'),
            // 'create' => CreatePayout::route('/create'),
        ];
    }
}
