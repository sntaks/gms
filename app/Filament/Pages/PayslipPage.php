<?php

namespace App\Filament\Pages;

use App\Services\GameApiService;
use App\Support\Format;
use App\Traits\SuperAdminAccess;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

class PayslipPage extends Page
{
    use SuperAdminAccess;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Payslip';

    protected static string|UnitEnum|null $navigationGroup = 'Players';

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.payslip';

    public ?int $selectedAccountId = null;

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('selectedAccountId')
                ->label('Select Player')
                ->options(function (): array {
                    try {
                        return collect(app(GameApiService::class)->listCustomers())
                            ->filter(fn ($item) => is_array($item) && isset($item['id'], $item['name']))
                            ->mapWithKeys(fn ($item) => [(string) $item['id'] => (string) $item['name']])
                            ->toArray();
                    } catch (\Throwable) {
                        return [];
                    }
                })
                ->searchable()
                ->live()
                ->afterStateUpdated(fn () => null),
        ]);
    }

    public function payslipInfolist(Schema $schema): Schema
    {
        $data = $this->getPlayerData();
        $account = $data['account'] ?? null;

        return $schema
            ->state([
                'name' => $account?->name,
                'phone_no' => $account?->phone_no ?? null,
                'email' => $account?->email ?? null,
                'credits' => $account?->credits ?? 0,
                'totalDeposits' => $data['totalDeposits'] ?? 0,
                'totalWithdrawals' => $data['totalWithdrawals'] ?? 0,
                'totalPurchases' => $data['totalPurchases'] ?? 0,
                'gamesPlayed' => $data['gamesPlayed'] ?? 0,
                'gamesWon' => $data['gamesWon'] ?? 0,
                'winRate' => $data['winRate'] ?? 0,
            ])
            ->components([
                EmptyState::make('No player selected')
                    ->description('Select a player above to generate their payslip.')
                    ->icon('heroicon-o-document-text')
                    ->visible(fn (): bool => $data === null),

                Section::make('Player Information')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->visible(fn (): bool => $data !== null)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name')
                            ->placeholder('—'),
                        TextEntry::make('phone_no')
                            ->label('Phone')
                            ->placeholder('—'),
                        TextEntry::make('email')
                            ->label('Email')
                            ->placeholder('—'),
                        TextEntry::make('credits')
                            ->label('Game Credits')
                            ->numeric(),
                    ]),

                Section::make('Financial Summary')
                    ->icon('heroicon-o-banknotes')
                    ->columns(3)
                    ->visible(fn (): bool => $data !== null)
                    ->schema([
                        TextEntry::make('totalDeposits')
                            ->label('Total Deposits')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                        TextEntry::make('totalWithdrawals')
                            ->label('Total Withdrawals')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                        TextEntry::make('totalPurchases')
                            ->label('Total Purchases')
                            ->formatStateUsing(fn ($state): string => Format::money($state)),
                    ]),

                Section::make('Game Statistics')
                    ->icon('heroicon-o-puzzle-piece')
                    ->columns(3)
                    ->visible(fn (): bool => $data !== null)
                    ->schema([
                        TextEntry::make('gamesPlayed')
                            ->label('Games Played')
                            ->numeric(),
                        TextEntry::make('gamesWon')
                            ->label('Games Won')
                            ->numeric(),
                        TextEntry::make('winRate')
                            ->label('Win Rate')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state.'%')
                            ->color(fn ($state): string => match (true) {
                                $state >= 50 => 'success',
                                $state > 0 => 'warning',
                                default => 'gray',
                            }),
                    ]),
            ]);
    }

    public function getPlayerData(): ?array
    {
        if (! $this->selectedAccountId) {
            return null;
        }

        $gameApi = app(GameApiService::class);

        try {
            $apiCustomer = Cache::remember("payslip_customer_{$this->selectedAccountId}", 300,
                fn () => $gameApi->getCustomer($this->selectedAccountId)
            );
        } catch (\Throwable) {
            return null;
        }

        try {
            $txResponse = Cache::remember("payslip_{$this->selectedAccountId}", 300,
                fn () => $gameApi->getCustomerTransactions($this->selectedAccountId, 'all')
            );
            $transactions = $txResponse['transactions'] ?? [];
            $purchases = Cache::remember("payslip_purchases_{$this->selectedAccountId}", 300,
                fn () => $gameApi->getCustomerPurchases($this->selectedAccountId)
            );
        } catch (\Throwable) {
            $transactions = [];
            $purchases = [];
        }

        // Cash paid in or collected at a bet shop counts as a deposit or withdrawal too.
        $totalDeposits = collect($transactions)->whereIn('payment_type', ['deposit', 'shop_load'])->sum('amount');
        $totalWithdrawals = collect($transactions)->whereIn('payment_type', ['withdrawal', 'shop_withdrawal'])->sum('amount');
        $totalPurchases = collect($purchases)->sum('amount');

        try {
            $gamesPlayed = Cache::remember("payslip_games_{$this->selectedAccountId}", 300,
                fn () => $gameApi->getCustomerGamesPlayed($this->selectedAccountId)
            );
            $singleGames = $gamesPlayed['single_games'] ?? [];
            $gamesPlayedCount = count($singleGames);
            $gamesWon = count(array_filter($singleGames, fn ($g) => ($g['payment_type'] ?? '') === 'win'));
        } catch (\Throwable) {
            $gamesPlayedCount = 0;
            $gamesWon = 0;
        }

        return [
            'account' => (object) $apiCustomer,
            'totalDeposits' => $totalDeposits,
            'totalWithdrawals' => $totalWithdrawals,
            'totalPurchases' => $totalPurchases,
            'gamesPlayed' => $gamesPlayedCount,
            'gamesWon' => $gamesWon,
            'winRate' => $gamesPlayedCount > 0
                ? round(($gamesWon / $gamesPlayedCount) * 100, 1)
                : 0,
        ];
    }

    public function downloadPdf(): Response
    {
        $data = $this->getPlayerData();

        if (! $data) {
            Notification::make()
                ->title('Please select a player first')
                ->warning()
                ->send();

            return response()->noContent();
        }

        $pdf = Pdf::loadView('filament.pages.payslip-pdf', $data)
            ->setPaper('a4', 'portrait');

        return $pdf->download("payslip_{$data['account']->id}_{$data['account']->name}.pdf");
    }
}
