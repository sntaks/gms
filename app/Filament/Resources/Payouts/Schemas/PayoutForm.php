<?php

namespace App\Filament\Resources\Payouts\Schemas;

use App\Models\Payee;
use App\Services\PayoutDailyCap;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class PayoutForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payout Details')
                ->columns(2)
                ->icon('heroicon-o-banknotes')
                ->schema([
                    Select::make('payee_id')
                        ->label('Payee')
                        ->relationship('payee', 'name')
                        ->getOptionLabelFromRecordUsing(fn (Payee $record) => "{$record->name} — {$record->phone} ({$record->designation})")
                        ->searchable(['name', 'phone'])
                        ->createOptionForm([
                            Section::make('Payee Details')
                                ->columns(2)
                                ->icon('heroicon-o-user')
                                ->schema([
                                    TextInput::make('name')
                                        ->required()
                                        ->maxLength(255)
                                        ->prefixIcon(Heroicon::OutlinedUser)
                                        ->prefixIconColor('primary'),
                                    TextInput::make('phone')
                                        ->required()
                                        ->tel()
                                        ->maxLength(20)
                                        ->placeholder('2547XXXXXXXX or 2541XXXXXXXX')
                                        ->regex('/^(?:0|254)[0-9]{9}$/')
                                        ->validationMessages([
                                            'regex' => 'Phone must be in format 2547XXXXXXXX, 2541XXXXXXXX, or 07XXXXXXXX.',
                                        ])
                                        ->prefixIcon(Heroicon::OutlinedPhone)
                                        ->prefixIconColor('info'),
                                    TextInput::make('email')
                                        ->email()
                                        ->nullable()
                                        ->maxLength(255)
                                        ->prefixIcon(Heroicon::OutlinedEnvelope)
                                        ->prefixIconColor('gray'),
                                    TextInput::make('designation')
                                        ->required()
                                        ->maxLength(255)
                                        ->placeholder('e.g. Accountant, Director')
                                        ->prefixIcon(Heroicon::OutlinedBriefcase)
                                        ->prefixIconColor('warning'),
                                    TextInput::make('team')
                                        ->required()
                                        ->maxLength(255)
                                        ->placeholder('e.g. Finance, Operations')
                                        ->prefixIcon(Heroicon::OutlinedUserGroup)
                                        ->prefixIconColor('success'),
                                ])->columns(2)->columnSpan(['lg' => 3]),
                        ])
                        ->createOptionAction(function (Action $action) {
                            return $action
                                ->modalHeading('Add Payee')
                                ->modalDescription('Create a new payee and select it')
                                ->modalSubmitActionLabel('Add Payee');
                        })
                        ->preload()
                        ->required()
                        ->prefixIcon(Heroicon::OutlinedUser)
                        ->prefixIconColor('primary')
                        ->helperText('Search by payee name or phone number'),
                    TextInput::make('amount')
                        ->label('Amount (KES)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->prefix('KES')
                        ->live(debounce: 500)
                        ->suffixIcon(Heroicon::OutlinedBanknotes)
                        ->suffixIconColor('success')
                        ->helperText(fn (PayoutDailyCap $dailyCap): string => 'Daily cap: KES '.number_format($dailyCap->remainingToday(), 2).' of '.number_format($dailyCap->cap(), 2).' remaining today'),
                    Callout::make('This payout exceeds the daily cap')
                        ->key('dailyCapWarning')
                        ->description(function (Get $get, PayoutDailyCap $dailyCap): string {
                            $amount = (float) $get('amount');
                            $used = $dailyCap->usedToday();

                            return 'KES '.number_format($used, 2).' of the KES '.number_format($dailyCap->cap(), 2).' daily cap is already used. '
                                .'With this payout of KES '.number_format($amount, 2).' the total will be KES '.number_format($used + $amount, 2).', '
                                .'so another super-admin must approve it — you will not be able to approve it yourself.';
                        })
                        ->warning()
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->visible(fn (Get $get, PayoutDailyCap $dailyCap): bool => is_numeric($get('amount'))
                            && (float) $get('amount') > 0
                            && $dailyCap->requiresIndependentApproval((float) $get('amount')))
                        ->columnSpanFull(),
                ])->columns(2)->columnSpan(['lg' => 3]),
            Section::make('Payout Reason')->schema([
                Textarea::make('reason')
                    ->required()
                    ->columnSpanFull()
                    ->rows(3)
                    ->placeholder('Describe the reason for this payout…'),
            ])->columnSpanFull(),
        ])->columns(3);
    }
}
