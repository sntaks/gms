<?php

namespace App\Filament\Pages;

use App\Services\GameApiService;
use App\Support\Format;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

/**
 * Every admin write on prediction markets, as the Kadi API logged it
 * (`GET /predict/admin/audit`), optionally for one market.
 */
class PredictAuditPage extends PredictListPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'predictions/audit';

    protected ?string $heading = 'Prediction audit log';

    protected ?string $subheading = 'Every admin action on prediction markets, newest first.';

    #[Url]
    public ?int $market = null;

    protected function fetch(array $filters): array
    {
        return app(GameApiService::class)->predictAudit([...$filters, 'market_id' => $this->market]);
    }

    protected function configureTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->formatStateUsing(fn (?string $state): string => Format::dateTime($state)),
                TextColumn::make('action')->badge()->formatStateUsing(fn (?string $state): string => str_replace('_', ' ', (string) $state)),
                TextColumn::make('admin_user')->label('By')->placeholder('System'),
                TextColumn::make('market_id')->label('Market')->formatStateUsing(fn ($state): string => '#'.$state)
                    ->url(fn (array $record): ?string => static::marketUrl($record['market_id'] ?? null))->placeholder('—'),
                TextColumn::make('reason')->wrap()->placeholder('—'),
                TextColumn::make('changed')
                    ->label('Changed')
                    ->state(fn (array $record): ?string => static::changes($record['before'] ?? null, $record['after'] ?? null))
                    ->wrap()
                    ->placeholder('—'),
            ]);
    }

    /**
     * "status: open → suspended · cut_bp: 1000 → 2000".
     */
    public static function changes(mixed $before, mixed $after): ?string
    {
        $before = is_array($before) ? $before : [];
        $after = is_array($after) ? $after : [];
        $show = fn (mixed $value): string => $value === null ? '—' : (is_scalar($value) ? (string) $value : (string) json_encode($value));

        $lines = collect(array_unique([...array_keys($before), ...array_keys($after)]))
            ->filter(fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null))
            ->map(fn (string $key): string => $key.': '.$show($before[$key] ?? null).' → '.$show($after[$key] ?? null));

        return $lines->isEmpty() ? null : $lines->implode(' · ');
    }
}
