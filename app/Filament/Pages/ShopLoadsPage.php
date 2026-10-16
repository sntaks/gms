<?php

namespace App\Filament\Pages;

use App\Livewire\ShopLoadsTable;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Cash loads across every bet shop, from KadiApi's `GET /bet-shops/loads`, in a
 * {@see ShopLoadsTable}. `?load={id}` opens one load straight away,
 * e.g. from an excise duty charge.
 */
class ShopLoadsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Shop Loads';

    protected static string|UnitEnum|null $navigationGroup = '🏪 Bet Shops';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'bet-shops/loads';

    protected ?string $heading = 'Shop Loads';

    protected ?string $subheading = 'Cash players paid in at bet shops. The wallet gets the amount less 5% excise duty; the shop float pays the full amount.';

    protected string $view = 'filament.pages.shop-loads-page';

    #[Url]
    public ?int $load = null;

    public static function canAccess(): bool
    {
        return BetShopsPage::canAccess();
    }
}
