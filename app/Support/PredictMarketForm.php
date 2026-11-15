<?php

namespace App\Support;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;

/**
 * The prediction market form, and how its values map to the Kadi API's
 * (`StorePredictMarketRequest`, `UpdatePredictMarketRequest`).
 *
 * Percentages are entered as percent and sent as fractions; times are entered
 * in Nairobi time and sent as ISO 8601. Optional terms left blank take the
 * API's defaults on a new market. Once a market is published only the
 * {@see LIVE_FIELDS} may change (api MarketService::EDITABLE_WHEN_LIVE).
 */
final class PredictMarketForm
{
    /**
     * Form field => API field, for 422 errors.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'engine' => 'engine',
        'question' => 'question',
        'description' => 'description',
        'resolution_criteria' => 'resolution_criteria',
        'resolution_source' => 'resolution_source',
        'category' => 'category',
        'image_url' => 'image_url',
        'countries' => 'countries',
        'close_at' => 'close_at',
        'resolution_deadline_at' => 'resolution_deadline_at',
        'start_probability' => 'start_probability_percent',
        'seed' => 'seed',
        'cut' => 'cut_percent',
        'min_stake' => 'min_stake',
        'max_stake' => 'max_stake',
        'max_payout' => 'max_payout',
        'min_multiplier' => 'min_multiplier',
        'liquidity' => 'liquidity',
        'margin' => 'margin_percent',
        'max_liability' => 'max_liability',
        'max_customer_stake' => 'max_customer_stake',
    ];

    /**
     * API fields that may change after publishing.
     *
     * @var list<string>
     */
    public const LIVE_FIELDS = [
        'question', 'description', 'resolution_source', 'image_url', 'category', 'countries',
        'close_at', 'resolution_deadline_at', 'margin', 'max_liability', 'max_customer_stake',
    ];

    /**
     * @return list<Component|Field>
     */
    public static function draftFields(bool $creating, bool $imported = false): array
    {
        return [
            ...self::wordingFields(includeCriteria: true),
            Section::make('Pricing')
                ->columns(2)
                ->schema([
                    Select::make('engine')
                        ->options(['pool' => 'Pool (pari-mutuel)', 'lmsr' => 'LMSR (market maker)'])
                        ->default('pool')
                        ->required()
                        ->live()
                        ->visible($creating),
                    TextInput::make('start_probability_percent')
                        ->label('Opening chance of Yes (%)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(99)
                        ->step(0.01)
                        ->required($creating && ! $imported),
                    TextInput::make('seed')
                        ->label('Seed (KES)')
                        ->helperText('Notional: moves no money. Blank uses the Kadi API default.')
                        ->numeric()
                        ->minValue(100)
                        ->maxValue(10_000_000)
                        ->visible(fn (Get $get): bool => $get('engine') !== 'lmsr'),
                    TextInput::make('cut_percent')
                        ->label('Cut (% of the losing side)')
                        ->helperText('Blank uses the Kadi API default.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(50)
                        ->step(0.01),
                    TextInput::make('min_multiplier')
                        ->label('Minimum multiplier')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(2)
                        ->step(0.01)
                        ->visible(fn (Get $get): bool => $get('engine') !== 'lmsr'),
                    TextInput::make('liquidity')
                        ->label('Liquidity b (KES)')
                        ->helperText('The most the house can lose is about b × ln(1 / the smaller opening price).')
                        ->numeric()
                        ->minValue(100)
                        ->maxValue(10_000_000)
                        ->visible(fn (Get $get): bool => $get('engine') === 'lmsr'),
                ]),
            Section::make('Limits (KES)')
                ->columns(3)
                ->schema([
                    TextInput::make('min_stake')->label('Minimum stake')->numeric()->gt(0),
                    TextInput::make('max_stake')->label('Maximum stake')->numeric()->gt(0),
                    TextInput::make('max_payout')->label('Maximum payout')->numeric()->gt(0),
                ]),
        ];
    }

    /**
     * What may change once a market is live.
     *
     * @return list<Component|Field>
     */
    public static function liveFields(bool $quoted): array
    {
        return [
            ...self::wordingFields(includeCriteria: false),
            Section::make('Fixed-odds limits')
                ->description('Apply to the next quote only, never to bets already placed.')
                ->columns(3)
                ->visible($quoted)
                ->schema([
                    TextInput::make('margin_percent')->label('Margin (%)')->numeric()->minValue(0)->maxValue(50)->step(0.01),
                    TextInput::make('max_liability')->label('Max liability per side (KES)')->numeric()->gt(0)->maxValue(100_000_000),
                    TextInput::make('max_customer_stake')->label('Max stake per customer (KES)')->numeric()->gt(0)->maxValue(100_000_000),
                ]),
        ];
    }

    /**
     * @return list<Component|Field>
     */
    private static function wordingFields(bool $includeCriteria): array
    {
        return array_values(array_filter([
            TextInput::make('question')->required()->maxLength(255),
            Textarea::make('description')->maxLength(5000)->rows(3),
            $includeCriteria
                ? Textarea::make('resolution_criteria')
                    ->label('Resolution criteria')
                    ->helperText('Exactly what makes it Yes. Frozen once published.')
                    ->required()
                    ->maxLength(5000)
                    ->rows(3)
                : null,
            TextInput::make('resolution_source')->label('Resolution source')->maxLength(255),
            Select::make('category')->options(config('predict.categories'))->required(),
            CheckboxList::make('countries')->options(config('predict.countries'))->required()->default(array_keys(config('predict.countries'))),
            TextInput::make('image_url')->label('Image URL')->url()->maxLength(500),
            DateTimePicker::make('close_at')
                ->label('Betting closes (Nairobi)')
                ->seconds(false)
                ->required()
                ->after('now'),
            DateTimePicker::make('resolution_deadline_at')
                ->label('Resolve by (Nairobi)')
                ->seconds(false)
                ->required()
                ->afterOrEqual('close_at'),
        ]));
    }

    /**
     * The form's values as the API's fields. Blank optional fields are left out.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $only  API fields to keep (e.g. {@see LIVE_FIELDS}).
     * @return array<string, mixed>
     */
    public static function payload(array $data, ?array $only = null): array
    {
        $blank = fn (mixed $value): bool => $value === null || (is_string($value) && trim($value) === '');
        $text = fn (string $key): ?string => $blank($data[$key] ?? null) ? null : trim((string) $data[$key]);
        $number = fn (string $key): ?float => $blank($data[$key] ?? null) ? null : (float) $data[$key];
        $fraction = fn (string $key): ?float => $blank($data[$key] ?? null) ? null : round(((float) $data[$key]) / 100, 4);
        $time = fn (string $key): ?string => $blank($data[$key] ?? null) ? null : Carbon::parse($data[$key], config('app.timezone'))->toIso8601String();

        $payload = [
            'engine' => $text('engine'),
            'question' => $text('question'),
            'description' => $text('description'),
            'resolution_criteria' => $text('resolution_criteria'),
            'resolution_source' => $text('resolution_source'),
            'category' => $text('category'),
            'image_url' => $text('image_url'),
            'countries' => isset($data['countries']) ? array_values((array) $data['countries']) : null,
            'close_at' => $time('close_at'),
            'resolution_deadline_at' => $time('resolution_deadline_at'),
            'start_probability' => $fraction('start_probability_percent'),
            'seed' => $number('seed'),
            'cut' => $fraction('cut_percent'),
            'min_multiplier' => $number('min_multiplier'),
            'liquidity' => $number('liquidity'),
            'min_stake' => $number('min_stake'),
            'max_stake' => $number('max_stake'),
            'max_payout' => $number('max_payout'),
            'margin' => $fraction('margin_percent'),
            'max_liability' => $number('max_liability'),
            'max_customer_stake' => $number('max_customer_stake'),
        ];

        if ($only !== null) {
            $payload = array_intersect_key($payload, array_flip($only));
        }

        return array_filter($payload, fn (mixed $value): bool => $value !== null);
    }

    /**
     * An API market as form values, for editing.
     *
     * @param  array<string, mixed>  $market
     * @return array<string, mixed>
     */
    public static function fill(array $market): array
    {
        $percent = fn (string $key): ?float => isset($market[$key]) ? round(((float) $market[$key]) * 100, 2) : null;
        $local = fn (string $key): ?string => empty($market[$key]) ? null : Carbon::parse($market[$key])->timezone(config('app.timezone'))->format('Y-m-d H:i:s');

        return [
            'engine' => $market['engine'] ?? null,
            'question' => $market['question'] ?? null,
            'description' => $market['description'] ?? null,
            'resolution_criteria' => $market['resolution_criteria'] ?? null,
            'resolution_source' => $market['resolution_source'] ?? null,
            'category' => $market['category'] ?? null,
            'image_url' => $market['image_url'] ?? null,
            'countries' => $market['countries'] ?? [],
            'close_at' => $local('close_at'),
            'resolution_deadline_at' => $local('resolution_deadline_at'),
            'start_probability_percent' => $percent('start_probability'),
            'seed' => $market['seed'] ?? null,
            'cut_percent' => $percent('cut'),
            'min_multiplier' => $market['min_multiplier'] ?? null,
            'liquidity' => $market['liquidity'] ?? null,
            'min_stake' => $market['min_stake'] ?? null,
            'max_stake' => $market['max_stake'] ?? null,
            'max_payout' => $market['max_payout'] ?? null,
            'margin_percent' => $percent('margin'),
            'max_liability' => $market['max_liability'] ?? null,
            'max_customer_stake' => $market['max_customer_stake'] ?? null,
        ];
    }
}
