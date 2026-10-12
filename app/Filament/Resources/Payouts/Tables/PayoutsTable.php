<?php

namespace App\Filament\Resources\Payouts\Tables;

use App\Enums\ExpenseCategory;
use App\Enums\PayoutStatus;
use App\Filament\Exports\PayoutExporter;
use App\Models\Expense;
use App\Models\Payout;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ExportBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayoutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stickyableColumns()
            ->striped()
            ->defaultSort('created_at', 'desc')
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('payee.name')
                            ->label('Payee')
                            ->weight('bold')
                            ->searchable()
                            ->sortable(),
                        TextColumn::make('reason')
                            ->limit(40)
                            ->color('gray')
                            ->size(TextSize::Small),
                    ]),
                    Stack::make([
                        TextColumn::make('amount')
                            ->prefix('KES ')
                            ->numeric(2)
                            ->weight('bold')
                            ->sortable(),
                        TextColumn::make('status')
                            ->badge()
                            ->sortable(),
                    ])->visibleFrom('md'),
                    Stack::make([
                        TextColumn::make('payee.phone')
                            ->label('Phone')
                            ->color('gray'),
                        TextColumn::make('created_at')
                            ->label('Created')
                            ->dateTime()
                            ->color('gray')
                            ->size(TextSize::Small)
                            ->sortable(),
                    ])->visibleFrom('md'),
                ])->from('md'),
                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approvedBy.name')
                    ->label('Approved By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PayoutStatus::class),
                Filter::make('created_at')
                    ->label('Date Range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('approve')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->tooltip('Approve Payout')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Payout')
                    ->modalDescription(fn (Payout $record) => 'Approve KES '.number_format($record->amount, 2)." payout to {$record->payee->name}?")
                    ->action(function (Payout $record): void {
                        if (! self::approvePayout($record)) {
                            Notification::make()->title('You cannot approve this payout')->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Payout approved — queued for M-Pesa processing')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Payout $record) => $record->status === PayoutStatus::Pending && auth()->user()->can('approve', $record)),
                Action::make('decline')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->tooltip('Decline Payout')
                    ->requiresConfirmation(false)
                    ->form([
                        Textarea::make('declined_reason')
                            ->label('Reason for declining')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Payout $record, array $data): void {
                        DB::transaction(function () use ($record, $data): void {
                            $fresh = Payout::lockForUpdate()->find($record->id);
                            if ($fresh->status !== PayoutStatus::Pending) {
                                return;
                            }
                            $fresh->update([
                                'status' => PayoutStatus::Declined,
                                'declined_by' => auth()->id(),
                                'declined_reason' => $data['declined_reason'],
                            ]);
                        });
                        Notification::make()->title('Payout declined')->warning()->send();
                    })
                    ->visible(fn (Payout $record) => $record->status === PayoutStatus::Pending && auth()->user()->hasPermissionTo('payouts.decline')),
                Action::make('retry')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->tooltip('Retry Payout')
                    ->requiresConfirmation()
                    ->modalHeading('Retry Failed Payout')
                    ->action(function (Payout $record): void {
                        $requeued = DB::transaction(function () use ($record): bool {
                            $fresh = Payout::lockForUpdate()->find($record->id);
                            if ($fresh->status !== PayoutStatus::Failed || Gate::denies('retry', $fresh)) {
                                return false;
                            }
                            $fresh->update(['status' => PayoutStatus::Approved]);

                            return true;
                        });

                        if (! $requeued) {
                            Notification::make()->title('You cannot retry this payout')->danger()->send();

                            return;
                        }

                        Notification::make()->title('Payout re-queued for processing')->info()->send();
                    })
                    ->visible(fn (Payout $record) => $record->status === PayoutStatus::Failed && auth()->user()->can('retry', $record)),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label('Export Payouts')
                    ->icon('hugeicons-file-export')
                    ->color('success')
                    ->exporter(PayoutExporter::class),
                BulkActionGroup::make([
                    BulkAction::make('bulk_approve')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn () => auth()->user()->hasRole('super-admin') && auth()->user()->hasPermissionTo('payouts.approve'))
                        ->action(function (Collection $records): void {
                            $approved = 0;
                            $skipped = 0;
                            foreach ($records as $record) {
                                if ($record->status !== PayoutStatus::Pending) {
                                    continue;
                                }

                                self::approvePayout($record) ? $approved++ : $skipped++;
                            }

                            Notification::make()
                                ->title("{$approved} payout(s) approved")
                                ->body($skipped > 0 ? "{$skipped} skipped — you cannot approve payouts you created." : null)
                                ->status($skipped > 0 ? 'warning' : 'success')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulk_decline')
                        ->label('Decline Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn () => auth()->user()->hasPermissionTo('payouts.decline'))
                        ->schema([
                            Textarea::make('declined_reason')
                                ->label('Reason for declining')
                                ->required()
                                ->rows(3),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === PayoutStatus::Pending) {
                                    DB::transaction(function () use ($record, $data): void {
                                        $fresh = Payout::lockForUpdate()->find($record->id);
                                        if ($fresh->status !== PayoutStatus::Pending) {
                                            return;
                                        }
                                        $fresh->update([
                                            'status' => PayoutStatus::Declined,
                                            'declined_by' => auth()->id(),
                                            'declined_reason' => $data['declined_reason'],
                                        ]);
                                    });
                                    $count++;
                                }
                            }
                            Notification::make()->title("{$count} payout(s) declined")->warning()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->before(function (Collection $records): void {
                            $blocked = $records->filter(fn ($r) => in_array($r->status, [
                                PayoutStatus::Processing,
                                PayoutStatus::Approved,
                            ]));
                            if ($blocked->isNotEmpty()) {
                                Notification::make()
                                    ->title('Cannot delete approved/processing payouts')
                                    ->danger()
                                    ->send();
                                throw new ValidationException(
                                    Validator::make([], [])
                                );
                            }
                        }),
                ]),
            ]);
    }

    /**
     * Approve a pending payout under maker-checker rules; returns false when the current user may not approve it.
     */
    private static function approvePayout(Payout $record): bool
    {
        return DB::transaction(function () use ($record): bool {
            $fresh = Payout::lockForUpdate()->find($record->id);
            if ($fresh->status !== PayoutStatus::Pending) {
                return true;
            }

            if (Gate::denies('approve', $fresh)) {
                return false;
            }

            $expense = Expense::create([
                'category' => ExpenseCategory::Expense,
                'amount' => $fresh->amount,
                'description' => "Payout to {$fresh->payee->name} ({$fresh->payee->designation}) — {$fresh->reason}",
            ]);

            $fresh->update([
                'status' => PayoutStatus::Approved,
                'approved_by' => auth()->id(),
                'expense_id' => $expense->id,
            ]);

            return true;
        });
    }
}
