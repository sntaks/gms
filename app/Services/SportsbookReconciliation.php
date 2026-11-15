<?php

namespace App\Services;

use App\Exceptions\GameApiException;
use App\Exceptions\SiteBackofficeException;
use App\Support\SportsbookFormat;
use Illuminate\Support\Carbon;

/**
 * One Nairobi day of the Kadi site's sportsbook against the Kadi API's own figures.
 *
 * Site side: `GET backoffice/sports/reconciliation?date=` (kadi SportsController).
 * API side: `GET /finance/house-banked?from=&to=&exclude_test=0`, per product.
 * Both count settlements made that day, so these are compared:
 *
 * - won + lost bets (site `settled`) with the API's `bets`;
 * - void + cancelled bets, or refunded jackpot entries, with the API's `voids`;
 * - site payouts (winnings plus multi-bet bonus, or jackpot prizes, before tax) with the
 *   API's payouts + bonuses + free_bet_winnings;
 * - winnings tax with the API's winnings_tax_withheld.
 *
 * Stakes are shown but not compared: the site counts bets placed that day, the API cash
 * stakes settled that day. Test customers are included, since the site has none.
 *
 * Until the site splits in-play bets out (source gap G1), its `sportsbook` entry is
 * compared with the API's `sportsbook` and `sportsbook_live` together.
 */
class SportsbookReconciliation
{
    /**
     * Site product => the API products it covers.
     *
     * @var array<string, list<string>>
     */
    public const PRODUCTS = [
        'sportsbook' => ['sportsbook', 'sportsbook_live'],
        'sportsbook_jackpot' => ['sportsbook_jackpot'],
    ];

    public const PRODUCT_LABELS = [
        'sportsbook' => 'Sports bets (pre-match and in play)',
        'sportsbook_jackpot' => 'Jackpot entries',
    ];

    public const LIVE_SPLIT_WARNING = 'The Kadi site does not report in-play bets apart yet, so its sports bets are compared with the Kadi API\'s sportsbook and sportsbook_live products together.';

    public function __construct(
        protected SiteBackofficeClient $site,
        protected GameApiService $api,
    ) {}

    /**
     * @return array{
     *     date: string,
     *     rows: list<array{key: string, product: string, measure: string, site: string, api: string, difference: string|null, status: string}>,
     *     needs_operator: array{ops: list<array<string, mixed>>, pending_bets: list<string>, pending_jackpot_entries: list<string>},
     *     warnings: list<string>,
     *     errors: list<string>
     * }
     */
    public function forDay(string $date): array
    {
        $date = Carbon::parse($date, config('app.timezone'))->toDateString();
        $errors = [];

        try {
            $site = $this->site->get('reconciliation', ['date' => $date])->data;
        } catch (SiteBackofficeException $e) {
            $site = null;
            $errors[] = 'Kadi site: '.$e->getMessage();
        }

        try {
            $api = $this->api->financeReport('house-banked', ['from' => $date, 'to' => $date, 'exclude_test' => false]);
        } catch (GameApiException $e) {
            $api = null;
            $errors[] = 'Kadi API: '.($e->apiMessage !== '' ? $e->apiMessage : $e->getMessage());
        }

        $result = [
            'date' => $date,
            'rows' => [],
            'needs_operator' => [
                'ops' => array_values($site['needs_operator']['ops'] ?? []),
                'pending_bets' => array_values($site['needs_operator']['pending_bets'] ?? []),
                'pending_jackpot_entries' => array_values($site['needs_operator']['pending_jackpot_entries'] ?? []),
            ],
            'warnings' => [],
            'errors' => $errors,
        ];

        if ($site === null || $api === null) {
            return $result;
        }

        $apiByProduct = collect($api['by_product'] ?? [])->keyBy('product');

        if ($apiByProduct->has('sportsbook_live')) {
            $result['warnings'][] = self::LIVE_SPLIT_WARNING;
        }

        foreach (self::PRODUCTS as $siteProduct => $apiProducts) {
            $siteFigures = $site['products'][$siteProduct] ?? [];
            $apiFigures = collect($apiProducts)->map(fn (string $product): array => $apiByProduct->get($product, []));
            $sum = fn (string $figure): float => (float) $apiFigures->sum(fn (array $row): float => (float) ($row[$figure] ?? 0));
            $settled = $siteFigures['settled'] ?? [];
            $isJackpot = $siteProduct === 'sportsbook_jackpot';
            $label = self::PRODUCT_LABELS[$siteProduct];

            $result['rows'][] = $this->countRow($siteProduct, $label, 'Settled won or lost',
                (int) ($settled['won'] ?? 0) + (int) ($settled['lost'] ?? 0), (int) $sum('bets'));

            $result['rows'][] = $this->countRow($siteProduct, $label, $isJackpot ? 'Refunded' : 'Settled void or cancelled',
                $isJackpot ? (int) ($settled['refunded'] ?? 0) : (int) ($settled['void'] ?? 0) + (int) ($settled['cancelled'] ?? 0), (int) $sum('voids'));

            $result['rows'][] = $this->moneyRow($siteProduct, $label, $isJackpot ? 'Prizes (before tax)' : 'Payouts incl. bonus (before tax)',
                (int) ($siteFigures[$isJackpot ? 'prizes' : 'payouts'] ?? 0), $sum('payouts') + $sum('bonuses') + $sum('free_bet_winnings'));

            $result['rows'][] = $this->moneyRow($siteProduct, $label, 'Winnings tax',
                (int) ($siteFigures['tax'] ?? 0), $sum('winnings_tax_withheld'));

            $siteStakes = $isJackpot ? (int) ($siteFigures['stakes'] ?? 0) : (int) ($siteFigures['cash_stakes'] ?? 0);
            $result['rows'][] = [
                'key' => "{$siteProduct}.stakes",
                'product' => $label,
                'measure' => 'Stakes (site: placed that day; API: settled that day)',
                'site' => SportsbookFormat::cents($siteStakes),
                'api' => SportsbookFormat::cents($this->cents($sum('turnover'))),
                'difference' => null,
                'status' => 'info',
            ];
        }

        return $result;
    }

    /**
     * @return array{key: string, product: string, measure: string, site: string, api: string, difference: string|null, status: string}
     */
    protected function countRow(string $product, string $label, string $measure, int $site, int $api): array
    {
        return [
            'key' => $product.'.'.str($measure)->slug(),
            'product' => $label,
            'measure' => $measure,
            'site' => number_format($site),
            'api' => number_format($api),
            'difference' => $site === $api ? null : sprintf('%+d', $site - $api),
            'status' => $site === $api ? 'match' : 'differs',
        ];
    }

    /**
     * @return array{key: string, product: string, measure: string, site: string, api: string, difference: string|null, status: string}
     */
    protected function moneyRow(string $product, string $label, string $measure, int $siteCents, float $apiKes): array
    {
        $apiCents = $this->cents($apiKes);

        return [
            'key' => $product.'.'.str($measure)->slug(),
            'product' => $label,
            'measure' => $measure,
            'site' => SportsbookFormat::cents($siteCents),
            'api' => SportsbookFormat::cents($apiCents),
            'difference' => $siteCents === $apiCents ? null : ($siteCents > $apiCents ? '+' : '−').SportsbookFormat::cents(abs($siteCents - $apiCents)),
            'status' => $siteCents === $apiCents ? 'match' : 'differs',
        ];
    }

    protected function cents(float $kes): int
    {
        return (int) round($kes * 100);
    }
}
