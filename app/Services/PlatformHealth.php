<?php

namespace App\Services;

use App\Enums\PayoutStatus;
use App\Filament\Pages\SportsbookInPlayPage;
use App\Filament\Pages\SportsbookWagerOpsPage;
use App\Filament\Pages\WithdrawalApprovalsPage;
use App\Models\Payout;
use App\Models\Site\SbBet;
use App\Models\Site\SbFixture;
use App\Models\Site\SbWagerOp;
use App\Support\SportsbookFormat;

/**
 * What GMS depends on, checked now: the Kadi API, the Kadi site's signed back
 * office and its read-only database, and the backlogs staff must act on. Each
 * check stands alone, so one system being down never hides the others.
 *
 * Two things cannot be checked from GMS: the site's own platform:health results
 * (source gap G3) and the wallet webhook's deliveries (source gap G9).
 */
class PlatformHealth
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const FAILING = 'failing';

    public const UNKNOWN = 'unknown';

    /**
     * Bets still being checked this long after placing need a person (kadi sportsbook.wagers.pending_alert_minutes).
     */
    public const PENDING_PLACEMENT_MINUTES = 10;

    public function __construct(
        protected GameApiService $api,
        protected SiteBackofficeClient $site,
    ) {}

    /**
     * @return list<array{key: string, group: string, label: string, status: string, value: string, detail: string|null}>
     */
    public function checks(): array
    {
        return [
            $this->check('kadi_api', 'Connections', 'Kadi API', fn (): array => $this->kadiApi()),
            $this->check('site_backoffice', 'Connections', 'Kadi site back office (signed)', fn (): array => $this->siteBackoffice()),
            $this->check('site_database', 'Connections', 'Kadi site database (read-only)', fn (): array => $this->siteDatabase()),
            $this->check('wager_ops', 'Sportsbook', 'Wager ops needing attention', fn (): array => $this->wagerOps()),
            $this->check('pending_bets', 'Sportsbook', 'Bets still being checked', fn (): array => $this->pendingBets()),
            $this->check('live_feed', 'Sportsbook', 'Live prices', fn (): array => $this->liveFeed()),
            $this->check('held_withdrawals', 'Money', 'Held withdrawals', fn (): array => $this->heldWithdrawals()),
            $this->check('unmatched_deposits', 'Money', 'Unmatched deposits', fn (): array => $this->unmatchedDeposits()),
            $this->check('payout_expenses', 'Money', 'Payouts the Kadi API books refused', fn (): array => $this->payoutExpenses()),
            $this->unavailable('site_health', 'Not readable from GMS', 'Kadi site platform:health', 'The site keeps no readable record of its own health checks (source gap G3).'),
            $this->unavailable('wallet_webhook', 'Not readable from GMS', 'Wallet webhook deliveries', 'The Kadi API has no read for its wallet webhook deliveries or failures (source gap G9).'),
        ];
    }

    /**
     * @param  callable(): array{0: string, 1: string, 2?: string|null}  $run  [status, value, detail]
     * @return array{key: string, group: string, label: string, status: string, value: string, detail: string|null}
     */
    protected function check(string $key, string $group, string $label, callable $run): array
    {
        try {
            [$status, $value, $detail] = [...$run(), null, null];
        } catch (\Throwable $e) {
            [$status, $value, $detail] = [self::FAILING, 'Could not check', $e->getMessage()];
        }

        return compact('key', 'group', 'label', 'status', 'value', 'detail');
    }

    /**
     * @return array{key: string, group: string, label: string, status: string, value: string, detail: string|null}
     */
    protected function unavailable(string $key, string $group, string $label, string $detail): array
    {
        return ['key' => $key, 'group' => $group, 'label' => $label, 'status' => self::UNKNOWN, 'value' => 'Not available', 'detail' => $detail];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function kadiApi(): array
    {
        $started = microtime(true);
        $games = $this->api->listGames();
        $ms = (int) round((microtime(true) - $started) * 1000);

        return [$ms > 3000 ? self::WARNING : self::OK, 'Answering in '.$ms.' ms', count($games).' games in the catalogue'];
    }

    /**
     * A signed read, so this also proves the shared secret and the clocks agree.
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function siteBackoffice(): array
    {
        if (! $this->site->isConfigured()) {
            return [self::FAILING, 'Not set up', 'KADI_SITE_BACKOFFICE_URL or KADI_SITE_BACKOFFICE_SECRET is empty in GMS.'];
        }

        $credits = $this->site->get('odds-api/credits')->data;
        $remaining = (int) ($credits['remaining'] ?? 0);
        $reserve = (int) ($credits['reserve'] ?? 0);

        return [
            $remaining <= $reserve ? self::WARNING : self::OK,
            'Signed requests accepted',
            number_format($remaining).' Odds API credits left this month (reserve '.number_format($reserve).')',
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function siteDatabase(): array
    {
        $fixtures = SbFixture::query()->count();

        return [self::OK, 'Readable', number_format($fixtures).' fixtures'];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function wagerOps(): array
    {
        $failed = SbWagerOp::query()->where('status', 'failed')->count();
        $needing = SportsbookWagerOpsPage::navigationBadgeCount();

        return [
            $failed > 0 ? self::FAILING : ($needing > 0 ? self::WARNING : self::OK),
            $needing === 0 ? 'None' : number_format($needing).' ('.number_format($failed).' failed)',
            'Settlements and reversals the site has not got through to the Kadi API.',
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function pendingBets(): array
    {
        $stuck = SbBet::query()
            ->where('status', 'pending')
            ->where('placed_at', '<', now()->utc()->subMinutes(self::PENDING_PLACEMENT_MINUTES))
            ->count();

        return [$stuck > 0 ? self::WARNING : self::OK, $stuck === 0 ? 'None stuck' : number_format($stuck).' stuck', 'Placements still unknown '.self::PENDING_PLACEMENT_MINUTES.' minutes after the player placed them.'];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function liveFeed(): array
    {
        $live = SportsbookInPlayPage::liveFixtures()->get();

        if ($live->isEmpty()) {
            return [self::OK, 'No live matches', null];
        }

        $stale = $live->filter(fn (SbFixture $fixture): bool => in_array(SportsbookFormat::feedHealth(SportsbookInPlayPage::priceAgeSeconds($fixture)), ['stale', 'none'], true))->count();

        return [
            $stale > 0 ? self::WARNING : self::OK,
            $stale === 0 ? 'Fresh' : $stale.' of '.$live->count().' stale',
            $live->count().' live matches; stale means no live price for '.config('sportsbook.in_play.feed_alert_seconds').' seconds.',
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function heldWithdrawals(): array
    {
        $page = $this->api->listPendingWithdrawalApprovals(['per_page' => 200]);
        $items = collect($page['items'] ?? []);
        $total = (int) ($page['pagination']['total'] ?? $items->count());
        $overdue = $items->filter(fn ($item): bool => is_array($item) && WithdrawalApprovalsPage::isOverdue($item))->count();

        return [
            $overdue > 0 ? self::WARNING : self::OK,
            $total === 0 ? 'None waiting' : number_format($total).' waiting',
            $overdue > 0 ? $overdue.' held over '.WithdrawalApprovalsPage::OVERDUE_HOURS.' hours.' : null,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function unmatchedDeposits(): array
    {
        $summary = $this->api->getUnmatchedDepositsSummary();
        $count = (int) ($summary['unmatched_count'] ?? 0);

        return [
            $count > 0 ? self::WARNING : self::OK,
            $count === 0 ? 'None' : number_format($count).' waiting',
            $count === 0 ? null : 'KES '.number_format((float) ($summary['unmatched_amount'] ?? 0), 2).' in paybill payments not yet credited to anyone.',
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    protected function payoutExpenses(): array
    {
        // Only payouts whose recording was tried and failed: those paid before recording existed have no error.
        $failed = Payout::query()
            ->where('status', PayoutStatus::Completed)
            ->whereNull('api_expense_id')
            ->whereNotNull('api_expense_error')
            ->count();

        return [$failed > 0 ? self::WARNING : self::OK, $failed === 0 ? 'None failed' : number_format($failed).' failed', $failed === 0 ? null : 'Retry them from the payouts list.'];
    }
}
