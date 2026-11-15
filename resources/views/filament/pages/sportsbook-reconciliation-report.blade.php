<x-filament-panels::page>
    @php($reconciliation = $this->reconciliation())

    @foreach ($reconciliation['errors'] as $error)
        <x-filament::section>
            <div class="flex items-center gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0 text-warning-500" />
                <p class="text-sm font-semibold text-warning-600 dark:text-warning-400">{{ $error }}</p>
            </div>
        </x-filament::section>
    @endforeach

    @foreach ($reconciliation['warnings'] as $warning)
        <x-filament::section>
            <div class="flex items-center gap-3">
                <x-filament::icon icon="heroicon-o-information-circle" class="h-5 w-5 shrink-0 text-info-500" />
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $warning }}</p>
            </div>
        </x-filament::section>
    @endforeach

    {{ $this->table }}

    @php($needs = $reconciliation['needs_operator'])
    <x-filament::section heading="Needs an operator now" :description="'Failed or overdue wager ops, and placements still unknown after 10 minutes, as the Kadi site reports them.'">
        @if ($needs['ops'] === [] && $needs['pending_bets'] === [] && $needs['pending_jackpot_entries'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing needs attention.</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($needs['ops'] as $op)
                    <li>
                        <span class="font-semibold">{{ ucfirst($op['operation'] ?? 'op') }} v{{ $op['version'] ?? '?' }}</span>
                        · {{ $op['product'] ?? '' }} · {{ $op['status'] ?? '' }}, {{ $op['attempts'] ?? 0 }} attempts
                        @if (! empty($op['last_error']))
                            · <span class="text-danger-600">{{ $op['last_status'] ? 'HTTP '.$op['last_status'].' ' : '' }}{{ $op['last_error'] }}</span>
                        @endif
                    </li>
                @endforeach
                @if ($needs['pending_bets'] !== [])
                    <li>Bets still being checked: <span class="font-mono">{{ implode(', ', $needs['pending_bets']) }}</span></li>
                @endif
                @if ($needs['pending_jackpot_entries'] !== [])
                    <li>Jackpot entries still being checked: <span class="font-mono">{{ implode(', ', $needs['pending_jackpot_entries']) }}</span></li>
                @endif
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
