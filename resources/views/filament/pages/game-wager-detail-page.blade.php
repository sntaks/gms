<x-filament-panels::page>
    @if ($this->apiError)
        <x-filament::section>
            <p class="text-sm font-semibold text-warning-600 dark:text-warning-400">KadiApi unavailable: this wager could not be loaded. Refresh the page to try again.</p>
        </x-filament::section>
    @else
        {{ $this->wagerInfolist }}
    @endif
</x-filament-panels::page>
