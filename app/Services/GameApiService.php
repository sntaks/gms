<?php

namespace App\Services;

use App\Exceptions\GameApiException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GameApiService
{
    public const UNMATCHED_SUMMARY_CACHE_KEY = 'game_api:deposits:unmatched:summary';

    protected string $baseUrl;

    protected string $apiKey;

    protected string $encKey;

    protected string $encMethod;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.game_api.url', ''), '/');
        $this->apiKey = config('services.game_api.key', '');
        $this->encKey = config('services.game_api.openssl_key', '');
        $this->encMethod = config('services.game_api.openssl_method', 'AES-128-CBC');
    }

    // -------------------------------------------------------------------------
    // Core infrastructure
    // -------------------------------------------------------------------------

    /**
     * Encrypts a plain identifier using AES-256-CBC + base64url, exactly as the
     * wallet API expects. This is done locally to avoid a round-trip to /encrypt.
     */
    public function encryptId(int|string $id): string
    {
        $ivLength = openssl_cipher_iv_length($this->encMethod);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $encrypted = openssl_encrypt((string) $id, $this->encMethod, $this->encKey, 0, $iv);
        $combined = $iv.$encrypted;

        return rtrim(strtr(base64_encode($combined), '+/', '-_'), '=');
    }

    /**
     * Authenticated request to the wallet API.
     *
     * @throws GameApiException on 4xx/5xx responses
     */
    protected function makeRequest(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        int $timeout = 8,
        int $connectTimeout = 5,
        ?string $idempotencyKey = null,
        bool $readOnly = false,
        bool $withIdempotencyKey = true,
    ): array {
        $url = $this->baseUrl.$path;

        $headers = [
            'X-API-KEY' => $this->apiKey,
            'Accept' => 'application/json',
        ];

        // Read-only POSTs (leaderboards, stats, lookups) are not mutations, so they only
        // carry an Idempotency-Key when the caller explicitly supplies one. A write whose
        // response must never be stored (issuing a shop key) opts out entirely.
        $isMutation = strtoupper($method) !== 'GET' && ! $readOnly;

        if ($withIdempotencyKey && ($isMutation || $idempotencyKey !== null)) {
            $headers['Idempotency-Key'] = $idempotencyKey ?? Str::uuid()->toString();
        }

        $pending = Http::withHeaders($headers)->timeout($timeout)->connectTimeout($connectTimeout);

        // Reads are safe to retry once on a transient failure; mutations never are.
        if (strtoupper($method) === 'GET') {
            $pending = $pending->retry(2, 300, fn ($exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url, $query ?: null),
                'POST' => $pending->post($url, $body),
                'PUT' => $pending->put($url, $body),
                'PATCH' => $pending->patch($url, $body),
                'DELETE' => $pending->delete($url, $body),
                default => throw new GameApiException("Unsupported HTTP method: {$method}"),
            };
        } catch (ConnectionException $e) {
            Log::error('GameAPI unreachable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new GameApiException('Game API is unreachable. Please try again later.', 0, $e->getMessage());
        }

        // BUG B5: STK push (and possibly others) can return null/empty body on exception.
        $decoded = $response->json() ?? [];

        if ($response->failed()) {
            $apiMessage = $decoded['message'] ?? $decoded['error'] ?? $decoded['status'] ?? '';
            $statusCode = $response->status();

            $retryAfter = null;

            if ($statusCode === 429) {
                $retryAfterHeader = $response->header('Retry-After');
                $retryAfter = is_numeric($retryAfterHeader) ? (int) $retryAfterHeader : null;
                $apiMessage = 'Rate limit reached. Please retry shortly'.($retryAfterHeader !== '' ? " (in {$retryAfterHeader}s)." : '.');
            }

            Log::error('GameAPI request failed', [
                'method' => $method,
                'path' => $path,
                'status' => $statusCode,
                'api_message' => $apiMessage,
            ]);

            throw new GameApiException(
                "Game API error {$statusCode}: {$apiMessage}",
                $statusCode,
                $apiMessage,
                is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [],
                is_string($decoded['code'] ?? null) ? $decoded['code'] : null,
                is_array($decoded) ? $decoded : [],
                $retryAfter,
            );
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // HIGH PRIORITY — TASK-001 to TASK-016
    // -------------------------------------------------------------------------

    // TASK-001: auth is handled in makeRequest via X-API-KEY header.

    /**
     * TASK-002: Encrypt ID via the API's own endpoint (fallback / one-off use only).
     * Prefer the local encryptId() method — it's faster and avoids a network round-trip.
     */
    public function encryptIdViaApi(string $plainId, ?string $idempotencyKey = null): string
    {
        return $this->makeRequest('POST', '/encrypt', ['identifier' => $plainId], idempotencyKey: $idempotencyKey, readOnly: true)['encrypted_id'] ?? '';
    }

    /**
     * TASK-003: Fetch a single customer by their API customer ID.
     * Endpoint: GET /api/v1/customers/{enc}
     */
    public function getCustomer(int|string $customerId): array
    {
        $enc = $this->encryptId((string) $customerId);

        return $this->makeRequest('GET', "/customers/{$enc}")['data'] ?? [];
    }

    /**
     * TASK-003: Fetch a customer by their plain account_no (no encryption needed —
     * the API's DecryptIdentifier middleware accepts plain account_no).
     * Endpoint: GET /api/v1/customers/{enc}
     */
    public function getCustomerByAccount(string $accountNo): array
    {
        return $this->makeRequest('GET', "/customers/{$accountNo}")['data'] ?? [];
    }

    /**
     * TASK-004: List all active customers.
     * Endpoint: GET /api/v1/customers
     *
     * BUG B1: Hard-coded filter `created_at >= 2026-03-01` on the API side.
     * Customers registered before that date will not appear here.
     * Use searchCustomers() for full-history lookups.
     */
    public function listCustomers(): array
    {
        return $this->makeRequest('GET', '/customers')['data'] ?? [];
    }

    /**
     * TASK-004: Search customers by name, account_no, phone, or email (LIKE search).
     * Endpoint: GET /api/v1/customers/search?q={query}
     */
    public function searchCustomers(string $query): array
    {
        return $this->makeRequest('GET', '/customers/search', [], ['q' => $query]);
    }

    /**
     * TASK-005: Create a new customer (auto-creates wallet with 250 KES balance).
     * Endpoint: POST /api/v1/customers
     *
     * @param  array{account_no: string, name: string, email: string, id_no?: string, phone_no?: string, referral_code?: string}  $data
     */
    public function createCustomer(array $data, ?string $idempotencyKey = null): array
    {
        $key = $idempotencyKey ?? 'customer-create-'.($data['account_no'] ?? $data['google_id'] ?? Str::uuid()->toString());

        return $this->makeRequest('POST', '/customers', $data, idempotencyKey: $key);
    }

    /**
     * TASK-006: Fetch a wallet by its wallet ID.
     * Endpoint: GET /api/v1/wallets/{enc}
     */
    public function getWallet(int $walletId): array
    {
        $enc = $this->encryptId((string) $walletId);

        return $this->makeRequest('GET', "/wallets/{$enc}");
    }

    /**
     * TASK-006: List all wallets with customer info.
     * Endpoint: GET /api/v1/wallets
     */
    public function listWallets(): array
    {
        return $this->makeRequest('GET', '/wallets');
    }

    /**
     * TASK-007: Create a new game wallet for a game round.
     * Endpoint: POST /api/v1/game/wallets
     */
    public function createGameWallet(string $gameId, int $gameType = 1, ?string $idempotencyKey = null): array
    {
        return $this->makeRequest('POST', '/game/wallets', [
            'game_id' => $gameId,
            'game_type' => $gameType,
        ], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-008: Place a bet — deducts from customer wallet and adds to game pot.
     * Endpoint: POST /api/v1/game/bets
     *
     * @throws GameApiException with "Insufficient balance" message on HTTP 400
     */
    public function placeBet(int $gameWalletId, int $customerId, float $amount, ?string $idempotencyKey = null): array
    {
        try {
            return $this->makeRequest('POST', '/game/bets', [
                'game_wallet_id' => $gameWalletId,
                'customer_id' => $customerId,
                'payment_type' => 'deposit',
                'amount' => $amount,
            ], idempotencyKey: $idempotencyKey);
        } catch (GameApiException $e) {
            if ($e->statusCode === 400) {
                throw new GameApiException('Insufficient balance', 400, 'Insufficient balance');
            }
            throw $e;
        }
    }

    /**
     * TASK-009: Pay out the game pot — 90% to winner, 10% to house. Closes the game wallet.
     * Endpoint: POST /api/v1/game/withdraw/{enc}
     *
     * Note: customer_id must be cast to string in the request body (API requirement).
     */
    public function payoutGame(int $gameWalletId, int $winnerId, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $gameWalletId);

        return $this->makeRequest('POST', "/game/withdraw/{$enc}", [
            'customer_id' => (string) $winnerId,
        ], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-010: Handle a player disconnect / drop event.
     * Endpoint: POST /api/v1/game/drop/{enc}
     *
     * Note: `game` param is int 1/0 (not bool) — converted here from the bool argument.
     */
    public function handlePlayerDrop(
        int $gameWalletId,
        array $players,
        array $active,
        array $dropped,
        bool $gameStarted = true,
        ?string $idempotencyKey = null
    ): array {
        $enc = $this->encryptId((string) $gameWalletId);

        return $this->makeRequest('POST', "/game/drop/{$enc}", [
            'players' => $players,
            'active' => $active,
            'dropped' => $dropped,
            'game' => $gameStarted ? 1 : 0,
        ], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-011: Create a competition wallet entry (enroll a player in a tournament/jackpot).
     * Endpoint: POST /api/v1/competition/wallets
     *
     * Note: jp_rounds must be 13, 17, or 21 for jackpot payout tiers to work correctly.
     *
     * @param  array{competition_id: string, cmp_uid: string, game_type: int, customer_id: int, jp_rounds: int}  $data
     */
    public function createCompetitionWallet(array $data, ?string $idempotencyKey = null): array
    {
        return $this->makeRequest('POST', '/competition/wallets', $data, idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-012: Record a player's competition entry (deducts from wallet, loads competition wallet).
     * Endpoint: POST /api/v1/competition/transactions
     * Tournament: 85% to comp wallet, 15% to house. Jackpot: 80% to comp wallet, 20% to house.
     */
    public function recordCompetitionEntry(int $competitionWalletId, int $customerId, float $amount, ?string $idempotencyKey = null): array
    {
        return $this->makeRequest('POST', '/competition/transactions', [
            'competition_wallet_id' => $competitionWalletId,
            'customer_id' => $customerId,
            'payment_type' => 'deposit',
            'amount' => $amount,
        ], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-013: Process a competition match result (loser → winner balance transfer).
     * Endpoint: POST /api/v1/competition/payout
     */
    public function processCompetitionMatchResult(int $loserWalletId, int $winnerWalletId, ?string $idempotencyKey = null): array
    {
        return $this->makeRequest('POST', '/competition/payout', [
            'sender_competition_wallet_id' => $loserWalletId,
            'receiver_competition_wallet_id' => $winnerWalletId,
        ], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-014: Pay out the competition winner's balance to their main wallet.
     * Endpoint: POST /api/v1/competition/withdraw/{enc}
     */
    public function withdrawCompetitionWinnings(int $competitionWalletId, int $customerId, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $competitionWalletId);

        return $this->makeRequest('POST', "/competition/withdraw/{$enc}", [
            'customer_id' => (string) $customerId,
        ], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-015: Trigger an M-Pesa STK Push to deposit funds.
     * Endpoint: POST /api/v1/deposits/{enc}  (rate limited: 10/min)
     *
     * BUG B5: This endpoint can return a null body on exception — handled in makeRequest()
     * by defaulting to []. The actual wallet credit happens asynchronously via C2B callback.
     */
    public function triggerStkPush(int $customerId, float $amount, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $customerId);

        return $this->makeRequest('POST', "/deposits/{$enc}", [
            'amount' => (int) $amount,
        ], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-016: Trigger an M-Pesa STK Push for an in-app purchase (load/gift/emoji).
     * Endpoint: POST /api/v1/load/{enc}  (rate limited: 10/min)
     */
    public function triggerStkLoad(
        int $customerId,
        float $amount,
        string $type,
        float $coinValue = 0,
        string $phoneNo = '',
        string $referralCode = '',
        ?string $idempotencyKey = null
    ): array {
        $enc = $this->encryptId((string) $customerId);
        $body = ['amount' => $amount, 'type' => $type];

        if ($coinValue > 0) {
            $body['coin_value'] = $coinValue;
        }
        if ($phoneNo !== '') {
            $body['phone_no'] = $phoneNo;
        }
        if ($referralCode !== '') {
            $body['referral_code'] = $referralCode;
        }

        return $this->makeRequest('POST', "/load/{$enc}", $body, idempotencyKey: $idempotencyKey);
    }

    // -------------------------------------------------------------------------
    // MEDIUM PRIORITY — TASK-017 to TASK-029
    // -------------------------------------------------------------------------

    /**
     * TASK-017: Fetch all four dashboard stat endpoints in parallel and merge into one array.
     * Endpoints: GET /stats/customers, /stats/income, /stats/played, /stats/purchases
     */
    public function getDashboardStats(): array
    {
        $headers = ['X-API-KEY' => $this->apiKey, 'Accept' => 'application/json'];
        $base = $this->baseUrl;

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('customer')->withHeaders($headers)->timeout(8)->connectTimeout(5)->get("{$base}/stats/customers"),
            $pool->as('income')->withHeaders($headers)->timeout(8)->connectTimeout(5)->get("{$base}/stats/income"),
            $pool->as('played')->withHeaders($headers)->timeout(8)->connectTimeout(5)->get("{$base}/stats/played"),
            $pool->as('purchases')->withHeaders($headers)->timeout(8)->connectTimeout(5)->get("{$base}/stats/purchases"),
        ]);

        $safe = fn (string $key) => ! ($responses[$key] instanceof \Throwable) && ! $responses[$key]->failed()
            ? ($responses[$key]->json('data') ?? [])
            : [];

        return [
            'customer' => $safe('customer'),
            'income' => $safe('income'),
            'played' => $safe('played'),
            'purchases' => $safe('purchases'),
        ];
    }

    /**
     * TASK-018: Fetch player retention stats.
     * Endpoint: GET /api/v1/stats/retention
     */
    public function getRetentionStats(): array
    {
        return $this->makeRequest('GET', '/stats/retention')['data'] ?? [];
    }

    /**
     * Fetch daily income stats for the last 30 days.
     * Endpoint: GET /api/v1/stats/income/daily-30-days
     * Returns: { start_date, end_date, daily_stats: { "YYYY-MM-DD": { single_games, tournaments, jackpots, total } } }
     *
     * Subject to the `stats` limiter (20/min), so callers should cache.
     */
    public function getDailyIncome(): array
    {
        return $this->makeRequest('GET', '/stats/income/daily-30-days')['data'] ?? [];
    }

    /**
     * Fetch current business day's cumulative income.
     * Endpoint: GET /api/v1/stats/income
     * Returns: { success: true, data: { total_income, games: {total, 2_players, …},
     *            tournaments: {total, 3_rounds, …}, jackpots: {total, 13_rounds, …} } }
     */
    public function getCurrentDayIncome(): array
    {
        return $this->makeRequest('GET', '/stats/income')['data'] ?? [];
    }

    /**
     * TASK-019: Fetch leaderboards for a date range.
     * Endpoint: POST /api/v1/customers/leaderboard
     * Returns: { single_leaderboard: [...], competitions_leaderboard: [...] }
     */
    public function getLeaderboard(string $startDate, string $endDate, ?string $idempotencyKey = null): array
    {
        return $this->makeRequest('POST', '/customers/leaderboard', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], idempotencyKey: $idempotencyKey, readOnly: true);
    }

    /**
     * TASK-019: Fetch the combined leaderboard (single + competition winnings).
     * Endpoint: POST /api/v1/customers/combined-leaderboard
     *
     * The API documents this as a POST (a GET returns 400 "Invalid identifier").
     * Dates are optional. Verified live: the API currently returns the current week's
     * leaderboard whether or not dates are sent.
     * Returns: { leaderboard: [ { id, name, single_game_wins, competition_wins, total_wins } ] }
     */
    public function getCombinedLeaderboard(?string $startDate = null, ?string $endDate = null): array
    {
        return $this->makeRequest('POST', '/customers/combined-leaderboard', array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]), readOnly: true);
    }

    /**
     * TASK-020: Fetch all completed game results.
     * Endpoint: GET /api/v1/game/results
     * Returns: { status: "Success", data: [ { id, game_id, players, total_bet, customer_id, name, amount, income, created_at } ] }
     */
    public function getGameResults(): array
    {
        return $this->makeRequest('GET', '/game/results')['data'] ?? [];
    }

    /**
     * TASK-021: Fetch competition results for a game type.
     * Endpoint: GET /api/v1/competition/results/{enc}  (encrypt the gameType integer)
     *
     * @param  int  $gameType  1 = Tournament, 2 = Jackpot
     */
    public function getCompetitionResults(int $gameType): array
    {
        $enc = $this->encryptId((string) $gameType);

        return $this->makeRequest('GET', "/competition/results/{$enc}")['data'] ?? [];
    }

    /**
     * TASK-022: Fetch competition award winners for a game type.
     * Endpoint: GET /api/v1/competition/awards/{enc}  (encrypt the gameType integer)
     *
     * @param  int  $gameType  1 = Tournament, 2 = Jackpot
     */
    public function getCompetitionAwards(int $gameType): array
    {
        $enc = $this->encryptId((string) $gameType);

        return $this->makeRequest('GET', "/competition/awards/{$enc}")['data'] ?? [];
    }

    /**
     * TASK-023: Purchase coins for a customer (KES 10 = 1 coin, floor division).
     * Endpoint: POST /api/v1/coins/buy/{enc}
     */
    public function buyCoins(int $customerId, float $amount, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $customerId);

        return $this->makeRequest('POST', "/coins/buy/{$enc}", ['amount' => $amount], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-024: Exchange coins back to KES (credits customer's main wallet).
     * Endpoint: PUT /api/v1/coins/exchange/{enc}
     *
     * @param  int|null  $coins  Coins to exchange; omit to exchange all.
     */
    public function exchangeCoins(int $coinWalletId, ?int $coins = null, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $coinWalletId);
        $body = $coins !== null ? ['coins' => $coins] : [];

        return $this->makeRequest('PUT', "/coins/exchange/{$enc}", $body, idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-025: Fetch the last 10 wallet transactions for a customer.
     * Endpoint: POST /api/v1/customers/transactions/{enc}
     *
     * Maps payment_type from PHP class name to human-readable label:
     *   "App\Models\Deposit"        → "deposit"
     *   "App\Models\Withdraw"       → "withdrawal"
     *   "App\Models\BetShopLoad"    → "shop_load" (cash loaded at a bet shop, money in)
     *   "App\Models\ShopWithdrawal" → "shop_withdrawal" (cash collected at a bet shop, money out)
     *
     * @param  string  $type  "deposit" | "withdraw" | "all"
     */
    public function getCustomerTransactions(int $customerId, string $type = 'all', ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $customerId);
        $response = $this->makeRequest('POST', "/customers/transactions/{$enc}", [
            'payment_type' => $type,
        ], idempotencyKey: $idempotencyKey, readOnly: true);

        $typeMap = [
            'App\\Models\\Deposit' => 'deposit',
            'App\\Models\\Withdraw' => 'withdrawal',
            'App\\Models\\BetShopLoad' => 'shop_load',
            'App\\Models\\ShopWithdrawal' => 'shop_withdrawal',
        ];

        $response['transactions'] = array_map(function (array $tx) use ($typeMap): array {
            $tx['payment_type'] = $typeMap[$tx['payment_type']] ?? $tx['payment_type'];

            return $tx;
        }, $response['transactions'] ?? []);

        return $response;
    }

    /**
     * TASK-026: Fetch all game sessions (single, tournament, jackpot) for a customer.
     * Endpoint: GET /api/v1/customers/played/{enc}
     *
     * @param  int  $customerId  The customer ID
     * @param  int|null  $singlePage  Page number for single games
     * @param  int|null  $tournamentPage  Page number for tournament games
     * @param  int|null  $jackpotPage  Page number for jackpot games
     * @param  int  $perPage  Items per page for all game types
     */
    public function getCustomerGamesPlayed(
        int $customerId,
        ?int $singlePage = 1,
        ?int $tournamentPage = 1,
        ?int $jackpotPage = 1,
        int $perPage = 10
    ): array {
        $enc = $this->encryptId((string) $customerId);

        $query = [];
        if ($singlePage !== null) {
            $query['single_page'] = $singlePage;
        }
        if ($tournamentPage !== null) {
            $query['tournament_page'] = $tournamentPage;
        }
        if ($jackpotPage !== null) {
            $query['jackpot_page'] = $jackpotPage;
        }
        if ($perPage !== 10) {
            $query['per_page'] = $perPage;
        }

        return $this->makeRequest('GET', "/customers/played/{$enc}", [], $query);
    }

    /**
     * TASK-027: Fetch referral stats (customers and purchases for given referral codes).
     * Accepts comma-separated codes or an array.
     *
     * @param  string|array<int, string>  $referralCode
     */
    public function getReferralStats(string|array $referralCode): array
    {
        $codes = is_array($referralCode) ? implode(',', $referralCode) : $referralCode;

        return [
            'customers' => $this->makeRequest('POST', '/stats/customers/referrals', ['referral_code' => $codes], readOnly: true),
            'purchases' => $this->makeRequest('POST', '/stats/purchases/referrals', ['referral_code' => $codes], readOnly: true),
            'customer_list' => $this->makeRequest('POST', '/customers/referrals', ['referral_code' => $codes], readOnly: true),
            'purchase_list' => $this->makeRequest('POST', '/purchases/referrals', ['referral_code' => $codes], readOnly: true),
        ];
    }

    /**
     * TASK-027 (partial): Fetch customers by referral code(s).
     * Endpoint: POST /api/v1/customers/referrals
     *
     * @param  string|array<int, string>  $codes
     */
    public function getCustomersByReferral(string|array $codes, ?string $idempotencyKey = null): array
    {
        $referralCode = is_array($codes) ? implode(',', $codes) : $codes;

        return $this->makeRequest('POST', '/customers/referrals', ['referral_code' => $referralCode], idempotencyKey: $idempotencyKey, readOnly: true);
    }

    /**
     * TASK-027 (partial): Fetch purchases by referral code(s).
     * Endpoint: POST /api/v1/purchases/referrals
     *
     * @param  string|array<int, string>  $codes
     */
    public function getPurchasesByReferral(string|array $codes, ?string $idempotencyKey = null): array
    {
        $referralCode = is_array($codes) ? implode(',', $codes) : $codes;

        return $this->makeRequest('POST', '/purchases/referrals', ['referral_code' => $referralCode], idempotencyKey: $idempotencyKey, readOnly: true);
    }

    /**
     * TASK-028: Fetch game session counts for a specific player and date range.
     * Endpoint: POST /api/v1/stats/customers/played
     *
     * Returns nested counts: { total, games: {total, 2_players, ...}, tournament: {...}, jackpots: {...} }.
     */
    public function getPlayerGameStats(int $customerId, string $startDate, string $endDate, ?string $idempotencyKey = null): array
    {
        return $this->makeRequest('POST', '/stats/customers/played', [
            'customer_id' => $customerId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], idempotencyKey: $idempotencyKey, readOnly: true)['data'] ?? [];
    }

    /**
     * TASK-029: Send an OTP code to a phone number.
     * Endpoint: POST /api/v1/customer/send-code
     *
     * BUG B4: SMS sending is stubbed on the API side ($smsSent = true).
     * No real SMS is ever delivered. Track ticket for real SMS provider integration.
     */
    public function sendOtpCode(string $phoneNo, ?string $idempotencyKey = null): array
    {
        return $this->makeRequest('POST', '/customer/send-code', ['phone_no' => $phoneNo], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-029: Verify a customer's phone using the 6-digit OTP.
     * Endpoint: PATCH /api/v1/customers/{enc}/verify-phone
     */
    public function verifyPhone(int $customerId, string $code, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $customerId);

        return $this->makeRequest('PATCH', "/customers/{$enc}/verify-phone", ['code' => $code], idempotencyKey: $idempotencyKey);
    }

    // -------------------------------------------------------------------------
    // LOW PRIORITY — TASK-030 to TASK-037
    // -------------------------------------------------------------------------

    /**
     * TASK-030: Mark a customer's email as verified.
     * Endpoint: GET /api/v1/customers/{enc}/verify-email
     */
    public function verifyEmail(int $customerId): array
    {
        $enc = $this->encryptId((string) $customerId);

        return $this->makeRequest('GET', "/customers/{$enc}/verify-email");
    }

    /**
     * List all player withdrawal requests.
     * Endpoint: GET /api/v1/withdraws
     */
    public function listWithdrawals(): array
    {
        return $this->makeRequest('GET', '/withdraws');
    }

    /**
     * List all non-test in-app purchases.
     * Endpoint: GET /api/v1/purchases
     */
    public function listPurchases(): array
    {
        return $this->makeRequest('GET', '/purchases');
    }

    /**
     * One deposit with its payer, excise split and customer. For lists use the
     * paginated `/finance/deposits` report; `GET /deposits` is unpaginated.
     * Endpoint: GET /api/v1/deposits/{enc}  (rate limited: 20/min)
     *
     * @return array<string, mixed>
     */
    public function getDeposit(int $depositId): array
    {
        $enc = $this->encryptId((string) $depositId);

        return $this->makeRequest('GET', "/deposits/{$enc}")['data'] ?? [];
    }

    /**
     * One page of the unmatched-deposit work queue: `summary`, `items` (each with
     * `suggestions` and, once resolved, `resolution`) and `pagination`.
     * Endpoint: GET /api/v1/deposits/unmatched  (rate limited: 20/min)
     *
     * @param  array<string, mixed>  $filters  status (unmatched|assigned|refunded), from, to, page, per_page
     * @return array<string, mixed>
     */
    public function listUnmatchedDeposits(array $filters = []): array
    {
        $query = collect($filters)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();

        return $this->makeRequest('GET', '/deposits/unmatched', query: $query, timeout: 20)['data'] ?? [];
    }

    /**
     * The unmatched count and amount for the dashboard, cached for a minute because
     * the endpoint shares the 20/min `stats` limiter with the finance reports.
     *
     * @return array{unmatched_count?: int, unmatched_amount?: float}
     */
    public function getUnmatchedDepositsSummary(): array
    {
        return Cache::remember(self::UNMATCHED_SUMMARY_CACHE_KEY, 60, fn (): array => $this->listUnmatchedDeposits(['status' => 'unmatched', 'per_page' => 1])['summary'] ?? []);
    }

    /**
     * Drop the cached unmatched summary after a deposit is assigned, refunded or matched.
     */
    public function forgetUnmatchedDepositsSummary(): void
    {
        Cache::forget(self::UNMATCHED_SUMMARY_CACHE_KEY);
    }

    /**
     * Credit an unmatched deposit's full amount to a customer's wallet, exactly like a
     * plain C2B deposit (ledger entry, 5% excise, referral bonuses). The caller owns the
     * Idempotency-Key so a retry of the same attempt replays instead of crediting twice.
     * Endpoint: POST /api/v1/deposits/{enc}/assign  (rate limited: 30/min)
     *
     * @return array<string, mixed> The resolved deposit.
     *
     * @throws GameApiException 404 deposit or customer not found, 409 no longer unmatched, 422 validation
     */
    public function assignDeposit(int $depositId, int $customerId, string $note, string $idempotencyKey): array
    {
        $enc = $this->encryptId($depositId);

        $deposit = $this->makeRequest('POST', "/deposits/{$enc}/assign", [
            'customer_id' => $customerId,
            'note' => $note,
        ], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetDepositCaches();

        return $deposit;
    }

    /**
     * Record that an unmatched deposit was reversed on the M-Pesa portal. No money moves
     * and no wallet changes; the deposit leaves the unmatched liability.
     * Endpoint: POST /api/v1/deposits/{enc}/refund  (rate limited: 30/min)
     *
     * @return array<string, mixed> The resolved deposit.
     *
     * @throws GameApiException 404 not found, 409 no longer unmatched or reference already used, 422 validation
     */
    public function refundDeposit(int $depositId, string $mpesaReference, string $note, string $idempotencyKey): array
    {
        $enc = $this->encryptId($depositId);

        $deposit = $this->makeRequest('POST', "/deposits/{$enc}/refund", [
            'mpesa_reference' => $mpesaReference,
            'note' => $note,
        ], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetDepositCaches();

        return $deposit;
    }

    /**
     * Assign every unmatched deposit whose bill ref now exactly matches a customer's account
     * number (never a phone). A dry run only previews; the live call needs its own
     * Idempotency-Key so a retry replays instead of assigning again.
     * Endpoint: POST /api/v1/deposits/unmatched/match  (rate limited: 30/min)
     *
     * @return array{dry_run?: bool, matched?: int, matched_amount?: float, assigned?: int, assigned_amount?: float, items?: list<array<string, mixed>>}
     *
     * @throws GameApiException
     */
    public function matchUnmatchedDeposits(bool $dryRun, ?string $idempotencyKey = null): array
    {
        if (! $dryRun && $idempotencyKey === null) {
            throw new GameApiException('A live match needs an Idempotency-Key.', 422, 'A live match needs an Idempotency-Key.');
        }

        $result = $this->makeRequest(
            'POST',
            '/deposits/unmatched/match',
            ['dry_run' => $dryRun],
            timeout: 60,
            idempotencyKey: $idempotencyKey,
            // A dry run changes nothing, so it is sent without a random key.
            readOnly: $dryRun,
        )['data'] ?? [];

        if (! $dryRun) {
            $this->forgetDepositCaches();
        }

        return $result;
    }

    /**
     * A resolved deposit changes the unmatched summary and the finance reports.
     */
    protected function forgetDepositCaches(): void
    {
        $this->forgetUnmatchedDepositsSummary();
        $this->forgetFinanceReports();
    }

    /**
     * TASK-032: Get single-game income analytics grouped by number of players.
     * Endpoint: POST /api/v1/game/income
     */
    public function getGameIncomeBreakdown(string $startDate, string $endDate, ?string $idempotencyKey = null): array
    {
        return $this->makeRequest('POST', '/game/income', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], [], 60, 10, $idempotencyKey, true)['data'] ?? [];
    }

    /**
     * TASK-033: Get competition income analytics grouped by jp_rounds bracket.
     * Endpoint: POST /api/v1/competition/income/{enc}  (encrypt the gameType integer)
     *
     * @param  int  $gameType  1 = Tournament, 2 = Jackpot
     */
    public function getCompetitionIncomeBreakdown(int $gameType, string $startDate, string $endDate, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $gameType);

        return $this->makeRequest('POST', "/competition/income/{$enc}", [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], [], 60, 10, $idempotencyKey, true)['data'] ?? [];
    }

    /**
     * TASK-034: Get total amount deposited into the house wallet today.
     * Endpoint: GET /api/v1/wallets/today
     */
    public function getWalletToday(): float
    {
        return (float) ($this->makeRequest('GET', '/wallets/today')['amount'] ?? 0);
    }

    /**
     * TASK-035: Transfer funds from one wallet to another.
     * Endpoint: POST /api/v1/wallets/transfer/{enc}
     *
     * @param  int  $toWalletId  Defaults to 1 (house wallet).
     */
    public function transferWallet(int $fromWalletId, float $amount, int $toWalletId = 1, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $fromWalletId);

        return $this->makeRequest('POST', "/wallets/transfer/{$enc}", [
            'amount' => $amount,
            'wallet_id' => $toWalletId,
        ], idempotencyKey: $idempotencyKey);
    }

    /**
     * TASK-036: Register M-Pesa C2B callback URLs with Safaricom.
     * Endpoint: GET /api/v1/c2b/register
     *
     * WARNING: Only call this once in production. Calling it multiple times
     * re-registers the callbacks and may disrupt in-flight M-Pesa transactions.
     */
    public function registerC2BCallbacks(): array
    {
        return $this->makeRequest('GET', '/c2b/register');
    }

    /**
     * TASK-037: Get the latest B2C balance record (withdrawal float monitoring).
     * Endpoint: GET /api/v1/b2c/balance
     */
    public function getB2CBalance(): array
    {
        return $this->makeRequest('GET', '/b2c/balance')['data'] ?? [];
    }

    /**
     * Get the B2C float as a plain numeric value (convenience wrapper for widgets).
     *
     * /b2c/balance returns `{"data": null}` until a balance fetch has been recorded, so this
     * falls back to the b2c accounts on the finance balance sheet.
     */
    public function getB2CBalanceAmount(): float
    {
        $data = $this->getB2CBalance();

        if (isset($data['balance']) || isset($data['amount'])) {
            return (float) ($data['balance'] ?? $data['amount']);
        }

        return (float) collect($this->getCashAccounts())
            ->where('type', 'b2c')
            ->sum('amount');
    }

    // -------------------------------------------------------------------------
    // Finance reporting — GET /finance/* (read-only, `stats` limiter: 20/min)
    // -------------------------------------------------------------------------

    /**
     * Reports the API can export as CSV via GET /finance/export/{report}.
     *
     * @var list<string>
     */
    public const FINANCE_EXPORTS = [
        'ledger', 'deposits', 'withdrawals', 'purchases', 'adjustments', 'games', 'competitions',
        'customers-top', 'cash-flow', 'income-statement', 'trial-balance', 'expenses', 'taxes',
        'excise-duty', 'excise-duty-charges', 'excise-duty-returns', 'excise-duty-remittances', 'disputes',
        'referral-bonuses', 'referral-withdrawals', 'promotions', 'bet-shops', 'bet-shop-loads', 'bet-shop-withdrawals',
        'house-banked', 'open-wallets', 'house-funding',
    ];

    /**
     * Bumped after every finance write so cached reports are refetched instead of served stale.
     */
    protected const FINANCE_CACHE_VERSION_KEY = 'game_api:finance:version';

    /**
     * Fetch one finance report and return its `data` payload.
     *
     * Common filters: from, to (Y-m-d, max 366 days), group_by (day|week|month), exclude_test,
     * page, per_page (max 200) plus report-specific filters. Results are cached briefly, matching
     * the API's own ~2 minute cache, so dashboards and pages share calls under the 20/min limit.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function financeReport(string $report, array $filters = [], int $cacheSeconds = 120): array
    {
        $path = '/finance/'.ltrim($report, '/');

        $query = collect($filters)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->map(fn ($value) => is_bool($value) ? (int) $value : $value)
            ->sortKeys()
            ->all();

        $fetch = fn (): array => $this->makeRequest('GET', $path, query: $query, timeout: 45)['data'] ?? [];

        if ($cacheSeconds <= 0) {
            return $fetch();
        }

        $version = (int) Cache::get(self::FINANCE_CACHE_VERSION_KEY, 0);

        return Cache::remember('game_api:finance:'.$version.':'.md5($path.json_encode($query)), $cacheSeconds, $fetch);
    }

    /**
     * Make every cached finance report stale, e.g. after a remittance is recorded or voided.
     */
    public function forgetFinanceReports(): void
    {
        Cache::forever(self::FINANCE_CACHE_VERSION_KEY, (int) Cache::get(self::FINANCE_CACHE_VERSION_KEY, 0) + 1);
    }

    // -------------------------------------------------------------------------
    // Statistics — GET /stats/{dashboard,games,liability,players,…} (read-only, `stats-dashboard` limiter: 60/min)
    // -------------------------------------------------------------------------

    /**
     * Statistics the API can export as CSV via GET /stats/export/{report}.
     *
     * @var list<string>
     */
    public const STATS_EXPORTS = ['stats-games', 'stats-players-top', 'stats-promotions', 'stats-payments'];

    /**
     * KadiApi rebuilds today's figures at most once a minute, so an answer is never kept longer.
     */
    public const STATS_MAX_CACHE_SECONDS = 60;

    /**
     * Fetch one statistics answer (`/stats/{report}`) and return its `data` payload.
     *
     * Filters: period or from/to, compare, group_by, exclude_test, game, product, plus report-specific ones
     * (sort, limit). The answer is cached for at most a minute, and widgets asking at the same moment wait
     * for one another so a dashboard load makes a single call.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws GameApiException 422 with `errors` (e.g. a product outside the game, `product_filter_not_supported`)
     */
    public function statsReport(string $report, array $filters = [], int $cacheSeconds = self::STATS_MAX_CACHE_SECONDS): array
    {
        $path = '/stats/'.ltrim($report, '/');
        $query = $this->statsQuery($filters);

        $fetch = fn (): array => $this->makeRequest('GET', $path, query: $query, timeout: 45)['data'] ?? [];

        if ($cacheSeconds <= 0) {
            return $fetch();
        }

        $key = 'game_api:stats:'.md5($path.json_encode($query));
        $seconds = min($cacheSeconds, self::STATS_MAX_CACHE_SECONDS);

        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        try {
            return Cache::lock($key.':lock', 50)->block(50, fn (): array => Cache::remember($key, $seconds, $fetch));
        } catch (LockTimeoutException) {
            return Cache::remember($key, $seconds, $fetch);
        }
    }

    /**
     * Stream a statistics CSV (`/stats/export/{report}`) with the page's own query.
     *
     * @param  array<string, mixed>  $filters
     * @return array{body: string, filename: string}
     *
     * @throws GameApiException
     */
    public function downloadStatsCsv(string $report, array $filters = []): array
    {
        if (! in_array($report, self::STATS_EXPORTS, true)) {
            throw new GameApiException("Unsupported statistics export: {$report}", 422, 'Unsupported export');
        }

        return $this->downloadCsv('/stats/export/'.$report, $this->statsQuery($filters), $report.'-'.($filters['from'] ?? $filters['period'] ?? 'period').'.csv');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function statsQuery(array $filters): array
    {
        return collect($filters)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->map(fn ($value) => is_bool($value) ? (int) $value : $value)
            ->sortKeys()
            ->all();
    }

    /**
     * Record a payment of excise duty to KRA. The API attaches every unremitted charge in the
     * period, so they can no longer be refunded.
     *
     * @param  array{period_start: string, period_end: string, amount_paid: float|int|string, kra_reference: string, paid_at?: ?string}  $data
     * @return array<string, mixed> The new remittance.
     *
     * @throws GameApiException 422 when nothing is unremitted in the period or the KRA reference is taken
     */
    public function recordExciseRemittance(array $data, string $idempotencyKey): array
    {
        $body = collect($data)
            ->only(['period_start', 'period_end', 'amount_paid', 'kra_reference', 'paid_at'])
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();

        $remittance = $this->makeRequest('POST', '/finance/excise-duty/remittances', $body, timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetFinanceReports();

        return $remittance;
    }

    /**
     * Void a KRA remittance; its charges are detached and owed again.
     *
     * @return array<string, mixed> The voided remittance.
     *
     * @throws GameApiException 404 when not found, 409 when already voided
     */
    public function voidExciseRemittance(int $remittanceId, string $reason, string $idempotencyKey): array
    {
        $enc = $this->encryptId($remittanceId);

        $remittance = $this->makeRequest('POST', "/finance/excise-duty/remittances/{$enc}/void", ['reason' => $reason], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetFinanceReports();

        return $remittance;
    }

    /**
     * Record a payment of winnings tax to KRA. The API attaches every unremitted charge and credit
     * in the period (422 when nothing is owed in it or the KRA reference is taken).
     * Endpoint: POST /api/v1/finance/winnings-tax/remittances
     *
     * @param  array{period_start: string, period_end: string, amount_paid: float|int|string, kra_reference: string, paid_at?: ?string}  $data
     * @return array<string, mixed> The new remittance.
     */
    public function recordWinningsTaxRemittance(array $data, string $idempotencyKey): array
    {
        $body = collect($data)
            ->only(['period_start', 'period_end', 'amount_paid', 'kra_reference', 'paid_at'])
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();

        $remittance = $this->makeRequest('POST', '/finance/winnings-tax/remittances', $body, timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetFinanceReports();

        return $remittance;
    }

    /**
     * Void a winnings tax remittance; its charges are detached and owed again (404, 409 already voided).
     * Endpoint: POST /api/v1/finance/winnings-tax/remittances/{enc}/void
     *
     * @return array<string, mixed> The voided remittance.
     */
    public function voidWinningsTaxRemittance(int $remittanceId, string $reason, string $idempotencyKey): array
    {
        $enc = $this->encryptId($remittanceId);

        $remittance = $this->makeRequest('POST', "/finance/winnings-tax/remittances/{$enc}/void", ['reason' => $reason], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetFinanceReports();

        return $remittance;
    }

    /**
     * Record a company expense in the Kadi API's ledger (it feeds the income statement). A reference
     * may be used only once among active expenses (422 when taken).
     * Endpoint: POST /api/v1/finance/expenses
     *
     * @param  array{expense_date: string, category: string, amount: float|int|string, description?: ?string, reference?: ?string}  $data
     * @return array<string, mixed> The new expense.
     */
    public function recordExpense(array $data, string $idempotencyKey): array
    {
        $body = collect($data)
            ->only(['expense_date', 'category', 'amount', 'description', 'reference'])
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();

        $expense = $this->makeRequest('POST', '/finance/expenses', $body, timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetFinanceReports();

        return $expense;
    }

    /**
     * Void an expense entered in error; expenses are never edited (404, 409 already voided).
     * Endpoint: POST /api/v1/finance/expenses/{enc}/void (reason 3–255)
     *
     * @return array<string, mixed>
     */
    public function voidExpense(int $expenseId, string $reason, string $idempotencyKey): array
    {
        $expense = $this->makeRequest('POST', '/finance/expenses/'.$this->encryptId($expenseId).'/void', ['reason' => $reason], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetFinanceReports();

        return $expense;
    }

    /**
     * Void a house funding entered in error (409 insufficient_house_balance, already_resolved).
     * Endpoint: POST /api/v1/finance/house-funding/{enc}/void
     *
     * @return array<string, mixed>
     */
    public function voidHouseFunding(int $houseFundingId, string $reason, string $idempotencyKey): array
    {
        $enc = $this->encryptId($houseFundingId);

        $result = $this->makeRequest('POST', "/finance/house-funding/{$enc}/void", ['reason' => $reason], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetFinanceReports();

        return $result;
    }

    /**
     * Dashboard figures for today / week / month / year / all-time plus the balance position.
     *
     * @return array<string, mixed>
     */
    public function getFinanceSummary(): array
    {
        return $this->financeReport('summary');
    }

    /**
     * Cash held against what is owed. Pass a past date to see a historical balance sheet.
     *
     * @return array<string, mixed>
     */
    public function getBalanceSheet(?string $asOf = null): array
    {
        return $this->financeReport('balance-sheet', ['as_of' => $asOf]);
    }

    /**
     * The ledger, wallet, payment, excise duty and bet shop reconciliation controls. KadiApi adds
     * checks over time, so callers never assume how many there are.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getReconciliation(array $filters = []): array
    {
        return $this->financeReport('reconciliation', $filters);
    }

    /**
     * Cash accounts (b2c / c2b, working / utility / charges …) from the balance sheet.
     *
     * @return list<array{type: string, account: string, amount: float|int, as_of: ?string}>
     */
    public function getCashAccounts(): array
    {
        return $this->getBalanceSheet()['assets']['cash']['accounts'] ?? [];
    }

    /**
     * Wallet statement for a single customer (defaults to the last 30 days).
     *
     * @param  array<string, mixed>  $filters  from, to, page, per_page
     * @return array<string, mixed>
     */
    public function getCustomerStatement(int $customerId, array $filters = []): array
    {
        $enc = $this->encryptId((string) $customerId);

        return $this->financeReport("customers/{$enc}/statement", $filters);
    }

    /**
     * Download a finance report as CSV (UTF-8 with BOM). Fetched server-side so the API key
     * never reaches the browser.
     *
     * @param  array<string, mixed>  $filters
     * @return array{body: string, filename: string}
     *
     * @throws GameApiException
     */
    public function downloadFinanceCsv(string $report, array $filters = []): array
    {
        if (! in_array($report, self::FINANCE_EXPORTS, true)) {
            throw new GameApiException("Unsupported finance export: {$report}", 422, 'Unsupported export');
        }

        $query = collect($filters)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->map(fn ($value) => is_bool($value) ? (int) $value : $value)
            ->all();

        return $this->downloadCsv("/finance/export/{$report}", $query, 'finance-'.$report.'-'.($filters['from'] ?? 'start').'-'.($filters['to'] ?? 'today').'.csv');
    }

    /**
     * Fetch a CSV server-side so the API key never reaches the browser. The API's own file name wins.
     *
     * @param  array<string, mixed>  $query
     * @return array{body: string, filename: string}
     *
     * @throws GameApiException
     */
    protected function downloadCsv(string $path, array $query, string $filename): array
    {
        try {
            $response = Http::withHeaders(['X-API-KEY' => $this->apiKey, 'Accept' => 'text/csv'])
                ->timeout(60)
                ->connectTimeout(5)
                ->get($this->baseUrl.$path, $query ?: null);
        } catch (ConnectionException $e) {
            throw new GameApiException('Game API is unreachable. Please try again later.', 0, $e->getMessage());
        }

        if ($response->failed()) {
            $message = $response->status() === 429
                ? 'Rate limit reached. Please retry shortly.'
                : (string) ($response->json('message') ?? '');

            throw new GameApiException("Game API error {$response->status()}: {$message}", $response->status(), $message);
        }

        if (preg_match('/filename="?([^";]+)"?/i', $response->header('Content-Disposition'), $matches) === 1) {
            $filename = basename($matches[1]);
        }

        return ['body' => $response->body(), 'filename' => $filename];
    }

    // -------------------------------------------------------------------------
    // Complaints — GET/POST /complaints (closing uses the `write` limiter: 30/min)
    // -------------------------------------------------------------------------

    /**
     * The endpoints that close a pending complaint, keyed by the status each one leaves behind.
     *
     * @var array<string, string>
     */
    public const COMPLAINT_OUTCOMES = [
        'resolve' => 'resolved',
        'reject' => 'rejected',
        'cancel' => 'cancelled',
    ];

    /**
     * One page of complaints, newest first, as the raw `{data, links, meta}` payload.
     *
     * @param  array<string, mixed>  $filters  status, subject_type, customer_id, game_wallet_id, competition_wallet_id, from, to, page, per_page
     * @return array<string, mixed>
     */
    public function listComplaints(array $filters = []): array
    {
        $query = collect($filters)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();

        return $this->makeRequest('GET', '/complaints', query: $query, timeout: 20);
    }

    /**
     * A single complaint with its disputed transactions and refunds.
     *
     * @return array<string, mixed>
     *
     * @throws GameApiException 404 when the complaint does not exist
     */
    public function getComplaint(int $complaintId): array
    {
        $enc = $this->encryptId($complaintId);

        return $this->makeRequest('GET', "/complaints/{$enc}")['data'] ?? [];
    }

    /**
     * Close a pending complaint by resolving, rejecting or cancelling it. The caller owns the
     * Idempotency-Key so a retry of the same attempt replays instead of acting twice.
     *
     * @return array<string, mixed> The updated complaint.
     *
     * @throws GameApiException 409 when already closed, 422 on validation or a missing round
     */
    public function closeComplaint(int $complaintId, string $outcome, string $note, string $idempotencyKey): array
    {
        if (! array_key_exists($outcome, self::COMPLAINT_OUTCOMES)) {
            throw new GameApiException("Unsupported complaint outcome: {$outcome}", 422, 'Unsupported outcome');
        }

        $enc = $this->encryptId($complaintId);

        $complaint = $this->makeRequest('POST', "/complaints/{$enc}/{$outcome}", ['note' => $note], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        // Closing moves money out of dispute escrow, so held totals in finance reports change.
        $this->forgetFinanceReports();

        return $complaint;
    }

    /**
     * Pending dispute totals from GET /finance/disputes, including `currently_held` escrow.
     * Spans the widest range the finance API accepts (366 days) so older open disputes count.
     *
     * @return array<string, mixed>
     */
    public function getDisputesSummary(): array
    {
        return $this->financeReport('disputes', [
            'status' => 'pending_dispute',
            'from' => today()->subDays(365)->toDateString(),
            'to' => today()->toDateString(),
            'per_page' => 1,
        ])['summary'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Player referral withdrawals — paid by KadiApi from the 4151665 shortcode.
    // Not to be confused with the agent referral codes above (getReferralStats).
    // -------------------------------------------------------------------------

    /**
     * Programme-wide player referral stats from `GET /stats/referrals`, cached for a minute
     * because the endpoint shares the 20/min `stats` limiter with every finance report.
     *
     * @return array<string, mixed>
     */
    public function getPlayerReferralProgrammeStats(): array
    {
        return Cache::remember('game_api:stats:referrals', 60, fn (): array => $this->makeRequest('GET', '/stats/referrals')['data'] ?? []);
    }

    /**
     * One page of referrals (who referred whom) as the raw `{data, links, meta}` payload.
     *
     * @param  array<string, mixed>  $filters  status, referrer_id, referred_id, from, to, page, per_page
     * @return array<string, mixed>
     */
    public function listReferrals(array $filters = []): array
    {
        $query = collect($filters)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();

        return $this->makeRequest('GET', '/referrals', query: $query, timeout: 20);
    }

    /**
     * The customer's own referral code, link and QR code, or null when they have none yet.
     *
     * @return array{customer_id?: int, code?: string, link?: ?string, qr_code?: ?string}|null
     */
    public function getCustomerReferralCode(int $customerId): ?array
    {
        $enc = $this->encryptId($customerId);

        try {
            return $this->makeRequest('GET', "/customers/{$enc}/referral-code")['data'] ?? null;
        } catch (GameApiException $e) {
            if ($e->statusCode === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Set or change the customer's referral code, link and QR code. Earlier referrals and
     * earnings are kept; the old code stops matching new signups.
     *
     * @return array<string, mixed> The saved code, link and QR code.
     *
     * @throws GameApiException 409 when another customer has the code, 422 on validation
     */
    public function updateCustomerReferralCode(int $customerId, string $code, string $link, string $qrCode, string $idempotencyKey): array
    {
        $enc = $this->encryptId($customerId);

        return $this->makeRequest('PUT', "/customers/{$enc}/referral-code", [
            'code' => $code,
            'link' => $link,
            'qr_code' => $qrCode,
        ], timeout: 20, idempotencyKey: $idempotencyKey)['data'] ?? [];
    }

    /**
     * A referrer's counts, earnings and referral wallet balance.
     *
     * @return array<string, mixed>
     */
    public function getCustomerReferralStats(int $customerId): array
    {
        $enc = $this->encryptId($customerId);

        return $this->makeRequest('GET', "/customers/{$enc}/referrals/stats")['data'] ?? [];
    }

    /**
     * One page of the customers this customer referred, as the raw `{data, links, meta}` payload.
     *
     * @param  array<string, mixed>  $filters  status, from, to, page, per_page
     * @return array<string, mixed>
     */
    public function listCustomerReferrals(int $customerId, array $filters = []): array
    {
        $enc = $this->encryptId($customerId);

        return $this->makeRequest('GET', "/customers/{$enc}/referrals", query: $this->withoutBlankFilters($filters), timeout: 20);
    }

    /**
     * Referral wallet balance plus one page of bonus history. Paging sits under `pagination`.
     *
     * @param  array<string, mixed>  $filters  page, per_page
     * @return array{data?: array<string, mixed>, pagination?: array<string, int>}
     */
    public function getCustomerReferralWallet(int $customerId, array $filters = []): array
    {
        $enc = $this->encryptId($customerId);

        return $this->makeRequest('GET', "/customers/{$enc}/referral-wallet", query: $this->withoutBlankFilters($filters), timeout: 20);
    }

    /**
     * One page of the customer's referral withdrawals, as the raw `{data, links, meta}` payload.
     *
     * @param  array<string, mixed>  $filters  page, per_page
     * @return array<string, mixed>
     */
    public function listCustomerReferralWithdrawals(int $customerId, array $filters = []): array
    {
        $enc = $this->encryptId($customerId);

        return $this->makeRequest('GET', "/customers/{$enc}/referral-wallet/withdrawals", query: $this->withoutBlankFilters($filters), timeout: 20);
    }

    /**
     * Who referred this customer (`referrer_id`, `code_used`, `created_at`), or null if nobody did.
     *
     * @return array<string, mixed>|null
     */
    public function getCustomerReferrer(int $customerId): ?array
    {
        $referral = $this->listReferrals(['referred_id' => $customerId, 'per_page' => 1])['data'][0] ?? null;

        return is_array($referral) ? $referral : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function withoutBlankFilters(array $filters): array
    {
        return collect($filters)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();
    }

    /**
     * How an admin can settle a withdrawal that M-Pesa never confirmed.
     *
     * @var list<string>
     */
    public const REFERRAL_WITHDRAWAL_OUTCOMES = ['completed', 'failed'];

    /**
     * One page of referral withdrawals as the raw `{data, links, meta}` payload.
     *
     * @param  array<string, mixed>  $filters  status, customer_id, page, per_page
     * @return array<string, mixed>
     */
    public function listReferralWithdrawals(array $filters = []): array
    {
        $query = collect($filters)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();

        return $this->makeRequest('GET', '/referral-withdrawals', query: $query, timeout: 20);
    }

    /**
     * A single referral withdrawal.
     *
     * @return array<string, mixed>
     *
     * @throws GameApiException 404 when the withdrawal does not exist
     */
    public function getReferralWithdrawal(int $withdrawalId): array
    {
        $enc = $this->encryptId($withdrawalId);

        return $this->makeRequest('GET', "/referral-withdrawals/{$enc}")['data'] ?? [];
    }

    /**
     * Record the real outcome of a pending or processing withdrawal. `failed` refunds the
     * customer's referral wallet. The caller owns the Idempotency-Key so a retry of the same
     * attempt replays instead of settling twice.
     *
     * @return array<string, mixed> The settled withdrawal.
     *
     * @throws GameApiException 404 not found, 409 already settled or receipt taken, 422 validation
     */
    public function settleReferralWithdrawal(int $withdrawalId, string $outcome, ?string $mpesaReceipt, string $note, string $idempotencyKey): array
    {
        if (! in_array($outcome, self::REFERRAL_WITHDRAWAL_OUTCOMES, true)) {
            throw new GameApiException("Unsupported settlement outcome: {$outcome}", 422, 'Unsupported outcome');
        }

        $enc = $this->encryptId($withdrawalId);

        $body = array_filter([
            'outcome' => $outcome,
            'mpesa_receipt' => $outcome === 'completed' ? $mpesaReceipt : null,
            'note' => $note,
        ], fn ($value): bool => $value !== null);

        $withdrawal = $this->makeRequest('POST', "/referral-withdrawals/{$enc}/settle", $body, timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        // Settling moves money between payouts and referral wallets in the finance reports.
        $this->forgetFinanceReports();

        return $withdrawal;
    }

    // -------------------------------------------------------------------------
    // Promo codes — signup bonus campaigns (writes use the `write` limiter: 30/min)
    // -------------------------------------------------------------------------

    /**
     * Statuses a promo code can have.
     *
     * @var list<string>
     */
    public const PROMO_CODE_STATUSES = ['active', 'expired', 'deactivated'];

    public const ACTIVE_PROMO_CODES_CACHE_KEY = 'game_api:promo-codes:active-count';

    /**
     * One page of promo codes, newest first, as the `data` payload (`items` plus `pagination`).
     *
     * @param  array<string, mixed>  $filters  status, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listPromoCodes(array $filters = []): array
    {
        return $this->makeRequest('GET', '/promo-codes', query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    /**
     * Create a promo code. Codes cannot be edited afterwards. The caller owns the
     * Idempotency-Key so a retry of the same form submission replays instead of creating twice.
     *
     * @param  array{code: string, expires_at: string, max_redemptions?: ?int, note?: ?string}  $data  `expires_at` is `Y-m-d H:i` Nairobi time
     * @return array<string, mixed> The new code.
     *
     * @throws GameApiException 422 on validation (including a duplicate code)
     */
    public function createPromoCode(array $data, string $idempotencyKey): array
    {
        $body = collect($data)
            ->only(['code', 'expires_at', 'max_redemptions', 'note'])
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();

        $promoCode = $this->makeRequest('POST', '/promo-codes', $body, timeout: 20, idempotencyKey: $idempotencyKey)['data'] ?? [];

        Cache::forget(self::ACTIVE_PROMO_CODES_CACHE_KEY);

        return $promoCode;
    }

    /**
     * How many codes are active right now, cached for a minute.
     */
    public function countActivePromoCodes(): int
    {
        return Cache::remember(self::ACTIVE_PROMO_CODES_CACHE_KEY, 60, fn (): int => (int) ($this->listPromoCodes(['status' => 'active', 'per_page' => 1])['pagination']['total'] ?? 0));
    }

    /**
     * Stop a promo code at once. Players who signed up with it but are not verified yet
     * will not get the bonus.
     *
     * @return array<string, mixed> The deactivated code.
     *
     * @throws GameApiException 404 not found, 409 already deactivated
     */
    public function deactivatePromoCode(int $promoCodeId, string $idempotencyKey): array
    {
        $enc = $this->encryptId($promoCodeId);

        $promoCode = $this->makeRequest('POST', "/promo-codes/{$enc}/deactivate", timeout: 20, idempotencyKey: $idempotencyKey)['data'] ?? [];

        Cache::forget(self::ACTIVE_PROMO_CODES_CACHE_KEY);

        return $promoCode;
    }

    /**
     * A customer's signup bonuses and how much is still locked (can only be staked, not
     * withdrawn, transferred or spent on coins).
     *
     * @return array{locked_amount?: float|int, items?: list<array<string, mixed>>}
     *
     * @throws GameApiException 404 when the customer does not exist
     */
    public function getCustomerPromotions(int $customerId): array
    {
        $enc = $this->encryptId($customerId);

        return $this->makeRequest('GET', "/customers/{$enc}/promotions")['data'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Bet shops — /bet-shops, /bet-shop-keys (writes use the `write` limiter: 30/min)
    // -------------------------------------------------------------------------

    /**
     * @var list<string>
     */
    public const BET_SHOP_STATUSES = ['active', 'suspended', 'closed'];

    /**
     * Fields a shop can be created or edited with. `shop_no` is only sent on create:
     * owners use it in their bill ref, so it never changes.
     *
     * @var list<string>
     */
    // ── Compliance: held withdrawals, responsible gambling, customer status ──

    /**
     * Withdrawals of KES 20,000 or more held for an admin, oldest first.
     * Endpoint: GET /api/v1/withdrawals/pending
     *
     * @param  array<string, mixed>  $filters  customer, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listPendingWithdrawalApprovals(array $filters = []): array
    {
        return $this->makeRequest('GET', '/withdrawals/pending', query: $this->withoutBlankFilters($filters))['data'] ?? [];
    }

    /**
     * Every held withdrawal and its decision, newest first.
     * Endpoint: GET /api/v1/withdrawals/approvals
     *
     * @param  array<string, mixed>  $filters  status (awaiting, approved, rejected, cancelled), customer, from, to, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listWithdrawalApprovals(array $filters = []): array
    {
        return $this->makeRequest('GET', '/withdrawals/approvals', query: $this->withoutBlankFilters($filters))['data'] ?? [];
    }

    /**
     * Approve a held withdrawal and send it to M-Pesa. 200: sent. 502: approved but M-Pesa refused, the
     * player is refunded. 504 `payout_unknown`: approved but M-Pesa never answered; never retry, check the
     * statement. 409 `not_awaiting`: already decided.
     * Endpoint: POST /api/v1/withdrawals/{id}/approve
     *
     * @return array<string, mixed> The approval.
     */
    public function approveWithdrawal(int $withdrawalId, string $adminUser): array
    {
        return $this->makeRequest('POST', "/withdrawals/{$withdrawalId}/approve", ['admin_user' => $adminUser], timeout: 45)['data'] ?? [];
    }

    /**
     * Reject a held withdrawal; the player is refunded at once.
     * Endpoint: POST /api/v1/withdrawals/{id}/reject (reason 3–500)
     *
     * @return array<string, mixed> The approval.
     */
    public function rejectWithdrawal(int $withdrawalId, string $adminUser, string $reason): array
    {
        return $this->makeRequest('POST', "/withdrawals/{$withdrawalId}/reject", ['admin_user' => $adminUser, 'reason' => $reason])['data'] ?? [];
    }

    /**
     * Deposits credited although the player's own rules would have refused them.
     * Endpoint: GET /api/v1/responsible-gambling/flags
     *
     * @param  array<string, mixed>  $filters  customer, type, resolved, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listRgFlags(array $filters = []): array
    {
        return $this->makeRequest('GET', '/responsible-gambling/flags', query: $this->withoutBlankFilters($filters))['data'] ?? [];
    }

    /**
     * Endpoint: POST /api/v1/responsible-gambling/flags/{id}/resolve (note 3–500)
     *
     * @return array<string, mixed>
     */
    public function resolveRgFlag(int $flagId, string $adminUser, string $note): array
    {
        return $this->makeRequest('POST', "/responsible-gambling/flags/{$flagId}/resolve", ['admin_user' => $adminUser, 'note' => $note])['data'] ?? [];
    }

    /**
     * A player's status, whether they can play, any active exclusion and their deposit limits.
     * Endpoint: GET /api/v1/customers/{enc}/responsible-gambling
     *
     * @return array<string, mixed>
     */
    public function getCustomerResponsibleGambling(int $customerId): array
    {
        return $this->makeRequest('GET', '/customers/'.$this->encryptId($customerId).'/responsible-gambling')['data'] ?? [];
    }

    /**
     * Endpoint: GET /api/v1/customers/{enc}/exclusions
     *
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listCustomerExclusions(int $customerId): array
    {
        return $this->makeRequest('GET', '/customers/'.$this->encryptId($customerId).'/exclusions')['data'] ?? [];
    }

    /**
     * Start a cool-off (24h, 7d, 30d) or self-exclusion (6m, 1y, 5y) on the player's request.
     * Endpoint: POST /api/v1/customers/{enc}/exclusion
     *
     * @return array<string, mixed>
     */
    public function startCustomerExclusion(int $customerId, string $type, string $duration): array
    {
        return $this->makeRequest('POST', '/customers/'.$this->encryptId($customerId).'/exclusion', ['type' => $type, 'duration' => $duration])['data'] ?? [];
    }

    /**
     * Endpoint: PUT /api/v1/customers/{enc}/status (status 1–6, reason 3–500, admin_user)
     *
     * @return array{status?: int, previous_status?: int}
     */
    public function updateCustomerStatus(int $customerId, int $status, string $reason, string $adminUser): array
    {
        return $this->makeRequest('PUT', '/customers/'.$this->encryptId($customerId).'/status', ['status' => $status, 'reason' => $reason, 'admin_user' => $adminUser])['data'] ?? [];
    }

    /**
     * Endpoint: GET /api/v1/customers/{enc}/status-changes
     *
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listCustomerStatusChanges(int $customerId): array
    {
        return $this->makeRequest('GET', '/customers/'.$this->encryptId($customerId).'/status-changes')['data'] ?? [];
    }

    /**
     * A player's prediction market bets.
     * Endpoint: GET /api/v1/customers/{enc}/predict/bets
     *
     * @param  array<string, mixed>  $filters  status, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listCustomerPredictBets(int $customerId, array $filters = []): array
    {
        return $this->makeRequest('GET', '/customers/'.$this->encryptId($customerId).'/predict/bets', query: $this->withoutBlankFilters($filters))['data'] ?? [];
    }

    // ── Games catalogue, products, game keys and wagers ──────
    // The games, products and wager-void routes keep no idempotency record: never resend a write.

    /**
     * Games with their products, by sort order.
     * Endpoint: GET /api/v1/games?status=all
     *
     * @return list<array<string, mixed>>
     */
    public function listGames(string $status = 'all'): array
    {
        return $this->makeRequest('GET', '/games', query: ['status' => $status])['data'] ?? [];
    }

    /**
     * Endpoint: PUT /api/v1/games/{code} (name, launch_url, status, sort_order)
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateGame(string $code, array $payload): array
    {
        return $this->makeRequest('PUT', '/games/'.rawurlencode($code), $payload)['data'] ?? [];
    }

    /**
     * Endpoint: GET /api/v1/products (optional game)
     *
     * @return list<array<string, mixed>>
     */
    public function listProducts(?string $game = null): array
    {
        return $this->makeRequest('GET', '/products', query: $this->withoutBlankFilters(['game' => $game]))['data'] ?? [];
    }

    /**
     * Endpoint: PUT /api/v1/products/{code} (name, status, min_stake, max_stake, max_payout — null
     * removes a limit — and winnings_tax_rate 0–1)
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateProduct(string $code, array $payload): array
    {
        return $this->makeRequest('PUT', '/products/'.rawurlencode($code), $payload)['data'] ?? [];
    }

    /**
     * API keys (never the key itself).
     * Endpoint: GET /api/v1/api-keys
     *
     * @param  array<string, mixed>  $filters  scope, game, active, page, per_page (max 200)
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listApiKeys(array $filters = []): array
    {
        return $this->makeRequest('GET', '/api-keys', query: $this->withoutBlankFilters($filters))['data'] ?? [];
    }

    /**
     * Issue a game key. The key is in the answer once (`key`) and never again; the API stores no
     * response, so this call is never retried and carries no Idempotency-Key.
     * Endpoint: POST /api/v1/games/{code}/keys (409 game_retired)
     *
     * @return array<string, mixed>
     */
    public function issueGameKey(string $code, string $name): array
    {
        return $this->makeRequest('POST', '/games/'.rawurlencode($code).'/keys', ['name' => $name], timeout: 20, withIdempotencyKey: false)['data'] ?? [];
    }

    /**
     * Endpoint: POST /api/v1/api-keys/{enc}/revoke (409 own_key)
     *
     * @return array<string, mixed>
     */
    public function revokeApiKey(int $keyId): array
    {
        return $this->makeRequest('POST', '/api-keys/'.$this->encryptId($keyId).'/revoke')['data'] ?? [];
    }

    /**
     * Wagers across games, newest first.
     * Endpoint: GET /api/v1/wagers
     *
     * @param  array<string, mixed>  $filters  game, product, status, customer, reference, from, to, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listWagers(array $filters = []): array
    {
        return $this->makeRequest('GET', '/wagers', query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    /**
     * A wager with its settlements and ledger entries.
     * Endpoint: GET /api/v1/wagers/{id}/detail
     *
     * @return array{wager?: array<string, mixed>, settlements?: list<array<string, mixed>>, ledger_entries?: list<array<string, mixed>>}
     */
    public function getWagerDetail(int $id): array
    {
        return $this->makeRequest('GET', "/wagers/{$id}/detail")['data'] ?? [];
    }

    /**
     * Void an open wager and refund its stake (409 wager_not_open).
     * Endpoint: POST /api/v1/wagers/{id}/void (reason, admin_user)
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The wager.
     */
    public function voidWager(int $id, array $payload): array
    {
        return $this->makeRequest('POST', "/wagers/{$id}/void", $payload)['data']['wager'] ?? [];
    }

    // ── Prediction markets (admin) ───────────────────────────
    // Every write needs `admin_user` (StaffIdentity::adminUser()). The predict admin routes keep no
    // idempotency record, so a write that timed out must never be sent again blindly: reload the
    // market and check its status first.

    /**
     * One page of markets, drafts and rejected ones included.
     * Endpoint: GET /api/v1/predict/admin/markets
     *
     * @param  array<string, mixed>  $filters  status, source, category, country, sort, page, per_page (max 100)
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listPredictMarkets(array $filters = []): array
    {
        return $this->makeRequest('GET', '/predict/admin/markets', query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    /**
     * Endpoint: GET /api/v1/predict/admin/markets/{id}
     *
     * @return array<string, mixed>
     */
    public function getPredictMarket(int $id): array
    {
        return $this->makeRequest('GET', "/predict/admin/markets/{$id}")['data'] ?? [];
    }

    /**
     * Create a draft. Terms left out take the API's defaults.
     * Endpoint: POST /api/v1/predict/admin/markets
     *
     * @param  array<string, mixed>  $payload  Including admin_user.
     * @return array<string, mixed>
     */
    public function createPredictMarket(array $payload): array
    {
        return $this->makeRequest('POST', '/predict/admin/markets', $payload)['data'] ?? [];
    }

    /**
     * Endpoint: PUT /api/v1/predict/admin/markets/{id} (409 market_terms_frozen / market_not_editable)
     *
     * @param  array<string, mixed>  $payload  Including admin_user.
     * @return array<string, mixed>
     */
    public function updatePredictMarket(int $id, array $payload): array
    {
        return $this->unwrapPredictMarket($this->makeRequest('PUT', "/predict/admin/markets/{$id}", $payload)['data'] ?? []);
    }

    /**
     * publish, reject, suspend, resume, close, resolve (`result`) or void (`reason`).
     * Endpoint: POST /api/v1/predict/admin/markets/{id}/{action} (409 invalid_transition, close_time_passed, price_unavailable…)
     *
     * @param  array<string, mixed>  $payload  Including admin_user.
     * @return array<string, mixed>
     */
    public function predictMarketAction(int $id, string $action, array $payload): array
    {
        if (! in_array($action, self::PREDICT_MARKET_ACTIONS, true)) {
            throw new \InvalidArgumentException("[{$action}] is not a prediction market action.");
        }

        return $this->unwrapPredictMarket($this->makeRequest('POST', "/predict/admin/markets/{$id}/{$action}", $payload)['data'] ?? []);
    }

    /**
     * Updates and lifecycle actions answer `{market: {...}, replayed}`; show and store answer the market itself.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function unwrapPredictMarket(array $data): array
    {
        return is_array($data['market'] ?? null) ? $data['market'] : $data;
    }

    public const PREDICT_MARKET_ACTIONS = ['publish', 'reject', 'suspend', 'resume', 'close', 'resolve', 'void'];

    /**
     * Publish or reject drafts by `ids`, or by `filter` with the `after_id` cursor: repeat with
     * `after_id` = the answer's `next_after_id` until it is null, so refused drafts are passed over.
     * Endpoint: POST /api/v1/predict/admin/markets/bulk/{publish|reject}
     *
     * @param  array<string, mixed>  $payload  admin_user, ids | filter (+ after_id, limit), reason (reject)
     * @return array{done?: list<int>, refused?: list<array{id: int, code: string}>, remaining?: int, next_after_id?: int|null}
     */
    public function bulkPredictMarkets(string $action, array $payload): array
    {
        if (! in_array($action, ['publish', 'reject'], true)) {
            throw new \InvalidArgumentException("[{$action}] is not a bulk prediction market action.");
        }

        return $this->makeRequest('POST', "/predict/admin/markets/bulk/{$action}", $payload, timeout: 45)['data'] ?? [];
    }

    /**
     * Queue a failed settlement or refund run again (only unsettled wagers are touched).
     * Endpoint: POST /api/v1/predict/admin/settlements/{id}/retry (409 settlement_not_retryable)
     *
     * @param  array<string, mixed>  $payload  admin_user
     * @return array<string, mixed> The settlement run.
     */
    public function retryPredictSettlement(int $settlementId, array $payload): array
    {
        return $this->makeRequest('POST', "/predict/admin/settlements/{$settlementId}/retry", $payload)['data']['settlement'] ?? [];
    }

    /**
     * Players kept from betting on one market.
     * Endpoint: GET /api/v1/predict/admin/markets/{id}/blocks
     *
     * @return list<array{customer_id: int, reason: string|null, admin_user: string|null, created_at: string|null}>
     */
    public function listPredictBlocks(int $marketId): array
    {
        return $this->makeRequest('GET', "/predict/admin/markets/{$marketId}/blocks")['data'] ?? [];
    }

    /**
     * Endpoint: POST /api/v1/predict/admin/markets/{id}/blocks (customer_id, reason)
     *
     * @param  array<string, mixed>  $payload  admin_user, customer_id, reason
     * @return array<string, mixed>
     */
    public function blockPredictCustomer(int $marketId, array $payload): array
    {
        return $this->makeRequest('POST', "/predict/admin/markets/{$marketId}/blocks", $payload)['data'] ?? [];
    }

    /**
     * Endpoint: DELETE /api/v1/predict/admin/markets/{id}/blocks/{customerId} (404 block_not_found)
     *
     * @param  array<string, mixed>  $payload  admin_user, reason (optional)
     * @return array<string, mixed>
     */
    public function unblockPredictCustomer(int $marketId, int $customerId, array $payload): array
    {
        return $this->makeRequest('DELETE', "/predict/admin/markets/{$marketId}/blocks/{$customerId}", $payload)['data'] ?? [];
    }

    /**
     * Polymarket results waiting for an admin (status pending by default).
     * Endpoint: GET /api/v1/predict/admin/proposals
     *
     * @param  array<string, mixed>  $filters  status, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listPredictProposals(array $filters = []): array
    {
        return $this->makeRequest('GET', '/predict/admin/proposals', query: $this->withoutBlankFilters($filters))['data'] ?? [];
    }

    /**
     * confirm (resolves the market on the proposed result) or reject (`reason`).
     * Endpoint: POST /api/v1/predict/admin/proposals/{id}/{confirm|reject} (409 proposal_not_pending)
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The proposal.
     */
    public function predictProposalAction(int $proposalId, string $action, array $payload): array
    {
        if (! in_array($action, ['confirm', 'reject'], true)) {
            throw new \InvalidArgumentException("[{$action}] is not a proposal action.");
        }

        return $this->makeRequest('POST', "/predict/admin/proposals/{$proposalId}/{$action}", $payload)['data']['proposal'] ?? [];
    }

    /**
     * Ask for a settled market's result to be corrected; a different admin must approve.
     * Endpoint: POST /api/v1/predict/admin/markets/{id}/corrections (result, reason;
     * 409 market_not_settled, same_result, correction_open)
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The correction.
     */
    public function requestPredictCorrection(int $marketId, array $payload): array
    {
        return $this->makeRequest('POST', "/predict/admin/markets/{$marketId}/corrections", $payload)['data']['correction'] ?? [];
    }

    /**
     * Endpoint: GET /api/v1/predict/admin/corrections
     *
     * @param  array<string, mixed>  $filters  status, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listPredictCorrections(array $filters = []): array
    {
        return $this->makeRequest('GET', '/predict/admin/corrections', query: $this->withoutBlankFilters($filters))['data'] ?? [];
    }

    /**
     * approve (a different admin; 403 same_admin), cancel (`reason`) or retry (failed only).
     * Endpoint: POST /api/v1/predict/admin/corrections/{id}/{approve|cancel|retry}
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The correction.
     */
    public function predictCorrectionAction(int $correctionId, string $action, array $payload): array
    {
        if (! in_array($action, ['approve', 'cancel', 'retry'], true)) {
            throw new \InvalidArgumentException("[{$action}] is not a correction action.");
        }

        return $this->makeRequest('POST', "/predict/admin/corrections/{$correctionId}/{$action}", $payload)['data']['correction'] ?? [];
    }

    /**
     * Risk flags raised by prediction bets, newest first; unresolved only unless resolved=1.
     * Endpoint: GET /api/v1/predict/admin/risk-events
     *
     * @param  array<string, mixed>  $filters  resolved, type, customer_id, market_id, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listPredictRiskEvents(array $filters = []): array
    {
        return $this->makeRequest('GET', '/predict/admin/risk-events', query: $this->withoutBlankFilters($filters))['data'] ?? [];
    }

    /**
     * Endpoint: POST /api/v1/predict/admin/risk-events/{id}/resolve (reason; 409 risk_event_resolved)
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The risk event.
     */
    public function resolvePredictRiskEvent(int $riskEventId, array $payload): array
    {
        return $this->makeRequest('POST', "/predict/admin/risk-events/{$riskEventId}/resolve", $payload)['data']['risk_event'] ?? [];
    }

    /**
     * Every admin write on prediction markets, newest first.
     * Endpoint: GET /api/v1/predict/admin/audit
     *
     * @param  array<string, mixed>  $filters  market_id, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function predictAudit(array $filters = []): array
    {
        return $this->makeRequest('GET', '/predict/admin/audit', query: $this->withoutBlankFilters($filters))['data'] ?? [];
    }

    /**
     * Per-market stakes and results: settled markets show payouts, house net, cut and seed result;
     * quoted markets show payouts per side and the worst case.
     * Endpoint: GET /api/v1/predict/admin/reports/markets
     *
     * @param  array<string, mixed>  $filters  status, category, source, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function predictReport(array $filters = []): array
    {
        return $this->makeRequest('GET', '/predict/admin/reports/markets', query: $this->withoutBlankFilters($filters), timeout: 30)['data'] ?? [];
    }

    /**
     * The Kadi API's own record of a wager, found by the reference its game sent
     * (for a Kadi site sports bet, `sb_bets.wager_reference`). Null when the API
     * has no wager with that reference.
     * Endpoint: GET /api/v1/wagers?reference=
     *
     * @return array<string, mixed>|null
     */
    public function findWagerByReference(string $reference): ?array
    {
        $items = $this->makeRequest('GET', '/wagers', query: ['reference' => $reference, 'per_page' => 1])['data']['items'] ?? [];

        return is_array($items[0] ?? null) ? $items[0] : null;
    }

    public const BET_SHOP_FIELDS = ['shop_no', 'name', 'owner_name', 'owner_phone', 'location', 'notes'];

    public const BET_SHOP_OPTIONS_CACHE_KEY = 'game_api:bet-shops:options';

    /**
     * One page of bet shops, ordered by shop number, each with its float balance and active keys.
     * Endpoint: GET /api/v1/bet-shops
     *
     * @param  array<string, mixed>  $filters  status, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listBetShops(array $filters = []): array
    {
        return $this->makeRequest('GET', '/bet-shops', query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    /**
     * One bet shop with its float balance.
     * Endpoint: GET /api/v1/bet-shops/{enc}
     *
     * @return array<string, mixed>
     *
     * @throws GameApiException 404 when the shop does not exist
     */
    public function getBetShop(int $shopId): array
    {
        $enc = $this->encryptId($shopId);

        return $this->makeRequest('GET', "/bet-shops/{$enc}")['data'] ?? [];
    }

    /**
     * Onboard a shop and its float wallet (balance 0). The caller owns the Idempotency-Key so
     * a retry of the same submission replays instead of creating twice.
     * Endpoint: POST /api/v1/bet-shops  (rate limited: 30/min)
     *
     * @param  array<string, mixed>  $data  shop_no, name, owner_name, owner_phone, location, notes
     * @return array<string, mixed> The new shop.
     *
     * @throws GameApiException 422 on validation (including a shop number already taken)
     */
    public function createBetShop(array $data, string $idempotencyKey): array
    {
        $body = $this->withoutBlankFilters(collect($data)->only(self::BET_SHOP_FIELDS)->all());

        $shop = $this->makeRequest('POST', '/bet-shops', $body, timeout: 20, idempotencyKey: $idempotencyKey)['data'] ?? [];

        Cache::forget(self::BET_SHOP_OPTIONS_CACHE_KEY);

        return $shop;
    }

    /**
     * Change a shop's details. `shop_no` and status are never sent: KadiApi ignores them here.
     * Endpoint: PUT /api/v1/bet-shops/{enc}  (rate limited: 30/min)
     *
     * @param  array<string, mixed>  $data  name, owner_name, owner_phone, location, notes
     * @return array<string, mixed> The updated shop.
     *
     * @throws GameApiException 404 not found, 422 validation
     */
    public function updateBetShop(int $shopId, array $data, string $idempotencyKey): array
    {
        $enc = $this->encryptId($shopId);
        $body = collect($data)->only(array_values(array_diff(self::BET_SHOP_FIELDS, ['shop_no'])))->all();

        $shop = $this->makeRequest('PUT', "/bet-shops/{$enc}", $body, timeout: 20, idempotencyKey: $idempotencyKey)['data'] ?? [];

        Cache::forget(self::BET_SHOP_OPTIONS_CACHE_KEY);

        return $shop;
    }

    /**
     * Suspend a shop: its keys are refused until it is reactivated, the float is kept.
     * Endpoint: POST /api/v1/bet-shops/{enc}/suspend  (rate limited: 30/min)
     *
     * @return array<string, mixed> The shop.
     *
     * @throws GameApiException 404 not found, 409 shop_closed, 422 validation
     */
    public function suspendBetShop(int $shopId, string $reason, string $idempotencyKey): array
    {
        return $this->changeBetShopStatus($shopId, 'suspend', ['reason' => $reason], $idempotencyKey);
    }

    /**
     * Reactivate a suspended shop so its keys work again.
     * Endpoint: POST /api/v1/bet-shops/{enc}/activate  (rate limited: 30/min)
     *
     * @return array<string, mixed> The shop.
     *
     * @throws GameApiException 404 not found, 409 shop_closed, 422 validation
     */
    public function activateBetShop(int $shopId, ?string $reason, string $idempotencyKey): array
    {
        return $this->changeBetShopStatus($shopId, 'activate', $this->withoutBlankFilters(['reason' => $reason]), $idempotencyKey);
    }

    /**
     * Close a shop for good, revoking all its keys. Only once its float is 0.
     * Endpoint: POST /api/v1/bet-shops/{enc}/close  (rate limited: 30/min)
     *
     * @return array<string, mixed> The shop.
     *
     * @throws GameApiException 404 not found, 409 float_not_empty, 422 validation
     */
    public function closeBetShop(int $shopId, string $reason, string $idempotencyKey): array
    {
        return $this->changeBetShopStatus($shopId, 'close', ['reason' => $reason], $idempotencyKey);
    }

    /**
     * @param  'suspend'|'activate'|'close'  $change
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function changeBetShopStatus(int $shopId, string $change, array $body, string $idempotencyKey): array
    {
        $enc = $this->encryptId($shopId);

        $shop = $this->makeRequest('POST', "/bet-shops/{$enc}/{$change}", $body, timeout: 20, idempotencyKey: $idempotencyKey)['data'] ?? [];

        Cache::forget(self::BET_SHOP_OPTIONS_CACHE_KEY);

        return $shop;
    }

    /**
     * One page of a shop's API keys, newest first. The key itself is never returned; `key_hint`
     * is its last 4 characters.
     * Endpoint: GET /api/v1/bet-shops/{enc}/keys
     *
     * @param  array<string, mixed>  $filters  page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listBetShopKeys(int $shopId, array $filters = []): array
    {
        $enc = $this->encryptId($shopId);

        return $this->makeRequest('GET', "/bet-shops/{$enc}/keys", query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    /**
     * Issue a key for the shop's desktop system. The response holds `key` this once. Sent
     * without an Idempotency-Key, so KadiApi never stores the response; a retry after a
     * timeout would issue a second key, so callers never retry this.
     * Endpoint: POST /api/v1/bet-shops/{enc}/keys  (rate limited: 30/min)
     *
     * @return array<string, mixed> The key record, including `key`.
     *
     * @throws GameApiException 404 not found, 409 shop_closed, 422 validation
     */
    public function issueBetShopKey(int $shopId, string $name): array
    {
        $enc = $this->encryptId($shopId);

        return $this->makeRequest('POST', "/bet-shops/{$enc}/keys", ['name' => $name], timeout: 20, withIdempotencyKey: false)['data'] ?? [];
    }

    /**
     * Revoke a shop key; it stops working at once.
     * Endpoint: POST /api/v1/bet-shop-keys/{enc}/revoke  (rate limited: 30/min)
     *
     * @return array<string, mixed> The revoked key.
     *
     * @throws GameApiException 404 when it is not a shop key
     */
    public function revokeBetShopKey(int $keyId, string $idempotencyKey): array
    {
        $enc = $this->encryptId($keyId);

        return $this->makeRequest('POST', "/bet-shop-keys/{$enc}/revoke", timeout: 20, idempotencyKey: $idempotencyKey)['data'] ?? [];
    }

    /**
     * Every shop (id, shop_no, name, status), ordered by shop number, for choosing a shop in a
     * form. Cached for a minute and dropped whenever the GMS changes a shop.
     *
     * @return list<array{id: int, shop_no: string, name: string, status: string}>
     */
    public function betShopOptions(): array
    {
        return Cache::remember(self::BET_SHOP_OPTIONS_CACHE_KEY, 60, function (): array {
            $shops = [];
            $page = 1;

            do {
                $data = $this->listBetShops(['page' => $page, 'per_page' => 200]);

                foreach ($data['items'] ?? [] as $shop) {
                    if (is_array($shop) && isset($shop['id'], $shop['shop_no'])) {
                        $shops[] = [
                            'id' => (int) $shop['id'],
                            'shop_no' => (string) $shop['shop_no'],
                            'name' => (string) ($shop['name'] ?? ''),
                            'status' => (string) ($shop['status'] ?? ''),
                        ];
                    }
                }

                $lastPage = (int) ($data['pagination']['last_page'] ?? 1);
            } while ($page++ < min($lastPage, 10));

            return $shops;
        });
    }

    // -------------------------------------------------------------------------
    // Bet shop float — credited from the paybill, paid back to owners
    // -------------------------------------------------------------------------

    /**
     * Credit an unmatched paybill deposit (an owner buying float with bill ref SHOP-<shop_no>)
     * to a shop's float in full: no excise duty, no referral. The caller owns the Idempotency-Key.
     * Endpoint: POST /api/v1/deposits/{enc}/shop-float  (rate limited: 30/min)
     *
     * @return array{float_purchase?: array<string, mixed>, deposit?: array<string, mixed>}
     *
     * @throws GameApiException 404 deposit_not_found, 409 already_resolved or shop_closed, 422 errors.shop_no
     */
    public function creditDepositToShopFloat(int $depositId, string $shopNo, string $note, string $idempotencyKey): array
    {
        $enc = $this->encryptId($depositId);

        $result = $this->makeRequest('POST', "/deposits/{$enc}/shop-float", [
            'shop_no' => $shopNo,
            'note' => $note,
        ], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetDepositCaches();

        return $result;
    }

    /**
     * One page of a shop's float purchases, newest first, voided ones included.
     * Endpoint: GET /api/v1/bet-shops/{enc}/float-purchases
     *
     * @param  array<string, mixed>  $filters  page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listShopFloatPurchases(int $shopId, array $filters = []): array
    {
        $enc = $this->encryptId($shopId);

        return $this->makeRequest('GET', "/bet-shops/{$enc}/float-purchases", query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    /**
     * Take a float purchase back out of the shop's float and put its deposit back to unmatched.
     * Endpoint: POST /api/v1/bet-shops/float-purchases/{enc}/void  (rate limited: 30/min)
     *
     * @return array<string, mixed> The voided float purchase.
     *
     * @throws GameApiException 409 insufficient_float (with `float_balance`) or already_resolved
     */
    public function voidShopFloatPurchase(int $floatPurchaseId, string $reason, string $idempotencyKey): array
    {
        $enc = $this->encryptId($floatPurchaseId);

        $result = $this->makeRequest('POST', "/bet-shops/float-purchases/{$enc}/void", ['reason' => $reason], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetDepositCaches();

        return $result['float_purchase'] ?? $result;
    }

    /**
     * Record float paid back to the owner outside the API (M-Pesa B2C or bank). The float goes
     * down by the amount.
     * Endpoint: POST /api/v1/bet-shops/{enc}/float-redemptions  (rate limited: 30/min)
     *
     * @return array{redemption: array<string, mixed>, float_balance: float|null}
     *
     * @throws GameApiException 409 insufficient_float (with `float_balance`), 422 validation
     */
    public function recordShopFloatRedemption(int $shopId, float $amount, string $paymentReference, string $note, string $idempotencyKey): array
    {
        $enc = $this->encryptId($shopId);

        $response = $this->makeRequest('POST', "/bet-shops/{$enc}/float-redemptions", [
            'amount' => $amount,
            'payment_reference' => $paymentReference,
            'note' => $note,
        ], timeout: 30, idempotencyKey: $idempotencyKey);

        $this->forgetFinanceReports();

        return [
            'redemption' => $response['data'] ?? [],
            'float_balance' => isset($response['float_balance']) ? (float) $response['float_balance'] : null,
        ];
    }

    /**
     * One page of a shop's float redemptions, newest first, voided ones included.
     * Endpoint: GET /api/v1/bet-shops/{enc}/float-redemptions
     *
     * @param  array<string, mixed>  $filters  page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listShopFloatRedemptions(int $shopId, array $filters = []): array
    {
        $enc = $this->encryptId($shopId);

        return $this->makeRequest('GET', "/bet-shops/{$enc}/float-redemptions", query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    /**
     * Void a redemption recorded by mistake; the amount goes back on the float.
     * Endpoint: POST /api/v1/bet-shops/float-redemptions/{enc}/void  (rate limited: 30/min)
     *
     * @return array<string, mixed> The voided redemption.
     *
     * @throws GameApiException 409 already_resolved
     */
    public function voidShopFloatRedemption(int $redemptionId, string $reason, string $idempotencyKey): array
    {
        $enc = $this->encryptId($redemptionId);

        $redemption = $this->makeRequest('POST', "/bet-shops/float-redemptions/{$enc}/void", ['reason' => $reason], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetFinanceReports();

        return $redemption;
    }

    // -------------------------------------------------------------------------
    // Bet shop operations — cash loads and cash withdrawals at the counters
    // -------------------------------------------------------------------------

    /**
     * One page of cash loads, newest first, for every shop or one shop, with the player's
     * full name and customer id.
     * Endpoint: GET /api/v1/bet-shops/loads or /bet-shops/{enc}/loads
     *
     * @param  array<string, mixed>  $filters  status (completed|reversed), customer_id, from, to (Y-m-d), page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listShopLoads(?int $shopId = null, array $filters = []): array
    {
        $path = $shopId === null ? '/bet-shops/loads' : '/bet-shops/'.$this->encryptId($shopId).'/loads';

        return $this->makeRequest('GET', $path, query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    /**
     * One cash load, e.g. the one behind an excise duty charge's `bet_shop_load_id`.
     * Endpoint: GET /api/v1/bet-shops/loads/{enc}  (the encrypted `id`, not `load_id`)
     *
     * @return array<string, mixed>
     *
     * @throws GameApiException 404 when the load does not exist
     */
    public function getShopLoad(int $loadId): array
    {
        $enc = $this->encryptId($loadId);

        return $this->makeRequest('GET', "/bet-shops/loads/{$enc}")['data'] ?? [];
    }

    /**
     * Reverse a cash load made by mistake: the player's wallet gives back the gross amount,
     * the excise duty is returned and the shop float is refunded.
     * Endpoint: POST /api/v1/bet-shops/loads/{enc}/reverse  (rate limited: 30/min)
     *
     * @return array<string, mixed> The reversed load.
     *
     * @throws GameApiException 409 player_has_spent, excise_remitted or already_reversed
     */
    public function reverseShopLoad(int $loadId, string $reason, string $idempotencyKey): array
    {
        $enc = $this->encryptId($loadId);

        $load = $this->makeRequest('POST', "/bet-shops/loads/{$enc}/reverse", ['reason' => $reason], timeout: 30, idempotencyKey: $idempotencyKey)['data'] ?? [];

        $this->forgetFinanceReports();

        return $load;
    }

    /**
     * One page of cash withdrawals, newest first, for every shop or one shop. Never with
     * the pickup code.
     * Endpoint: GET /api/v1/bet-shops/withdrawals or /bet-shops/{enc}/withdrawals
     *
     * @param  array<string, mixed>  $filters  status (pending|paid|cancelled|rejected|expired), customer_id, from, to (Y-m-d), page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listShopWithdrawals(?int $shopId = null, array $filters = []): array
    {
        $path = $shopId === null ? '/bet-shops/withdrawals' : '/bet-shops/'.$this->encryptId($shopId).'/withdrawals';

        return $this->makeRequest('GET', $path, query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    /**
     * One page of a player's own shop cash withdrawals, newest first.
     * Endpoint: GET /api/v1/customers/{enc}/shop-withdrawals
     *
     * @param  array<string, mixed>  $filters  status, page, per_page
     * @return array{items?: list<array<string, mixed>>, pagination?: array<string, int>}
     */
    public function listCustomerShopWithdrawals(int $customerId, array $filters = []): array
    {
        $enc = $this->encryptId($customerId);

        return $this->makeRequest('GET', "/customers/{$enc}/shop-withdrawals", query: $this->withoutBlankFilters($filters), timeout: 20)['data'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Wallet management helpers
    // -------------------------------------------------------------------------

    /**
     * Update a customer's wallet by directly setting an amount (adds to balance).
     * Endpoint: PUT /api/v1/customers/{enc}/wallet
     */
    public function updateCustomerWallet(int $customerId, float $amount, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $customerId);

        return $this->makeRequest('PUT', "/customers/{$enc}/wallet", ['amount' => $amount], idempotencyKey: $idempotencyKey);
    }

    /**
     * Update a customer's fields.
     * Endpoint: PUT /api/v1/customers/{enc}
     */
    public function updateCustomer(int $customerId, array $data, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $customerId);

        return $this->makeRequest('PUT', "/customers/{$enc}", $data, idempotencyKey: $idempotencyKey);
    }

    /**
     * Get a game wallet by ID.
     * Endpoint: GET /api/v1/game/wallets/{enc}
     */
    public function getGameWallet(int $gameWalletId): array
    {
        $enc = $this->encryptId((string) $gameWalletId);

        return $this->makeRequest('GET', "/game/wallets/{$enc}");
    }

    /**
     * Update a game wallet (e.g. status change).
     * Endpoint: PUT /api/v1/game/wallets/{enc}
     */
    public function updateGameWallet(int $gameWalletId, array $data, ?string $idempotencyKey = null): array
    {
        $enc = $this->encryptId((string) $gameWalletId);

        return $this->makeRequest('PUT', "/game/wallets/{$enc}", $data, idempotencyKey: $idempotencyKey);
    }

    /**
     * Get customer purchases.
     * Endpoint: GET /api/v1/customers/purchases/{enc}
     */
    public function getCustomerPurchases(int $customerId): array
    {
        $enc = $this->encryptId((string) $customerId);

        return $this->makeRequest('GET', "/customers/purchases/{$enc}")['data'] ?? [];
    }
}
