@php
    use App\Support\StatsFilters;
    use App\Support\StatsFormat;

    $muted = 'font-size: 0.75rem; color: var(--gray-500);';
    $value = 'font-size: 1.25rem; font-weight: 600;';
    $rows = array_values(array_filter((array) ($liability['by_product'] ?? []), 'is_array'));
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Liability now" description="Open exposure at this moment, whatever the period.">
        @if ($problem)
            <p style="{{ $muted }}">{{ $problem }}</p>
        @else
            <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem;">
                <div title="{{ StatsFormat::tooltip('open_wagers') }}">
                    <p style="{{ $muted }}">Open wagers</p>
                    <p style="{{ $value }}">{{ StatsFormat::count($liability['open_wagers'] ?? null) }}</p>
                </div>
                <div title="{{ StatsFormat::tooltip('open_stakes') }}">
                    <p style="{{ $muted }}">Open stakes</p>
                    <p style="{{ $value }}">{{ StatsFormat::money($liability['open_stakes'] ?? null) }}</p>
                </div>
                <div title="{{ StatsFormat::tooltip('open_potential_payout') }}">
                    <p style="{{ $muted }}">Potential payout</p>
                    <p style="{{ $value }}">{{ StatsFormat::money($liability['open_potential_payout'] ?? null) }}</p>
                </div>
            </div>

            <div style="margin-top: 1rem; overflow-x: auto;">
                <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
                    <thead>
                        <tr style="{{ $muted }} text-align: left;">
                            <th style="padding: 0.375rem 0.5rem 0.375rem 0;">Product</th>
                            <th style="padding: 0.375rem 0.5rem; text-align: right;">Open stakes</th>
                            <th style="padding: 0.375rem 0 0.375rem 0.5rem; text-align: right;">Potential payout</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr style="border-top: 1px solid color-mix(in oklab, var(--gray-400) 25%, transparent);">
                                <td style="padding: 0.375rem 0.5rem 0.375rem 0;">{{ StatsFilters::productName($row['product'] ?? null) }}</td>
                                <td style="padding: 0.375rem 0.5rem; text-align: right; white-space: nowrap;">{{ StatsFormat::money($row['open_stakes'] ?? null) }}</td>
                                <td style="padding: 0.375rem 0 0.375rem 0.5rem; text-align: right; white-space: nowrap;">{{ StatsFormat::money($row['open_potential_payout'] ?? null) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="padding: 0.5rem 0; {{ $muted }}">Nothing open.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
