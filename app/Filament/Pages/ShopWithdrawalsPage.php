<?php

namespace App\Filament\Pages;

use App\Livewire\ShopWithdrawalsTable;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Cash withdrawals across every bet shop, read-only, from KadiApi's
 * `GET /bet-shops/withdrawals`, in a {@see ShopWithdrawalsTable}.
 * `?status=pending` opens the pending list, e.g. from the stale withdrawals check.
 */
class ShopWithdrawalsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Shop Withdrawals';

    protected static string|UnitEnum|null $navigationGroup = '🏪 Bet Shops';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'bet-shops/withdrawals';

    protected ?string $heading = 'Shop Withdrawals';

    protected ?string $subheading = 'Cash players asked to collect at bet shops. Only the player and the shop act on these; the GMS never sees pickup codes.';

    protected string $view = 'filament.pages.shop-withdrawals-page';

    #[Url]
    public ?string $status = null;

    public static function canAccess(): bool
    {
        return BetShopsPage::canAccess();
    }

    public static function getUrlForStatus(string $status): string
    {
        return static::getUrl(['status' => $status]);
    }
}
