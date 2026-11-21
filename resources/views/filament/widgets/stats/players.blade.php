@php
    use App\Support\StatsFormat;

    $active = (array) ($players['active'] ?? []);
    $deltas = (array) ($players['delta_pct'] ?? []);
    $new = (int) ($players['new'] ?? 0);
    $returning = (int) ($players['returning'] ?? 0);
    $total = max($new + $returning, 1);
    $cross = (array) ($players['cross_game'] ?? []);
    $muted = 'font-size: 0.75rem; color: var(--gray-500);';
    $value = 'font-size: 1.25rem; font-weight: 600;';
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Players" description="Active is the last day, 7 days and 30 days ending on the period's last day.">
        @if ($problem)
            <p style="{{ $muted }}">{{ $problem }}</p>
        @else
            <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem;">
                @foreach (['day' => 'Active today', 'week' => 'Active 7 days', 'month' => 'Active 30 days'] as $key => $label)
                    @php($delta = StatsFormat::delta($deltas['active'][$key] ?? null))
                    <div title="{{ StatsFormat::tooltip('active_players') }}">
                        <p style="{{ $muted }}">{{ $label }}</p>
                        <p style="{{ $value }}">{{ StatsFormat::count($active[$key] ?? null) }}</p>
                        <p style="font-size: 0.75rem; color: var(--{{ $delta['color'] }}-600);">{{ $delta['label'] }}</p>
                    </div>
                @endforeach
            </div>

            <div style="margin-top: 1.25rem;">
                <div style="display: flex; justify-content: space-between; {{ $muted }}">
                    <span>New {{ StatsFormat::count($new) }}</span>
                    <span>Returning {{ StatsFormat::count($returning) }}</span>
                </div>
                <div role="img" aria-label="New {{ $new }}, returning {{ $returning }}"
                    style="display: flex; margin-top: 0.375rem; height: 0.75rem; border-radius: 9999px; overflow: hidden; background: color-mix(in oklab, var(--gray-400) 30%, transparent);">
                    <div style="width: {{ $new / $total * 100 }}%; background: var(--primary-500);"></div>
                    <div style="width: {{ $returning / $total * 100 }}%; background: var(--info-500);"></div>
                </div>
                <p style="margin-top: 0.375rem; {{ $muted }}">New: registered in the period. Returning: registered before it.</p>
            </div>

            <div style="margin-top: 1.25rem;">
                <p style="font-size: 0.875rem; font-weight: 600;">Games played per player</p>
                <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem; margin-top: 0.5rem;">
                    @foreach (['1' => '1 game', '2' => '2 games', '3_plus' => '3+ games'] as $key => $label)
                        <div>
                            <p style="{{ $muted }}">{{ $label }}</p>
                            <p style="{{ $value }}">{{ StatsFormat::count($cross[$key] ?? 0) }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
