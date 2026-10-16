<?php

namespace App\Filament\Resources\CompanyWithdraws;

use App\Filament\Resources\CompanyWithdraws\Pages\CreateCompanyWithdraw;
use App\Filament\Resources\CompanyWithdraws\Pages\EditCompanyWithdraw;
use App\Filament\Resources\CompanyWithdraws\Pages\ListCompanyWithdraws;
use App\Filament\Resources\CompanyWithdraws\Schemas\CompanyWithdrawForm;
use App\Filament\Resources\CompanyWithdraws\Tables\CompanyWithdrawsTable;
use App\Models\CompanyWithdraw;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class CompanyWithdrawResource extends Resource
{
    protected static ?string $model = CompanyWithdraw::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = '📊 Finance';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return CompanyWithdrawForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanyWithdrawsTable::configure($table);
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
            'index' => ListCompanyWithdraws::route('/'),
            // 'create' => CreateCompanyWithdraw::route('/create'),
            // 'edit' => EditCompanyWithdraw::route('/{record}/edit'),
        ];
    }
}
