@php
    use App\Support\Format;
    use App\Support\StatsFormat;

    $muted = 'font-size: 0.75rem; color: var(--gray-500);';
    $cell = 'padding: 0.375rem 0.5rem; text-align: center; white-space: nowrap;';
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Weekly retention" description="Share of each registration week's players who played again in week 1 to 8 after it. Empty: that week has not ended yet.">
        @if ($problem)
            <p style="{{ $muted }}">{{ $problem }}</p>
        @elseif ($cohorts === [])
            <p style="{{ $muted }}">No registrations in this period.</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; min-width: 44rem; border-collapse: separate; border-spacing: 2px; font-size: 0.8125rem;">
                    <thead>
                        <tr style="{{ $muted }}">
                            <th style="text-align: left; padding: 0.375rem 0.5rem;">Week of</th>
                            <th style="text-align: right; padding: 0.375rem 0.5rem;">Registered</th>
                            @for ($week = 1; $week <= 8; $week++)
                                <th style="{{ $cell }}">Week {{ $week }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cohorts as $cohort)
                            <tr>
                                <td style="padding: 0.375rem 0.5rem; white-space: nowrap;">{{ Format::date($cohort['week'] ?? null) }}</td>
                                <td style="padding: 0.375rem 0.5rem; text-align: right;">{{ StatsFormat::count($cohort['registered'] ?? 0) }}</td>
                                @for ($week = 1; $week <= 8; $week++)
                                    @php($rate = $cohort['weeks']["week_{$week}"] ?? null)
                                    <td style="{{ $cell }} border-radius: 0.25rem; {{ $rate === null ? '' : 'background: color-mix(in oklab, var(--success-500) '.round(8 + min((float) $rate, 100) * 0.8).'%, transparent);' }}">
                                        {{ $rate === null ? '' : StatsFormat::percent($rate) }}
                                    </td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
