<?php

namespace App\Filament\Resources\Payouts\Schemas;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

class PayoutInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payout')
                ->icon(Heroicon::OutlinedBanknotes)
                ->columns(['default' => 2, 'md' => 3])
                ->schema([
                    TextEntry::make('amount')
                        ->money('KES')
                        ->weight(FontWeight::Bold)
                        ->size(TextSize::Large),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('requires_independent_approval')
                        ->label('Approval')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Needs checker' : 'Self-approvable')
                        ->color(fn (bool $state): string => $state ? 'danger' : 'success')
                        ->icon(fn (bool $state): Heroicon => $state ? Heroicon::OutlinedUserGroup : Heroicon::OutlinedUser)
                        ->tooltip(fn (bool $state): string => $state
                            ? 'Exceeded the daily cap when created — a super-admin other than the maker must approve'
                            : 'Within the daily cap when created — the maker may approve it'),
                    TextEntry::make('reason')
                        ->columnSpanFull(),
                ]),
            Section::make('Payee')
                ->icon(Heroicon::OutlinedUser)
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    TextEntry::make('payee.name')
                        ->label('Name')
                        ->weight(FontWeight::Bold),
                    TextEntry::make('payee.phone')
                        ->label('Phone')
                        ->icon(Heroicon::OutlinedPhone)
                        ->copyable(),
                    TextEntry::make('payee.designation')
                        ->label('Designation'),
                    TextEntry::make('payee.team')
                        ->label('Team'),
                ]),
            Section::make('Approval Trail')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    TextEntry::make('createdBy.name')
                        ->label('Created by')
                        ->placeholder('Unknown'),
                    TextEntry::make('created_at')
                        ->label('Created at')
                        ->dateTime(),
                    TextEntry::make('approvedBy.name')
                        ->label('Approved by')
                        ->placeholder('—')
                        ->visible(fn (Payout $record): bool => $record->approved_by !== null),
                    TextEntry::make('declinedBy.name')
                        ->label('Declined by')
                        ->placeholder('—')
                        ->visible(fn (Payout $record): bool => $record->status === PayoutStatus::Declined),
                    TextEntry::make('declined_reason')
                        ->label('Decline reason')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->visible(fn (Payout $record): bool => $record->status === PayoutStatus::Declined),
                    IconEntry::make('expense_id')
                        ->label('Booked as expense')
                        ->state(fn (Payout $record): bool => $record->expense_id !== null)
                        ->boolean(),
                ]),
            Section::make('M-Pesa')
                ->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->columns(['default' => 1, 'sm' => 2])
                ->collapsible()
                ->schema([
                    TextEntry::make('conversation_id')
                        ->label('Conversation ID')
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('processed_at')
                        ->label('Processed at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('response')
                        ->label('Response')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ])->columns(1);
    }
}
