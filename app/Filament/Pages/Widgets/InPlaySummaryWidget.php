<?php

namespace App\Filament\Pages\Widgets;

use App\Filament\Pages\SportsbookBetsPage;
use App\Filament\Pages\SportsbookInPlayPage;
use App\Models\Site\SbBet;
use App\Models\Site\SbFixture;
use App\Support\SportsbookFormat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Header widget for {@see SportsbookInPlayPage}: live matches, how many have
 * stale live prices, bets held for the live delay, and in-play bets the site's
 * re-check rejected in the last hour. Lives outside the auto-discovered widgets
 * directory so it never lands on the dashboard.
 */
class InPlaySummaryWidget extends BaseWidget
{
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $fixtures = SportsbookInPlayPage::liveFixtures()->get();
        $unhealthy = $fixtures->filter(fn (SbFixture $fixture): bool => in_array(SportsbookFormat::feedHealth(SportsbookInPlayPage::priceAgeSeconds($fixture)), ['stale', 'none'], true))->count();
        $held = SbBet::query()->where('status', 'pending')->whereNotNull('accept_after')->count();
        $rejected = SbBet::query()
            ->where('product', 'sportsbook_live')
            ->where('status', 'rejected')
            ->where('placed_at', '>=', now()->utc()->subHour())
            ->count();

        return [
            Stat::make('Live matches', (string) $fixtures->count()),
            Stat::make('Stale live prices', (string) $unhealthy)
                ->description('newest price '.config('sportsbook.in_play.feed_alert_seconds').' s old or more, or none yet')
                ->color($unhealthy > 0 ? 'danger' : 'success'),
            Stat::make('Held for the live delay', (string) $held)
                ->url(SportsbookBetsPage::getUrl(['tab' => 'pending'])),
            Stat::make('Rejected in play, last hour', (string) $rejected)
                ->description('failed the re-check after the delay')
                ->color($rejected > 0 ? 'warning' : 'gray')
                ->url(SportsbookBetsPage::getUrl(['tab' => 'rejected'])),
        ];
    }
}
