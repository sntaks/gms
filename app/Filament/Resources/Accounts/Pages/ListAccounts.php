<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Services\GameApiService;
use App\Support\ApiTablePaginator;
use App\Support\Format;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class ListAccounts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = AccountResource::class;

    protected string $view = 'filament.resources.accounts.pages.list-accounts';

    public bool $apiError = false;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int|string $page, int|string $recordsPerPage, ?string $search, ?string $sortColumn, ?string $sortDirection): LengthAwarePaginator => ApiTablePaginator::make(
                response: $this->fetchRecords(),
                page: $page,
                perPage: $recordsPerPage,
                // A player number may be typed with spaces ("1234 5678"); KadiApi sends bare digits.
                search: is_string($search) && preg_match('/^[\d\s-]{4,}$/', $search) === 1 ? preg_replace('/\D/', '', $search) : $search,
                searchKeys: ['name', 'email', 'phone_no', 'player_no'],
                sortColumn: $sortColumn ?? 'id',
                sortDirection: $sortDirection ?? 'desc',
            ))
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('player_no')
                    ->label('Player no.')
                    ->fontFamily('mono')
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => Format::playerNo($state))
                    ->placeholder('—'),
                TextColumn::make('phone_no')
                    ->label('Phone')
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => Format::maskedPhone($state)),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('balance')
                    ->label('Wallet Balance')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => Format::money($state)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => (int) $state === Account::STATUS_ACTIVE ? 'Active' : 'Hidden')
                    ->color(fn ($state): string => (int) $state === Account::STATUS_ACTIVE ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('view')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEye)
                    ->color('info')
                    ->tooltip('View Details')
                    ->visible(fn () => auth()->user()->hasPermissionTo('account.view'))
                    ->url(fn (array $record): ?string => isset($record['id']) ? AccountResource::getUrl('view', ['record' => $record['id']]) : null),

                Action::make('editWallet')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('warning')
                    ->tooltip('Edit Wallet')
                    ->fillForm(fn (array $record): array => ['balance' => $record['balance'] ?? 0])
                    ->visible(fn () => auth()->user()->hasPermissionTo('account.wallet.edit'))
                    ->schema([
                        TextInput::make('balance')
                            ->label('Wallet Balance')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->prefix('KES')
                            ->prefixIcon(Heroicon::OutlinedBanknotes)
                            ->prefixIconColor('warning'),
                    ])
                    ->action(function (array $data, array $record): void {
                        try {
                            app(GameApiService::class)->updateCustomerWallet($record['id'], (float) $data['balance']);
                            Cache::forget('customers_list');
                            Notification::make()->title('Wallet updated')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Failed to update wallet')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('hide')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('danger')
                    ->tooltip('Hide Profile')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => ((int) ($record['status'] ?? Account::STATUS_ACTIVE)) === Account::STATUS_ACTIVE && auth()->user()->hasPermissionTo('account.edit'))
                    ->action(function (array $record): void {
                        try {
                            app(GameApiService::class)->updateCustomer($record['id'], ['status' => Account::STATUS_HIDDEN]);
                            Cache::forget('customers_list');
                            Notification::make()->title('Profile hidden')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Failed to hide profile')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('unhide')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEye)
                    ->color('success')
                    ->tooltip('Unhide Profile')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => ((int) ($record['status'] ?? Account::STATUS_ACTIVE)) === Account::STATUS_HIDDEN && auth()->user()->hasPermissionTo('account.edit'))
                    ->action(function (array $record): void {
                        try {
                            app(GameApiService::class)->updateCustomer($record['id'], ['status' => Account::STATUS_ACTIVE]);
                            Cache::forget('customers_list');
                            Notification::make()->title('Profile unhidden')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Failed to unhide profile')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateHeading(fn (): string => $this->apiError ? 'Customers unavailable' : 'No customers found')
            ->emptyStateDescription(fn (): string => $this->apiError
                ? 'The wallet API could not be reached. Refresh the page to try again.'
                : 'No customers have registered yet.')
            ->striped();
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function fetchRecords(): array
    {
        try {
            $records = Cache::remember('customers_list', 300, fn (): array => app(GameApiService::class)->listCustomers());
            $this->apiError = false;

            return collect($records)
                ->filter(fn ($item): bool => is_array($item) && isset($item['id']))
                ->values()
                ->all();
        } catch (\Throwable) {
            $this->apiError = true;

            return [];
        }
    }
}
