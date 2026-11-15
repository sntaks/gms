<?php

namespace App\Filament\Pages;

use App\Services\PlatformHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Whether what GMS depends on is working: the Kadi API, the Kadi site's signed
 * back office and read-only database, and the backlogs staff must act on.
 * Needs `health.view`.
 */
class PlatformHealthPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'Platform health';

    protected static string|UnitEnum|null $navigationGroup = '⚙️ Administration';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'platform-health';

    protected ?string $heading = 'Platform health';

    protected ?string $subheading = 'Checked when the page opens. Refresh to check again.';

    protected string $view = 'filament.pages.platform-health-page';

    /**
     * @var list<array{key: string, group: string, label: string, status: string, value: string, detail: string|null}>
     */
    public array $checks = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermissionTo('health.view') ?? false;
    }

    public function mount(): void
    {
        $this->runChecks();
    }

    public function runChecks(): void
    {
        $this->checks = app(PlatformHealth::class)->checks();
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            PlatformHealth::OK => 'success',
            PlatformHealth::WARNING => 'warning',
            PlatformHealth::FAILING => 'danger',
            default => 'gray',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            PlatformHealth::OK => 'OK',
            PlatformHealth::WARNING => 'Needs attention',
            PlatformHealth::FAILING => 'Failing',
            default => 'Unknown',
        };
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Check again')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->runChecks()),
        ];
    }
}
