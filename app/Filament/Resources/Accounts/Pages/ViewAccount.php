<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Pages\BetShopsPage;
use App\Filament\Pages\CustomerStatementReport;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Services\GameApiService;
use App\Support\Format;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;

class ViewAccount extends Page
{
    /**
     * Labels for the transaction types shown in the Deposits and Withdrawals tabs.
     *
     * @var array<string, string>
     */
    public const TRANSACTION_TYPES = [
        'deposit' => 'M-Pesa deposit',
        'shop_load' => 'Shop load',
        'withdrawal' => 'M-Pesa withdrawal',
        'shop_withdrawal' => 'Shop withdrawal',
    ];

    protected static string $resource = AccountResource::class;

    protected string $view = 'filament.resources.accounts.pages.view-account';

    public int $customerId = 0;

    public array $deposits = [];

    public array $withdrawals = [];

    public array $purchases = [];

    public ?array $walletInfo = null;

    public ?array $apiCustomer = null;

    public bool $apiUnavailable = false;

    public function mount(int|string $record): void
    {
        $this->customerId = (int) $record;
        $this->loadApiData();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('walletStatement')
                ->label('Wallet statement')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (): bool => CustomerStatementReport::canAccess())
                ->url(fn (): string => CustomerStatementReport::getUrl(['customerId' => $this->customerId])),
        ];
    }

    private function loadApiData(): void
    {
        $customerId = $this->customerId;
        $gameApi = app(GameApiService::class);

        $anyFailed = false;

        try {
            $this->apiCustomer = Cache::remember(
                "api_customer_{$customerId}",
                300,
                fn () => $gameApi->getCustomer($customerId)
            );

            if (! empty($this->apiCustomer['wallet_id'])) {
                $this->walletInfo = [
                    'balance' => $this->apiCustomer['balance'] ?? 0,
                    'deposits' => $this->apiCustomer['deposits'] ?? 0,
                    'withdraws' => $this->apiCustomer['withdraws'] ?? 0,
                    'shop_loads' => $this->apiCustomer['shop_loads'] ?? 0,
                    'shop_withdrawals' => $this->apiCustomer['shop_withdrawals'] ?? 0,
                    'purchases_load' => $this->apiCustomer['purchases_load'] ?? 0,
                    'purchases_gift' => $this->apiCustomer['purchases_gift'] ?? 0,
                    'purchases_emojis' => $this->apiCustomer['purchases_emojis'] ?? 0,
                    'coins' => $this->apiCustomer['coins'] ?? 0,
                ];
            }
        } catch (\Throwable) {
            $anyFailed = true;
            $this->apiCustomer = null;
            $this->walletInfo = null;
        }

        try {
            $txResponse = Cache::remember(
                "api_transactions_{$customerId}",
                300,
                fn () => $gameApi->getCustomerTransactions($customerId, 'all')
            );
            $transactions = $txResponse['transactions'] ?? [];
            $this->deposits = array_values(array_filter($transactions, fn ($tx) => in_array($tx['payment_type'] ?? '', ['deposit', 'shop_load'], true)));
            $this->withdrawals = array_values(array_filter($transactions, fn ($tx) => in_array($tx['payment_type'] ?? '', ['withdrawal', 'shop_withdrawal'], true)));
        } catch (\Throwable) {
            $anyFailed = true;
            $this->deposits = [];
            $this->withdrawals = [];
        }

        try {
            $this->purchases = Cache::remember(
                "api_purchases_{$customerId}",
                300,
                fn () => $gameApi->getCustomerPurchases($customerId)
            );
        } catch (\Throwable) {
            $anyFailed = true;
            $this->purchases = [];
        }

        $this->apiUnavailable = $anyFailed;
    }

    public function playerInfolist(Schema $schema): Schema
    {
        return $schema
            ->state($this->infolistState())
            ->components([
                Section::make('Identity')
                    ->icon('heroicon-o-identification')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name')
                            ->placeholder('—'),
                        TextEntry::make('phone_no')
                            ->label('Phone')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('email')
                            ->label('Email')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('player_no')
                            ->label('Player number')
                            ->helperText('Given at a bet shop counter to load cash.')
                            ->fontFamily('mono')
                            ->formatStateUsing(fn ($state): string => Format::playerNo($state))
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => (int) $state === Account::STATUS_ACTIVE ? 'Active' : 'Hidden')
                            ->color(fn ($state): string => (int) $state === Account::STATUS_ACTIVE ? 'success' : 'gray'),
                        TextEntry::make('current_vip')
                            ->label('VIP Tier')
                            ->badge()
                            ->color('info')
                            ->placeholder('—'),
                    ]),

                Section::make('Wallet')
                    ->icon('heroicon-o-wallet')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('balance')
                            ->label('Wallet Balance')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                        TextEntry::make('coins')
                            ->label('VCoins')
                            ->numeric(),
                        TextEntry::make('credits')
                            ->label('Game Credits')
                            ->numeric(),
                        TextEntry::make('wallet_deposits')
                            ->label('M-Pesa Deposits')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                        TextEntry::make('wallet_withdraws')
                            ->label('M-Pesa Withdrawals')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                        TextEntry::make('shop_loads')
                            ->label('Shop Loads')
                            ->helperText('Cash loaded at bet shops, after excise duty')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                        TextEntry::make('shop_withdrawals')
                            ->label('Shop Withdrawals')
                            ->helperText('Cash collected at bet shops')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                    ]),

                Tabs::make('Activity')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Single Games')
                            ->schema([
                                ViewEntry::make('single_games')
                                    ->view('infolists.single-games-tab')
                                    ->viewData(['customerId' => $this->customerId]),
                            ]),

                        Tab::make('Tournaments')
                            ->schema([
                                ViewEntry::make('tournament_games')
                                    ->view('infolists.tournament-games-tab')
                                    ->viewData(['customerId' => $this->customerId]),
                            ]),

                        Tab::make('Jackpots')
                            ->schema([
                                ViewEntry::make('jackpot_games')
                                    ->view('infolists.jackpot-games-tab')
                                    ->viewData(['customerId' => $this->customerId]),
                            ]),

                        Tab::make('Deposits')
                            ->badge(count($this->deposits))
                            ->schema([
                                RepeatableEntry::make('deposit_txns')
                                    ->hiddenLabel()
                                    ->placeholder('No deposit records available.')
                                    ->table([
                                        TableColumn::make('Type'),
                                        TableColumn::make('Transaction ID'),
                                        TableColumn::make('Amount'),
                                        TableColumn::make('Date'),
                                    ])
                                    ->schema($this->transactionRowSchema()),
                            ]),

                        Tab::make('Withdrawals')
                            ->badge(count($this->withdrawals))
                            ->schema([
                                RepeatableEntry::make('withdrawal_txns')
                                    ->hiddenLabel()
                                    ->placeholder('No withdrawal records available.')
                                    ->table([
                                        TableColumn::make('Type'),
                                        TableColumn::make('Transaction ID'),
                                        TableColumn::make('Amount'),
                                        TableColumn::make('Date'),
                                    ])
                                    ->schema($this->transactionRowSchema()),
                            ]),

                        Tab::make('Shop loads')
                            ->visible(fn (): bool => BetShopsPage::canAccess())
                            ->schema([
                                ViewEntry::make('shop_loads_tab')
                                    ->hiddenLabel()
                                    ->view('infolists.bet-shop-loads-tab')
                                    ->viewData(['customerId' => $this->customerId]),
                            ]),

                        Tab::make('Shop withdrawals')
                            ->visible(fn (): bool => BetShopsPage::canAccess())
                            ->schema([
                                ViewEntry::make('shop_withdrawals_tab')
                                    ->hiddenLabel()
                                    ->view('infolists.bet-shop-withdrawals-tab')
                                    ->viewData(['customerId' => $this->customerId]),
                            ]),

                        Tab::make('Referrals')
                            ->schema([
                                ViewEntry::make('referrals')
                                    ->hiddenLabel()
                                    ->view('infolists.referrals-tab')
                                    ->viewData(['customerId' => $this->customerId]),
                            ]),

                        Tab::make('Promotions')
                            ->schema([
                                ViewEntry::make('promotions')
                                    ->hiddenLabel()
                                    ->view('infolists.promotions-tab')
                                    ->viewData(['customerId' => $this->customerId]),
                            ]),

                        Tab::make('Purchases')
                            ->badge(count($this->purchases))
                            ->schema([
                                RepeatableEntry::make('purchase_txns')
                                    ->hiddenLabel()
                                    ->placeholder('No purchase records available.')
                                    ->table([
                                        TableColumn::make('Type'),
                                        TableColumn::make('Amount'),
                                        TableColumn::make('Value'),
                                        TableColumn::make('Date'),
                                    ])
                                    ->schema([
                                        TextEntry::make('type')->placeholder('—'),
                                        TextEntry::make('amount')
                                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                                        TextEntry::make('value')->placeholder('—'),
                                        TextEntry::make('date')
                                            ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * Row schema shared by the deposit and withdrawal tables.
     *
     * @return array<int, TextEntry>
     */
    private function transactionRowSchema(): array
    {
        return [
            TextEntry::make('type')->placeholder('—'),
            TextEntry::make('transaction_id')->placeholder('—'),
            TextEntry::make('amount')
                ->formatStateUsing(fn ($state): string => Format::money($state)),
            TextEntry::make('date')
                ->formatStateUsing(fn ($state): string => Format::dateTime($state)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function infolistState(): array
    {
        return [
            'name' => $this->apiCustomer['name'] ?? null,
            'phone_no' => $this->apiCustomer['phone_no'] ?? null,
            'email' => $this->apiCustomer['email'] ?? null,
            'player_no' => $this->apiCustomer['player_no'] ?? null,
            'status' => $this->apiCustomer['status'] ?? Account::STATUS_ACTIVE,
            'current_vip' => $this->apiCustomer['current_vip'] ?? null,
            'credits' => $this->apiCustomer['credits'] ?? 0,
            'balance' => $this->walletInfo['balance'] ?? 0,
            'coins' => $this->walletInfo['coins'] ?? 0,
            'wallet_deposits' => $this->walletInfo['deposits'] ?? 0,
            'wallet_withdraws' => $this->walletInfo['withdraws'] ?? 0,
            'shop_loads' => $this->walletInfo['shop_loads'] ?? 0,
            'shop_withdrawals' => $this->walletInfo['shop_withdrawals'] ?? 0,
            'deposit_txns' => array_map($this->normalizeTransaction(...), $this->deposits),
            'withdrawal_txns' => array_map($this->normalizeTransaction(...), $this->withdrawals),
            'purchase_txns' => $this->purchases,
        ];
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    private function normalizeTransaction(array $transaction): array
    {
        return [
            'type' => self::TRANSACTION_TYPES[$transaction['payment_type'] ?? ''] ?? null,
            'transaction_id' => $transaction['transaction_id'] ?? $transaction['id'] ?? null,
            'amount' => $transaction['amount'] ?? 0,
            'date' => $transaction['date'] ?? $transaction['created_at'] ?? null,
        ];
    }
}
