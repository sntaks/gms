<?php

namespace App\Filament\Resources\ApiIncomeLogs;

use App\Filament\Resources\ApiIncomeLogs\Pages\ListApiIncomeLogs;
use App\Filament\Resources\ApiIncomeLogs\Tables\ApiIncomeLogsTable;
use App\Models\ApiIncomeLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ApiIncomeLogResource extends Resource
{
    protected static ?string $model = ApiIncomeLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;

    protected static string|UnitEnum|null $navigationGroup = '🤝 Shareholders';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermissionTo('api-income-logs.view') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return ApiIncomeLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiIncomeLogs::route('/'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
